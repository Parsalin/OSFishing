-- ============================================================
-- Fishing spot archive instead of delete
-- ============================================================
-- Spots are no longer deleted — they're marked archived.
-- Catch logs and leaderboards keep their references intact.

ALTER TABLE fishing_spots ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
ALTER TABLE fishing_spots ADD COLUMN archived_at DATETIME DEFAULT NULL AFTER archived;
ALTER TABLE fishing_spots ADD INDEX idx_archived (archived);
