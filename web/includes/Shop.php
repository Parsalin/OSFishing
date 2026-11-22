<?php
/**
 * Fishing Game - Shop
 * Purchase bait, rods, and other items with Fishing Points.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ShopSystem.php';
require_once __DIR__ . '/Player.php';

class Shop {

    /**
     * Get all items available for purchase.
     */
    public static function getItems(int $playerLevel): array {
        $pdo = db();

        // Bait for sale
        $stmt = $pdo->prepare('
            SELECT id, name, description, shop_price, COALESCE(NULLIF(shop_quantity, 0), 10) AS shop_quantity,
                   min_level, \'bait\' as item_type
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
     * Buy bait remotely (portal shop or HUD), in bundles of shop_quantity
     * (10 when unset). Only bait the shop sells (shop_enabled, priced, at
     * or under the player's level). Remote buying is capped per UTC day
     * (ShopSystem::WEB_DAILY_LIMIT units); in-world shops have no cap.
     * One transaction: nothing is charged unless the bait is delivered.
     */
    public static function buyBait(int $playerId, int $baitId, int $bundles = 1): array {
        $pdo = db();

        if ($bundles < 1 || $bundles > 100) json_error('Choose 1 to 100 bundles.');

        $stmt = $pdo->prepare('
            SELECT * FROM bait_types
            WHERE id = :id AND shop_enabled = 1 AND shop_price > 0 AND is_active = 1
        ');
        $stmt->execute([':id' => $baitId]);
        $bait = $stmt->fetch();
        if (!$bait) {
            json_error('Item not available for purchase');
        }

        $stmt = $pdo->prepare('SELECT level FROM players WHERE id = :id');
        $stmt->execute([':id' => $playerId]);
        if ((int)$stmt->fetchColumn() < (int)$bait['min_level']) {
            json_error("Requires level {$bait['min_level']}");
        }

        $perBundle = (int)($bait['shop_quantity'] ?? 0) ?: 10;
        $totalCost = (int)$bait['shop_price'] * $bundles;
        $totalQty  = $perBundle * $bundles;

        $daily = ShopSystem::checkWebDailyLimit($playerId);
        if ($daily['remaining'] < $totalQty) {
            json_error("Daily web limit: {$daily['remaining']} bait remaining today. Visit an in-world shop for more!");
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT quantity FROM player_bait WHERE player_id = :pid AND bait_id = :bid FOR UPDATE');
            $stmt->execute([':pid' => $playerId, ':bid' => $baitId]);
            if ((int)$stmt->fetchColumn() + $totalQty > MAX_BAIT_STACK) {
                $pdo->rollBack();
                json_error('You can carry at most ' . MAX_BAIT_STACK . " {$bait['name']}.");
            }
            if (!Player::deductPoints($playerId, $totalCost)) {
                $pdo->rollBack();
                json_error('Insufficient Fishing Points');
            }
            $pdo->prepare('
                INSERT INTO player_bait (player_id, bait_id, quantity)
                VALUES (:pid, :bid, :qty)
                ON DUPLICATE KEY UPDATE quantity = quantity + :qty2
            ')->execute([':pid' => $playerId, ':bid' => $baitId, ':qty' => $totalQty, ':qty2' => $totalQty]);
            ShopSystem::incrementWebPurchase($playerId, $totalQty);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

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
