-- ============================================================
-- GATHER POINTS: Add ownership, system flag, regen tracking
-- ============================================================

ALTER TABLE bait_gather_points
    ADD COLUMN player_id    INT UNSIGNED DEFAULT NULL AFTER bait_id,
    ADD COLUMN is_system    TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
    ADD COLUMN last_regen   DATETIME DEFAULT NULL AFTER last_depleted,
    ADD FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE SET NULL;
