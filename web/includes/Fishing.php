<?php
/**
 * Fishing Game - Fishing Engine
 * Core catch logic: loot rolls, weight generation, fight params.
 * All randomization happens here server-side.
 */

require_once __DIR__ . '/../config.php';

class Fishing {

    /**
     * Process a cast request.
     * Validates bait, determines what fish will bite, and returns
     * all data the HUD needs to run the fishing session.
     *
     * Called when the player releases the power bar.
     */
    public static function cast(array $player, int $spotId, float $castPower): array {
        $pdo = db();
        $playerId = (int)$player['id'];

        // ── Validate fishing spot ──
        $stmt = $pdo->prepare('SELECT * FROM fishing_spots WHERE id = :id AND is_active = 1');
        $stmt->execute([':id' => $spotId]);
        $spot = $stmt->fetch();

        if (!$spot) {
            json_error('Invalid fishing spot');
        }

        // Check level requirement
        $stmt = $pdo->prepare('SELECT min_level FROM water_types WHERE id = :id');
        $stmt->execute([':id' => $spot['water_type_id']]);
        $waterMinLevel = (int)$stmt->fetchColumn();

        if ((int)$player['level'] < $waterMinLevel || (int)$player['level'] < (int)$spot['min_level']) {
            json_error('Your level is too low for this fishing spot');
        }

        // ── Validate equipped bait ──
        $baitId = $player['equipped_bait_id'];
        if (!$baitId) {
            json_error('No bait equipped');
        }

        $stmt = $pdo->prepare('
            SELECT pb.quantity, bt.*
            FROM player_bait pb
            JOIN bait_types bt ON bt.id = pb.bait_id
            WHERE pb.player_id = :pid AND pb.bait_id = :bid
        ');
        $stmt->execute([':pid' => $playerId, ':bid' => $baitId]);
        $bait = $stmt->fetch();

        if (!$bait || (int)$bait['quantity'] <= 0) {
            json_error('No bait remaining. Equip different bait.');
        }

        // ── Equipped line ──
        $line = Line::getEquipped($playerId);
        if (!$line) {
            json_error('No line equipped. Visit the Tackle page to equip a line.');
        }

        // ── Cast distance (just a display value now, rod is cosmetic) ──
        $castPower = max(MIN_CAST_POWER, min(1.0, $castPower));
        $castDistance = round($castPower * 25.0, 1);  // Static cast range

        // ── Record cast ──
        Player::incrementCasts($playerId);
        Player::awardXP($playerId, XP_PER_CAST);

        // ── Generate a schedule of roll opportunities for minute 1 (2-4 rolls over 60s) ──
        $schedule = self::generateMinuteSchedule(
            (int)$spot['water_type_id'],
            $spotId,
            $bait,
            $line,
            $player,
            1  // minute number
        );

        // ── Create a cast session record so subsequent roll requests can authenticate ──
        $castToken = self::generateCastToken($playerId, $spotId);

        return [
            'cast_distance' => $castDistance,
            'cast_token'    => $castToken,
            'schedule'      => $schedule['times'],       // [12.4, 34.1, 51.0] seconds
            'schedule_fish' => $schedule['fish'],         // [null, {species_id, name, weight, ...}, null]
            'minute'        => 1,
            'dead_water'    => $schedule['dead_water'],   // TRUE if no fish could ever bite (warn at 60s)
            'line'          => [
                'name'      => $line['name'],
                'weight_lb' => (float)$line['weight_lb'],
                'visibility'=> (float)$line['visibility'],
            ],
            'bait_remaining'=> (int)$bait['quantity'],
        ];
    }

    /**
     * Request next minute's schedule (called by HUD after 60s with no catch).
     */
    public static function rollMinute(int $playerId, int $spotId, int $minute): array {
        $pdo = db();

        $stmt = $pdo->prepare('SELECT * FROM fishing_spots WHERE id = :id AND is_active = 1');
        $stmt->execute([':id' => $spotId]);
        $spot = $stmt->fetch();
        if (!$spot) {
            json_error('Spot no longer active');
        }

        $stmt = $pdo->prepare('
            SELECT pb.quantity, bt.*
            FROM players p
            JOIN player_bait pb ON pb.player_id = p.id AND pb.bait_id = p.equipped_bait_id
            JOIN bait_types bt ON bt.id = pb.bait_id
            WHERE p.id = :pid
        ');
        $stmt->execute([':pid' => $playerId]);
        $bait = $stmt->fetch();

        if (!$bait) {
            json_error('No bait');
        }

        $line = Line::getEquipped($playerId);
        if (!$line) json_error('No line equipped');

        // Load player row directly
        $stmt = $pdo->prepare('SELECT * FROM players WHERE id = :pid');
        $stmt->execute([':pid' => $playerId]);
        $player = $stmt->fetch();
        if (!$player) json_error('Player not found');

        // Minutes 2+ get 1-2 rolls each
        $schedule = self::generateMinuteSchedule(
            (int)$spot['water_type_id'],
            $spotId,
            $bait,
            $line,
            $player,
            $minute
        );

        return [
            'minute'        => $minute,
            'schedule'      => $schedule['times'],
            'schedule_fish' => $schedule['fish'],
            'dead_water'    => $schedule['dead_water'],
        ];
    }

    /**
     * Generate a schedule of bite opportunities for one minute of fishing.
     * Returns array with:
     *   'times' => list of floats (seconds within the minute when a roll happens)
     *   'fish'  => parallel list of fish data or null (null = fake nibble)
     *   'dead_water' => TRUE if no fish are available in this pool at all
     */
    private static function generateMinuteSchedule(
        int $waterTypeId,
        int $spotId,
        array $bait,
        array $line,
        array $player,
        int $minute
    ): array {
        $pdo = db();

        // Build eligible fish pool (water type + line visibility filter)
        $eligibleFish = self::getEligibleFish($waterTypeId, (float)$line['visibility']);

        // If no fish in pool: dead_water = true, no rolls scheduled
        if (empty($eligibleFish)) {
            return ['times' => [], 'fish' => [], 'dead_water' => true];
        }

        // How many rolls this minute?
        $rollCount = ($minute === 1) ? mt_rand(2, 4) : mt_rand(1, 2);

        // ── Get active spot buffs ──
        $buffs = Buff::getActiveMultipliers($spotId);

        // ── Chum buff: add extra roll opportunity ──
        if (isset($buffs['chum']) && mt_rand(1, 100) <= 50) {
            $rollCount++;
        }

        // Spread roll times across the 60-second window, roughly evenly with jitter
        $times = [];
        $segmentSize = 60.0 / $rollCount;
        for ($i = 0; $i < $rollCount; $i++) {
            $base = $segmentSize * $i;
            $jitter = mt_rand(0, (int)($segmentSize * 1000)) / 1000.0;
            $t = round($base + $jitter, 1);
            if ($t < 2.0) $t = 2.0;
            if ($t > 58.0) $t = 58.0;
            $times[] = $t;
        }

        // For each roll time, decide if a real fish bites (applying bait + line mods)
        $fishList = [];
        $rod = ['reel_speed' => 1.0, 'tension_buffer' => 1.0, 'line_strength' => 100.0];

        // Check for magnet bait and junk availability
        $isMagnet = (strtolower($bait['name'] ?? '') === 'magnet');
        $junkItems = [];
        if ($isMagnet || isset($buffs['treasure'])) {
            $stmt = $pdo->prepare('SELECT id, item_name, rarity_weight FROM spot_junk_items WHERE spot_id = :sid');
            $stmt->execute([':sid' => $spotId]);
            $junkItems = $stmt->fetchAll();
        }

        foreach ($times as $t) {
            // ── Junk catch roll (magnet or buff) ──
            if (!empty($junkItems)) {
                $junkChance = 10;
                if ($isMagnet) $junkChance += 30;  // Magnet: +30%
                if (isset($buffs['treasure'])) $junkChance += (int)($buffs['treasure'] * 100);
                if (isset($buffs['golden_hour'])) $junkChance += 5;

                if (mt_rand(1, 100) <= $junkChance) {
                    // Rolled junk! Pick a random junk item
                    $totalJW = 0;
                    foreach ($junkItems as $j) $totalJW += (float)$j['rarity_weight'];
                    $jRoll = mt_rand(1, (int)($totalJW * 1000)) / 1000;
                    $jAcc = 0;
                    $junkName = $junkItems[0]['item_name'];
                    foreach ($junkItems as $j) {
                        $jAcc += (float)$j['rarity_weight'];
                        if ($jRoll <= $jAcc) { $junkName = $j['item_name']; break; }
                    }

                    // Junk fight params: scaled by water type
                    // Pond=easy, River=moderate, Lake=hard, Ocean=brutal
                    $junkWeightMin = 1.0; $junkWeightMax = 4.0;
                    $tensionBase = 3.0; $tensionScale = 0.8;
                    if ($waterTypeId == 2) {       // River
                        $junkWeightMin = 2.0; $junkWeightMax = 6.0;
                        $tensionBase = 5.0; $tensionScale = 1.2;
                    } elseif ($waterTypeId == 3) { // Lake
                        $junkWeightMin = 3.0; $junkWeightMax = 8.0;
                        $tensionBase = 7.0; $tensionScale = 1.8;
                    } elseif ($waterTypeId == 4) { // Ocean
                        $junkWeightMin = 5.0; $junkWeightMax = 15.0;
                        $tensionBase = 12.0; $tensionScale = 3.0;
                    }

                    $junkWeight = mt_rand((int)($junkWeightMin * 10), (int)($junkWeightMax * 10)) / 10.0;
                    $lineStrength = (float)$line['weight_lb'] * 10.0;
                    $tensionRate = round($tensionBase + $junkWeight * $tensionScale, 1);

                    $fishList[] = [
                        'is_junk'         => true,
                        'junk_item_name'  => $junkName,
                        'fish_name'       => $junkName,
                        'fish_weight'     => $junkWeight,
                        'fish_species_id' => 0,
                        'rarity_id'       => 1,
                        'bite_window'     => BITE_WINDOW,
                        'fight'           => [
                            'is_junk'              => true,
                            'initial_distance'     => round(10 + $junkWeight, 1),
                            'line_strength'        => $lineStrength,
                            'reel_rate'            => 2.0,
                            'fish_stamina'         => 999.0,
                            'fish_power'           => 0.0,
                            'fish_unpredictability' => 0.0,
                            'tension_reel_rate'    => $tensionRate,
                            'tick_rate'            => FIGHT_TICK_RATE,
                        ],
                        'catch_token'     => self::generateCatchToken(
                            (int)$player['id'], 0, $junkWeight, $spotId
                        ),
                    ];
                    continue;
                }
            }

            $fish = self::rollFishFromPool($eligibleFish, $bait, $line, $player);
            if ($fish === null) {
                $fishList[] = null;
            } else {
                $weight = self::rollWeight($fish);
                // Apply growth buff to weight
                if (isset($buffs['growth'])) $weight *= (1.0 + $buffs['growth']);
                if (isset($buffs['golden_hour'])) $weight *= (1.0 + $buffs['golden_hour']);
                $weight = round($weight, 2);

                $fightParams = self::generateFightParamsForLine($fish, $line, $weight, $buffs);
                $fishList[] = [
                    'fish_species_id' => (int)$fish['id'],
                    'fish_name'       => $fish['name'],
                    'fish_weight'     => round($weight, 2),
                    'rarity_id'       => (int)$fish['rarity_id'],
                    'bite_window'     => BITE_WINDOW,
                    'fight'           => $fightParams,
                    'catch_token'     => self::generateCatchToken(
                        (int)$player['id'], (int)$fish['id'], $weight, $spotId
                    ),
                ];
            }
        }

        return [
            'times'      => $times,
            'fish'       => $fishList,
            'dead_water' => false,
        ];
    }

    /**
     * Handle reel-in (end-of-cast) - determine if bait was lost.
     */
    public static function reelIn(int $playerId, float $castElapsed, bool $caughtFish, bool $lineBroken = false): array {
        $pdo = db();

        // Get equipped bait
        $stmt = $pdo->prepare('
            SELECT p.equipped_bait_id, pb.quantity, bt.name
            FROM players p
            LEFT JOIN player_bait pb ON pb.player_id = p.id AND pb.bait_id = p.equipped_bait_id
            LEFT JOIN bait_types bt ON bt.id = p.equipped_bait_id
            WHERE p.id = :pid
        ');
        $stmt->execute([':pid' => $playerId]);
        $row = $stmt->fetch();

        if (!$row || !$row['equipped_bait_id']) {
            return ['bait_consumed' => false, 'message' => 'Line reeled in.'];
        }

        $baitId = (int)$row['equipped_bait_id'];
        $baitName = $row['name'];

        // Bait consumption rules:
        // - If fish caught: already consumed in confirmCatch() — skip here.
        // - If line broke: 100% consumed (bait went with the fish).
        // - If reeled in within 60s: 10% chance.
        // - If reeled in after 60s: 90% chance.
        $consumeBait = false;
        if (!$caughtFish) {
            if ($lineBroken) {
                $consumeBait = true;
            } elseif ($castElapsed < 60.0) {
                $consumeBait = (mt_rand(1, 100) <= 10);
            } else {
                $consumeBait = (mt_rand(1, 100) <= 90);
            }

            if ($consumeBait) {
                $stmt = $pdo->prepare('
                    UPDATE player_bait SET quantity = quantity - 1
                    WHERE player_id = :pid AND bait_id = :bid AND quantity > 0
                ');
                $stmt->execute([':pid' => $playerId, ':bid' => $baitId]);
            }
        }

        $remaining = self::getBaitCount($playerId, $baitId);

        $message = 'Line reeled in.';
        if ($lineBroken && $consumeBait) {
            $message = "The fish took your {$baitName} along with the hook.";
        } elseif ($consumeBait) {
            $message = "A small fish made off with your {$baitName} without you noticing.";
        } elseif (!$caughtFish) {
            $message = "Line reeled in. Your {$baitName} is still on the hook.";
        }

        return [
            'bait_consumed'  => $consumeBait,
            'bait_remaining' => $remaining,
            'message'        => $message,
        ];
    }

    /**
     * Get fish species available in a water type that aren't scared by the line's visibility.
     */
    private static function getEligibleFish(int $waterTypeId, float $lineVisibility): array {
        $stmt = db()->prepare('
            SELECT fs.*, fwt.catch_weight_override
            FROM fish_species fs
            JOIN fish_water_types fwt ON fwt.fish_id = fs.id
            WHERE fwt.water_type_id = :wt
              AND fs.line_tolerance >= :lv
        ');
        $stmt->execute([':wt' => $waterTypeId, ':lv' => $lineVisibility]);
        return $stmt->fetchAll();
    }

    /**
     * Roll one fish from the eligible pool (or null for fake nibble).
     * Applies bait affinity weighting.
     */
    private static function rollFishFromPool(array $pool, array $bait, array $line, array $player): ?array {
        // Fetch rarities
        $rarities = [];
        foreach (db()->query('SELECT * FROM rarity_tiers')->fetchAll() as $r) {
            $rarities[(int)$r['id']] = $r;
        }

        // Build weighted pool with bait affinity
        $weightedPool = [];
        $totalWeight = 0.0;
        foreach ($pool as $fish) {
            $baseWeight = $fish['catch_weight_override'] ?? $rarities[(int)$fish['rarity_id']]['catch_weight'];
            $w = (float)$baseWeight;

            // Bait affinity
            $stmt = db()->prepare('
                SELECT affinity FROM fish_bait_affinity
                WHERE fish_id = :fid AND bait_id = :bid
            ');
            $stmt->execute([':fid' => $fish['id'], ':bid' => $bait['id']]);
            $affinity = $stmt->fetchColumn();
            if ($affinity !== false) {
                $w *= (float)$affinity;
            } else {
                $w *= 0.3;  // Low affinity for unlisted bait
            }

            // Player level check
            if ((int)$player['level'] < (int)($fish['min_level'] ?? 1)) {
                $w *= 0.1;  // Very unlikely if under-leveled
            }

            if ($w > 0) {
                $weightedPool[] = ['fish' => $fish, 'weight' => $w];
                $totalWeight += $w;
            }
        }

        // Also add a "no bite" outcome (fake nibble) - weight roughly 1.5x the total
        // This means roughly 60% of rolls result in a fake nibble
        $noBiteWeight = $totalWeight * 1.5;
        $totalWeight += $noBiteWeight;

        if ($totalWeight <= 0) return null;

        $roll = mt_rand(0, (int)($totalWeight * 1000)) / 1000.0;
        $accumulator = 0.0;
        foreach ($weightedPool as $entry) {
            $accumulator += $entry['weight'];
            if ($roll <= $accumulator) {
                return $entry['fish'];
            }
        }
        return null;  // Fake nibble
    }

    /**
     * Roll a weight for a fish within its species range.
     */
    private static function rollWeight(array $fish): float {
        $min = (float)$fish['min_weight'];
        $max = (float)$fish['max_weight'];
        if ($max <= $min) return $min;
        // Weighted toward the lower end (smaller fish more common)
        $roll = pow(mt_rand(0, 1000000) / 1000000.0, 1.8);
        return $min + ($max - $min) * $roll;
    }

    /**
     * Generate fight params using the equipped line.
     */
    private static function generateFightParamsForLine(array $fish, array $line, float $weight, array $buffs = []): array {
        $minW = (float)$fish['min_weight'];
        $maxW = (float)$fish['max_weight'];
        $range = $maxW - $minW;
        $weightScale = $range > 0 ? ($weight - $minW) / $range : 0.5;
        $staminaMod = 1.0 + ($weightScale * 0.5);
        $powerMod   = 1.0 + ($weightScale * 0.3);

        // ── Calm Waters buff ──
        if (isset($buffs['calm'])) {
            $staminaMod *= (1.0 - $buffs['calm']);
            $powerMod *= (1.0 - $buffs['calm']);
        }
        if (isset($buffs['golden_hour'])) {
            $staminaMod *= (1.0 - $buffs['golden_hour']);
        }

        return [
            'initial_distance'      => round(15 + ($weight * 0.3), 1),
            'line_strength'         => (float)$line['weight_lb'] * 10.0,
            'reel_rate'             => 2.0,
            'fish_stamina'          => round((float)$fish['fight_stamina'] * $staminaMod, 1),
            'fish_power'            => round((float)$fish['fight_power'] * $powerMod, 2),
            'fish_unpredictability' => (float)($fish['fight_unpredictability'] ?? 0.5),
            'tick_rate'             => FIGHT_TICK_RATE,
        ];
    }

    /**
     * Simple signed cast session token.
     */
    private static function generateCastToken(int $playerId, int $spotId): string {
        $payload = $playerId . ':' . $spotId . ':' . time();
        $sig = hash('sha256', $payload . ':' . (defined('CATCH_SECRET') ? CATCH_SECRET : 'devsecret'));
        return base64_encode($payload . ':' . $sig);
    }

    /**
     * Roll the loot table to determine what fish bites (if any).
     */
    private static function rollCatch(
        int $waterTypeId, int $spotId, array $bait, array $rod, float $castDistance, array $player
    ): ?array {
        $pdo = db();

        // Get all fish for this water type
        $stmt = $pdo->prepare('
            SELECT fs.*, fwt.catch_weight_override
            FROM fish_species fs
            JOIN fish_water_types fwt ON fwt.fish_id = fs.id
            WHERE fwt.water_type_id = :wt AND fs.is_active = 1
        ');
        $stmt->execute([':wt' => $waterTypeId]);
        $allFish = $stmt->fetchAll();

        if (empty($allFish)) {
            return null;
        }

        // Get rarity base weights
        $stmt = $pdo->prepare('SELECT * FROM rarity_tiers');
        $stmt->execute();
        $rarities = [];
        foreach ($stmt->fetchAll() as $r) {
            $rarities[(int)$r['id']] = $r;
        }

        // Check active world events
        $stmt = $pdo->prepare('SELECT event_key FROM world_events WHERE is_active = 1');
        $stmt->execute();
        $activeEvents = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get spot loot modifier
        $stmt = $pdo->prepare('SELECT loot_modifier FROM fishing_spots WHERE id = :id');
        $stmt->execute([':id' => $spotId]);
        $spotMod = (float)$stmt->fetchColumn();

        // Get current time of day (simplified: use server hour)
        $hour = (int)date('G');
        $timeOfDay = 'day';
        if ($hour >= 5 && $hour < 8) $timeOfDay = 'dawn';
        elseif ($hour >= 8 && $hour < 18) $timeOfDay = 'day';
        elseif ($hour >= 18 && $hour < 21) $timeOfDay = 'dusk';
        else $timeOfDay = 'night';

        // Build weighted pool
        $pool = [];
        $totalWeight = 0;

        foreach ($allFish as $fish) {
            $fishRarityId = (int)$fish['rarity_id'];
            $baseWeight = $fish['catch_weight_override'] ?? $rarities[$fishRarityId]['catch_weight'];
            $weight = (float)$baseWeight;

            // ── Apply bait affinity modifier ──
            $stmt = $pdo->prepare('
                SELECT affinity, is_required FROM fish_bait_affinity
                WHERE fish_id = :fid AND bait_id = :bid
            ');
            $stmt->execute([':fid' => $fish['id'], ':bid' => $bait['id']]);
            $affinity = $stmt->fetch();

            if ($affinity) {
                $weight *= (float)$affinity['affinity'];
            } else {
                // Check if this fish requires specific bait
                $stmt2 = $pdo->prepare('
                    SELECT COUNT(*) FROM fish_bait_affinity
                    WHERE fish_id = :fid AND is_required = 1
                ');
                $stmt2->execute([':fid' => $fish['id']]);
                if ((int)$stmt2->fetchColumn() > 0) {
                    // Fish requires specific bait and we don't have it — skip
                    continue;
                }
                // No special affinity, slight penalty
                $weight *= 0.5;
            }

            // ── Apply bait global modifiers ──
            $weight *= (float)$bait['catch_rate_mod'];

            // Rarity shift from bait
            if ((float)$bait['rarity_mod'] > 1.0 && $fishRarityId >= 3) {
                $weight *= (float)$bait['rarity_mod'];
            }

            // ── Apply time of day modifier ──
            $fishTime = $fish['time_modifier'] ?? 'any';
            if ($fishTime !== 'any') {
                if ($fishTime === 'dawn_dusk' && ($timeOfDay === 'dawn' || $timeOfDay === 'dusk')) {
                    $weight *= 2.0;
                } elseif ($fishTime === $timeOfDay) {
                    $weight *= 2.0;
                } else {
                    $weight *= 0.2; // Much harder outside preferred time
                }
            }

            // ── Apply special condition check ──
            if ($fish['special_condition']) {
                if (!in_array($fish['special_condition'], $activeEvents)) {
                    continue; // Event not active, fish unavailable
                }
                $weight *= 3.0; // Boost during event
            }

            // ── Apply season modifier ──
            $currentSeason = self::getCurrentSeason();
            if ($fish['season_modifier'] && $fish['season_modifier'] !== 'any') {
                if ($fish['season_modifier'] === $currentSeason) {
                    $weight *= 2.0;
                } else {
                    $weight *= 0.3;
                }
            }

            // ── Apply spot modifier ──
            $weight *= $spotMod;

            if ($weight > 0) {
                $pool[] = ['fish' => $fish, 'weight' => $weight];
                $totalWeight += $weight;
            }
        }

        if (empty($pool) || $totalWeight <= 0) {
            return null;
        }

        // ── Get active spot buffs ──
        $buffs = Buff::getActiveMultipliers($spotId);

        // ── Base no-bite chance (20%) ──
        $noBiteChance = 0.20 / (float)$bait['catch_rate_mod'];
        // Chum buff: reduce no-bite chance
        if (isset($buffs['chum'])) {
            $noBiteChance *= (1.0 - $buffs['chum']); // e.g. 0.20 * 0.75 = 0.15
        }
        // Golden hour: small bite boost
        if (isset($buffs['golden_hour'])) {
            $noBiteChance *= (1.0 - $buffs['golden_hour']);
        }
        if (mt_rand(1, 1000) / 1000 < $noBiteChance) {
            return null;
        }

        // ── Lucky Lure Oil: boost rare+ fish weights in pool ──
        if (isset($buffs['lure_oil'])) {
            $lureBoost = 1.0 + $buffs['lure_oil']; // e.g. 1.15
            $totalWeight = 0;
            foreach ($pool as &$entry) {
                if ((int)$entry['fish']['rarity_id'] >= 3) {
                    $entry['weight'] *= $lureBoost;
                }
                $totalWeight += $entry['weight'];
            }
            unset($entry);
        }

        // ── Golden hour: slight rarity boost ──
        if (isset($buffs['golden_hour'])) {
            $ghBoost = 1.0 + $buffs['golden_hour'];
            $totalWeight = 0;
            foreach ($pool as &$entry) {
                if ((int)$entry['fish']['rarity_id'] >= 3) {
                    $entry['weight'] *= $ghBoost;
                }
                $totalWeight += $entry['weight'];
            }
            unset($entry);
        }

        // ── Weighted random selection ──
        $roll = mt_rand(1, (int)($totalWeight * 1000)) / 1000;
        $cumulative = 0;
        $selected = $pool[0]; // fallback

        foreach ($pool as $entry) {
            $cumulative += $entry['weight'];
            if ($roll <= $cumulative) {
                $selected = $entry;
                break;
            }
        }

        $fish = $selected['fish'];

        // ── Generate weight ──
        $minW = (float)$fish['min_weight'];
        $maxW = (float)$fish['max_weight'];

        // Weight distribution: skewed toward smaller (more realistic)
        // Use beta-like distribution: average of two random rolls
        $r1 = mt_rand(0, 10000) / 10000;
        $r2 = mt_rand(0, 10000) / 10000;
        $normalized = ($r1 * $r2);
        $weight = $minW + ($normalized * ($maxW - $minW));

        // ── Growth Tonic buff: boost weight ──
        if (isset($buffs['growth'])) {
            $weight *= (1.0 + $buffs['growth']);
        }
        // ── Golden Hour: slight weight boost ──
        if (isset($buffs['golden_hour'])) {
            $weight *= (1.0 + $buffs['golden_hour']);
        }

        $weight = round($weight, 2);

        return ['fish' => $fish, 'weight' => $weight, 'buffs' => $buffs];
    }

    /**
     * Generate all fight parameters for the HUD.
     */
    private static function generateFightParams(array $fish, array $rod, float $weight, array $buffs = []): array {
        // Scale fight values by weight within species range
        $minW = (float)$fish['min_weight'];
        $maxW = (float)$fish['max_weight'];
        $range = $maxW - $minW;
        $weightScale = $range > 0 ? ($weight - $minW) / $range : 0.5;

        // Heavier fish fight harder
        $staminaMod = 1.0 + ($weightScale * 0.5);
        $powerMod   = 1.0 + ($weightScale * 0.3);

        // ── Calm Waters buff: reduce fight difficulty ──
        if (isset($buffs['calm'])) {
            $staminaMod *= (1.0 - $buffs['calm']); // e.g. stamina * 0.8
            $powerMod *= (1.0 - $buffs['calm']);
        }
        // ── Golden Hour: slight difficulty reduction ──
        if (isset($buffs['golden_hour'])) {
            $staminaMod *= (1.0 - $buffs['golden_hour']);
        }

        // Rod modifiers make fights easier
        $rodReelSpeed    = (float)$rod['reel_speed'];
        $rodTensionBuf   = (float)$rod['tension_buffer'];
        $rodLineStrength = (float)$rod['line_strength'];

        return [
            'initial_distance'      => round(50 + ($weight * 0.5), 0),
            'line_strength'         => $rodLineStrength,
            'reel_rate'             => round(BASE_REEL_RATE * $rodReelSpeed, 2),
            'tension_decay'         => round(BASE_TENSION_DECAY * $rodTensionBuf, 2),
            'tension_wrong_dir'     => round(TENSION_WRONG_DIR / $rodTensionBuf, 2),
            'tension_reel_vs_run'   => round(TENSION_REEL_AGAINST_RUN / $rodTensionBuf, 2),
            'fish_stamina'          => round((float)$fish['fight_stamina'] * $staminaMod, 1),
            'fish_speed'            => round((float)$fish['fight_speed'] * (1 + $weightScale * 0.2), 2),
            'fish_power'            => round((float)$fish['fight_power'] * $powerMod, 2),
            'fish_line_pull'        => round((float)$fish['fight_line_pull'] * FISH_RUN_RATE * $powerMod, 2),
            'fish_unpredictability' => (float)$fish['fight_unpredictability'],
            'tick_rate'             => FIGHT_TICK_RATE,
        ];
    }

    /**
     * Confirm a successful catch (called after player lands the fish).
     */
    public static function confirmCatch(
        array $player, string $catchToken, int $spotId, float $fightDuration, float $castDistance
    ): array {
        $pdo = db();
        $playerId = (int)$player['id'];

        // Validate catch token
        $tokenData = self::validateCatchToken($catchToken, $playerId);
        if (!$tokenData) {
            json_error('Invalid or expired catch token');
        }

        $fishSpeciesId = $tokenData['fish_id'];
        $weight        = $tokenData['weight'];

        // ── Cast distance weight bonus: up to +10% for max distance casts ──
        if ($castDistance > 0) {
            // Normalize cast distance: 10m = min, ~50m = max
            $distancePct = min(1.0, max(0.0, ($castDistance - 10.0) / 40.0));
            $distanceBonus = 1.0 + ($distancePct * 0.10);  // 1.0 to 1.10
            $weight = round($weight * $distanceBonus, 2);
        }

        // ── Junk catch (fish_id = 0) ──
        if ($fishSpeciesId === 0) {
            // XP and points scaled by water type
            $stmt = $pdo->prepare('SELECT water_type_id FROM fishing_spots WHERE id = :sid');
            $stmt->execute([':sid' => $spotId]);
            $spotRow = $stmt->fetch();
            $wtId = $spotRow ? (int)$spotRow['water_type_id'] : 1;

            // Water type XP multiplier: pond=1, river=1.5, lake=2, ocean=3
            $junkXpMult = [1 => 1.0, 2 => 1.5, 3 => 2.0, 4 => 3.0];
            $mult = $junkXpMult[$wtId] ?? 1.0;

            $totalXP = max(1, (int)round($weight * 3 * $mult));
            $totalPoints = max(1, (int)round($weight * $mult));
            $levelUp = Player::awardXP($playerId, $totalXP);
            Player::addPoints($playerId, $totalPoints);

            // Get junk item name from spot's junk table
            $junkItem = null;
            $stmt = $pdo->prepare('SELECT item_name FROM spot_junk_items WHERE spot_id = :sid ORDER BY RAND() LIMIT 1');
            $stmt->execute([':sid' => $spotId]);
            $junkName = $stmt->fetchColumn();
            if ($junkName) $junkItem = $junkName;

            return [
                'fish_name'     => $junkItem ?: 'Junk',
                'weight'        => round($weight, 2),
                'rarity'        => 'junk',
                'rarity_color'  => '888888',
                'points_value'  => $totalPoints,
                'xp_awarded'    => $totalXP,
                'level_up'      => false,
                'double_catch'  => false,
                'bait_saved'    => false,
                'junk_item'     => $junkItem,
                'is_junk'       => true,
                'quest_updates' => [],
                'quest_msg'     => '',
                'message'       => "You hauled up: " . ($junkItem ?: 'some junk') . "! (" . round($weight, 2) . " lbs of dead weight)",
            ];
        }

        // Get fish and rarity info
        $stmt = $pdo->prepare('
            SELECT fs.*, rt.name as rarity_name, rt.base_xp, rt.point_multiplier, rt.color_hex
            FROM fish_species fs
            JOIN rarity_tiers rt ON rt.id = fs.rarity_id
            WHERE fs.id = :id
        ');
        $stmt->execute([':id' => $fishSpeciesId]);
        $fish = $stmt->fetch();

        // Calculate points for this fish
        $basePoints   = (int)$fish['base_points'];
        $weightBonus  = ($weight - (float)$fish['min_weight']) * WEIGHT_BONUS_MULTIPLIER;
        $totalPoints  = max(1, (int)round($basePoints + $weightBonus));

        // Calculate XP
        $baseXP       = (int)$fish['base_xp'];
        $xpWeightMod  = ($weight - (float)$fish['min_weight']) * WEIGHT_BONUS_MULTIPLIER;
        $totalXP      = max(1, (int)round($baseXP + $xpWeightMod));

        $baitId = $player['equipped_bait_id'];
        $rodId  = $player['equipped_rod_id'];

        // ── Get active buffs for this spot ──
        $buffs = Buff::getActiveMultipliers($spotId);

        // Blessing buff: boost XP
        if (isset($buffs['blessing'])) {
            $totalXP = (int)round($totalXP * (1.0 + $buffs['blessing']));
        }
        // Golden hour: boost XP and points
        if (isset($buffs['golden_hour'])) {
            $totalXP = (int)round($totalXP * (1.0 + $buffs['golden_hour']));
            $totalPoints = (int)round($totalPoints * (1.0 + $buffs['golden_hour']));
        }

        // ── Insert into fish inventory ──
        $stmt = $pdo->prepare('
            INSERT INTO player_fish
            (player_id, fish_species_id, weight, rarity_id, spot_id, bait_used_id, rod_used_id, status)
            VALUES (:pid, :fid, :w, :rid, :sid, :bid, :rodid, \'held\')
        ');
        $stmt->execute([
            ':pid'   => $playerId,
            ':fid'   => $fishSpeciesId,
            ':w'     => $weight,
            ':rid'   => (int)$fish['rarity_id'],
            ':sid'   => $spotId,
            ':bid'   => $baitId,
            ':rodid' => $rodId,
        ]);

        // ── Insert into catch log (permanent) ──
        $stmt = $pdo->prepare('
            INSERT INTO catch_log
            (player_id, fish_species_id, weight, rarity_id, spot_id, bait_used_id, rod_used_id,
             cast_distance, fight_duration, xp_awarded)
            VALUES (:pid, :fid, :w, :rid, :sid, :bid, :rodid, :cd, :fd, :xp)
        ');
        $stmt->execute([
            ':pid'   => $playerId,
            ':fid'   => $fishSpeciesId,
            ':w'     => $weight,
            ':rid'   => (int)$fish['rarity_id'],
            ':sid'   => $spotId,
            ':bid'   => $baitId,
            ':rodid' => $rodId,
            ':cd'    => $castDistance,
            ':fd'    => (int)$fightDuration,
            ':xp'    => $totalXP,
        ]);

        // ── Award XP ──
        $levelUp = Player::awardXP($playerId, $totalXP);

        // ── Increment catch counter ──
        Player::incrementCatches($playerId);

        // ── Consume one bait (the fish ate it) ──
        if ($baitId) {
            $saveBait = false;
            if (isset($buffs['bait_saver'])) {
                $saveBait = (mt_rand(1, 100) <= (int)($buffs['bait_saver'] * 100));
            }
            if (!$saveBait) {
                $stmt = $pdo->prepare('
                    UPDATE player_bait SET quantity = quantity - 1
                    WHERE player_id = :pid AND bait_id = :bid AND quantity > 0
                ');
                $stmt->execute([':pid' => $playerId, ':bid' => $baitId]);
            }
        }

        // ── Double hook buff: chance for a second fish ──
        $doubleCatch = false;
        if (isset($buffs['double_hook'])) {
            $doubleCatch = (mt_rand(1, 100) <= (int)($buffs['double_hook'] * 100));
            if ($doubleCatch) {
                // Grant another copy of the same fish
                $pdo->prepare('
                    INSERT INTO player_fish
                    (player_id, fish_species_id, weight, rarity_id, spot_id, bait_used_id, rod_used_id, status)
                    VALUES (:pid, :fid, :w, :rid, :sid, :bid, :rodid, \'held\')
                ')->execute([
                    ':pid' => $playerId, ':fid' => $fishSpeciesId, ':w' => $weight,
                    ':rid' => (int)$fish['rarity_id'], ':sid' => $spotId,
                    ':bid' => $baitId, ':rodid' => $rodId,
                ]);
                $totalPoints *= 2;
                $totalXP *= 2;
            }
        }

        // ── Check tournament entries ──
        self::updateTournaments($playerId, $fishSpeciesId, $weight, $spotId);

        // ── Auto-track quest progress ──
        $questUpdates = [];
        try {
            // Get the water type for this spot
            $stmt = $pdo->prepare('SELECT water_type_id FROM fishing_spots WHERE id = :sid');
            $stmt->execute([':sid' => $spotId]);
            $spotRow = $stmt->fetch();
            $waterTypeId = $spotRow ? (int)$spotRow['water_type_id'] : null;

            $questUpdates = Quest::trackCatch(
                $playerId, $fishSpeciesId, $weight, $spotId,
                $baitId ? (int)$baitId : null,
                $waterTypeId,
                (int)$fish['rarity_id']
            );
        } catch (\Throwable $e) {
            // Don't let quest errors break the catch
            error_log("Quest tracking error: " . $e->getMessage());
        }

        // Build quest progress message for HUD
        $questMsg = '';
        foreach ($questUpdates as $qu) {
            if (!empty($qu['ready_to_claim'])) {
                $questMsg .= $qu['message'];
            } elseif (!empty($qu['progress'])) {
                $questMsg .= $qu['quest_title'] . ': ' . $qu['progress'];
                if ($qu['complete']) $questMsg .= ' ✓';
                $questMsg .= "\n";
            }
        }

        $catchMsg = "You caught a {$fish['rarity_name']} {$fish['name']} weighing " . round($weight, 2) . " lbs!";
        if ($doubleCatch) $catchMsg .= " DOUBLE HOOK! Two fish!";

        // ── Bonus junk side-catch (only when Treasure Scent buff is active) ──
        // Note: junk FIGHTS are handled separately via the schedule system.
        // This is a secondary bonus drop for fish catches only.
        $junkItem = null;
        if (isset($buffs['treasure'])) {
            try {
                $stmt = $pdo->prepare('SELECT id, item_name, rarity_weight FROM spot_junk_items WHERE spot_id = :sid');
                $stmt->execute([':sid' => $spotId]);
                $junkItems = $stmt->fetchAll();

                if (!empty($junkItems)) {
                    // Only Treasure Scent triggers this (50% chance), Golden Hour adds 5%
                    $junkChance = (int)($buffs['treasure'] * 100);
                    if (isset($buffs['golden_hour'])) $junkChance += 5;

                    if (mt_rand(1, 100) <= $junkChance) {
                        $totalJunkWeight = 0;
                        foreach ($junkItems as $j) $totalJunkWeight += (float)$j['rarity_weight'];
                        $roll = mt_rand(1, max(1, (int)($totalJunkWeight * 1000))) / 1000;
                        $acc = 0;
                        foreach ($junkItems as $j) {
                            $acc += (float)$j['rarity_weight'];
                            if ($roll <= $acc) { $junkItem = $j['item_name']; break; }
                        }
                        if ($junkItem) $catchMsg .= " You also fished up: " . $junkItem . "!";
                    }
                }
            } catch (\Throwable $e) {
                error_log("Junk side-catch error: " . $e->getMessage());
            }
        }

        return [
            'fish_name'     => $fish['name'],
            'weight'        => round($weight, 2),
            'rarity'        => $fish['rarity_name'],
            'rarity_color'  => $fish['color_hex'],
            'points_value'  => $totalPoints,
            'xp_awarded'    => $totalXP,
            'level_up'      => $levelUp,
            'double_catch'  => $doubleCatch,
            'bait_saved'    => isset($buffs['bait_saver']) && !empty($saveBait) && $saveBait,
            'junk_item'     => $junkItem,
            'quest_updates' => $questUpdates,
            'quest_msg'     => trim($questMsg),
            'message'       => $catchMsg,
        ];
    }

    /**
     * Report a line break. Awards small XP by water type.
     * Only consumes bait if it's a Magnet.
     */
    public static function reportLineBreak(int $playerId, int $spotId): array {
        $pdo = db();

        // Get water type for XP scaling
        $wtId = 1;
        if ($spotId > 0) {
            $stmt = $pdo->prepare('SELECT water_type_id FROM fishing_spots WHERE id = :sid');
            $stmt->execute([':sid' => $spotId]);
            $row = $stmt->fetch();
            if ($row) $wtId = (int)$row['water_type_id'];
        }

        // Small XP: pond=2, river=3, lake=5, ocean=8
        $xpByWater = [1 => 2, 2 => 3, 3 => 5, 4 => 8];
        $xp = $xpByWater[$wtId] ?? 2;
        Player::awardXP($playerId, $xp);

        // Check if equipped bait is Magnet — only lose magnet on break
        $stmt = $pdo->prepare('SELECT equipped_bait_id FROM players WHERE id = :id');
        $stmt->execute([':id' => $playerId]);
        $player = $stmt->fetch();
        $baitLost = false;

        if ($player && $player['equipped_bait_id']) {
            $stmt = $pdo->prepare('SELECT name FROM bait_types WHERE id = :id');
            $stmt->execute([':id' => $player['equipped_bait_id']]);
            $baitName = $stmt->fetchColumn();

            if (strtolower($baitName) === 'magnet') {
                // Consume one magnet
                $pdo->prepare('
                    UPDATE player_bait SET quantity = quantity - 1
                    WHERE player_id = :pid AND bait_id = :bid AND quantity > 0
                ')->execute([':pid' => $playerId, ':bid' => $player['equipped_bait_id']]);
                $baitLost = true;
            }
        }

        return [
            'xp_awarded' => $xp,
            'bait_lost' => $baitLost,
            'message' => "Line snapped! +" . $xp . " XP" . ($baitLost ? " (Magnet lost)" : ""),
        ];
    }

    /**
     * Generate a signed catch token so the HUD can't fake catches.
     */
    private static function generateCatchToken(int $playerId, int $fishId, float $weight, int $spotId): string {
        $data = json_encode([
            'pid' => $playerId,
            'fid' => $fishId,
            'w'   => $weight,
            'sid' => $spotId,
            't'   => time(),
        ]);
        $sig = hash_hmac('sha256', $data, HUD_SECRET);
        return base64_encode($data) . '.' . $sig;
    }

    /**
     * Validate and decode a catch token.
     */
    private static function validateCatchToken(string $token, int $playerId): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;

        $data = base64_decode($parts[0]);
        $sig  = $parts[1];

        $expected = hash_hmac('sha256', $data, HUD_SECRET);
        if (!hash_equals($expected, $sig)) return null;

        $decoded = json_decode($data, true);
        if (!$decoded) return null;

        // Verify player match
        if ((int)$decoded['pid'] !== $playerId) return null;

        // Token expires after 10 minutes (generous for long fights)
        if (time() - (int)$decoded['t'] > 600) return null;

        return [
            'fish_id' => (int)$decoded['fid'],
            'weight'  => (float)$decoded['w'],
            'spot_id' => (int)$decoded['sid'],
        ];
    }

    /**
     * Calculate bite wait time based on bait effectiveness.
     */
    private static function calculateBiteWait(array $bait, array $fish): int {
        $base = rand(BITE_WAIT_MIN, BITE_WAIT_MAX);
        $mod  = (float)$bait['catch_rate_mod'];
        return max(BITE_WAIT_MIN, (int)round($base / $mod));
    }

    /**
     * Generate random times for fake nibbles during the wait.
     */
    private static function generateNibbleTimes(int $biteWait, int $count): array {
        if ($count === 0) return [];

        $times = [];
        // Nibbles happen in first 80% of wait, not too close to real bite
        $maxNibbleTime = (int)($biteWait * 0.8);

        for ($i = 0; $i < $count; $i++) {
            $times[] = rand(2, max(3, $maxNibbleTime));
        }
        sort($times);
        return $times;
    }

    /**
     * Get current season from server date.
     */
    private static function getCurrentSeason(): string {
        $month = (int)date('n');
        if ($month >= 3 && $month <= 5) return 'spring';
        if ($month >= 6 && $month <= 8) return 'summer';
        if ($month >= 9 && $month <= 11) return 'fall';
        return 'winter';
    }

    /**
     * Get remaining bait count.
     */
    private static function getBaitCount(int $playerId, int $baitId): int {
        $stmt = db()->prepare('
            SELECT quantity FROM player_bait
            WHERE player_id = :pid AND bait_id = :bid
        ');
        $stmt->execute([':pid' => $playerId, ':bid' => $baitId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    /**
     * Update any active tournaments with a new catch.
     */
    private static function updateTournaments(int $playerId, int $fishSpeciesId, float $weight, int $spotId): void {
        $pdo = db();

        $stmt = $pdo->prepare('
            SELECT * FROM tournaments
            WHERE is_active = 1 AND starts_at <= NOW() AND ends_at > NOW()
        ');
        $stmt->execute();
        $tournaments = $stmt->fetchAll();

        foreach ($tournaments as $t) {
            // Check species filter
            if ($t['target_species_id'] && (int)$t['target_species_id'] !== $fishSpeciesId) continue;

            // Check water filter
            if ($t['target_water_id']) {
                $stmt2 = $pdo->prepare('SELECT water_type_id FROM fishing_spots WHERE id = :id');
                $stmt2->execute([':id' => $spotId]);
                $spotWater = (int)$stmt2->fetchColumn();
                if ($spotWater !== (int)$t['target_water_id']) continue;
            }

            // Upsert tournament entry
            $scoring = $t['scoring_type'];
            $stmt2 = $pdo->prepare('
                INSERT INTO tournament_entries (tournament_id, player_id, score, total_catches)
                VALUES (:tid, :pid, :score, 1)
                ON DUPLICATE KEY UPDATE
                    score = CASE
                        WHEN :scoring = \'biggest_single\' AND :score2 > score THEN :score3
                        WHEN :scoring2 = \'total_weight\' THEN score + :score4
                        WHEN :scoring3 = \'most_catches\' THEN total_catches + 1
                        ELSE score
                    END,
                    total_catches = total_catches + 1,
                    last_updated = NOW()
            ');

            $scoreVal = ($scoring === 'most_catches') ? 1 : $weight;
            $stmt2->execute([
                ':tid'      => $t['id'],
                ':pid'      => $playerId,
                ':score'    => $scoreVal,
                ':score2'   => $scoreVal,
                ':score3'   => $scoreVal,
                ':score4'   => $scoreVal,
                ':scoring'  => $scoring,
                ':scoring2' => $scoring,
                ':scoring3' => $scoring,
            ]);
        }
    }
}
