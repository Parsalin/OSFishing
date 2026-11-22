<?php
/**
 * shop_transactions: the shape ShopSystem and the portal's Shop → History
 * use (audit item 31). The table had an older purchase-log shape
 * (item_ref_id, points_spent, purchased_at), so every vendor buy/sell
 * failed on its log insert after the purchase had gone through.
 */
return function (PDO $pdo): void {
    $cols = $pdo->query('SHOW COLUMNS FROM shop_transactions')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('shop_id', $cols, true)) return;

    $pdo->exec("
        ALTER TABLE shop_transactions
            CHANGE item_ref_id  item_id       INT UNSIGNED NOT NULL DEFAULT 0,
            CHANGE points_spent points_amount INT UNSIGNED NOT NULL DEFAULT 0,
            CHANGE purchased_at created_at    DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
            ADD COLUMN shop_id    INT UNSIGNED NOT NULL DEFAULT 0 AFTER id,
            ADD COLUMN listing_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER shop_id,
            ADD COLUMN action     ENUM('buy','sell') NOT NULL DEFAULT 'buy' AFTER player_id,
            ADD COLUMN item_name  VARCHAR(100) NOT NULL DEFAULT '' AFTER item_id,
            ADD KEY idx_shop (shop_id, created_at)
    ");
};
