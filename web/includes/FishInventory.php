<?php
/**
 * Fishing Game - Fish Inventory Manager
 * View, sell, and manage caught fish.
 */

require_once __DIR__ . '/../config.php';

class FishInventory {

    /**
     * Get a player's held fish inventory with full details.
     */
    public static function getHeld(int $playerId, ?string $sortBy = 'caught_at', ?string $order = 'DESC', ?int $limit = 100, ?int $offset = 0): array {
        $allowedSort = ['caught_at', 'weight', 'fish_species_id', 'rarity_id'];
        $sortBy = in_array($sortBy, $allowedSort) ? $sortBy : 'caught_at';
        $order  = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $stmt = db()->prepare("
            SELECT pf.*, fs.name as species_name, fs.base_points, fs.min_weight,
                   rt.name as rarity_name, rt.color_hex, rt.point_multiplier,
                   bt.name as bait_name, fspot.name as spot_name
            FROM player_fish pf
            JOIN fish_species fs ON fs.id = pf.fish_species_id
            JOIN rarity_tiers rt ON rt.id = pf.rarity_id
            LEFT JOIN bait_types bt ON bt.id = pf.bait_used_id
            LEFT JOIN fishing_spots fspot ON fspot.id = pf.spot_id
            WHERE pf.player_id = :pid AND pf.status = 'held'
            ORDER BY pf.{$sortBy} {$order}
            LIMIT :lim OFFSET :off
        ");
        $stmt->bindValue(':pid', $playerId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $fish = $stmt->fetchAll();

        // Calculate point value for each fish
        foreach ($fish as &$f) {
            $f['point_value'] = self::calculatePointValue($f);
        }

        // Get total count
        $stmt = db()->prepare('
            SELECT COUNT(*) FROM player_fish WHERE player_id = :pid AND status = \'held\'
        ');
        $stmt->execute([':pid' => $playerId]);
        $total = (int)$stmt->fetchColumn();

        return [
            'fish'  => $fish,
            'total' => $total,
            'limit' => $limit,
            'offset'=> $offset,
        ];
    }

    /**
     * Get inventory grouped by species (for compact HUD display).
     */
    public static function getGrouped(int $playerId): array {
        $stmt = db()->prepare('
            SELECT fs.id as species_id, fs.name, rt.name as rarity_name, rt.color_hex,
                   COUNT(*) as count,
                   MIN(pf.weight) as min_weight,
                   MAX(pf.weight) as max_weight,
                   AVG(pf.weight) as avg_weight
            FROM player_fish pf
            JOIN fish_species fs ON fs.id = pf.fish_species_id
            JOIN rarity_tiers rt ON rt.id = pf.rarity_id
            WHERE pf.player_id = :pid AND pf.status = \'held\'
            GROUP BY fs.id, fs.name, rt.name, rt.color_hex
            ORDER BY rt.id DESC, fs.name
        ');
        $stmt->execute([':pid' => $playerId]);
        return $stmt->fetchAll();
    }

    /**
     * Sell specific fish for fishing points.
     */
    public static function sellFish(int $playerId, array $fishIds): array {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $totalPoints = 0;
            $fishCount   = 0;
            $details     = [];

            foreach ($fishIds as $fishId) {
                $stmt = $pdo->prepare('
                    SELECT pf.*, fs.name as species_name, fs.base_points, fs.min_weight,
                           rt.point_multiplier
                    FROM player_fish pf
                    JOIN fish_species fs ON fs.id = pf.fish_species_id
                    JOIN rarity_tiers rt ON rt.id = pf.rarity_id
                    WHERE pf.id = :id AND pf.player_id = :pid AND pf.status = \'held\'
                    FOR UPDATE
                ');
                $stmt->execute([':id' => (int)$fishId, ':pid' => $playerId]);
                $fish = $stmt->fetch();

                if (!$fish) continue;

                $points = self::calculatePointValue($fish);

                // Mark as sold
                $stmt = $pdo->prepare('
                    UPDATE player_fish
                    SET status = \'sold\', sold_at = NOW(), points_received = :pts
                    WHERE id = :id
                ');
                $stmt->execute([':pts' => $points, ':id' => $fish['id']]);

                $totalPoints += $points;
                $fishCount++;
                $details[] = ['name' => $fish['species_name'], 'weight' => (float)$fish['weight'], 'points' => $points];
            }

            if ($fishCount === 0) {
                $pdo->rollBack();
                json_error('No valid fish to sell');
            }

            // Credit points
            Player::addPoints($playerId, $totalPoints);

            // Log the transaction
            $stmt = $pdo->prepare('
                INSERT INTO sell_transactions (player_id, fish_count, total_points, details)
                VALUES (:pid, :fc, :tp, :d)
            ');
            $stmt->execute([
                ':pid' => $playerId,
                ':fc'  => $fishCount,
                ':tp'  => $totalPoints,
                ':d'   => json_encode($details),
            ]);

            $pdo->commit();

            return [
                'fish_sold'    => $fishCount,
                'points_earned'=> $totalPoints,
                'details'      => $details,
                'message'      => "Sold {$fishCount} fish for {$totalPoints} Fishing Points!",
            ];

        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Sell all fish of a given rarity tier.
     */
    public static function sellByRarity(int $playerId, int $rarityId): array {
        $stmt = db()->prepare('
            SELECT id FROM player_fish
            WHERE player_id = :pid AND rarity_id = :rid AND status = \'held\'
        ');
        $stmt->execute([':pid' => $playerId, ':rid' => $rarityId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($ids)) {
            json_error('No fish of that rarity to sell');
        }

        return self::sellFish($playerId, $ids);
    }

    /**
     * Sell all fish of a given species.
     */
    public static function sellBySpecies(int $playerId, int $speciesId): array {
        $stmt = db()->prepare('
            SELECT id FROM player_fish
            WHERE player_id = :pid AND fish_species_id = :sid AND status = \'held\'
        ');
        $stmt->execute([':pid' => $playerId, ':sid' => $speciesId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($ids)) {
            json_error('No fish of that species to sell');
        }

        return self::sellFish($playerId, $ids);
    }

    /**
     * Request a physical prim copy of a fish (marks it, returns data for rezzer).
     */
    public static function requestPhysicalCopy(int $playerId, int $playerFishId): array {
        $pdo = db();

        $stmt = $pdo->prepare('
            SELECT pf.*, fs.name as species_name, fs.trophy_prim_uuid, fs.texture_uuid,
                   rt.name as rarity_name, rt.color_hex
            FROM player_fish pf
            JOIN fish_species fs ON fs.id = pf.fish_species_id
            JOIN rarity_tiers rt ON rt.id = pf.rarity_id
            WHERE pf.id = :id AND pf.player_id = :pid AND pf.status = \'held\'
        ');
        $stmt->execute([':id' => $playerFishId, ':pid' => $playerId]);
        $fish = $stmt->fetch();

        if (!$fish) {
            json_error('Fish not found in your inventory');
        }

        // Mark physical copy issued
        $stmt = $pdo->prepare('UPDATE player_fish SET physical_copy = 1 WHERE id = :id');
        $stmt->execute([':id' => $playerFishId]);

        return [
            'species_name'    => $fish['species_name'],
            'weight'          => (float)$fish['weight'],
            'rarity'          => $fish['rarity_name'],
            'rarity_color'    => $fish['color_hex'],
            'trophy_prim_uuid'=> $fish['trophy_prim_uuid'],
            'texture_uuid'    => $fish['texture_uuid'],
            'caught_at'       => $fish['caught_at'],
        ];
    }

    /**
     * Calculate point value for a fish.
     */
    public static function calculatePointValue(array $fish): int {
        $base = (int)$fish['base_points'];
        $weightBonus = ((float)$fish['weight'] - (float)$fish['min_weight']) * WEIGHT_BONUS_MULTIPLIER;
        return max(1, (int)round($base + $weightBonus));
    }

    /**
     * Get the catch log (permanent history).
     */
    public static function getCatchLog(int $playerId, int $limit = 50, int $offset = 0): array {
        $stmt = db()->prepare('
            SELECT cl.*, fs.name as species_name, rt.name as rarity_name, rt.color_hex,
                   bt.name as bait_name, fspot.name as spot_name
            FROM catch_log cl
            JOIN fish_species fs ON fs.id = cl.fish_species_id
            JOIN rarity_tiers rt ON rt.id = cl.rarity_id
            LEFT JOIN bait_types bt ON bt.id = cl.bait_used_id
            LEFT JOIN fishing_spots fspot ON fspot.id = cl.spot_id
            WHERE cl.player_id = :pid
            ORDER BY cl.caught_at DESC
            LIMIT :lim OFFSET :off
        ');
        $stmt->bindValue(':pid', $playerId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get fish collection stats (what species have been caught, completion %).
     */
    public static function getCollection(int $playerId): array {
        $pdo = db();

        // All available species
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM fish_species WHERE is_active = 1');
        $stmt->execute();
        $totalSpecies = (int)$stmt->fetchColumn();

        // Species this player has caught (ever, from catch_log)
        $stmt = $pdo->prepare('
            SELECT DISTINCT cl.fish_species_id, fs.name, rt.name as rarity_name, rt.color_hex,
                   MAX(cl.weight) as best_weight, COUNT(*) as times_caught
            FROM catch_log cl
            JOIN fish_species fs ON fs.id = cl.fish_species_id
            JOIN rarity_tiers rt ON rt.id = fs.rarity_id
            WHERE cl.player_id = :pid
            GROUP BY cl.fish_species_id, fs.name, rt.name, rt.color_hex
            ORDER BY rt.id DESC, fs.name
        ');
        $stmt->execute([':pid' => $playerId]);
        $caught = $stmt->fetchAll();

        return [
            'total_species' => $totalSpecies,
            'caught_species'=> count($caught),
            'completion_pct'=> round((count($caught) / max(1, $totalSpecies)) * 100, 1),
            'species'       => $caught,
        ];
    }
}
