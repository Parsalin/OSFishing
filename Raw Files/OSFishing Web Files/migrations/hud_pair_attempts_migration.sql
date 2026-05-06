-- ============================================================
-- HUD pairing audit log + hard-kill on new pair
-- ============================================================

-- Audit log: track every UUID/grid that has tried to pair to each player account
CREATE TABLE IF NOT EXISTS hud_pair_attempts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED DEFAULT NULL,    -- which account they tried to pair
    attempted_uuid  CHAR(36) NOT NULL,             -- UUID requesting the pair
    grid_name       VARCHAR(64) DEFAULT NULL,
    avatar_name     VARCHAR(128) DEFAULT NULL,     -- display name if available
    ip_address      VARCHAR(45) DEFAULT NULL,
    succeeded       TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_player (player_id, attempted_at),
    INDEX idx_uuid (attempted_uuid)
) ENGINE=InnoDB;
