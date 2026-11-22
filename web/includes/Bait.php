<?php
/**
 * Fishing Game - Bait Manager
 * Handles bait gathering, equipping, and inventory queries.
 */

require_once __DIR__ . '/bootstrap.php';

class Bait {

    /**
     * Get all bait owned by a player with type details.
     */
    public static function getInventory(int $playerId): array {
        $stmt = db()->prepare('
            SELECT bt.*, pb.quantity
            FROM player_bait pb
            JOIN bait_types bt ON bt.id = pb.bait_id
            WHERE pb.player_id = :pid AND pb.quantity > 0
            ORDER BY bt.name
        ');
        $stmt->execute([':pid' => $playerId]);
        return $stmt->fetchAll();
    }

    /**
     * Equip a bait type (must own at least 1).
     */
    public static function equip(int $playerId, int $baitId): array {
        $pdo = db();

        // Verify ownership
        $stmt = $pdo->prepare('
            SELECT pb.quantity, bt.name FROM player_bait pb
            JOIN bait_types bt ON bt.id = pb.bait_id
            WHERE pb.player_id = :pid AND pb.bait_id = :bid
        ');
        $stmt->execute([':pid' => $playerId, ':bid' => $baitId]);
        $row = $stmt->fetch();

        if (!$row || (int)$row['quantity'] <= 0) {
            json_error('You do not have that bait');
        }

        $stmt = $pdo->prepare('UPDATE players SET equipped_bait_id = :bid WHERE id = :pid');
        $stmt->execute([':bid' => $baitId, ':pid' => $playerId]);

        return [
            'equipped_bait_id' => $baitId,
            'bait_name'        => $row['name'],
            'quantity'         => (int)$row['quantity'],
        ];
    }

    /**
     * Cut a caught fish into bait at the bait prep table.
     * Consumes a fish from player inventory, produces cut bait.
     */
    public static function cutFishIntoBait(int $playerId, int $playerFishId): array {
        $pdo = db();

        // Get the fish
        $stmt = $pdo->prepare('
            SELECT pf.*, fs.name as species_name, fs.min_weight, fs.max_weight
            FROM player_fish pf
            JOIN fish_species fs ON fs.id = pf.fish_species_id
            WHERE pf.id = :id AND pf.player_id = :pid AND pf.status = \'held\'
        ');
        $stmt->execute([':id' => $playerFishId, ':pid' => $playerId]);
        $fish = $stmt->fetch();

        if (!$fish) {
            json_error('Fish not found in your inventory');
        }

        // Calculate bait yield based on fish weight
        $weight = (float)$fish['weight'];
        $yield = max(2, (int)floor($weight * 1.5));

        // Remove fish from inventory
        $stmt = $pdo->prepare('
            UPDATE player_fish SET status = \'sold\', sold_at = UTC_TIMESTAMP()
            WHERE id = :id
        ');
        $stmt->execute([':id' => $playerFishId]);

        // Add cut bait (id=5)
        $stmt = $pdo->prepare('
            INSERT INTO player_bait (player_id, bait_id, quantity)
            VALUES (:pid, 5, :qty)
            ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + :qty2, :max)
        ');
        $stmt->execute([
            ':pid' => $playerId,
            ':qty' => $yield,
            ':qty2'=> $yield,
            ':max' => MAX_BAIT_STACK,
        ]);

        return [
            'fish_used'  => $fish['species_name'],
            'bait_gained'=> $yield,
            'message'    => "Cut up {$fish['species_name']} into {$yield} pieces of Cut Bait.",
        ];
    }

    /**
     * Get all bait types (for shop display, info pages).
     */
    public static function getAllTypes(): array {
        $stmt = db()->prepare('SELECT * FROM bait_types WHERE is_active = 1 ORDER BY id');
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
