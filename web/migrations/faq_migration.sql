-- ============================================================
-- FISHING GAME - FAQ & PLAYER QUESTIONS MIGRATION
-- ============================================================

CREATE TABLE IF NOT EXISTS faq_entries (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question    VARCHAR(512) NOT NULL,
    answer      TEXT NOT NULL,
    category    VARCHAR(64) DEFAULT 'general',
    sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS player_questions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id   INT UNSIGNED DEFAULT NULL,
    player_name VARCHAR(128) DEFAULT NULL,
    question    TEXT NOT NULL,
    status      ENUM('new','answered','dismissed') NOT NULL DEFAULT 'new',
    admin_reply TEXT DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    replied_at  DATETIME DEFAULT NULL,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE SET NULL
) ENGINE=InnoDB;
