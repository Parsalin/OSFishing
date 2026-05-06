-- ============================================================
-- FISHING GAME - ADMIN PANEL MIGRATION
-- ============================================================
-- Adds:
--   1. is_admin flag on players
--   2. is_banned flag on players
--   3. spot_limit_override on players (NULL = use level formula)
--   4. announcements table
--   5. Set your account as admin
-- ============================================================

ALTER TABLE players
    ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER level,
    ADD COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin,
    ADD COLUMN spot_limit_override INT UNSIGNED DEFAULT NULL AFTER is_banned;

CREATE TABLE IF NOT EXISTS announcements (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(128) NOT NULL,
    body        TEXT NOT NULL,
    priority    ENUM('info','warning','urgent') NOT NULL DEFAULT 'info',
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_by  INT UNSIGNED DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME DEFAULT NULL,
    FOREIGN KEY (created_by) REFERENCES players(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ═══════════════════════════════════════
-- SET YOUR ACCOUNT AS ADMIN
-- Change the username below to match yours
-- ═══════════════════════════════════════
UPDATE players SET is_admin = 1 WHERE id = 1;
