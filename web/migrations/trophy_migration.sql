-- ============================================================
-- TROPHY SYSTEM - Saved fish for display
-- ============================================================

-- Add 'saved' to player_fish status
ALTER TABLE player_fish MODIFY COLUMN status ENUM('held','sold','saved') NOT NULL DEFAULT 'held';

-- Saved fish notes (one note per saved fish)
CREATE TABLE IF NOT EXISTS player_saved_fish (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    player_fish_id  INT UNSIGNED NOT NULL UNIQUE,
    note            VARCHAR(255) DEFAULT NULL,
    saved_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (player_fish_id) REFERENCES player_fish(id) ON DELETE CASCADE,
    INDEX idx_player (player_id)
) ENGINE=InnoDB;
