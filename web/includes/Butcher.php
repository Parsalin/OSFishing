<?php
/**
 * Butcher.php - Fish butchering system
 *
 * Sit at a butchering table, select a fish from inventory,
 * chop it into Fish Chunks (based on weight).
 * Small chance to find a special bait inside based on rarity + water type.
 *
 * Yield: floor(weight / 2) + 1 chunks
 *
 * Special bait drop rates by rarity:
 *   Common=1%, Uncommon=3%, Rare=8%, Epic=15%, Legendary=25%
 *
 * Special bait type matches the water type where the fish was caught.
 */

class Butcher
{
    // Special bait name per water type id
    private static array $specialBaits = [
        1 => 'Shimmering Minnow',  // pond
        2 => 'River Pearl',         // river
        3 => 'Deep Lake Grub',      // lake
        4 => 'Abyssal Eye',         // ocean
    ];

    // Drop chance by rarity tier id
    private static array $dropChance = [
        1 => 1,    // common
        2 => 3,    // uncommon
        3 => 8,    // rare
        4 => 15,   // epic
        5 => 25,   // legendary
    ];

    /**
     * Get player's held fish available for butchering.
     */
    public static function listFish(int $playerId): array {
        $stmt = db()->prepare('
            SELECT pf.id AS player_fish_id, pf.weight, pf.caught_at,
                   fs.name AS fish_name, rt.name AS rarity_name, rt.id AS rarity_id,
                   fsp.water_type_id
            FROM player_fish pf
            JOIN fish_species fs ON fs.id = pf.fish_species_id
            JOIN rarity_tiers rt ON rt.id = pf.rarity_id
            LEFT JOIN fishing_spots fsp ON fsp.id = pf.spot_id
            WHERE pf.player_id = :pid AND pf.status = \'held\'
            ORDER BY pf.weight DESC
        ');
        $stmt->execute([':pid' => $playerId]);
        return ['fish' => $stmt->fetchAll()];
    }

    /**
     * Butcher a fish.
     * Returns chunks gained + any special bait found.
     */
    public static function butcher(int $playerId, int $playerFishId): array {
        $pdo = db();

        // Get the fish
        $stmt = $pdo->prepare('
            SELECT pf.*, fs.name AS fish_name, rt.name AS rarity_name,
                   fsp.water_type_id
            FROM player_fish pf
            JOIN fish_species fs ON fs.id = pf.fish_species_id
            JOIN rarity_tiers rt ON rt.id = pf.rarity_id
            LEFT JOIN fishing_spots fsp ON fsp.id = pf.spot_id
            WHERE pf.id = :id AND pf.player_id = :pid AND pf.status = \'held\'
        ');
        $stmt->execute([':id' => $playerFishId, ':pid' => $playerId]);
        $fish = $stmt->fetch();

        if (!$fish) json_error('Fish not found or not in your inventory');

        $weight = (float)$fish['weight'];
        $rarityId = (int)$fish['rarity_id'];
        $waterTypeId = $fish['water_type_id'] ? (int)$fish['water_type_id'] : null;

        // Calculate chunks
        $chunks = (int)floor($weight / 2) + 1;
        if ($chunks < 1) $chunks = 1;

        // Get Fish Chunks bait id
        $stmt = $pdo->prepare('SELECT id FROM bait_types WHERE name = :n');
        $stmt->execute([':n' => 'Fish Chunks']);
        $chunksBaitId = (int)$stmt->fetchColumn();
        if (!$chunksBaitId) json_error('Fish Chunks bait type not found — run migration');

        // Grant chunks
        $pdo->prepare('
            INSERT INTO player_bait (player_id, bait_id, quantity)
            VALUES (:pid, :bid, :qty)
            ON DUPLICATE KEY UPDATE quantity = quantity + :qty2
        ')->execute([':pid' => $playerId, ':bid' => $chunksBaitId, ':qty' => $chunks, ':qty2' => $chunks]);

        // Roll for special bait
        $specialFound = null;
        $dropPct = self::$dropChance[$rarityId] ?? 1;
        $roll = mt_rand(1, 100);

        if ($roll <= $dropPct) {
            // Determine which special bait based on water type
            $specialName = null;
            if ($waterTypeId && isset(self::$specialBaits[$waterTypeId])) {
                $specialName = self::$specialBaits[$waterTypeId];
            } else {
                // No water type — pick random
                $keys = array_keys(self::$specialBaits);
                $specialName = self::$specialBaits[$keys[array_rand($keys)]];
            }

            // Get bait id
            $stmt = $pdo->prepare('SELECT id FROM bait_types WHERE name = :n');
            $stmt->execute([':n' => $specialName]);
            $specialBaitId = $stmt->fetchColumn();

            if ($specialBaitId) {
                $pdo->prepare('
                    INSERT INTO player_bait (player_id, bait_id, quantity)
                    VALUES (:pid, :bid, 1)
                    ON DUPLICATE KEY UPDATE quantity = quantity + 1
                ')->execute([':pid' => $playerId, ':bid' => (int)$specialBaitId]);

                $specialFound = $specialName;
            }
        }

        // Remove the fish from inventory (mark as butchered)
        $pdo->prepare('UPDATE player_fish SET status = \'sold\' WHERE id = :id')
            ->execute([':id' => $playerFishId]);

        // Build result
        $message = "Butchered " . $fish['fish_name'] . " (" . round($weight, 2) . " lb) into " . $chunks . " Fish Chunks!";
        if ($specialFound) {
            $message .= " Found a " . $specialFound . " inside!";
        }

        return [
            'fish_name'     => $fish['fish_name'],
            'weight'        => round($weight, 2),
            'rarity'        => $fish['rarity_name'],
            'chunks'        => $chunks,
            'special_bait'  => $specialFound,
            'message'       => $message,
        ];
    }
}
