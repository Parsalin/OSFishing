-- ============================================================
-- FISHING GAME - STARTER QUEST DATA
-- 12 quests covering progression from beginner to mid-game.
-- Rewards: points, bait, XP, and one rod.
-- ============================================================

-- ═══════════════════════════════════════
-- QUEST 1: First Catch (Level 1)
-- Catch any fish. Tutorial quest.
-- Reward: 50 points + 10 Earthworms
-- ═══════════════════════════════════════
INSERT INTO quests (id, title, description, quest_type, min_level, is_active)
VALUES (1, 'First Catch', 'Catch your very first fish! Any species, any size — just get one on the line.', 'collection', 1, 1);

INSERT INTO quest_requirements (quest_id, quantity, description)
VALUES (1, 1, 'Catch any fish');

INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description)
VALUES (1, 'points', 50, '50 Fishing Points'),
       (1, 'bait', 10, '10 Earthworms');
UPDATE quest_rewards SET reward_ref_id = 1 WHERE quest_id = 1 AND reward_type = 'bait';

-- ═══════════════════════════════════════
-- QUEST 2: Pond Sampler (Level 1)
-- Catch 3 different pond species.
-- Reward: 100 points
-- ═══════════════════════════════════════
INSERT INTO quests (id, title, description, quest_type, min_level, is_active)
VALUES (2, 'Pond Sampler', 'Catch 3 different species from a pond. Try different bait to find what works!', 'multi_species', 1, 1);

INSERT INTO quest_requirements (quest_id, fish_species_id, quantity, description) VALUES
(2, 1, 1, 'Catch a Sunfish'),
(2, 2, 1, 'Catch a Bluegill'),
(2, 5, 1, 'Catch a Largemouth Bass');

INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description)
VALUES (2, 'points', 100, '100 Fishing Points');

-- ═══════════════════════════════════════
-- QUEST 3: Bread Winner (Level 1)
-- Catch 5 fish using Bread Dough.
-- Reward: 20 Corn Kernels + 75 points
-- ═══════════════════════════════════════
INSERT INTO quests (id, title, description, quest_type, min_level, is_active)
VALUES (3, 'Bread Winner', 'Catch 5 fish using Bread Dough as bait. Koi and Bluegill love it!', 'collection', 1, 1);

INSERT INTO quest_requirements (quest_id, bait_required_id, quantity, description)
VALUES (3, 9, 5, 'Catch 5 fish with Bread Dough');

INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description) VALUES
(3, 'points', 75, NULL, '75 Fishing Points'),
(3, 'bait', 20, 7, '20 Corn Kernels');

-- ═══════════════════════════════════════
-- QUEST 4: Big One (Level 1)
-- Catch a fish weighing over 3 lbs.
-- Reward: 150 points
-- ═══════════════════════════════════════
INSERT INTO quests (id, title, description, quest_type, min_level, is_active)
VALUES (4, 'The Big One', 'Land a fish weighing over 3 pounds. Try using Minnows on a Largemouth Bass!', 'size', 1, 1);

INSERT INTO quest_requirements (quest_id, min_weight, quantity, description)
VALUES (4, 3.0, 1, 'Catch a fish over 3 lbs');

INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description)
VALUES (4, 'points', 150, '150 Fishing Points');

-- ═══════════════════════════════════════
-- QUEST 5: Worm Farmer (Level 1, repeatable)
-- Gather 50 bait from gather points.
-- Reward: 30 points (repeatable every 24h)
-- ═══════════════════════════════════════
INSERT INTO quests (id, title, description, quest_type, min_level, is_repeatable, repeat_cooldown, is_active)
VALUES (5, 'Worm Farmer', 'Gather 50 pieces of bait from gathering points around the world. Sit down and search!', 'collection', 1, 1, 24, 1);

INSERT INTO quest_requirements (quest_id, quantity, description)
VALUES (5, 50, 'Gather 50 bait from gather points');

INSERT INTO quest_rewards (quest_id, reward_type, reward_value, description)
VALUES (5, 'points', 30, '30 Fishing Points');

-- ═══════════════════════════════════════
-- QUEST 6: River Runner (Level 3)
-- Catch 5 fish from a river.
-- Reward: 200 points + 15 Grasshoppers
-- ═══════════════════════════════════════
INSERT INTO quests (id, title, description, quest_type, min_level, is_active)
VALUES (6, 'River Runner', 'Head to a river and catch 5 fish. Rivers unlock at Level 3. Grasshoppers are great for trout!', 'collection', 3, 1);

INSERT INTO quest_requirements (quest_id, water_type_id, quantity, description)
VALUES (6, 2, 5, 'Catch 5 fish from a river');

INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description) VALUES
(6, 'points', 200, NULL, '200 Fishing Points'),
(6, 'bait', 15, 2, '15 Grasshoppers');

-- ═══════════════════════════════════════
-- QUEST 7: Trout Tracker (Level 3)
-- Catch a Rainbow Trout and a Brown Trout.
-- Reward: 250 points + 10 Minnows
-- ═══════════════════════════════════════
INSERT INTO quests (id, title, description, quest_type, min_level, is_active)
VALUES (7, 'Trout Tracker', 'Track down both species of trout. Rainbow Trout love Grasshoppers, Brown Trout prefer Minnows.', 'multi_species', 3, 1);

