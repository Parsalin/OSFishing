-- ============================================================
-- SHOP SYSTEM - Physical bait economy
-- ============================================================

CREATE TABLE IF NOT EXISTS shops (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id    INT UNSIGNED DEFAULT NULL,
    name         VARCHAR(128) NOT NULL,
    region_name  VARCHAR(64) DEFAULT NULL,
    grid_name    VARCHAR(64) DEFAULT NULL,
    region_x     INT DEFAULT NULL,
    region_y     INT DEFAULT NULL,
    pos_x        FLOAT DEFAULT 0,
    pos_y        FLOAT DEFAULT 0,
    pos_z        FLOAT DEFAULT 0,
    is_system    TINYINT(1) NOT NULL DEFAULT 0,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shop_listings (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id        INT UNSIGNED NOT NULL,
    item_type      ENUM('bait','rod','buff') NOT NULL DEFAULT 'bait',
    item_id        INT UNSIGNED NOT NULL,
    stock          INT NOT NULL DEFAULT 25,
    max_stock      INT NOT NULL DEFAULT 25,
    price_modifier FLOAT NOT NULL DEFAULT 1.0,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    last_restock   DATETIME DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    UNIQUE KEY uk_shop_item (shop_id, item_type, item_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shop_transactions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id      INT UNSIGNED NOT NULL,
    listing_id   INT UNSIGNED DEFAULT NULL,
    player_id    INT UNSIGNED NOT NULL,
    action       ENUM('buy','sell') NOT NULL,
    item_type    VARCHAR(16) NOT NULL DEFAULT 'bait',
    item_name    VARCHAR(128) NOT NULL,
    quantity     INT NOT NULL DEFAULT 1,
    points_amount INT NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    INDEX idx_shop (shop_id, created_at),
    INDEX idx_player (player_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daily_purchase_limits (
    player_id     INT UNSIGNED NOT NULL,
    purchase_date DATE NOT NULL,
    web_purchases INT NOT NULL DEFAULT 0,
    PRIMARY KEY (player_id, purchase_date),
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB;
