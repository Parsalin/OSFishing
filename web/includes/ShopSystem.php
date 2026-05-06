<?php
/**
 * ShopSystem.php - Physical bait shop economy
 *
 * System shops: auto-restock 10/hr up to max, points disappear on buy
 * Player shops: no auto-restock, owner gets points, stock from sell-backs only
 * Price modifier: 0.9 (discount) to 1.1 (markup) per listing
 * Sell price: floor(base_price * modifier * 0.5)
 * Web portal: 20 bait purchases per day per player
 */

class ShopSystem
{
    const RESTOCK_RATE = 10;       // Units per hour for system shops
    const WEB_DAILY_LIMIT = 20;   // Web purchases per day

    // ═══════════════════════════════════════
    // SHOP REGISTRATION
    // ═══════════════════════════════════════

    public static function registerShop(array $data): array {
        $pdo = db();
        $name     = trim($data['name'] ?? '');
        $region   = trim($data['region'] ?? '');
        $gridName = trim($data['grid_name'] ?? '');
        $posX     = (float)($data['pos_x'] ?? 0);
        $posY     = (float)($data['pos_y'] ?? 0);
        $posZ     = (float)($data['pos_z'] ?? 0);
        $ownerKey = trim($data['owner_key'] ?? '');
        $isSystem = (int)($data['is_system'] ?? 0);
        $regionX  = isset($data['region_x']) ? (int)$data['region_x'] : null;
        $regionY  = isset($data['region_y']) ? (int)$data['region_y'] : null;

        if ($name === '') json_error('Shop name required');

        $playerId = null;
        if ($ownerKey !== '') {
            $stmt = $pdo->prepare('SELECT id, is_admin FROM players WHERE uuid = :u');
            $stmt->execute([':u' => $ownerKey]);
            $player = $stmt->fetch();
            if ($player) {
                $playerId = (int)$player['id'];
                if ($isSystem && !(int)$player['is_admin']) $isSystem = 0;
            }
        }

        // Check existing at position
        $stmt = $pdo->prepare('
            SELECT id FROM shops
            WHERE region_name = :r AND ABS(pos_x - :x) < 5 AND ABS(pos_y - :y) < 5
            LIMIT 1
        ');
        $stmt->execute([':r' => $region, ':x' => $posX, ':y' => $posY]);
        $existing = $stmt->fetch();

        if ($existing) {
            $pdo->prepare('
                UPDATE shops SET name = :n, grid_name = :gn, region_x = :rx, region_y = :ry,
                    pos_x = :x, pos_y = :y, pos_z = :z, is_system = :sys, is_active = 1
                WHERE id = :id
            ')->execute([':n' => $name, ':gn' => $gridName, ':rx' => $regionX, ':ry' => $regionY,
                         ':x' => $posX, ':y' => $posY, ':z' => $posZ, ':sys' => $isSystem, ':id' => $existing['id']]);
            return ['shop_id' => (int)$existing['id'], 'message' => 'Shop updated'];
        }

        $pdo->prepare('
            INSERT INTO shops (player_id, name, region_name, grid_name, region_x, region_y,
                               pos_x, pos_y, pos_z, is_system, is_active)
            VALUES (:pid, :n, :r, :gn, :rx, :ry, :x, :y, :z, :sys, 1)
        ')->execute([':pid' => $isSystem ? null : $playerId, ':n' => $name, ':r' => $region,
                     ':gn' => $gridName, ':rx' => $regionX, ':ry' => $regionY,
                     ':x' => $posX, ':y' => $posY, ':z' => $posZ, ':sys' => $isSystem]);

