-- ============================================================
-- BUFF SYSTEM - Items, player inventory, spot activation
-- ============================================================

-- Buff item definitions
CREATE TABLE IF NOT EXISTS buff_items (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(64) NOT NULL UNIQUE,
    buff_type     VARCHAR(32) NOT NULL,           -- chum, lure_oil, growth, blessing, treasure, calm, bait_saver, double_hook, golden_hour
    effect_value  FLOAT NOT NULL DEFAULT 0.25,    -- Magnitude of effect
    duration_mins INT UNSIGNED NOT NULL DEFAULT 30,
    description   TEXT DEFAULT NULL,
    source        ENUM('craft','quest','shop','admin') NOT NULL DEFAULT 'shop',
    shop_price    INT UNSIGNED DEFAULT NULL,       -- NULL = not in shop
    min_level     INT UNSIGNED NOT NULL DEFAULT 1,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Player buff item inventory
CREATE TABLE IF NOT EXISTS player_buff_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id   INT UNSIGNED NOT NULL,
    buff_item_id INT UNSIGNED NOT NULL,
    quantity    INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uk_player_buff (player_id, buff_item_id),
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (buff_item_id) REFERENCES buff_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Insert buff items ──

-- Craft items (level 5+)
INSERT INTO buff_items (name, buff_type, effect_value, duration_mins, description, source, shop_price, min_level) VALUES
('Chum Bucket', 'chum', 0.25, 30, '+25% bite chance. Craft from 2 rare fish at the butchering table.', 'craft', NULL, 5),
('Lucky Lure Oil', 'lure_oil', 0.15, 20, '+15% rare fish chance. Craft from 1 legendary fish.', 'craft', NULL, 5);

-- Quest reward items (level 4+)
INSERT INTO buff_items (name, buff_type, effect_value, duration_mins, description, source, shop_price, min_level) VALUES
('Growth Tonic', 'growth', 0.20, 30, '+20% fish weight. Earned from quests.', 'quest', NULL, 4),
('Angler''s Blessing', 'blessing', 0.25, 30, '+25% XP gain. Earned from quests.', 'quest', NULL, 4),
('Treasure Scent', 'treasure', 0.50, 20, '+50% junk item chance. Earned from quests.', 'quest', NULL, 4);

-- Shop items (level 3+)
INSERT INTO buff_items (name, buff_type, effect_value, duration_mins, description, source, shop_price, min_level) VALUES
('Calm Waters', 'calm', 0.20, 20, '-20% fight difficulty. Buy from the shop.', 'shop', 200, 3),
('Bait Saver', 'bait_saver', 0.25, 30, '25% chance bait not consumed. Buy from the shop.', 'shop', 250, 3);

-- Admin-only premium items
INSERT INTO buff_items (name, buff_type, effect_value, duration_mins, description, source, shop_price, min_level) VALUES
('Double Hook', 'double_hook', 0.30, 15, '30% chance double catch. Admin reward only.', 'admin', NULL, 1),
('Golden Hour', 'golden_hour', 0.10, 15, '+10% to everything. Admin reward only.', 'admin', NULL, 1);
