<?php
/**
 * Fishing Game - Shop
 * Purchase bait, rods, and other items with Fishing Points.
 */

require_once __DIR__ . '/../config.php';

class Shop {

    /**
     * Get all items available for purchase.
     */
    public static function getItems(int $playerLevel): array {
        $pdo = db();

        // Bait for sale
        $stmt = $pdo->prepare('
            SELECT id, name, description, shop_price, shop_quantity, \'bait\' as item_type
            FROM bait_types
            WHERE shop_enabled = 1 AND shop_price > 0 AND is_active = 1
            ORDER BY shop_price
        ');
        $stmt->execute();
        $bait = $stmt->fetchAll();

        // Rods for sale (level-gated)
        $stmt = $pdo->prepare('
            SELECT id, name, description, tier, min_level, cost as shop_price,
                   cast_range, reel_speed, tension_buffer, line_strength,
                   \'rod\' as item_type
            FROM rod_types
            WHERE cost > 0 AND is_active = 1
            ORDER BY tier
        ');
        $stmt->execute();
        $rods = $stmt->fetchAll();

        // Mark affordability and level access
        foreach ($rods as &$r) {
            $r['level_ok'] = $playerLevel >= (int)$r['min_level'];
        }

        return [
            'bait' => $bait,
            'rods' => $rods,
        ];
    }

    /**
     * Purchase bait.
     */
    public static function buyBait(int $playerId, int $baitId, int $bundles = 1): array {
        $pdo = db();

        $stmt = $pdo->prepare('
            SELECT * FROM bait_types WHERE id = :id AND shop_price IS NOT NULL AND is_active = 1
        ');
        $stmt->execute([':id' => $baitId]);
        $bait = $stmt->fetch();

        if (!$bait) {
            json_error('Item not available for purchase');
        }

        $bundles = max(1, min(100, $bundles)); // Cap at 100 bundles
        $totalCost = (int)$bait['shop_price'] * $bundles;
        $totalQty  = (int)$bait['shop_quantity'] * $bundles;

        // Deduct points
        if (!Player::deductPoints($playerId, $totalCost)) {
            json_error('Insufficient Fishing Points');
        }

        // Add bait
        $stmt = $pdo->prepare('
            INSERT INTO player_bait (player_id, bait_id, quantity)
            VALUES (:pid, :bid, :qty)
            ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + :qty2, :max)
        ');
        $stmt->execute([
            ':pid' => $playerId,
            ':bid' => $baitId,
            ':qty' => $totalQty,
            ':qty2'=> $totalQty,
            ':max' => MAX_BAIT_STACK,
        ]);

        return [
            'item'      => $bait['name'],
            'quantity'  => $totalQty,
            'cost'      => $totalCost,
            'message'   => "Purchased {$totalQty}x {$bait['name']} for {$totalCost} points.",
        ];
    }

    /**
     * Purchase a rod.
     */
    public static function buyRod(int $playerId, int $rodId, int $playerLevel): array {
        $pdo = db();

        $stmt = $pdo->prepare('SELECT * FROM rod_types WHERE id = :id AND cost > 0 AND is_active = 1');
        $stmt->execute([':id' => $rodId]);
        $rod = $stmt->fetch();

        if (!$rod) {
            json_error('Rod not available for purchase');
        }

        if ($playerLevel < (int)$rod['min_level']) {
            json_error("Requires level {$rod['min_level']}");
        }

        // Check if already owned
        $stmt = $pdo->prepare('
            SELECT id FROM player_rods WHERE player_id = :pid AND rod_id = :rid
        ');
        $stmt->execute([':pid' => $playerId, ':rid' => $rodId]);
        if ($stmt->fetch()) {
            json_error('You already own this rod');
        }

        // Deduct points
        if (!Player::deductPoints($playerId, (int)$rod['cost'])) {
            json_error('Insufficient Fishing Points');
        }

        // Grant rod
        $stmt = $pdo->prepare('INSERT INTO player_rods (player_id, rod_id) VALUES (:pid, :rid)');
        $stmt->execute([':pid' => $playerId, ':rid' => $rodId]);

        return [
            'rod'     => $rod['name'],
            'tier'    => (int)$rod['tier'],
            'cost'    => (int)$rod['cost'],
            'message' => "Purchased {$rod['name']}!",
        ];
    }

    /**
     * Get player's owned rods.
     */
    public static function getOwnedRods(int $playerId): array {
        $stmt = db()->prepare('
            SELECT r.* FROM player_rods pr
            JOIN rod_types r ON r.id = pr.rod_id
            WHERE pr.player_id = :pid
            ORDER BY r.tier
        ');
        $stmt->execute([':pid' => $playerId]);
        return $stmt->fetchAll();
    }
}
