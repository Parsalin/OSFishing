<?php
/**
 * Fishing Game - Fishing Engine
 * Core catch logic: loot rolls, weight generation, fight params.
 * All randomization happens here server-side.
 */

require_once __DIR__ . '/bootstrap.php';

class Fishing {

    // ── Cast record (fishing_casts) ──────────────────────────────────────
    // `cast` opens a row; roll_minute, confirm_catch, line_break and
    // reel_in must all match the player's open cast. The row fixes the spot,
    // bait, line and power at cast time, and closing it is what makes a
    // catch token single-use. Only the server decides bait use.
    private const CAST_KEEP       = 100;  // casts kept per player
    private const CAST_MAX_AGE    = 900;  // s: the HUD's 5-min idle cutoff + missed bites + a long fight
    private const CAST_MAX_MINUTE = 6;    // the HUD gives up during minute 5
    private const MINUTE_MIN_GAP  = 50;   // s between roll_minute calls (the HUD waits 60)
    private const CAST_MIN_GAP    = 2;    // s between two casts
    private const CAST_XP_GAP     = 15;   // s: a cast this soon after the last earns no cast XP
    private const LAND_MIN_SECS   = 2;    // s: earliest landing after the bite
    private const CAST_WEIGHT_BONUS = 0.10;  // full-power cast: +10 % weight

    /** Unix time of a stored UTC DATETIME. */
    private static function utcTs(?string $dt): int {
        return $dt ? (int)strtotime($dt . ' UTC') : 0;
    }

    /** The player's newest cast (any status), or null. */
    private static function lastCast(int $playerId): ?array {
        $stmt = db()->prepare('SELECT * FROM fishing_casts WHERE player_id = :pid ORDER BY id DESC LIMIT 1');
        $stmt->execute([':pid' => $playerId]);
        return $stmt->fetch() ?: null;
    }

    /** The player's open cast, or null. A cast left open too long is closed here. */
    private static function openCast(int $playerId): ?array {
        $cast = self::lastCast($playerId);
        if (!$cast || $cast['status'] !== 'open') return null;
        if (time() - self::utcTs($cast['started_at']) > self::CAST_MAX_AGE) {
            self::closeCast($cast, 'reeled');
            return null;
        }
        return $cast;
    }

