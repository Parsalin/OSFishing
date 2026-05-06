-- ============================================================
-- FISHING GAME - PAIRING SYSTEM MIGRATION
-- Run after the initial schema to enable HUD pairing.
-- ============================================================

-- Drop and recreate hud_tokens with the new structure
DROP TABLE IF EXISTS hud_tokens;
CREATE TABLE hud_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    token           VARCHAR(128) NOT NULL UNIQUE,           -- The actual device token
    device_name     VARCHAR(64) DEFAULT 'HUD',              -- Player-set label
    last_nonce      BIGINT UNSIGNED NOT NULL DEFAULT 0,     -- Replay protection
    issued_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used       DATETIME DEFAULT NULL,
    last_ip         VARCHAR(45) DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_player (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pairing codes table
CREATE TABLE IF NOT EXISTS hud_pairing_codes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(8) NOT NULL,                    -- Format: "384-291"
    player_uuid     VARCHAR(36) NOT NULL,                   -- The avatar requesting it
    player_id       INT UNSIGNED NOT NULL,                  -- Resolved at request time
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NOT NULL,
    claimed         TINYINT(1) NOT NULL DEFAULT 0,
    claimed_at      DATETIME DEFAULT NULL,
    issued_token_id INT UNSIGNED DEFAULT NULL,              -- Set when claimed
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    INDEX idx_code (code),
    INDEX idx_uuid (player_uuid),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
