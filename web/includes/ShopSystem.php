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

require_once __DIR__ . '/Grid.php';

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
        if ($gridName !== '') Grid::findOrRegister($gridName);   // so identity lookups find this host
        $posX     = (float)($data['pos_x'] ?? 0);
        $posY     = (float)($data['pos_y'] ?? 0);
        $posZ     = (float)($data['pos_z'] ?? 0);
        $ownerKey = trim($data['owner_key'] ?? '');
        $isSystem = (int)($data['is_system'] ?? 0);
        $regionX  = isset($data['region_x']) ? (int)$data['region_x'] : null;
        $regionY  = isset($data['region_y']) ? (int)$data['region_y'] : null;

        if ($name === '') json_error('Shop name required');
        if ($region === '' || $gridName === '') json_error('Region and grid required');

        $player = $ownerKey !== '' ? self::playerByUuid($ownerKey) : null;
        if (!$player) json_error('The shop owner needs an OSFishing account.');
        $playerId = (int)$player['id'];
        $isAdmin  = (int)$player['is_admin'] === 1;
        if ($isSystem && !$isAdmin) $isSystem = 0;

        // Same spot (same region on the same grid, within 5 m): re-register,
        // but only for its owner or an admin.
        $stmt = $pdo->prepare('
            SELECT id, player_id, is_system FROM shops
            WHERE region_name = :r AND ' . Grid::sameGridSql('grid_name', ':gi') . '
              AND ABS(pos_x - :x) < 5 AND ABS(pos_y - :y) < 5
            LIMIT 1
        ');
        $stmt->execute([':r' => $region, ':gi' => Grid::identityOf($gridName), ':x' => $posX, ':y' => $posY]);
        $existing = $stmt->fetch();

        if ($existing) {
            $mine = !(int)$existing['is_system'] && (int)$existing['player_id'] === $playerId;
            if (!$mine && !$isAdmin) json_error('Another shop is already registered here.', 403);
            $pdo->prepare('
                UPDATE shops SET name = :n, grid_name = :gn, region_x = :rx, region_y = :ry,
                    pos_x = :x, pos_y = :y, pos_z = :z, is_system = :sys, is_active = 1
                WHERE id = :id
            ')->execute([':n' => $name, ':gn' => $gridName, ':rx' => $regionX, ':ry' => $regionY,
                         ':x' => $posX, ':y' => $posY, ':z' => $posZ,
                         ':sys' => $isAdmin ? $isSystem : (int)$existing['is_system'], ':id' => $existing['id']]);
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

    /**
     * A vendor adds (or re-adds) a listing to a shop. $ownerKey is the
     * vendor's owner (X-SecondLife-Owner-Key): they must own the shop, and
     * only an admin may list in a system shop. Only bait the shop sells may
     * be listed. A player shop's listing starts empty: its owner deposits
     * real bait. A system shop starts full and restocks itself.
     */
    public static function registerListing(array $data, string $ownerKey): array {
        $pdo = db();
        $shopId   = (int)($data['shop_id'] ?? 0);
        $itemType = trim($data['item_type'] ?? 'bait');
        $itemId   = (int)($data['item_id'] ?? 0);
        $maxStock = max(1, min(100, (int)($data['max_stock'] ?? 25)));

        if ($shopId <= 0 || $itemId <= 0) json_error('Invalid shop or item ID');
        if ($itemType !== 'bait') json_error('Only bait can be listed');

        $stmt = $pdo->prepare('SELECT id, is_system, player_id FROM shops WHERE id = :id AND is_active = 1');
        $stmt->execute([':id' => $shopId]);
        $shop = $stmt->fetch();
        if (!$shop) json_error('Shop not found');

        $owner = $ownerKey !== '' ? self::playerByUuid($ownerKey) : null;
        if (!$owner) json_error('The vendor owner needs an OSFishing account.', 403);
        $isAdmin = (int)$owner['is_admin'] === 1;
        $isSystem = (int)$shop['is_system'] === 1;
        if ($isSystem ? !$isAdmin : ((int)$shop['player_id'] !== (int)$owner['id'] && !$isAdmin)) {
            json_error('Only the shop owner can add vendors to this shop.', 403);
        }

        $stmt = $pdo->prepare('SELECT name, shop_price FROM bait_types WHERE id = :id AND shop_enabled = 1 AND shop_price > 0 AND is_active = 1');
        $stmt->execute([':id' => $itemId]);
        $item = $stmt->fetch();
        if (!$item) json_error('That bait is not sold in shops');
        $itemName = $item['name'];
        $basePrice = (int)$item['shop_price'];

        $stmt = $pdo->prepare('
            INSERT INTO shop_listings (shop_id, item_type, item_id, stock, max_stock, last_restock)
            VALUES (:sid, :it, :iid, :stock, :max, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE max_stock = :max2, stock = LEAST(stock, :max3), is_active = 1
        ');
        $stmt->execute([':sid' => $shopId, ':it' => $itemType, ':iid' => $itemId,
                        ':stock' => $isSystem ? $maxStock : 0,
                        ':max' => $maxStock, ':max2' => $maxStock, ':max3' => $maxStock]);

        $stmt = $pdo->prepare('SELECT id FROM shop_listings WHERE shop_id = :sid AND item_type = :it AND item_id = :iid');
        $stmt->execute([':sid' => $shopId, ':it' => $itemType, ':iid' => $itemId]);
        $listingId = (int)$stmt->fetchColumn();

        return ['listing_id' => $listingId, 'item_name' => $itemName, 'base_price' => $basePrice, 'message' => "Listing registered: $itemName"];
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
            $pdo->prepare('UPDATE shop_listings SET stock = :s, last_restock = UTC_TIMESTAMP() WHERE id = :id')
                ->execute([':s' => $newStock, ':id' => $listing['id']]);
            $listing['stock'] = $newStock;
            $listing['last_restock'] = gmdate('Y-m-d H:i:s');
        }

        return $listing;
    }

    // ═══════════════════════════════════════
    // BUY / SELL / STOCK
    // Every trade runs in one transaction with the listing row locked, and
    // every balance change is guarded (UPDATE … WHERE enough), so nothing
    // can go negative and a failure leaves nothing half done.
    // Player shops hold real bait: the owner deposits it, buyers pay the
    // owner, sell-backs are paid by the owner. Nobody trades with their own shop.
    // ═══════════════════════════════════════

    const MAX_TRADE = 100;   // units per buy / sell / deposit / withdraw

    private static function playerByUuid(string $uuid): ?array {
        $stmt = db()->prepare('SELECT id, is_admin, is_banned FROM players WHERE id = (SELECT pu.player_id FROM player_uuids pu WHERE pu.uuid = :u)');
        $stmt->execute([':u' => $uuid]);
        return $stmt->fetch() ?: null;
    }

    private static function checkQty(int $qty): int {
        if ($qty < 1 || $qty > self::MAX_TRADE) json_error('Quantity must be 1 to ' . self::MAX_TRADE . '.');
        return $qty;
    }

    /** Roll back and stop with an error. */
    private static function fail(\PDO $pdo, string $msg, int $status = 400): void {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error($msg, $status);
    }

    /** The listing, its shop and its bait, locked for this transaction. */
    private static function lockListing(\PDO $pdo, int $listingId): array {
        $stmt = $pdo->prepare('
            SELECT sl.*, s.player_id AS owner_id, s.is_system, s.is_active AS shop_active,
                   bt.name AS item_name, bt.shop_price AS base_price
            FROM shop_listings sl
            JOIN shops s ON s.id = sl.shop_id
            LEFT JOIN bait_types bt ON bt.id = sl.item_id AND sl.item_type = \'bait\'
            WHERE sl.id = :id
            FOR UPDATE
        ');
        $stmt->execute([':id' => $listingId]);
        $l = $stmt->fetch();
        if (!$l || !(int)$l['is_active'] || !(int)$l['shop_active'] || $l['item_type'] !== 'bait' || $l['item_name'] === null) {
            self::fail($pdo, 'Listing not found');
        }
        $mod = (float)$l['price_modifier'];
        $l['buy_price']  = max(1, (int)round((int)$l['base_price'] * $mod));
        $l['sell_price'] = max(1, (int)floor((int)$l['base_price'] * $mod * 0.5));
        return $l;
    }

    /** Add bait, refusing (false) if it would pass MAX_BAIT_STACK. */
    private static function giveBait(\PDO $pdo, int $playerId, int $baitId, int $qty): bool {
        $stmt = $pdo->prepare('SELECT quantity FROM player_bait WHERE player_id = :pid AND bait_id = :bid FOR UPDATE');
        $stmt->execute([':pid' => $playerId, ':bid' => $baitId]);
        if ((int)$stmt->fetchColumn() + $qty > MAX_BAIT_STACK) return false;
        $pdo->prepare('
            INSERT INTO player_bait (player_id, bait_id, quantity) VALUES (:pid, :bid, :q)
            ON DUPLICATE KEY UPDATE quantity = quantity + :q2
        ')->execute([':pid' => $playerId, ':bid' => $baitId, ':q' => $qty, ':q2' => $qty]);
        return true;
    }

    /** Take bait, refusing (false) if the player has fewer than $qty. */
    private static function takeBait(\PDO $pdo, int $playerId, int $baitId, int $qty): bool {
        $stmt = $pdo->prepare('UPDATE player_bait SET quantity = quantity - :q WHERE player_id = :pid AND bait_id = :bid AND quantity >= :q2');
        $stmt->execute([':q' => $qty, ':q2' => $qty, ':pid' => $playerId, ':bid' => $baitId]);
        return $stmt->rowCount() === 1;
    }

    private static function movePoints(\PDO $pdo, int $playerId, int $delta): bool {
        $stmt = $pdo->prepare('UPDATE players SET fishing_points = fishing_points + :d WHERE id = :id AND fishing_points + :d2 >= 0');
        $stmt->execute([':d' => $delta, ':d2' => $delta, ':id' => $playerId]);
        return $stmt->rowCount() === 1;
    }

    private static function logTrade(\PDO $pdo, array $l, int $playerId, string $action, int $qty, int $points): void {
        $pdo->prepare('
            INSERT INTO shop_transactions
                (shop_id, listing_id, player_id, action, item_type, item_id, item_name, quantity, points_amount)
            VALUES (:sid, :lid, :pid, :a, :it, :iid, :in, :q, :pts)
        ')->execute([':sid' => $l['shop_id'], ':lid' => $l['id'], ':pid' => $playerId, ':a' => $action,
                     ':it' => $l['item_type'], ':iid' => $l['item_id'], ':in' => $l['item_name'],
                     ':q' => $qty, ':pts' => $points]);
    }

    public static function buy(int $playerId, int $listingId, int $quantity = 1): array {
        $quantity = self::checkQty($quantity);
        self::getListingStatus($listingId);   // applies a system shop's restock first
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $l = self::lockListing($pdo, $listingId);
            $ownerId = (int)$l['is_system'] ? 0 : (int)$l['owner_id'];
            if ($ownerId === $playerId) self::fail($pdo, "You can't buy from your own shop. Use Withdraw.");
            if ((int)$l['stock'] < $quantity) self::fail($pdo, 'Not enough stock. Only ' . (int)$l['stock'] . ' available.');

            $totalCost = $l['buy_price'] * $quantity;
            if (!self::movePoints($pdo, $playerId, -$totalCost)) self::fail($pdo, "Need {$totalCost} points.");
            if (!self::giveBait($pdo, $playerId, (int)$l['item_id'], $quantity)) {
                self::fail($pdo, 'You can carry at most ' . MAX_BAIT_STACK . " {$l['item_name']}.");
            }
            if ($ownerId) self::movePoints($pdo, $ownerId, $totalCost);
            $pdo->prepare('UPDATE shop_listings SET stock = stock - :q WHERE id = :id')
                ->execute([':q' => $quantity, ':id' => $listingId]);
            self::logTrade($pdo, $l, $playerId, 'buy', $quantity, $totalCost);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return [
            'message' => "Bought {$quantity}x {$l['item_name']} for {$totalCost} pts!",
            'item_name' => $l['item_name'],
            'quantity' => $quantity,
            'points_spent' => $totalCost,
            'stock_remaining' => (int)$l['stock'] - $quantity,
        ];
    }

    public static function sell(int $playerId, int $listingId, int $quantity = 1): array {
        $quantity = self::checkQty($quantity);
        self::getListingStatus($listingId);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $l = self::lockListing($pdo, $listingId);
            $ownerId = (int)$l['is_system'] ? 0 : (int)$l['owner_id'];
            if ($ownerId === $playerId) self::fail($pdo, "You can't sell to your own shop. Use Deposit.");
            if ((int)$l['stock'] + $quantity > (int)$l['max_stock']) {
                self::fail($pdo, 'This shop only has room for ' . max(0, (int)$l['max_stock'] - (int)$l['stock']) . ' more.');
            }

            $totalEarned = $l['sell_price'] * $quantity;
            if (!self::takeBait($pdo, $playerId, (int)$l['item_id'], $quantity)) self::fail($pdo, "You don't have enough to sell.");
            // A player shop pays from its owner's points; a system shop pays from the game.
            if ($ownerId && !self::movePoints($pdo, $ownerId, -$totalEarned)) {
                self::fail($pdo, "This shop's owner can't afford to buy that right now.");
            }
            self::movePoints($pdo, $playerId, $totalEarned);
            $pdo->prepare('UPDATE shop_listings SET stock = stock + :q WHERE id = :id')
                ->execute([':q' => $quantity, ':id' => $listingId]);
            self::logTrade($pdo, $l, $playerId, 'sell', $quantity, $totalEarned);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return [
            'message' => "Sold {$quantity}x {$l['item_name']} for {$totalEarned} pts!",
            'item_name' => $l['item_name'],
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
        $today = gmdate('Y-m-d');

        $stmt = $pdo->prepare('SELECT web_purchases FROM daily_purchase_limits WHERE player_id = :pid AND purchase_date = :d');
        $stmt->execute([':pid' => $playerId, ':d' => $today]);
        $row = $stmt->fetch();
        $used = $row ? (int)$row['web_purchases'] : 0;

        return ['used' => $used, 'limit' => self::WEB_DAILY_LIMIT, 'remaining' => max(0, self::WEB_DAILY_LIMIT - $used)];
    }

    public static function incrementWebPurchase(int $playerId, int $amount = 1): void {
        $pdo = db();
        $today = gmdate('Y-m-d');
        $pdo->prepare('
            INSERT INTO daily_purchase_limits (player_id, purchase_date, web_purchases)
            VALUES (:pid, :d, :n)
            ON DUPLICATE KEY UPDATE web_purchases = web_purchases + :n2
        ')->execute([':pid' => $playerId, ':d' => $today, ':n' => $amount, ':n2' => $amount]);
    }

    public static function getShopsInRegion(string $region, string $gridName = ''): array {
        $pdo = db();
        $where = 's.is_active = 1 AND s.region_name = :r';
        $params = [':r' => $region];
        if ($gridName !== '') { $where .= ' AND ' . Grid::sameGridSql('s.grid_name', ':gi'); $params[':gi'] = Grid::identityOf($gridName); }
        $stmt = $pdo->prepare("SELECT s.id, s.name, s.is_system FROM shops s WHERE $where ORDER BY s.name");
        $stmt->execute($params);
        return ['shops' => $stmt->fetchAll()];
    }

    /** The owner moves bait from their own inventory into their shop. */
    public static function deposit(string $ownerUuid, int $listingId, int $qty): array {
        return self::moveStock($ownerUuid, $listingId, self::checkQty($qty), true);
    }

    /** The owner takes bait back out of their shop into their inventory. */
    public static function withdraw(string $ownerUuid, int $listingId, int $qty): array {
        return self::moveStock($ownerUuid, $listingId, self::checkQty($qty), false);
    }

    private static function moveStock(string $ownerUuid, int $listingId, int $qty, bool $in): array {
        $player = self::playerByUuid($ownerUuid);
        if (!$player) json_error('Player not found');
        $playerId = (int)$player['id'];

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $l = self::lockListing($pdo, $listingId);
            if ((int)$l['is_system']) self::fail($pdo, 'System shops restock themselves.');
            if ((int)$l['owner_id'] !== $playerId) self::fail($pdo, 'Not authorized', 403);
            $baitId = (int)$l['item_id'];

            if ($in) {
                if ((int)$l['stock'] + $qty > (int)$l['max_stock']) {
                    self::fail($pdo, 'Room for ' . max(0, (int)$l['max_stock'] - (int)$l['stock']) . ' more.');
                }
                if (!self::takeBait($pdo, $playerId, $baitId, $qty)) self::fail($pdo, "You don't have {$qty} {$l['item_name']}.");
                $pdo->prepare('UPDATE shop_listings SET stock = stock + :q WHERE id = :id')
                    ->execute([':q' => $qty, ':id' => $listingId]);
            } else {
                if ((int)$l['stock'] < $qty) self::fail($pdo, 'Not enough stock to withdraw');
                if (!self::giveBait($pdo, $playerId, $baitId, $qty)) {
                    self::fail($pdo, 'You can carry at most ' . MAX_BAIT_STACK . " {$l['item_name']}.");
                }
                $pdo->prepare('UPDATE shop_listings SET stock = stock - :q WHERE id = :id')
                    ->execute([':q' => $qty, ':id' => $listingId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['message' => $in ? "Added {$qty} {$l['item_name']} to stock" : "Took {$qty} {$l['item_name']} from stock"];
    }

    public static function adminRestock(string $adminUuid, int $listingId): array {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, is_admin FROM players WHERE id = (SELECT pu.player_id FROM player_uuids pu WHERE pu.uuid = :u)');
        $stmt->execute([':u' => $adminUuid]);
        $player = $stmt->fetch();
        if (!$player || !(int)$player['is_admin']) json_error('Admin only');

        $pdo->prepare('UPDATE shop_listings SET stock = max_stock, last_restock = UTC_TIMESTAMP() WHERE id = :id')
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
            $where .= ' AND ' . Grid::sameGridSql('s.grid_name', ':gi');
            $params[':gi'] = Grid::identityOf($gridName);
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

        $stmt = $pdo->prepare('SELECT id, is_admin FROM players WHERE id = (SELECT pu.player_id FROM player_uuids pu WHERE pu.uuid = :u)');
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
