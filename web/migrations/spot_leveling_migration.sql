-- Spot Leveling System
-- Adds XP accumulation, level tracking, and per-fisher daily diminishing returns to fishing spots.
-- Spot level replaces water-type min_level as the sole player-access gate.

ALTER TABLE fishing_spots
  ADD COLUMN spot_xp          INT UNSIGNED  NOT NULL DEFAULT 0,
  ADD COLUMN spot_level       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN spot_level_ready TINYINT(1)    NOT NULL DEFAULT 0;

-- Per-fisher daily XP contributions (diminishing returns + spot daily cap enforcement)
CREATE TABLE spot_xp_daily (
  id          INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
  spot_id     INT UNSIGNED   NOT NULL,
  player_id   INT UNSIGNED   NOT NULL,
  log_date    DATE           NOT NULL,
  fish_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  xp_given    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_spot_player_date (spot_id, player_id, log_date),
  INDEX idx_spot_date (spot_id, log_date)
);