        return ['shop_id' => (int)$pdo->lastInsertId(), 'message' => 'Shop registered'];
    }

    // ═══════════════════════════════════════
    // LISTING MANAGEMENT
    // ═══════════════════════════════════════

    public static function registerListing(array $data): array {
        $pdo = db();
        $shopId   = (int)($data['shop_id'] ?? 0);
        $itemType = trim($data['item_type'] ?? 'bait');
        $itemId   = (int)($data['item_id'] ?? 0);
        $maxStock = (int)($data['max_stock'] ?? 25);

        if ($shopId <= 0 || $itemId <= 0) json_error('Invalid shop or item ID');

        // Verify shop exists
        $stmt = $pdo->prepare('SELECT id, is_system FROM shops WHERE id = :id');
        $stmt->execute([':id' => $shopId]);
        $shop = $stmt->fetch();
        if (!$shop) json_error('Shop not found');

        // Get item name and base price
        $itemName = ''; $basePrice = 0;
        if ($itemType === 'bait') {
            $stmt = $pdo->prepare('SELECT name, shop_price FROM bait_types WHERE id = :id');
            $stmt->execute([':id' => $itemId]);
            $item = $stmt->fetch();
            if (!$item) json_error('Bait type not found');
            $itemName = $item['name'];
            $basePrice = (int)$item['shop_price'];
        }

        // Upsert listing
        $stmt = $pdo->prepare('
            INSERT INTO shop_listings (shop_id, item_type, item_id, stock, max_stock, last_restock)
            VALUES (:sid, :it, :iid, :stock, :max, NOW())
            ON DUPLICATE KEY UPDATE max_stock = :max2, is_active = 1, last_restock = NOW()
        ');
        $stmt->execute([':sid' => $shopId, ':it' => $itemType, ':iid' => $itemId,
                        ':stock' => $maxStock, ':max' => $maxStock, ':max2' => $maxStock]);

        $listingId = $pdo->lastInsertId();
        if (!$listingId) {
            $stmt = $pdo->prepare('SELECT id FROM shop_listings WHERE shop_id = :sid AND item_type = :it AND item_id = :iid');
            $stmt->execute([':sid' => $shopId, ':it' => $itemType, ':iid' => $itemId]);
            $listingId = $stmt->fetchColumn();
        }

        return ['listing_id' => (int)$listingId, 'item_name' => $itemName, 'base_price' => $basePrice, 'message' => "Listing registered: $itemName"];
    }

    public static function getListingStatus(int $listingId): array {
        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT sl.*, s.is_system, s.player_id AS shop_owner_id,
                   bt.name AS item_name, bt.shop_price AS base_price
            FROM shop_listings sl
            JOIN shops s ON s.id = sl.shop_id
            LEFT JOIN bait_types bt ON bt.id = sl.item_id AND sl.item_type = \'bait\'
            WHERE sl.id = :id
        ');
        $stmt->execute([':id' => $listingId]);
        $listing = $stmt->fetch();
        if (!$listing) json_error('Listing not found');

        // Auto-restock for system shops
        if ((int)$listing['is_system'] && (int)$listing['stock'] < (int)$listing['max_stock']) {
            $listing = self::processRestock($listing);
        }

        $basePrice = (int)$listing['base_price'];
        $modifier = (float)$listing['price_modifier'];
        $buyPrice = max(1, (int)round($basePrice * $modifier));
        $sellPrice = max(1, (int)floor($basePrice * $modifier * 0.5));

        return [
            'listing_id' => (int)$listing['id'],
            'item_name'  => $listing['item_name'],
            'item_type'  => $listing['item_type'],
            'item_id'    => (int)$listing['item_id'],
            'stock'      => (int)$listing['stock'],
            'max_stock'  => (int)$listing['max_stock'],
            'buy_price'  => $buyPrice,
            'sell_price' => $sellPrice,
            'modifier'   => $modifier,
            'is_system'  => (int)$listing['is_system'],
        ];
    }

    private static function processRestock(array $listing): array {
        $pdo = db();
        $lastRestock = $listing['last_restock'] ? strtotime($listing['last_restock']) : time();
        $elapsed = time() - $lastRestock;
        $hoursElapsed = $elapsed / 3600.0;
        $restock = (int)floor($hoursElapsed * self::RESTOCK_RATE);

        if ($restock > 0) {
            $newStock = min((int)$listing['stock'] + $restock, (int)$listing['max_stock']);
            $pdo->prepare('UPDATE shop_listings SET stock = :s, last_restock = NOW() WHERE id = :id')
                ->execute([':s' => $newStock, ':id' => $listing['id']]);
            $listing['stock'] = $newStock;
            $listing['last_restock'] = date('Y-m-d H:i:s');
        }

        return $listing;
    }

    // ═══════════════════════════════════════
    // BUY / SELL
    // ═══════════════════════════════════════

    public static function buy(int $playerId, int $listingId, int $quantity = 1): array {
        $pdo = db();

        $status = self::getListingStatus($listingId);
        if ($status['stock'] < $quantity) json_error('Not enough stock. Only ' . $status['stock'] . ' available.');

        $totalCost = $status['buy_price'] * $quantity;

        // Check player points
        $stmt = $pdo->prepare('SELECT fishing_points FROM players WHERE id = :id');
        $stmt->execute([':id' => $playerId]);
        $player = $stmt->fetch();
        if ((int)$player['fishing_points'] < $totalCost) {
            json_error("Need {$totalCost} points. You have {$player['fishing_points']}.");
        }

        // Deduct points
        $pdo->prepare('UPDATE players SET fishing_points = fishing_points - :p WHERE id = :id')
            ->execute([':p' => $totalCost, ':id' => $playerId]);

        // If player-owned shop, give points to owner
        $stmt = $pdo->prepare('SELECT s.player_id, s.is_system FROM shops s JOIN shop_listings sl ON sl.shop_id = s.id WHERE sl.id = :id');
        $stmt->execute([':id' => $listingId]);
        $shopInfo = $stmt->fetch();
        if ($shopInfo && !(int)$shopInfo['is_system'] && $shopInfo['player_id']) {
            $pdo->prepare('UPDATE players SET fishing_points = fishing_points + :p WHERE id = :id')
                ->execute([':p' => $totalCost, ':id' => $shopInfo['player_id']]);
        }

        // Reduce stock
        $pdo->prepare('UPDATE shop_listings SET stock = stock - :q WHERE id = :id')
            ->execute([':q' => $quantity, ':id' => $listingId]);

        // Grant bait
        if ($status['item_type'] === 'bait') {
            $pdo->prepare('
                INSERT INTO player_bait (player_id, bait_id, quantity)
                VALUES (:pid, :bid, :qty)
                ON DUPLICATE KEY UPDATE quantity = quantity + :qty2
            ')->execute([':pid' => $playerId, ':bid' => $status['item_id'],
                         ':qty' => $quantity, ':qty2' => $quantity]);
        }

        // Log transaction
        $pdo->prepare('
            INSERT INTO shop_transactions (shop_id, listing_id, player_id, action, item_type, item_name, quantity, points_amount)
            VALUES (:sid, :lid, :pid, \'buy\', :it, :in, :qty, :pts)
        ')->execute([':sid' => $shopInfo ? (int)$shopInfo['player_id'] : 0,
                     ':lid' => $listingId, ':pid' => $playerId,
                     ':it' => $status['item_type'], ':in' => $status['item_name'],
                     ':qty' => $quantity, ':pts' => $totalCost]);

        // Fix shop_id in transaction
        $pdo->prepare('
            UPDATE shop_transactions SET shop_id = (SELECT shop_id FROM shop_listings WHERE id = :lid) WHERE id = LAST_INSERT_ID()
        ')->execute([':lid' => $listingId]);

        return [
            'message' => "Bought {$quantity}x {$status['item_name']} for {$totalCost} pts!",
            'item_name' => $status['item_name'],
            'quantity' => $quantity,
            'points_spent' => $totalCost,
            'stock_remaining' => $status['stock'] - $quantity,
        ];
    }

    public static function sell(int $playerId, int $listingId, int $quantity = 1): array {
        $pdo = db();

        $status = self::getListingStatus($listingId);
        $totalEarned = $status['sell_price'] * $quantity;

        // Check player has the bait
        if ($status['item_type'] === 'bait') {
            $stmt = $pdo->prepare('SELECT quantity FROM player_bait WHERE player_id = :pid AND bait_id = :bid');
            $stmt->execute([':pid' => $playerId, ':bid' => $status['item_id']]);
            $held = $stmt->fetch();
            if (!$held || (int)$held['quantity'] < $quantity) {
                json_error("You don't have enough to sell.");
            }

            // Remove bait from player
            $pdo->prepare('UPDATE player_bait SET quantity = quantity - :q WHERE player_id = :pid AND bait_id = :bid')
                ->execute([':q' => $quantity, ':pid' => $playerId, ':bid' => $status['item_id']]);
        }

        // Give points to player
        $pdo->prepare('UPDATE players SET fishing_points = fishing_points + :p WHERE id = :id')
            ->execute([':p' => $totalEarned, ':id' => $playerId]);

        // Add stock to listing
        $pdo->prepare('UPDATE shop_listings SET stock = stock + :q WHERE id = :id')
            ->execute([':q' => $quantity, ':id' => $listingId]);

        // Log transaction
        $stmt = $pdo->prepare('SELECT shop_id FROM shop_listings WHERE id = :id');
        $stmt->execute([':id' => $listingId]);
        $shopId = $stmt->fetchColumn();

        $pdo->prepare('
            INSERT INTO shop_transactions (shop_id, listing_id, player_id, action, item_type, item_name, quantity, points_amount)
            VALUES (:sid, :lid, :pid, \'sell\', :it, :in, :qty, :pts)
        ')->execute([':sid' => $shopId, ':lid' => $listingId, ':pid' => $playerId,
                     ':it' => $status['item_type'], ':in' => $status['item_name'],
                     ':qty' => $quantity, ':pts' => $totalEarned]);

        return [
            'message' => "Sold {$quantity}x {$status['item_name']} for {$totalEarned} pts!",
            'item_name' => $status['item_name'],
            'quantity' => $quantity,
            'points_earned' => $totalEarned,
        ];
    }

    // ═══════════════════════════════════════
    // TRANSACTIONS
    // ═══════════════════════════════════════

    public static function playerTransactions(int $playerId, int $limit = 25): array {
        $stmt = db()->prepare('
            SELECT st.*, s.name AS shop_name
            FROM shop_transactions st
            JOIN shops s ON s.id = st.shop_id
            WHERE st.player_id = :pid
            ORDER BY st.created_at DESC
            LIMIT ' . (int)$limit . '
        ');
        $stmt->execute([':pid' => $playerId]);
        return ['transactions' => $stmt->fetchAll()];
    }

    public static function shopTransactions(int $shopId, int $limit = 25): array {
        $stmt = db()->prepare('
            SELECT st.*, p.display_name AS player_name
            FROM shop_transactions st
            JOIN players p ON p.id = st.player_id
            WHERE st.shop_id = :sid
            ORDER BY st.created_at DESC
            LIMIT ' . (int)$limit . '
        ');
        $stmt->execute([':sid' => $shopId]);
        return ['transactions' => $stmt->fetchAll()];
    }

    // ═══════════════════════════════════════
    // WEB DAILY LIMIT
    // ═══════════════════════════════════════

    public static function checkWebDailyLimit(int $playerId): array {
        $pdo = db();
        $today = date('Y-m-d');

        $stmt = $pdo->prepare('SELECT web_purchases FROM daily_purchase_limits WHERE player_id = :pid AND purchase_date = :d');
        $stmt->execute([':pid' => $playerId, ':d' => $today]);
        $row = $stmt->fetch();
        $used = $row ? (int)$row['web_purchases'] : 0;

        return ['used' => $used, 'limit' => self::WEB_DAILY_LIMIT, 'remaining' => max(0, self::WEB_DAILY_LIMIT - $used)];
    }

    public static function incrementWebPurchase(int $playerId): void {
        $pdo = db();
        $today = date('Y-m-d');
        $pdo->prepare('
            INSERT INTO daily_purchase_limits (player_id, purchase_date, web_purchases)
            VALUES (:pid, :d, 1)
            ON DUPLICATE KEY UPDATE web_purchases = web_purchases + 1
        ')->execute([':pid' => $playerId, ':d' => $today]);
    }

    public static function getShopsInRegion(string $region, string $gridName = ''): array {
        $pdo = db();
        $where = 's.is_active = 1 AND s.region_name = :r';
        $params = [':r' => $region];
        if ($gridName !== '') { $where .= ' AND s.grid_name = :gn'; $params[':gn'] = $gridName; }
        $stmt = $pdo->prepare("SELECT s.id, s.name, s.is_system FROM shops s WHERE $where ORDER BY s.name");
        $stmt->execute($params);
        return ['shops' => $stmt->fetchAll()];
    }

    public static function deposit(string $ownerUuid, int $listingId, int $qty): array {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, is_admin FROM players WHERE uuid = :u');
        $stmt->execute([':u' => $ownerUuid]);
        $player = $stmt->fetch();
        if (!$player) json_error('Player not found');

        $stmt = $pdo->prepare('SELECT sl.*, s.player_id FROM shop_listings sl JOIN shops s ON s.id = sl.shop_id WHERE sl.id = :id');
        $stmt->execute([':id' => $listingId]);
        $listing = $stmt->fetch();
        if (!$listing) json_error('Listing not found');
        if ((int)$listing['player_id'] !== (int)$player['id'] && !(int)$player['is_admin']) json_error('Not authorized');

        $pdo->prepare('UPDATE shop_listings SET stock = LEAST(stock + :q, max_stock) WHERE id = :id')
            ->execute([':q' => $qty, ':id' => $listingId]);
        return ['message' => "Added {$qty} to stock"];
    }

    public static function withdraw(string $ownerUuid, int $listingId, int $qty): array {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, is_admin FROM players WHERE uuid = :u');
        $stmt->execute([':u' => $ownerUuid]);
        $player = $stmt->fetch();
        if (!$player) json_error('Player not found');

        $stmt = $pdo->prepare('SELECT sl.*, s.player_id FROM shop_listings sl JOIN shops s ON s.id = sl.shop_id WHERE sl.id = :id');
        $stmt->execute([':id' => $listingId]);
        $listing = $stmt->fetch();
        if (!$listing) json_error('Listing not found');
        if ((int)$listing['player_id'] !== (int)$player['id'] && !(int)$player['is_admin']) json_error('Not authorized');
        if ((int)$listing['stock'] < $qty) json_error('Not enough stock to withdraw');

        $pdo->prepare('UPDATE shop_listings SET stock = stock - :q WHERE id = :id')
            ->execute([':q' => $qty, ':id' => $listingId]);
        return ['message' => "Removed {$qty} from stock"];
    }

    public static function adminRestock(string $adminUuid, int $listingId): array {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, is_admin FROM players WHERE uuid = :u');
        $stmt->execute([':u' => $adminUuid]);
        $player = $stmt->fetch();
        if (!$player || !(int)$player['is_admin']) json_error('Admin only');

        $pdo->prepare('UPDATE shop_listings SET stock = max_stock, last_restock = NOW() WHERE id = :id')
            ->execute([':id' => $listingId]);
        return ['message' => 'Restocked to maximum'];
    }

    // ═══════════════════════════════════════
    // GRID MAP DATA
    // ═══════════════════════════════════════

    public static function getShopsForMap(string $gridName = ''): array {
        $pdo = db();
        $where = 's.is_active = 1';
        $params = [];
        if ($gridName !== '') {
            $where .= ' AND s.grid_name = :gn';
            $params[':gn'] = $gridName;
        }

        $stmt = $pdo->prepare("
            SELECT s.*, p.display_name AS owner_name,
                   (SELECT COUNT(*) FROM shop_listings sl WHERE sl.shop_id = s.id AND sl.is_active = 1) AS listing_count
            FROM shops s
            LEFT JOIN players p ON p.id = s.player_id
            WHERE $where
            ORDER BY s.region_name, s.name
        ");
        $stmt->execute($params);
        return ['shops' => $stmt->fetchAll()];
    }

    // ═══════════════════════════════════════
    // PRICE MODIFIER (owner/admin)
    // ═══════════════════════════════════════

    public static function setModifier(string $ownerUuid, int $listingId, float $modifier): array {
        if ($modifier < 0.9 || $modifier > 1.1) json_error('Modifier must be between 0.9 and 1.1');
        $pdo = db();

        $stmt = $pdo->prepare('SELECT id, is_admin FROM players WHERE uuid = :u');
        $stmt->execute([':u' => $ownerUuid]);
        $player = $stmt->fetch();
        if (!$player) json_error('Player not found');

        $stmt = $pdo->prepare('
            SELECT sl.shop_id, s.player_id FROM shop_listings sl JOIN shops s ON s.id = sl.shop_id WHERE sl.id = :id
        ');
        $stmt->execute([':id' => $listingId]);
        $listing = $stmt->fetch();
        if (!$listing) json_error('Listing not found');

        if ((int)$listing['player_id'] !== (int)$player['id'] && !(int)$player['is_admin'])
            json_error('Not authorized');

        $pdo->prepare('UPDATE shop_listings SET price_modifier = :m WHERE id = :id')
            ->execute([':m' => $modifier, ':id' => $listingId]);

        return ['message' => 'Price modifier set to ' . round($modifier * 100) . '%'];
    }
}
