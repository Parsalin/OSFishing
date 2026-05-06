-- ============================================================
-- Add grid_name to fishing_spots for grid-scoped leaderboards
-- ============================================================

ALTER TABLE fishing_spots
    ADD COLUMN grid_name VARCHAR(64) DEFAULT NULL AFTER region_name;
