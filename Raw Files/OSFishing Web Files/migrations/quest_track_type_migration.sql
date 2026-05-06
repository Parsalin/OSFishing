-- ============================================================
-- Add track_type to quest_requirements
-- 'catch' = auto-tracked on fish catch (default)
-- 'gather' = auto-tracked on bait gather
-- 'manual' = requires manual turn-in
-- ============================================================

ALTER TABLE quest_requirements
    ADD COLUMN track_type ENUM('catch','gather','manual') NOT NULL DEFAULT 'catch' AFTER description;

-- Fix Worm Farmer quest (id=5) - should track on gather, not catch
UPDATE quest_requirements SET track_type = 'gather' WHERE quest_id = 5;

-- Reset any accidental progress on the Worm Farmer quest
UPDATE player_quest_progress pqp
JOIN player_quests pq ON pq.id = pqp.player_quest_id
SET pqp.current_count = 0, pqp.is_complete = 0
WHERE pq.quest_id = 5;
