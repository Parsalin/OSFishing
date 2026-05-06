-- ============================================================
-- FISHING GAME - BAIT GATHER POINTS MIGRATION
-- ============================================================

CREATE TABLE IF NOT EXISTS bait_gather_points (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(128) NOT NULL,
    bait_id         INT UNSIGNED NOT NULL,
    region_name     VARCHAR(64) DEFAULT NULL,
    pos_x           FLOAT DEFAULT NULL,
    pos_y           FLOAT DEFAULT NULL,
    pos_z           FLOAT DEFAULT NULL,
    max_stock       INT UNSIGNED NOT NULL DEFAULT 25,
    current_stock   INT UNSIGNED NOT NULL DEFAULT 25,
    last_depleted   DATETIME DEFAULT NULL,
    respawn_minutes INT UNSIGNED NOT NULL DEFAULT 30,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bait_id) REFERENCES bait_types(id) ON DELETE CASCADE
) ENGINE=InnoDB;