    /**
     * Close an open cast that ended without a catch and settle its bait:
     * a broken line takes the bait; otherwise 10 % inside the first minute,
     * 90 % after. Returns null when the cast was already closed (another
     * request got there first), else ['consumed' => bool].
     */
    private static function closeCast(array $cast, string $status): ?array {
        $pdo = db();
        $stmt = $pdo->prepare("
            UPDATE fishing_casts SET status = :s, ended_at = UTC_TIMESTAMP()
            WHERE id = :id AND status = 'open'
        ");
        $stmt->execute([':s' => $status, ':id' => $cast['id']]);
        if ($stmt->rowCount() !== 1) return null;

        if ($status === 'broken') {
            $consume = true;
        } else {
            $elapsed = time() - self::utcTs($cast['started_at']);
            $consume = mt_rand(1, 100) <= ($elapsed < 60 ? 10 : 90);
        }
        $consumed = $consume && $cast['bait_id'] && self::useBait((int)$cast['player_id'], (int)$cast['bait_id']);
        if ($consumed) {
            $pdo->prepare('UPDATE fishing_casts SET bait_consumed = 1 WHERE id = :id')
                ->execute([':id' => $cast['id']]);
        }
        return ['consumed' => $consumed];
    }

    /** Take one bait. False if the player had none left. */
    private static function useBait(int $playerId, int $baitId): bool {
        $stmt = db()->prepare('
            UPDATE player_bait SET quantity = quantity - 1
            WHERE player_id = :pid AND bait_id = :bid AND quantity > 0
        ');
        $stmt->execute([':pid' => $playerId, ':bid' => $baitId]);
        return $stmt->rowCount() === 1;
    }

    /** Keep only the player's newest CAST_KEEP casts. */
    private static function pruneCasts(int $playerId): void {
        db()->prepare('
            DELETE FROM fishing_casts WHERE player_id = :pid AND id < (
                SELECT id FROM (
                    SELECT id FROM fishing_casts WHERE player_id = :pid2
                    ORDER BY id DESC LIMIT 1 OFFSET ' . (self::CAST_KEEP - 1) . '
                ) AS keep_from
            )
        ')->execute([':pid' => $playerId, ':pid2' => $playerId]);
    }

    /** Remember the earliest real bite handed out on this cast (line_break needs one). */
    private static function noteFirstBite(int $castId, ?int $biteAt): void {
        if ($biteAt === null) return;
        db()->prepare('
            UPDATE fishing_casts
            SET first_bite_at = LEAST(COALESCE(first_bite_at, :t1), :t2)
            WHERE id = :id
        ')->execute([':t1' => gmdate('Y-m-d H:i:s', $biteAt), ':t2' => gmdate('Y-m-d H:i:s', $biteAt), ':id' => $castId]);
    }

    /** The bait row (with the player's quantity) for a cast; json_error if none is left. */
    private static function castBait(int $playerId, int $baitId): array {
        $stmt = db()->prepare('
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
        return $bait;
    }

    /**
     * Process a cast request.
     * Opens the cast record (closing any unfinished one), then returns the
     * first minute's bite schedule. Called when the player releases the power bar.
     */
    public static function cast(array $player, int $spotId, float $castPower): array {
        $pdo = db();
        $playerId = (int)$player['id'];

        // ── Validate fishing spot ──
        $stmt = $pdo->prepare('SELECT * FROM fishing_spots WHERE id = :id AND is_active = 1 AND archived = 0');
        $stmt->execute([':id' => $spotId]);
        $spot = $stmt->fetch();

        if (!$spot) {
            json_error('Invalid fishing spot');
        }

        // Check spot level requirement (spot_level is the sole player-access gate)
        if ((int)$player['level'] < (int)$spot['spot_level']) {
            json_error('Your level is too low for this fishing spot (requires level ' . (int)$spot['spot_level'] . ')');
        }

        // ── The previous cast: too soon, or still open (it ends unfinished) ──
        $last = self::lastCast($playerId);
        $sinceLast = $last ? time() - self::utcTs($last['started_at']) : PHP_INT_MAX;
        if ($sinceLast < self::CAST_MIN_GAP) {
            json_error('Your line is still in the air. Cast again in a moment.');
        }
        if ($last && $last['status'] === 'open') {
            self::closeCast($last, 'replaced');
        }

        // ── Validate equipped bait ──
        $baitId = (int)$player['equipped_bait_id'];
        if (!$baitId) {
            json_error('No bait equipped');
        }
        $bait = self::castBait($playerId, $baitId);

        // ── Equipped line ──
        $line = Line::getEquipped($playerId);
        if (!$line) {
            json_error('No line equipped. Visit the Tackle page to equip a line.');
        }

        // ── Cast distance (display; the rod is cosmetic) ──
        $castPower = max(MIN_CAST_POWER, min(1.0, $castPower));
        $castDistance = round($castPower * 25.0, 1);

        // ── Record the cast ──
        $now = time();
        $nowDt = gmdate('Y-m-d H:i:s', $now);
        $pdo->prepare('
            INSERT INTO fishing_casts
                (player_id, spot_id, bait_id, line_id, cast_power, cast_distance, started_at, minute_issued_at)
            VALUES (:pid, :sid, :bid, :lid, :pw, :cd, :t1, :t2)
        ')->execute([
            ':pid' => $playerId, ':sid' => $spotId, ':bid' => $baitId, ':lid' => (int)$line['id'],
            ':pw' => $castPower, ':cd' => $castDistance, ':t1' => $nowDt, ':t2' => $nowDt,
        ]);
        $castId = (int)$pdo->lastInsertId();
        self::pruneCasts($playerId);

        Player::incrementCasts($playerId);
        if ($sinceLast >= self::CAST_XP_GAP) {
            Player::awardXP($playerId, XP_PER_CAST);
        }

        // ── Minute 1: 2-4 roll opportunities over 60 s ──
        $schedule = self::generateMinuteSchedule(
            (int)$spot['water_type_id'], $spotId, $bait, $line, $player, 1,
            ['id' => $castId, 'power' => $castPower, 'issued' => $now]
        );
        self::noteFirstBite($castId, $schedule['first_bite']);

        return [
            'cast_distance' => $castDistance,
            'cast_id'       => $castId,
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
     * Next minute's schedule (the HUD asks every 60 s with no catch).
     * Only for the open cast, only the next minute, and not early.
     */
    public static function rollMinute(int $playerId, int $spotId, int $minute): array {
        $pdo = db();

        $cast = self::openCast($playerId);
        if (!$cast) {
            json_error('Your line is out of the water. Cast again.');
        }
        $prev = (int)$cast['last_minute'];
        if ((int)$cast['spot_id'] !== $spotId || $minute !== $prev + 1 || $minute > self::CAST_MAX_MINUTE
            || time() - self::utcTs($cast['minute_issued_at']) < self::MINUTE_MIN_GAP) {
            json_error('Your cast got out of step. Reel in and cast again.');
        }

        $now = time();
        $stmt = $pdo->prepare("
            UPDATE fishing_casts SET last_minute = :m, minute_issued_at = :t
            WHERE id = :id AND status = 'open' AND last_minute = :prev
        ");
        $stmt->execute([':m' => $minute, ':t' => gmdate('Y-m-d H:i:s', $now), ':id' => $cast['id'], ':prev' => $prev]);
        if ($stmt->rowCount() !== 1) {
            json_error('Your cast got out of step. Reel in and cast again.');
        }

        $stmt = $pdo->prepare('SELECT * FROM fishing_spots WHERE id = :id AND is_active = 1 AND archived = 0');
        $stmt->execute([':id' => $spotId]);
        $spot = $stmt->fetch();
        if (!$spot) {
            json_error('Spot no longer active');
        }

        // The bait and line the cast was made with, not whatever is equipped now
        $bait = self::castBait($playerId, (int)$cast['bait_id']);
        $stmt = $pdo->prepare('SELECT id, name, weight_lb, visibility, min_level FROM line_types WHERE id = :id');
        $stmt->execute([':id' => (int)$cast['line_id']]);
        $line = $stmt->fetch();
        if (!$line) json_error('No line equipped');

        $stmt = $pdo->prepare('SELECT * FROM players WHERE id = :pid');
        $stmt->execute([':pid' => $playerId]);
        $player = $stmt->fetch();
        if (!$player) json_error('Player not found');

        // Minutes 2+ get 1-2 rolls each
        $schedule = self::generateMinuteSchedule(
            (int)$spot['water_type_id'], $spotId, $bait, $line, $player, $minute,
            ['id' => (int)$cast['id'], 'power' => (float)$cast['cast_power'], 'issued' => $now]
        );
        self::noteFirstBite((int)$cast['id'], $schedule['first_bite']);

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
     *   'first_bite' => unix time of the earliest real bite, or null
     * $cast = ['id' => cast id, 'power' => 0..1, 'issued' => unix time the
     * schedule is handed out]; each catch token carries the cast and its bite time.
     */
    private static function generateMinuteSchedule(
        int $waterTypeId,
        int $spotId,
        array $bait,
        array $line,
        array $player,
        int $minute,
        array $cast
    ): array {
        $pdo = db();
        $firstBite = null;

        // Build eligible fish pool (water type + line visibility filter)
        $eligibleFish = self::getEligibleFish($waterTypeId, (float)$line['visibility']);

        // If no fish in pool: dead_water = true, no rolls scheduled
        if (empty($eligibleFish)) {
            return ['times' => [], 'fish' => [], 'dead_water' => true, 'first_bite' => null];
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
            $biteAt = (int)$cast['issued'] + (int)floor($t);
            // ── Junk catch roll (magnet or buff) ──
            if (!empty($junkItems)) {
                $junkChance = 10;
                if ($isMagnet) $junkChance += 30;  // Magnet: +30%
                if (isset($buffs['treasure'])) $junkChance += (int)($buffs['treasure'] * 100);
                if (isset($buffs['golden_hour'])) $junkChance += 5;

                if (mt_rand(1, 100) <= $junkChance) {
                    // Rolled junk! Pick a random junk item (the token names it)
                    $junk = self::pickJunk($junkItems);
                    $junkName = $junk['item_name'];

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
                            (int)$player['id'], 0, $junkWeight, $spotId,
                            (int)$cast['id'], $biteAt, (int)$junk['id']
                        ),
                    ];
                    $firstBite = $firstBite === null ? $biteAt : min($firstBite, $biteAt);
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
                // A stronger cast reaches bigger fish
                $weight *= 1.0 + self::CAST_WEIGHT_BONUS * (float)$cast['power'];
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
                        (int)$player['id'], (int)$fish['id'], $weight, $spotId,
                        (int)$cast['id'], $biteAt
                    ),
                ];
                $firstBite = $firstBite === null ? $biteAt : min($firstBite, $biteAt);
            }
        }

        return [
            'times'      => $times,
            'fish'       => $fishList,
            'dead_water' => false,
            'first_bite' => $firstBite,
        ];
    }

    /**
     * Reel in (the cast ended without a catch). The server settles the bait
     * from its own cast record; the HUD's elapsed time and caught flag are
     * ignored. A line break may already have closed the cast (line_break and
     * reel_in are sent together), so the newest cast is reported then.
     */
    public static function reelIn(int $playerId, bool $lineBroken = false): array {
        $consumed = false;
        $broken   = $lineBroken;
        $baitId   = 0;

        $cast = self::openCast($playerId);
        if ($cast) {
            $res = self::closeCast($cast, $lineBroken ? 'broken' : 'reeled');
            $consumed = (bool)($res['consumed'] ?? false);
            $baitId = (int)$cast['bait_id'];
        } else {
            $last = self::lastCast($playerId);
            if ($last && $last['status'] === 'broken' && time() - self::utcTs($last['ended_at']) < 120) {
                $broken   = true;
                $consumed = (int)$last['bait_consumed'] === 1;
                $baitId   = (int)$last['bait_id'];
            }
        }

        $baitName = 'bait';
        if ($baitId) {
            $stmt = db()->prepare('SELECT name FROM bait_types WHERE id = :id');
            $stmt->execute([':id' => $baitId]);
            $baitName = $stmt->fetchColumn() ?: 'bait';
        }

        $message = 'Line reeled in.';
        if ($broken && $consumed) {
            $message = "The fish took your {$baitName} along with the hook.";
        } elseif ($consumed) {
            $message = "A small fish made off with your {$baitName} without you noticing.";
        } elseif ($cast) {
            $message = "Line reeled in. Your {$baitName} is still on the hook.";
        }

        // The HUD shows the count of the bait equipped now
        $stmt = db()->prepare('SELECT equipped_bait_id FROM players WHERE id = :id');
        $stmt->execute([':id' => $playerId]);
        $equipped = (int)$stmt->fetchColumn();

        return [
            'bait_consumed'  => $consumed,
            'bait_remaining' => $equipped ? self::getBaitCount($playerId, $equipped) : 0,
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
     * Confirm a successful catch (called after the player lands the fish).
     * The catch token names its cast; the cast must still be open, and
     * closing it here makes the token single-use. Spot, bait and cast
     * distance come from the cast record, never from the request.
     */
    public static function confirmCatch(array $player, string $catchToken, float $fightDuration): array {
        $pdo = db();
        $playerId = (int)$player['id'];

        $tokenData = self::validateCatchToken($catchToken, $playerId);
        if (!$tokenData) {
            json_error('Invalid or expired catch token');
        }

        $stmt = $pdo->prepare('SELECT * FROM fishing_casts WHERE id = :id AND player_id = :pid');
        $stmt->execute([':id' => $tokenData['cast_id'], ':pid' => $playerId]);
        $cast = $stmt->fetch();

        $refuse = function (string $why) use ($playerId, $tokenData, $cast): void {
            LogicErrorLog::log('catch_refused', $playerId, $why, [
                'cast_id' => $tokenData['cast_id'], 'cast_status' => $cast['status'] ?? null,
                'fish_id' => $tokenData['fish_id'], 'bite_at' => $tokenData['bite_at'], 'now' => time(),
            ]);
            json_error('That catch could not be confirmed. Cast again.');
        };

        if (!$cast)                                               $refuse('no such cast');
        if ($cast['status'] !== 'open')                           $refuse('cast already ' . $cast['status']);
        if ((int)$cast['spot_id'] !== $tokenData['spot_id'])      $refuse('token spot differs from the cast');
        if (time() < $tokenData['bite_at'] + self::LAND_MIN_SECS) $refuse('landed before the bite');
        if (time() - self::utcTs($cast['started_at']) > self::CAST_MAX_AGE) $refuse('cast too old');

        $fishSpeciesId = $tokenData['fish_id'];
        $weight        = $tokenData['weight'];
        $spotId        = (int)$cast['spot_id'];
        $baitId        = $cast['bait_id'] ? (int)$cast['bait_id'] : null;
        $rodId         = $player['equipped_rod_id'];
        $castDistance  = (float)$cast['cast_distance'];

        $stmt = $pdo->prepare('SELECT is_system, spot_level, spot_level_ready, water_type_id FROM fishing_spots WHERE id = :sid');
        $stmt->execute([':sid' => $spotId]);
        $spotMeta = $stmt->fetch() ?: null;
        $waterTypeId = $spotMeta ? (int)$spotMeta['water_type_id'] : null;

        $fish = null;
        if ($fishSpeciesId !== 0) {
            $stmt = $pdo->prepare('
                SELECT fs.*, rt.name as rarity_name, rt.base_xp, rt.point_multiplier, rt.color_hex
                FROM fish_species fs
                JOIN rarity_tiers rt ON rt.id = fs.rarity_id
                WHERE fs.id = :id
            ');
            $stmt->execute([':id' => $fishSpeciesId]);
            $fish = $stmt->fetch();
            if (!$fish) $refuse('fish species gone');
        }

        // Buffs are read (and expired ones pushed) before the transaction
        $buffs = Buff::getActiveMultipliers($spotId);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                UPDATE fishing_casts SET status = 'caught', ended_at = UTC_TIMESTAMP()
                WHERE id = :id AND status = 'open'
            ");
            $stmt->execute([':id' => $cast['id']]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                $refuse('cast closed by a concurrent request');
            }

            $result = $fishSpeciesId === 0
                ? self::landJunk($pdo, $playerId, $spotId, $waterTypeId, $tokenData['junk_id'], $weight)
                : self::landFish($pdo, $player, $fish, $weight, $spotId, $spotMeta, $waterTypeId,
                                 $baitId, $rodId, $castDistance, $fightDuration, $buffs);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $result;
    }

    /** A junk haul: XP and points by water type; the item is the one that was fought. Uses no bait. */
    private static function landJunk(\PDO $pdo, int $playerId, int $spotId, ?int $waterTypeId, int $junkId, float $weight): array {
        // Water type XP multiplier: pond=1, river=1.5, lake=2, ocean=3
        $junkXpMult = [1 => 1.0, 2 => 1.5, 3 => 2.0, 4 => 3.0];
        $mult = $junkXpMult[$waterTypeId ?? 1] ?? 1.0;

        $totalXP = max(1, (int)round($weight * 3 * $mult));
        $totalPoints = max(1, (int)round($weight * $mult));
        $levelUp = Player::awardXP($playerId, $totalXP);
        Player::addPoints($playerId, $totalPoints);

        $stmt = $pdo->prepare('SELECT item_name FROM spot_junk_items WHERE id = :id AND spot_id = :sid');
        $stmt->execute([':id' => $junkId, ':sid' => $spotId]);
        $junkItem = $stmt->fetchColumn() ?: null;

        return [
            'fish_name'     => $junkItem ?: 'Junk',
            'weight'        => round($weight, 2),
            'rarity'        => 'junk',
            'rarity_color'  => '888888',
            'points_value'  => $totalPoints,
            'xp_awarded'    => $totalXP,
            'level_up'      => $levelUp,
            'double_catch'  => false,
            'bait_saved'    => false,
            'junk_item'     => $junkItem,
            'is_junk'       => true,
            'quest_updates' => [],
            'quest_msg'     => '',
            'message'       => "You hauled up: " . ($junkItem ?: 'some junk') . "! (" . round($weight, 2) . " lbs of dead weight)",
        ];
    }

    /** A landed fish: inventory, catch log, XP, bait, buffs, spot XP, quests. */
    private static function landFish(
        \PDO $pdo, array $player, array $fish, float $weight, int $spotId, ?array $spotMeta,
        ?int $waterTypeId, ?int $baitId, $rodId, float $castDistance, float $fightDuration, array $buffs
    ): array {
        $playerId      = (int)$player['id'];
        $fishSpeciesId = (int)$fish['id'];

        // Points and XP for this fish
        $basePoints   = (int)$fish['base_points'];
        $weightBonus  = ($weight - (float)$fish['min_weight']) * WEIGHT_BONUS_MULTIPLIER;
        $totalPoints  = max(1, (int)round($basePoints + $weightBonus));

        $baseXP       = (int)$fish['base_xp'];
        $xpWeightMod  = ($weight - (float)$fish['min_weight']) * WEIGHT_BONUS_MULTIPLIER;
        $totalXP      = max(1, (int)round($baseXP + $xpWeightMod));

        if (isset($buffs['blessing'])) {
            $totalXP = (int)round($totalXP * (1.0 + $buffs['blessing']));
        }
        if (isset($buffs['golden_hour'])) {
            $totalXP = (int)round($totalXP * (1.0 + $buffs['golden_hour']));
            $totalPoints = (int)round($totalPoints * (1.0 + $buffs['golden_hour']));
        }

        $insertFish = $pdo->prepare('
            INSERT INTO player_fish
            (player_id, fish_species_id, weight, rarity_id, spot_id, bait_used_id, rod_used_id, status)
            VALUES (:pid, :fid, :w, :rid, :sid, :bid, :rodid, \'held\')
        ');
        $fishRow = [
            ':pid'   => $playerId,
            ':fid'   => $fishSpeciesId,
            ':w'     => $weight,
            ':rid'   => (int)$fish['rarity_id'],
            ':sid'   => $spotId,
            ':bid'   => $baitId,
            ':rodid' => $rodId,
        ];
        $insertFish->execute($fishRow);

        $pdo->prepare('
            INSERT INTO catch_log
            (player_id, fish_species_id, weight, rarity_id, spot_id, bait_used_id, rod_used_id,
             cast_distance, fight_duration, xp_awarded)
            VALUES (:pid, :fid, :w, :rid, :sid, :bid, :rodid, :cd, :fd, :xp)
        ')->execute($fishRow + [
            ':cd' => $castDistance,
            ':fd' => max(0, (int)$fightDuration),
            ':xp' => $totalXP,
        ]);

        Player::incrementCatches($playerId);

        // ── One bait (the fish ate it), unless Bait Saver keeps it ──
        $saveBait = false;
        if ($baitId) {
            if (isset($buffs['bait_saver'])) {
                $saveBait = (mt_rand(1, 100) <= (int)($buffs['bait_saver'] * 100));
            }
            if (!$saveBait) self::useBait($playerId, $baitId);
        }

        // ── Double hook: a second copy of the same fish, with its XP ──
        $doubleCatch = false;
        if (isset($buffs['double_hook'])) {
            $doubleCatch = (mt_rand(1, 100) <= (int)($buffs['double_hook'] * 100));
            if ($doubleCatch) {
                $insertFish->execute($fishRow);
                $totalPoints *= 2;
                $totalXP *= 2;
            }
        }

        $levelUp = Player::awardXP($playerId, $totalXP);

        // Tournament standings are computed from catch_log (Tournament::getLeaderboard).

        $spotXpResult = self::awardSpotXp($pdo, $spotId, $playerId, (int)$fish['rarity_id'], $spotMeta);

        $questUpdates = [];
        try {
            $questUpdates = Quest::trackCatch(
                $playerId, $fishSpeciesId, $weight, $spotId,
                $baitId, $waterTypeId, (int)$fish['rarity_id']
            );
        } catch (\Throwable $e) {
            // Don't let quest errors break the catch
            error_log("Quest tracking error: " . $e->getMessage());
        }

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

        // ── Bonus junk side-catch (only when Treasure Scent is active) ──
        // Junk FIGHTS come from the schedule; this is a secondary drop on fish catches.
        $junkItem = null;
        if (isset($buffs['treasure'])) {
            $stmt = $pdo->prepare('SELECT id, item_name, rarity_weight FROM spot_junk_items WHERE spot_id = :sid');
            $stmt->execute([':sid' => $spotId]);
            $junkItems = $stmt->fetchAll();

            if (!empty($junkItems)) {
                $junkChance = (int)($buffs['treasure'] * 100);
                if (isset($buffs['golden_hour'])) $junkChance += 5;

                if (mt_rand(1, 100) <= $junkChance) {
                    $junkItem = self::pickJunk($junkItems)['item_name'];
                    $catchMsg .= " You also fished up: " . $junkItem . "!";
                }
            }
        }

        return [
            'fish_name'         => $fish['name'],
            'weight'            => round($weight, 2),
            'rarity'            => $fish['rarity_name'],
            'rarity_color'      => $fish['color_hex'],
            'points_value'      => $totalPoints,
            'xp_awarded'        => $totalXP,
            'level_up'          => $levelUp,
            'double_catch'      => $doubleCatch,
            'bait_saved'        => $saveBait,
            'junk_item'         => $junkItem,
            'quest_updates'     => $questUpdates,
            'quest_msg'         => trim($questMsg),
            'spot_xp_awarded'   => $spotXpResult['xp_awarded'],
            'spot_level_ready'  => $spotXpResult['level_ready'],
            'message'           => $catchMsg,
        ];
    }

    /** Weighted pick from spot_junk_items rows (id, item_name, rarity_weight). */
    private static function pickJunk(array $junkItems): array {
        $total = 0.0;
        foreach ($junkItems as $j) $total += (float)$j['rarity_weight'];
        $roll = mt_rand(1, max(1, (int)($total * 1000))) / 1000;
        $acc = 0.0;
        foreach ($junkItems as $j) {
            $acc += (float)$j['rarity_weight'];
            if ($roll <= $acc) return $j;
        }
        return $junkItems[0];
    }

    /**
     * Report a line break: small XP by water type, once per cast, and only
     * when the cast really had a bite. The bait goes with the fish.
     */
    public static function reportLineBreak(int $playerId): array {
        $pdo = db();

        $cast = self::openCast($playerId);
        if ($cast) {
            self::closeCast($cast, 'broken');
        }
        $cast = self::lastCast($playerId);

        $hadBite = $cast && $cast['status'] === 'broken' && $cast['first_bite_at']
            && self::utcTs($cast['first_bite_at']) <= time()
            && time() - self::utcTs($cast['ended_at']) < 120;
        if (!$hadBite) {
            return ['xp_awarded' => 0, 'bait_lost' => false, 'message' => 'Line snapped!'];
        }

        $xp = 0;
        $stmt = $pdo->prepare('UPDATE fishing_casts SET break_xp_given = 1 WHERE id = :id AND break_xp_given = 0');
        $stmt->execute([':id' => $cast['id']]);
        if ($stmt->rowCount() === 1) {
            $stmt = $pdo->prepare('SELECT water_type_id FROM fishing_spots WHERE id = :sid');
            $stmt->execute([':sid' => $cast['spot_id']]);
            $wtId = (int)($stmt->fetchColumn() ?: 1);
            // Small XP: pond=2, river=3, lake=5, ocean=8
            $xpByWater = [1 => 2, 2 => 3, 3 => 5, 4 => 8];
            $xp = $xpByWater[$wtId] ?? 2;
            Player::awardXP($playerId, $xp);
        }

        $baitLost = (int)$cast['bait_consumed'] === 1;
        return [
            'xp_awarded' => $xp,
            'bait_lost'  => $baitLost,
            'message'    => 'Line snapped!' . ($xp ? " +{$xp} XP" : ''),
        ];
    }

    /**
     * Sign a catch token so the HUD can't fake catches. It names the cast,
     * the spot and the moment the bite comes (bite_at, unix time).
     */
    private static function generateCatchToken(
        int $playerId, int $fishId, float $weight, int $spotId, int $castId, int $biteAt, int $junkId = 0
    ): string {
        $data = json_encode([
            'pid' => $playerId,
            'fid' => $fishId,
            'w'   => $weight,
            'sid' => $spotId,
            'c'   => $castId,
            'b'   => $biteAt,
            'j'   => $junkId,
            't'   => time(),
        ]);
        $sig = hash_hmac('sha256', $data, HUD_SECRET);
        return base64_encode($data) . '.' . $sig;
    }

    /**
     * Validate and decode a catch token. Tokens from before the cast record
     * (no cast id) are refused.
     */
    private static function validateCatchToken(string $token, int $playerId): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;

        $data = base64_decode($parts[0], true);
        if ($data === false) return null;
        $expected = hash_hmac('sha256', $data, HUD_SECRET);
        if (!hash_equals($expected, $parts[1])) return null;

        $decoded = json_decode($data, true);
        if (!is_array($decoded) || empty($decoded['c'])) return null;
        if ((int)$decoded['pid'] !== $playerId) return null;

        // Token expires after 10 minutes (generous for long fights)
        $age = time() - (int)$decoded['t'];
        if ($age > 600 || $age < -60) return null;

        return [
            'fish_id' => (int)$decoded['fid'],
            'weight'  => (float)$decoded['w'],
            'spot_id' => (int)$decoded['sid'],
            'cast_id' => (int)$decoded['c'],
            'bite_at' => (int)$decoded['b'],
            'junk_id' => (int)($decoded['j'] ?? 0),
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

    // ── Spot XP constants ─────────────────────────────────────────────────────

    private const SPOT_XP_DAILY_CAP = 200;  // max XP a spot can earn per day from all fishers
    private const SPOT_XP_BY_RARITY = [     // rarity_id => XP awarded to spot
        1 => 1,   // Common
        2 => 3,   // Uncommon
        3 => 8,   // Rare
        4 => 20,  // Epic
        5 => 50,  // Legendary
    ];
    // XP required to qualify for the NEXT level (indexed by current level, 1-based).
    // Levels 2, 5, 9 are bottlenecks (+50%) right before a buff-slot unlock.
    private const SPOT_XP_THRESHOLDS = [
        1 =>   100,
        2 =>   375,   // bottleneck: buff slot 3 unlocks at level 3
        3 =>   400,
        4 =>   550,
        5 =>  1050,   // bottleneck: buff slot 4 unlocks at level 6
        6 =>   800,
        7 =>   900,
        8 =>  1000,
        9 =>  1950,   // bottleneck: buff slot 5 unlocks at level 10
        10 => 1500,
        11 => 1750,
        12 => 2000,
        13 => 2250,
        14 => 2500,
    ];
    // Levels 15+ each cost 250 more than the previous.
    public static function xpThresholdForNextLevel(int $level): int {
        if (isset(self::SPOT_XP_THRESHOLDS[$level])) {
            return self::SPOT_XP_THRESHOLDS[$level];
        }
        // Level 14 = 2500; each subsequent level adds 250
        return 2500 + ($level - 14) * 250;
    }

    // loot_modifier = 1.0 + (level-1) * 0.05, capped at 2.50
    public static function lootModifierForLevel(int $level): float {
        return min(2.50, round(1.0 + ($level - 1) * 0.05, 2));
    }

    /**
     * Award XP to a fishing spot after a successful catch.
     * Applies per-fisher diminishing returns and a per-spot daily cap.
     * Skips system spots.
     * Returns ['xp_awarded' => int, 'level_ready' => bool].
     */
    private static function awardSpotXp(
        \PDO $pdo, int $spotId, int $playerId, int $rarityId, ?array $spotMeta
    ): array {
        $noOp = ['xp_awarded' => 0, 'level_ready' => false];

        if (!$spotMeta || (int)$spotMeta['is_system'] === 1) {
            return $noOp;
        }

        $baseXp = self::SPOT_XP_BY_RARITY[$rarityId] ?? 1;
        $today  = gmdate('Y-m-d');

        // Fetch current totals for this fisher today and for the spot today
        $stmt = $pdo->prepare('
            SELECT fish_count, xp_given FROM spot_xp_daily
            WHERE spot_id = :sid AND player_id = :pid AND log_date = :d
        ');
        $stmt->execute([':sid' => $spotId, ':pid' => $playerId, ':d' => $today]);
        $fisherRow = $stmt->fetch();
        $fisherCount = $fisherRow ? (int)$fisherRow['fish_count'] : 0;

        $stmt = $pdo->prepare('
            SELECT COALESCE(SUM(xp_given), 0) FROM spot_xp_daily
            WHERE spot_id = :sid AND log_date = :d
        ');
        $stmt->execute([':sid' => $spotId, ':d' => $today]);
        $spotTodayXp = (int)$stmt->fetchColumn();

        if ($spotTodayXp >= self::SPOT_XP_DAILY_CAP) {
            return $noOp;  // Daily cap already hit
        }

        // Diminishing returns by fish count this fisher has contributed today
        if ($fisherCount >= 40) {
            return $noOp;  // 41+ fish: 0% contribution
        }
        $multiplier = $fisherCount >= 20 ? 0.5 : 1.0;  // 21-40: 50%

        $xpToAward = (int)max(0, floor($baseXp * $multiplier));
        if ($xpToAward <= 0) {
            return $noOp;
        }

        // Clamp to remaining daily cap
        $xpToAward = min($xpToAward, self::SPOT_XP_DAILY_CAP - $spotTodayXp);
        if ($xpToAward <= 0) {
            return $noOp;
        }

        // Upsert fisher's daily row
        $pdo->prepare('
            INSERT INTO spot_xp_daily (spot_id, player_id, log_date, fish_count, xp_given)
            VALUES (:sid, :pid, :d, 1, :xp)
            ON DUPLICATE KEY UPDATE
                fish_count = fish_count + 1,
                xp_given   = xp_given + :xp2
        ')->execute([':sid' => $spotId, ':pid' => $playerId, ':d' => $today, ':xp' => $xpToAward, ':xp2' => $xpToAward]);

        // Increment spot XP
        $pdo->prepare('UPDATE fishing_spots SET spot_xp = spot_xp + :xp WHERE id = :sid')
            ->execute([':xp' => $xpToAward, ':sid' => $spotId]);

        // Check if spot now qualifies for a level-up
        $stmt = $pdo->prepare('SELECT spot_xp, spot_level, spot_level_ready FROM fishing_spots WHERE id = :sid');
        $stmt->execute([':sid' => $spotId]);
        $updated = $stmt->fetch();
        $levelReady = (bool)$updated['spot_level_ready'];

        if (!$levelReady) {
            $threshold = self::xpThresholdForNextLevel((int)$updated['spot_level']);
            if ((int)$updated['spot_xp'] >= $threshold) {
                $pdo->prepare('UPDATE fishing_spots SET spot_level_ready = 1 WHERE id = :sid')
                    ->execute([':sid' => $spotId]);
                $levelReady = true;
            }
        }

        return ['xp_awarded' => $xpToAward, 'level_ready' => $levelReady];
    }
}
