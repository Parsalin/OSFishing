<?php
/**
 * Trophy.php - Saved fish system
 *
 * Players can save fish from their inventory to their trophy collection.
 * Saved fish have a personal note and can be displayed on a wall plaque in-world.
 * Fish can be returned to inventory from the trophy page.
 */

class Trophy
{
    /**
     * Save a fish to the trophy collection.
     */
    public static function saveFish(int $playerId, int $playerFishId, string $note = ''): array {
        $pdo = db();

        // Verify fish belongs to this player and is held
        $stmt = $pdo->prepare('SELECT * FROM player_fish WHERE id = :id AND player_id = :pid AND status = \'held\'');
        $stmt->execute([':id' => $playerFishId, ':pid' => $playerId]);
        $fish = $stmt->fetch();
        if (!$fish) json_error('Fish not found or not in your inventory');

        // Mark as saved
        $pdo->prepare('UPDATE player_fish SET status = \'saved\' WHERE id = :id')
            ->execute([':id' => $playerFishId]);

        // Create trophy entry
        $pdo->prepare('
            INSERT INTO player_saved_fish (player_id, player_fish_id, note)
            VALUES (:pid, :fid, :note)
            ON DUPLICATE KEY UPDATE note = :note2
        ')->execute([':pid' => $playerId, ':fid' => $playerFishId, ':note' => trim($note), ':note2' => trim($note)]);

        return ['message' => 'Fish saved to trophy collection!'];
    }

    /**
     * Return a saved fish to the inventory.
     */
    public static function returnToInventory(int $playerId, int $savedFishId): array {
        $pdo = db();

        $stmt = $pdo->prepare('SELECT * FROM player_saved_fish WHERE id = :id AND player_id = :pid');
        $stmt->execute([':id' => $savedFishId, ':pid' => $playerId]);
        $saved = $stmt->fetch();
        if (!$saved) json_error('Saved fish not found');

        // Mark fish as held again
        $pdo->prepare('UPDATE player_fish SET status = \'held\' WHERE id = :id')
            ->execute([':id' => $saved['player_fish_id']]);

        // Remove from trophy
        $pdo->prepare('DELETE FROM player_saved_fish WHERE id = :id')
            ->execute([':id' => $savedFishId]);

        return ['message' => 'Fish returned to inventory'];
    }

    /**
     * Update the note on a saved fish.
     */
    public static function updateNote(int $playerId, int $savedFishId, string $note): array {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id FROM player_saved_fish WHERE id = :id AND player_id = :pid');
        $stmt->execute([':id' => $savedFishId, ':pid' => $playerId]);
        if (!$stmt->fetch()) json_error('Saved fish not found');

        $pdo->prepare('UPDATE player_saved_fish SET note = :n WHERE id = :id')
            ->execute([':n' => trim($note), ':id' => $savedFishId]);

        return ['message' => 'Note updated'];
    }

    /**
     * Get all saved fish for a player.
     */
    public static function getSaved(int $playerId): array {
        $stmt = db()->prepare('
            SELECT
                psf.id AS saved_id,
                psf.note,
                psf.saved_at,
                pf.id AS fish_id,
                pf.weight,
                pf.caught_at,
                COALESCE(fspot.region_name, \'Unknown\') AS caught_region,
                fsp.name AS species_name,
                fsp.min_weight, fsp.max_weight,
                rt.name AS rarity_name,
                rt.color_hex AS rarity_color,
                bt.name AS bait_name
            FROM player_saved_fish psf
            JOIN player_fish pf ON pf.id = psf.player_fish_id
            JOIN fish_species fsp ON fsp.id = pf.fish_species_id
            LEFT JOIN fishing_spots fspot ON fspot.id = pf.spot_id
            JOIN rarity_tiers rt ON rt.id = pf.rarity_id
            LEFT JOIN bait_types bt ON bt.id = pf.bait_used_id
            WHERE psf.player_id = :pid
            ORDER BY psf.saved_at DESC
        ');
        $stmt->execute([':pid' => $playerId]);
        return ['saved_fish' => $stmt->fetchAll()];
    }

    /**
     * Get saved fish for in-world plaque — returns only what the plaque needs.
     */
    public static function getForPlaque(int $playerId): array {
        $stmt = db()->prepare('
            SELECT
                psf.id AS saved_id,
                pf.id AS fish_id,
                pf.weight,
                pf.caught_at,
                COALESCE(fspot.region_name, \'Unknown\') AS caught_region,
                fsp.name AS species_name,
                fsp.min_weight, fsp.max_weight,
                rt.name AS rarity_name,
                bt.name AS bait_name,
                psf.note
            FROM player_saved_fish psf
            JOIN player_fish pf ON pf.id = psf.player_fish_id
            JOIN fish_species fsp ON fsp.id = pf.fish_species_id
            LEFT JOIN fishing_spots fspot ON fspot.id = pf.spot_id
            JOIN rarity_tiers rt ON rt.id = pf.rarity_id
            LEFT JOIN bait_types bt ON bt.id = pf.bait_used_id
            WHERE psf.player_id = :pid
            ORDER BY pf.weight DESC
        ');
        $stmt->execute([':pid' => $playerId]);
        return ['saved_fish' => $stmt->fetchAll()];
    }
}