INSERT INTO quest_requirements (quest_id, fish_species_id, quantity, description) VALUES
(7, 9, 1, 'Catch a Rainbow Trout'),
(7, 10, 1, 'Catch a Brown Trout');

INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description) VALUES
(7, 'points', 250, NULL, '250 Fishing Points'),
(7, 'bait', 10, 4, '10 Minnows');

-- ═══════════════════════════════════════
-- QUEST 8: Lake Legend (Level 5)
-- Catch a Northern Pike from a lake.
-- Reward: 400 points + 5 Leeches
-- ═══════════════════════════════════════
INSERT INTO quests (id, title, description, quest_type, min_level, is_active)
VALUES (8, 'Lake Legend', 'Northern Pike are ambush predators lurking in the lake. Use Minnows or Cut Bait to lure one out.', 'collection', 5, 1);

INSERT INTO quest_requirements (quest_id, fish_species_id, quantity, description)
VALUES (8, 21, 1, 'Catch a Northern Pike');

INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description) VALUES
(8, 'points', 400, NULL, '400 Fishing Points'),
(8, 'bait', 5, 6, '5 Leeches');

-- ═══════════════════════════════════════
-- QUEST 9: Carp Whisperer (Level 5)
-- Catch 3 Carp using Corn Kernels.
-- Reward: 300 points + 20 Bread Dough
-- ═══════════════════════════════════════
INSERT INTO quests (id, title, description, quest_type, min_level, is_active)
VALUES (9, 'Carp Whisperer', 'Carp are stubborn bottom feeders. Use Corn Kernels to catch 3 of them from a lake.', 'collection', 5, 1);

INSERT INTO quest_requirements (quest_id, fish_species_id, bait_required_id, quantity, description)
VALUES (9, 20, 7, 3, 'Catch 3 Carp with Corn Kernels');

INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description) VALUES
(9, 'points', 300, NULL, '300 Fishing Points'),
(9, 'bait', 20, 9, '20 Bread Dough');

-- ═══════════════════════════════════════
-- QUEST 10: Deep Sea Debut (Level 10)
-- Catch 3 ocean fish.
-- Reward: 500 points + 15 Shrimp
-- ═══════════════════════════════════════
INSERT INTO quests (id, title, description, quest_type, min_level, is_active)
VALUES (10, 'Deep Sea Debut', 'The ocean awaits! Catch 3 fish from ocean waters. You\'ll need Shrimp or Cut Bait and a strong line.', 'collection', 10, 1);

INSERT INTO quest_requirements (quest_id, water_type_id, quantity, description)
VALUES (10, 4, 3, 'Catch 3 ocean fish');

INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description) VALUES
(10, 'points', 500, NULL, '500 Fishing Points'),
(10, 'bait', 15, 8, '15 Shrimp');

-- ═══════════════════════════════════════
-- QUEST 11: Monster Hunter (Level 10)
-- Catch a fish over 20 lbs.
-- Reward: 750 points + 5 Glow Grubs
-- ═══════════════════════════════════════
INSERT INTO quests (id, title, description, quest_type, min_level, is_active)
VALUES (11, 'Monster Hunter', 'Land a true monster — a fish weighing over 20 pounds. Try deep lake or ocean fish with heavy tackle.', 'size', 10, 1);

INSERT INTO quest_requirements (quest_id, min_weight, quantity, description)
VALUES (11, 20.0, 1, 'Catch a fish over 20 lbs');

INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description) VALUES
(11, 'points', 750, NULL, '750 Fishing Points'),
(11, 'bait', 5, 10, '5 Glow Grubs');

-- ═══════════════════════════════════════
-- QUEST 12: The Angler's Rod (Level 8)
-- Catch 10 different species from any water.
-- Reward: Graphite Rod (rod_id 3)
-- ═══════════════════════════════════════
INSERT INTO quests (id, title, description, quest_type, min_level, is_active)
VALUES (12, 'The Angler''s Rod', 'Prove your skill by catching 10 different species across all water types. A master angler deserves a master''s tool.', 'multi_species', 8, 1);

INSERT INTO quest_requirements (quest_id, fish_species_id, quantity, description) VALUES
(12, 1, 1, 'Catch a Sunfish'),
(12, 5, 1, 'Catch a Largemouth Bass'),
(12, 9, 1, 'Catch a Rainbow Trout'),
(12, 11, 1, 'Catch a Smallmouth Bass'),
(12, 13, 1, 'Catch a Salmon'),
(12, 16, 1, 'Catch a Perch'),
(12, 20, 1, 'Catch a Carp'),
(12, 21, 1, 'Catch a Northern Pike'),
(12, 25, 1, 'Catch a Mackerel'),
(12, 26, 1, 'Catch a Sea Bass');

INSERT INTO quest_rewards (quest_id, reward_type, reward_value, reward_ref_id, description)
VALUES (12, 'rod', 1, 3, 'Graphite Rod');
