-- ============================================================
-- Tutorial state per player
-- ============================================================
-- tutorial_completed: 0 = not completed/skipped, 1 = done
-- tutorial_step: which step the player is on (0 = welcome, then advances)

ALTER TABLE players ADD COLUMN tutorial_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER home_grid;
ALTER TABLE players ADD COLUMN tutorial_step INT NOT NULL DEFAULT 0 AFTER tutorial_completed;
