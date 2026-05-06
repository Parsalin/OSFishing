-- ============================================================
-- FISHING GAME - SPOT OWNERSHIP MIGRATION
-- Adds player ownership and system flag to fishing_spots.
-- ============================================================

ALTER TABLE fishing_spots
    ADD COLUMN player_id INT UNSIGNED DEFAULT NULL AFTER id,
    ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER player_id,
    ADD CONSTRAINT fk_fishing_spots_player
        FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    ADD INDEX idx_player (player_id);
