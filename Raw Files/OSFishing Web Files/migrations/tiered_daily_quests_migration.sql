-- ============================================================
-- TIERED DAILY QUESTS - Clean run
-- quest_group and quest_tier already exist on quests table
-- Just need min_rarity_id on quest_requirements
-- ============================================================

ALTER TABLE quest_requirements ADD COLUMN min_rarity_id INT UNSIGNED DEFAULT NULL AFTER quantity;

-- ── Remove old single-tier daily quests ──
DELETE FROM player_quests WHERE quest_id IN (
    SELECT id FROM quests WHERE repeat_type = 'daily' AND (quest_group IS NULL OR quest_group = '')
);
DELETE FROM quests WHERE repeat_type = 'daily' AND (quest_group IS NULL OR quest_group = '');

-- ══════════════════════════════════════════
-- DAILY CATCH TIERS
-- ══════════════════════════════════════════

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Daily Catch', 'Catch 5 fish today. Any species, any water.', 'collection', 1, 1, 'daily', 24, 'daily_catch', 1);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, quantity, description, track_type) VALUES (@q, 5, 'Catch 5 fish', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 50, '50 Fishing Points');

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Daily Catch II', 'Catch 8 fish today, at least 2 from a river.', 'collection', 3, 1, 'daily', 24, 'daily_catch', 2);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, quantity, description, track_type) VALUES (@q, 8, 'Catch 8 fish', 'catch');
INSERT INTO quest_requirements (quest_id, water_type_id, quantity, description, track_type) VALUES (@q, 2, 2, 'Catch 2 river fish', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 80, '80 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'xp', 50, '50 XP');

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Daily Catch III', 'Catch 10 fish today, at least 3 from a lake.', 'collection', 5, 1, 'daily', 24, 'daily_catch', 3);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, quantity, description, track_type) VALUES (@q, 10, 'Catch 10 fish', 'catch');
INSERT INTO quest_requirements (quest_id, water_type_id, quantity, description, track_type) VALUES (@q, 3, 3, 'Catch 3 lake fish', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 120, '120 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'xp', 100, '100 XP');

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Daily Catch IV', 'Catch 12 fish today, at least 3 uncommon or better.', 'collection', 8, 1, 'daily', 24, 'daily_catch', 4);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, quantity, description, track_type) VALUES (@q, 12, 'Catch 12 fish', 'catch');
INSERT INTO quest_requirements (quest_id, min_rarity_id, quantity, description, track_type) VALUES (@q, 2, 3, 'Catch 3 uncommon+ fish', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 180, '180 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'growth' LIMIT 1), '1x Growth Tonic');

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Daily Catch V', 'Catch 15 fish today, at least 2 from the ocean.', 'collection', 10, 1, 'daily', 24, 'daily_catch', 5);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, quantity, description, track_type) VALUES (@q, 15, 'Catch 15 fish', 'catch');
INSERT INTO quest_requirements (quest_id, water_type_id, quantity, description, track_type) VALUES (@q, 4, 2, 'Catch 2 ocean fish', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 250, '250 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'growth' LIMIT 1), '1x Growth Tonic');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'blessing' LIMIT 1), '1x Angler''s Blessing');

-- ══════════════════════════════════════════
-- BIG FISH DAY TIERS
-- ══════════════════════════════════════════

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Big Fish Day', 'Catch a fish weighing over 2 lbs.', 'size', 1, 1, 'daily', 24, 'big_fish', 1);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, min_weight, quantity, description, track_type) VALUES (@q, 2.0, 1, 'Catch a fish over 2 lbs', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 50, '50 Fishing Points');

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Bigger Fish Day', 'Catch a fish weighing over 4 lbs.', 'size', 3, 1, 'daily', 24, 'big_fish', 2);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, min_weight, quantity, description, track_type) VALUES (@q, 4.0, 1, 'Catch a fish over 4 lbs', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 80, '80 Fishing Points');

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Huge Fish Day', 'Catch a fish weighing over 6 lbs.', 'size', 5, 1, 'daily', 24, 'big_fish', 3);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, min_weight, quantity, description, track_type) VALUES (@q, 6.0, 1, 'Catch a fish over 6 lbs', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 120, '120 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'treasure' LIMIT 1), '1x Treasure Scent');

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Massive Fish Day', 'Catch a fish weighing over 10 lbs.', 'size', 8, 1, 'daily', 24, 'big_fish', 4);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, min_weight, quantity, description, track_type) VALUES (@q, 10.0, 1, 'Catch a fish over 10 lbs', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 180, '180 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'treasure' LIMIT 1), '1x Treasure Scent');

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Legendary Fish Day', 'Catch a fish weighing over 15 lbs.', 'size', 10, 1, 'daily', 24, 'big_fish', 5);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, min_weight, quantity, description, track_type) VALUES (@q, 15.0, 1, 'Catch a fish over 15 lbs', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 250, '250 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 2, (SELECT id FROM buff_items WHERE buff_type = 'treasure' LIMIT 1), '2x Treasure Scent');

