-- Track when one HUD was blocked because another was recently active
CREATE TABLE IF NOT EXISTS hud_exclusion_triggers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    triggered_uuid  CHAR(36) NOT NULL,            -- UUID that got blocked
    blocking_uuid   CHAR(36) DEFAULT NULL,        -- UUID currently holding the lock
    triggered_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_player_uuid (player_id, triggered_uuid),
    INDEX idx_when (triggered_at)
) ENGINE=InnoDB;
