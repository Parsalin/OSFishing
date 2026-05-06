-- ============================================================
-- FISHING SPOTS EXPANSION
-- New columns + buff table + junk table
-- ============================================================

-- Add new columns to fishing_spots
ALTER TABLE fishing_spots
    ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active,
    ADD COLUMN region_x INT DEFAULT NULL AFTER region_name,
    ADD COLUMN region_y INT DEFAULT NULL AFTER region_x,
    ADD COLUMN setup_complete TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public;

-- Spot buffs (active effects on a fishing spot)
CREATE TABLE IF NOT EXISTS spot_buffs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    spot_id       INT UNSIGNED NOT NULL,
    buff_type     VARCHAR(32) NOT NULL,
    buff_value    FLOAT NOT NULL DEFAULT 0.25,
    activated_by  INT UNSIGNED DEFAULT NULL,
    activated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at    DATETIME NOT NULL,
    FOREIGN KEY (spot_id) REFERENCES fishing_spots(id) ON DELETE CASCADE,
    FOREIGN KEY (activated_by) REFERENCES players(id) ON DELETE SET NULL,
    INDEX idx_spot_active (spot_id, expires_at)
) ENGINE=InnoDB;

-- Spot junk items (objects in prim inventory that can be fished up)
CREATE TABLE IF NOT EXISTS spot_junk_items (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    spot_id       INT UNSIGNED NOT NULL,
    item_name     VARCHAR(128) NOT NULL,
    rarity_weight FLOAT NOT NULL DEFAULT 1.0,
    added_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (spot_id) REFERENCES fishing_spots(id) ON DELETE CASCADE,
    INDEX idx_spot (spot_id)
) ENGINE=InnoDB;

-- Mark all existing spots as needing re-setup
UPDATE fishing_spots SET setup_complete = 0, is_active = 0;