-- ══════════════════════════════════════════
-- RARE HAUL TIERS
-- ══════════════════════════════════════════

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Rare Haul', 'Catch 3 rare or better fish today.', 'rare_catch', 4, 1, 'daily', 24, 'rare_haul', 1);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, min_rarity_id, quantity, description, track_type) VALUES (@q, 3, 3, 'Catch 3 rare+ fish', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'growth' LIMIT 1), '1x Growth Tonic');

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Rarer Haul', 'Catch 4 rare or better fish today.', 'rare_catch', 6, 1, 'daily', 24, 'rare_haul', 2);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, min_rarity_id, quantity, description, track_type) VALUES (@q, 3, 4, 'Catch 4 rare+ fish', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 100, '100 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'growth' LIMIT 1), '1x Growth Tonic');

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Exceptional Haul', 'Catch 3 epic or better fish today.', 'rare_catch', 8, 1, 'daily', 24, 'rare_haul', 3);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, min_rarity_id, quantity, description, track_type) VALUES (@q, 4, 3, 'Catch 3 epic+ fish', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 150, '150 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 2, (SELECT id FROM buff_items WHERE buff_type = 'growth' LIMIT 1), '2x Growth Tonic');

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Legendary Haul', 'Catch 2 legendary fish today.', 'rare_catch', 10, 1, 'daily', 24, 'rare_haul', 4);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, min_rarity_id, quantity, description, track_type) VALUES (@q, 5, 2, 'Catch 2 legendary fish', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 200, '200 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'lure_oil' LIMIT 1), '1x Lucky Lure Oil');

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Mythic Haul', 'Catch 3 legendary fish today.', 'rare_catch', 12, 1, 'daily', 24, 'rare_haul', 5);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, min_rarity_id, quantity, description, track_type) VALUES (@q, 5, 3, 'Catch 3 legendary fish', 'catch');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 300, '300 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'lure_oil' LIMIT 1), '1x Lucky Lure Oil');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'blessing' LIMIT 1), '1x Angler''s Blessing');

-- ══════════════════════════════════════════
-- BAIT COLLECTOR TIERS
-- ══════════════════════════════════════════

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Bait Collector', 'Gather 20 pieces of bait today.', 'collection', 2, 1, 'daily', 24, 'bait_collector', 1);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, quantity, description, track_type) VALUES (@q, 20, 'Gather 20 bait', 'gather');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'blessing' LIMIT 1), '1x Angler''s Blessing');

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Bait Hoarder', 'Gather 35 pieces of bait today.', 'collection', 4, 1, 'daily', 24, 'bait_collector', 2);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, quantity, description, track_type) VALUES (@q, 35, 'Gather 35 bait', 'gather');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 80, '80 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'blessing' LIMIT 1), '1x Angler''s Blessing');

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Bait Fanatic', 'Gather 50 pieces of bait today.', 'collection', 6, 1, 'daily', 24, 'bait_collector', 3);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, quantity, description, track_type) VALUES (@q, 50, 'Gather 50 bait', 'gather');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 120, '120 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 2, (SELECT id FROM buff_items WHERE buff_type = 'blessing' LIMIT 1), '2x Angler''s Blessing');

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Bait Obsessed', 'Gather 75 pieces of bait today.', 'collection', 8, 1, 'daily', 24, 'bait_collector', 4);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, quantity, description, track_type) VALUES (@q, 75, 'Gather 75 bait', 'gather');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 180, '180 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'bait_saver' LIMIT 1), '1x Bait Saver');

INSERT INTO quests (title, description, quest_type, min_level, is_repeatable, repeat_type, repeat_cooldown, quest_group, quest_tier)
VALUES ('Bait Glutton', 'Gather 100 pieces of bait today.', 'collection', 10, 1, 'daily', 24, 'bait_collector', 5);
SET @q = LAST_INSERT_ID();
INSERT INTO quest_requirements (quest_id, quantity, description, track_type) VALUES (@q, 100, 'Gather 100 bait', 'gather');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description) VALUES (@q, 'points', 250, '250 Fishing Points');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'bait_saver' LIMIT 1), '1x Bait Saver');
INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (@q, 'buff_item', 1, (SELECT id FROM buff_items WHERE buff_type = 'blessing' LIMIT 1), '1x Angler''s Blessing');

-- ── Tag existing weekly quests with groups ──
UPDATE quests SET quest_group = 'weekly_angler',   quest_tier = 1 WHERE title = 'Weekly Angler';
UPDATE quests SET quest_group = 'species_sampler', quest_tier = 1 WHERE title = 'Species Sampler';
UPDATE quests SET quest_group = 'deep_sea',        quest_tier = 1 WHERE title = 'Deep Sea Challenge';
UPDATE quests SET quest_group = 'heavy_hauler',    quest_tier = 1 WHERE title = 'Heavy Hauler';
