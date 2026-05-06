-- ============================================================
-- REPEATABLE QUESTS + BUFF ITEM REWARDS
-- ============================================================

-- Add repeat_type to quests
ALTER TABLE quests
    ADD COLUMN repeat_type ENUM('none','daily','weekly') NOT NULL DEFAULT 'none' AFTER is_repeatable;

-- Add buff_item to reward types
ALTER TABLE quest_rewards
    MODIFY COLUMN reward_type ENUM('points','xp','bait','rod','title','trophy','special','buff_item') NOT NULL;

-- ── DAILY QUESTS ──

-- Daily: Catch 5 fish (Level 1+) → 30 points
INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown)
VALUES ('Daily Catch', 'Catch 5 fish today. Any species counts!', 'collection', 1, 1, 'daily', 24);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, quantity, description, track_type) VALUES (@q, 5, 'Catch 5 fish', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 50, '50 Fishing Points');

-- Daily: Catch 3 rare+ fish (Level 4+) → Growth Tonic
INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown)
VALUES ('Rare Haul', 'Catch 3 rare or better fish today.', 'rare_catch', 4, 1, 'daily', 24);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, min_weight, quantity, description, track_type) VALUES (@q, NULL, 3, 'Catch 3 rare+ fish', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'growth' LIMIT 1), '1x Growth Tonic');

-- Daily: Gather 20 bait (Level 2+) → Angler's Blessing
INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown)
VALUES ('Bait Collector', 'Gather 20 pieces of bait from gather points.', 'collection', 2, 1, 'daily', 24);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, quantity, description, track_type) VALUES (@q, 20, 'Gather 20 bait', 'gather');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'blessing' LIMIT 1), '1x Angler''s Blessing');

-- Daily: Catch a fish over 5 lbs (Level 4+) → Treasure Scent
INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown)
VALUES ('Big Fish Day', 'Catch a fish weighing over 5 lbs.', 'size', 4, 1, 'daily', 24);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, min_weight, quantity, description, track_type) VALUES (@q, 5.0, 1, 'Catch a fish over 5 lbs', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'treasure' LIMIT 1), '1x Treasure Scent');

-- ── WEEKLY QUESTS ──

-- Weekly: Catch 30 fish (Level 1+) → 200 points + Growth Tonic
INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown)
VALUES ('Weekly Angler', 'Catch 30 fish this week.', 'collection', 1, 1, 'weekly', 168);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, quantity, description, track_type) VALUES (@q, 30, 'Catch 30 fish', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 200, '200 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'growth' LIMIT 1), '1x Growth Tonic');

-- Weekly: Catch 5 different species (Level 3+) → Angler's Blessing x2
INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown)
VALUES ('Species Sampler', 'Catch 5 different species this week.', 'multi_species', 3, 1, 'weekly', 168);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, quantity, description, track_type) VALUES (@q, 5, 'Catch 5 different species', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 2, (SELECT id FROM buff_items WHERE buff_type = 'blessing' LIMIT 1), '2x Angler''s Blessing');

-- Weekly: Catch a fish in the ocean (Level 10+) → Treasure Scent x2 + 300 points
INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown)
VALUES ('Deep Sea Challenge', 'Catch any fish from the ocean this week.', 'collection', 10, 1, 'weekly', 168);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, water_type_id, quantity, description, track_type) VALUES (@q, 4, 1, 'Catch 1 ocean fish', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 300, '300 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 2, (SELECT id FROM buff_items WHERE buff_type = 'treasure' LIMIT 1), '2x Treasure Scent');

-- Weekly: Catch 10 fish over 3 lbs (Level 5+) → Growth Tonic x2 + Blessing
INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown)
VALUES ('Heavy Hauler', 'Catch 10 fish weighing over 3 lbs this week.', 'size', 5, 1, 'weekly', 168);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, min_weight, quantity, description, track_type) VALUES (@q, 3.0, 10, 'Catch 10 fish over 3 lbs', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 2, (SELECT id FROM buff_items WHERE buff_type = 'growth' LIMIT 1), '2x Growth Tonic');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'blessing' LIMIT 1), '1x Angler''s Blessing');
