-- ============================================================
-- FISHING GAME - BAIT AFFINITY DATA
-- Complete affinity matrix for all fish × bait combinations.
-- 
-- Affinity values:
--   2.0  = LOVES this bait (best possible)
--   1.5  = Really likes it
--   1.0  = Normal/good
--   0.8  = Okay
--   0.5  = Poor
--   0.3  = Very poor (default for unlisted)
--
-- Bait IDs:
--   1=Earthworms, 2=Grasshoppers, 3=Crickets, 4=Minnows,
--   5=Cut Bait, 6=Leeches, 7=Corn Kernels, 8=Shrimp,
--   9=Bread Dough, 10=Glow Grubs
-- ============================================================

-- Clear existing affinities first
DELETE FROM fish_bait_affinity;

-- ═══════════════════════════════════════
-- POND FISH (IDs 1-7)
-- Best bait: Earthworms, Bread Dough, Corn, Crickets
-- ═══════════════════════════════════════

-- Sunfish (1) - loves worms and crickets, eats anything small
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(1, 1, 2.0),  -- Earthworms: LOVES
(1, 2, 1.2),  -- Grasshoppers: likes
(1, 3, 1.5),  -- Crickets: really likes
(1, 7, 1.0),  -- Corn: good
(1, 9, 1.2),  -- Bread Dough: likes
(1, 6, 0.8),  -- Leeches: okay
(1, 4, 0.5),  -- Minnows: poor (too big)
(1, 5, 0.3),  -- Cut Bait: poor
(1, 8, 0.3),  -- Shrimp: poor
(1, 10, 0.5); -- Glow Grubs: poor

-- Bluegill (2) - loves worms and bread dough
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(2, 1, 2.0),  -- Earthworms: LOVES
(2, 9, 1.5),  -- Bread Dough: really likes
(2, 3, 1.2),  -- Crickets: likes
(2, 7, 1.0),  -- Corn: good
(2, 2, 1.0),  -- Grasshoppers: good
(2, 6, 0.8),  -- Leeches: okay
(2, 4, 0.5),  -- Minnows: poor
(2, 5, 0.3),  -- Cut Bait: poor
(2, 8, 0.3),  -- Shrimp: poor
(2, 10, 0.5); -- Glow Grubs: poor

-- Bullhead Catfish (3) - bottom feeder, loves cut bait and worms
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(3, 5, 2.0),  -- Cut Bait: LOVES
(3, 1, 1.5),  -- Earthworms: really likes
(3, 6, 1.2),  -- Leeches: likes
(3, 9, 1.0),  -- Bread Dough: good
(3, 8, 0.8),  -- Shrimp: okay
(3, 7, 0.8),  -- Corn: okay
(3, 4, 0.8),  -- Minnows: okay
(3, 3, 0.5),  -- Crickets: poor
(3, 2, 0.3),  -- Grasshoppers: poor
(3, 10, 1.0); -- Glow Grubs: good (catfish are nocturnal)

-- Koi (4) - ornamental, loves bread and corn
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(4, 9, 2.0),  -- Bread Dough: LOVES
(4, 7, 1.5),  -- Corn: really likes
(4, 1, 1.0),  -- Earthworms: good
(4, 3, 0.8),  -- Crickets: okay
(4, 2, 0.5),  -- Grasshoppers: poor
(4, 6, 0.5),  -- Leeches: poor
(4, 4, 0.3),  -- Minnows: poor
(4, 5, 0.3),  -- Cut Bait: poor
(4, 8, 0.3),  -- Shrimp: poor
(4, 10, 0.5); -- Glow Grubs: poor

-- Largemouth Bass (5) - predator, loves minnows and leeches
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(5, 4, 2.0),  -- Minnows: LOVES
(5, 6, 1.5),  -- Leeches: really likes
(5, 1, 1.2),  -- Earthworms: likes
(5, 5, 1.0),  -- Cut Bait: good
(5, 2, 1.0),  -- Grasshoppers: good (topwater)
(5, 3, 0.8),  -- Crickets: okay
(5, 8, 0.5),  -- Shrimp: poor
(5, 7, 0.3),  -- Corn: poor
(5, 9, 0.3),  -- Bread Dough: poor
(5, 10, 0.8); -- Glow Grubs: okay

-- Snapping Turtle (6) - aggressive, loves cut bait and minnows
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(6, 5, 2.0),  -- Cut Bait: LOVES
(6, 4, 1.5),  -- Minnows: really likes
(6, 1, 1.0),  -- Earthworms: good
(6, 6, 1.0),  -- Leeches: good
(6, 8, 0.8),  -- Shrimp: okay
(6, 3, 0.5),  -- Crickets: poor
(6, 2, 0.5),  -- Grasshoppers: poor
(6, 7, 0.3),  -- Corn: poor
(6, 9, 0.3),  -- Bread Dough: poor
(6, 10, 0.5); -- Glow Grubs: poor

-- Golden Koi (7) - rare, loves bread and glow grubs
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(7, 9, 1.5),  -- Bread Dough: really likes
(7, 7, 1.2),  -- Corn: likes
(7, 10, 2.0), -- Glow Grubs: LOVES (rare fish loves rare bait)
(7, 1, 0.8),  -- Earthworms: okay
(7, 3, 0.5),  -- Crickets: poor
(7, 2, 0.3),  -- Grasshoppers: poor
(7, 4, 0.3),  -- Minnows: poor
(7, 5, 0.3),  -- Cut Bait: poor
(7, 6, 0.3),  -- Leeches: poor
(7, 8, 0.3);  -- Shrimp: poor

-- ═══════════════════════════════════════
-- RIVER FISH (IDs 8-15)
-- Best bait: Worms, Minnows, Grasshoppers, Leeches
-- ═══════════════════════════════════════

-- Creek Chub (8) - small, eats everything
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(8, 1, 2.0),  -- Earthworms: LOVES
(8, 3, 1.2),  -- Crickets: likes
(8, 2, 1.0),  -- Grasshoppers: good
(8, 9, 1.0),  -- Bread Dough: good
(8, 7, 0.8),  -- Corn: okay
(8, 6, 0.8),  -- Leeches: okay
(8, 4, 0.5),  -- Minnows: poor
(8, 5, 0.3),  -- Cut Bait: poor
(8, 8, 0.3),  -- Shrimp: poor
(8, 10, 0.5); -- Glow Grubs: poor

-- Rainbow Trout (9) - loves insects
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(9, 2, 2.0),  -- Grasshoppers: LOVES
(9, 3, 1.5),  -- Crickets: really likes
(9, 1, 1.2),  -- Earthworms: likes
(9, 4, 1.0),  -- Minnows: good
(9, 6, 0.8),  -- Leeches: okay
(9, 5, 0.5),  -- Cut Bait: poor
(9, 8, 0.5),  -- Shrimp: poor
(9, 7, 0.3),  -- Corn: poor
(9, 9, 0.3),  -- Bread Dough: poor
(9, 10, 0.8); -- Glow Grubs: okay

-- Brown Trout (10) - larger, more predatory
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(10, 4, 2.0),  -- Minnows: LOVES
(10, 2, 1.5),  -- Grasshoppers: really likes
(10, 1, 1.2),  -- Earthworms: likes
(10, 3, 1.0),  -- Crickets: good
(10, 6, 1.0),  -- Leeches: good
(10, 5, 0.8),  -- Cut Bait: okay
(10, 8, 0.5),  -- Shrimp: poor
(10, 7, 0.3),  -- Corn: poor
(10, 9, 0.3),  -- Bread Dough: poor
(10, 10, 0.8); -- Glow Grubs: okay

-- Smallmouth Bass (11) - aggressive, loves leeches
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(11, 6, 2.0),  -- Leeches: LOVES
(11, 4, 1.5),  -- Minnows: really likes
(11, 1, 1.2),  -- Earthworms: likes
(11, 3, 1.0),  -- Crickets: good
(11, 2, 1.0),  -- Grasshoppers: good
(11, 5, 0.8),  -- Cut Bait: okay
(11, 8, 0.5),  -- Shrimp: poor
(11, 7, 0.3),  -- Corn: poor
(11, 9, 0.3),  -- Bread Dough: poor
(11, 10, 0.8); -- Glow Grubs: okay

-- Walleye (12) - loves minnows and leeches
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(12, 4, 2.0),  -- Minnows: LOVES
(12, 6, 1.5),  -- Leeches: really likes
(12, 1, 1.0),  -- Earthworms: good
(12, 5, 1.0),  -- Cut Bait: good
(12, 3, 0.8),  -- Crickets: okay
(12, 2, 0.5),  -- Grasshoppers: poor
(12, 8, 0.5),  -- Shrimp: poor
(12, 7, 0.3),  -- Corn: poor
(12, 9, 0.3),  -- Bread Dough: poor
(12, 10, 1.2); -- Glow Grubs: likes (walleye are crepuscular)

-- Salmon (13) - loves shrimp and cut bait
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(13, 8, 2.0),  -- Shrimp: LOVES
(13, 5, 1.5),  -- Cut Bait: really likes
(13, 4, 1.2),  -- Minnows: likes
(13, 1, 0.8),  -- Earthworms: okay
(13, 6, 0.8),  -- Leeches: okay
(13, 3, 0.5),  -- Crickets: poor
(13, 2, 0.5),  -- Grasshoppers: poor
(13, 7, 0.3),  -- Corn: poor
(13, 9, 0.3),  -- Bread Dough: poor
(13, 10, 1.0); -- Glow Grubs: good

-- River Sturgeon (14) - bottom feeder, loves cut bait
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(14, 5, 2.0),  -- Cut Bait: LOVES
(14, 1, 1.5),  -- Earthworms: really likes
(14, 6, 1.2),  -- Leeches: likes
(14, 8, 1.0),  -- Shrimp: good
(14, 4, 0.8),  -- Minnows: okay
(14, 3, 0.5),  -- Crickets: poor
(14, 2, 0.3),  -- Grasshoppers: poor
(14, 7, 0.5),  -- Corn: poor
(14, 9, 0.3),  -- Bread Dough: poor
(14, 10, 1.5); -- Glow Grubs: really likes (deep water)

-- Ghost Catfish (15) - rare, loves glow grubs
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(15, 10, 2.0), -- Glow Grubs: LOVES (rare catch)
(15, 5, 1.2),  -- Cut Bait: likes
(15, 6, 1.0),  -- Leeches: good
(15, 1, 0.8),  -- Earthworms: okay
(15, 4, 0.8),  -- Minnows: okay
(15, 8, 0.5),  -- Shrimp: poor
(15, 3, 0.3),  -- Crickets: poor
(15, 2, 0.3),  -- Grasshoppers: poor
(15, 7, 0.3),  -- Corn: poor
(15, 9, 0.3);  -- Bread Dough: poor

-- ═══════════════════════════════════════
-- LAKE FISH (IDs 16-24)
-- Best bait: Minnows, Leeches, Cut Bait, Worms
-- ═══════════════════════════════════════

-- Perch (16) - loves worms and minnows
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(16, 1, 2.0),  -- Earthworms: LOVES
(16, 4, 1.5),  -- Minnows: really likes
(16, 3, 1.0),  -- Crickets: good
(16, 6, 0.8),  -- Leeches: okay
(16, 2, 0.8),  -- Grasshoppers: okay
(16, 5, 0.5),  -- Cut Bait: poor
(16, 8, 0.5),  -- Shrimp: poor
(16, 7, 0.3),  -- Corn: poor
(16, 9, 0.5),  -- Bread Dough: poor
(16, 10, 0.5); -- Glow Grubs: poor

-- Crappie (17) - loves minnows and crickets
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(17, 4, 2.0),  -- Minnows: LOVES
(17, 3, 1.5),  -- Crickets: really likes
(17, 1, 1.2),  -- Earthworms: likes
(17, 2, 1.0),  -- Grasshoppers: good
(17, 6, 0.8),  -- Leeches: okay
(17, 5, 0.5),  -- Cut Bait: poor
(17, 8, 0.5),  -- Shrimp: poor
(17, 7, 0.3),  -- Corn: poor
(17, 9, 0.3),  -- Bread Dough: poor
(17, 10, 0.8); -- Glow Grubs: okay

-- Channel Catfish (18) - loves cut bait and stink bait
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(18, 5, 2.0),  -- Cut Bait: LOVES
(18, 1, 1.5),  -- Earthworms: really likes
(18, 6, 1.2),  -- Leeches: likes
(18, 8, 1.0),  -- Shrimp: good
(18, 4, 0.8),  -- Minnows: okay
(18, 9, 0.8),  -- Bread Dough: okay
(18, 7, 0.5),  -- Corn: poor
(18, 3, 0.5),  -- Crickets: poor
(18, 2, 0.3),  -- Grasshoppers: poor
(18, 10, 1.0); -- Glow Grubs: good

-- Lake Largemouth Bass (19) - same as pond bass but bigger
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(19, 4, 2.0),  -- Minnows: LOVES
(19, 6, 1.5),  -- Leeches: really likes
(19, 1, 1.0),  -- Earthworms: good
(19, 5, 1.0),  -- Cut Bait: good
(19, 2, 0.8),  -- Grasshoppers: okay
(19, 3, 0.8),  -- Crickets: okay
(19, 8, 0.5),  -- Shrimp: poor
(19, 7, 0.3),  -- Corn: poor
(19, 9, 0.3),  -- Bread Dough: poor
(19, 10, 0.8); -- Glow Grubs: okay

-- Carp (20) - loves corn and bread
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(20, 7, 2.0),  -- Corn: LOVES
(20, 9, 2.0),  -- Bread Dough: LOVES
(20, 1, 1.2),  -- Earthworms: likes
(20, 6, 0.8),  -- Leeches: okay
(20, 3, 0.5),  -- Crickets: poor
(20, 2, 0.3),  -- Grasshoppers: poor
(20, 4, 0.3),  -- Minnows: poor
(20, 5, 0.3),  -- Cut Bait: poor
(20, 8, 0.3),  -- Shrimp: poor
(20, 10, 0.5); -- Glow Grubs: poor

-- Northern Pike (21) - apex predator, loves minnows
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(21, 4, 2.0),  -- Minnows: LOVES
(21, 5, 1.5),  -- Cut Bait: really likes
(21, 6, 1.2),  -- Leeches: likes
(21, 1, 0.8),  -- Earthworms: okay
(21, 8, 0.8),  -- Shrimp: okay
(21, 2, 0.5),  -- Grasshoppers: poor
(21, 3, 0.5),  -- Crickets: poor
(21, 7, 0.3),  -- Corn: poor
(21, 9, 0.3),  -- Bread Dough: poor
(21, 10, 1.0); -- Glow Grubs: good

-- Lake Trout (22) - deep water, loves minnows and shrimp
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(22, 4, 2.0),  -- Minnows: LOVES
(22, 8, 1.5),  -- Shrimp: really likes
(22, 5, 1.2),  -- Cut Bait: likes
(22, 6, 1.0),  -- Leeches: good
(22, 1, 0.8),  -- Earthworms: okay
(22, 3, 0.5),  -- Crickets: poor
(22, 2, 0.3),  -- Grasshoppers: poor
(22, 7, 0.3),  -- Corn: poor
(22, 9, 0.3),  -- Bread Dough: poor
(22, 10, 1.5); -- Glow Grubs: really likes (deep water)

-- Muskie (23) - apex predator, loves big bait
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(23, 4, 2.0),  -- Minnows: LOVES
(23, 5, 2.0),  -- Cut Bait: LOVES
(23, 6, 1.2),  -- Leeches: likes
(23, 8, 1.0),  -- Shrimp: good
(23, 1, 0.5),  -- Earthworms: poor (too small)
(23, 3, 0.3),  -- Crickets: poor
(23, 2, 0.3),  -- Grasshoppers: poor
(23, 7, 0.3),  -- Corn: poor
(23, 9, 0.3),  -- Bread Dough: poor
(23, 10, 1.2); -- Glow Grubs: likes

-- Albino Muskie (24) - rare trophy, loves glow grubs
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(24, 10, 2.0), -- Glow Grubs: LOVES (rare fish)
(24, 4, 1.5),  -- Minnows: really likes
(24, 5, 1.5),  -- Cut Bait: really likes
(24, 6, 1.0),  -- Leeches: good
(24, 8, 0.8),  -- Shrimp: okay
(24, 1, 0.3),  -- Earthworms: poor
(24, 2, 0.3),  -- Grasshoppers: poor
(24, 3, 0.3),  -- Crickets: poor
(24, 7, 0.3),  -- Corn: poor
(24, 9, 0.3);  -- Bread Dough: poor

-- ═══════════════════════════════════════
-- OCEAN FISH (IDs 25-34)
-- Best bait: Shrimp, Cut Bait, Minnows
-- ═══════════════════════════════════════

-- Mackerel (25) - loves shrimp and cut bait
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(25, 8, 2.0),  -- Shrimp: LOVES
(25, 5, 1.5),  -- Cut Bait: really likes
(25, 4, 1.2),  -- Minnows: likes
(25, 1, 0.5),  -- Earthworms: poor
(25, 6, 0.5),  -- Leeches: poor
(25, 3, 0.3),  -- Crickets: poor
(25, 2, 0.3),  -- Grasshoppers: poor
(25, 7, 0.3),  -- Corn: poor
(25, 9, 0.3),  -- Bread Dough: poor
(25, 10, 0.8); -- Glow Grubs: okay

-- Sea Bass (26) - loves shrimp and cut bait
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(26, 8, 2.0),  -- Shrimp: LOVES
(26, 5, 1.5),  -- Cut Bait: really likes
(26, 4, 1.0),  -- Minnows: good
(26, 6, 0.8),  -- Leeches: okay
(26, 1, 0.5),  -- Earthworms: poor
(26, 3, 0.3),  -- Crickets: poor
(26, 2, 0.3),  -- Grasshoppers: poor
(26, 7, 0.3),  -- Corn: poor
(26, 9, 0.3),  -- Bread Dough: poor
(26, 10, 0.8); -- Glow Grubs: okay

-- Flounder (27) - bottom feeder, loves cut bait and shrimp
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(27, 5, 2.0),  -- Cut Bait: LOVES
(27, 8, 1.5),  -- Shrimp: really likes
(27, 4, 1.0),  -- Minnows: good
(27, 1, 0.8),  -- Earthworms: okay
(27, 6, 0.5),  -- Leeches: poor
(27, 3, 0.3),  -- Crickets: poor
(27, 2, 0.3),  -- Grasshoppers: poor
(27, 7, 0.3),  -- Corn: poor
(27, 9, 0.3),  -- Bread Dough: poor
(27, 10, 0.8); -- Glow Grubs: okay

-- Red Snapper (28) - loves shrimp and cut bait
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(28, 8, 2.0),  -- Shrimp: LOVES
(28, 5, 1.5),  -- Cut Bait: really likes
(28, 4, 1.2),  -- Minnows: likes
(28, 6, 0.8),  -- Leeches: okay
(28, 1, 0.5),  -- Earthworms: poor
(28, 3, 0.3),  -- Crickets: poor
(28, 2, 0.3),  -- Grasshoppers: poor
(28, 7, 0.3),  -- Corn: poor
(28, 9, 0.3),  -- Bread Dough: poor
(28, 10, 1.0); -- Glow Grubs: good (deep water)

-- Yellowfin Tuna (29) - fast predator, loves cut bait and minnows
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(29, 5, 2.0),  -- Cut Bait: LOVES
(29, 4, 1.5),  -- Minnows: really likes
(29, 8, 1.2),  -- Shrimp: likes
(29, 6, 0.5),  -- Leeches: poor
(29, 1, 0.3),  -- Earthworms: poor
(29, 3, 0.3),  -- Crickets: poor
(29, 2, 0.3),  -- Grasshoppers: poor
(29, 7, 0.3),  -- Corn: poor
(29, 9, 0.3),  -- Bread Dough: poor
(29, 10, 1.0); -- Glow Grubs: good

-- Barracuda (30) - aggressive predator, loves minnows
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(30, 4, 2.0),  -- Minnows: LOVES
(30, 5, 1.5),  -- Cut Bait: really likes
(30, 8, 1.0),  -- Shrimp: good
(30, 6, 0.5),  -- Leeches: poor
(30, 1, 0.3),  -- Earthworms: poor
(30, 3, 0.3),  -- Crickets: poor
(30, 2, 0.3),  -- Grasshoppers: poor
(30, 7, 0.3),  -- Corn: poor
(30, 9, 0.3),  -- Bread Dough: poor
(30, 10, 0.8); -- Glow Grubs: okay

-- Swordfish (31) - deep water, loves cut bait and glow grubs
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(31, 5, 2.0),  -- Cut Bait: LOVES
(31, 10, 1.5), -- Glow Grubs: really likes (deep water)
(31, 8, 1.2),  -- Shrimp: likes
(31, 4, 1.0),  -- Minnows: good
(31, 6, 0.5),  -- Leeches: poor
(31, 1, 0.3),  -- Earthworms: poor
(31, 3, 0.3),  -- Crickets: poor
(31, 2, 0.3),  -- Grasshoppers: poor
(31, 7, 0.3),  -- Corn: poor
(31, 9, 0.3);  -- Bread Dough: poor

-- Hammerhead Shark (32) - apex predator, loves cut bait
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(32, 5, 2.0),  -- Cut Bait: LOVES
(32, 4, 1.5),  -- Minnows: really likes
(32, 8, 1.2),  -- Shrimp: likes
(32, 6, 0.8),  -- Leeches: okay
(32, 1, 0.3),  -- Earthworms: poor
(32, 3, 0.3),  -- Crickets: poor
(32, 2, 0.3),  -- Grasshoppers: poor
(32, 7, 0.3),  -- Corn: poor
(32, 9, 0.3),  -- Bread Dough: poor
(32, 10, 1.0); -- Glow Grubs: good

-- Blue Marlin (33) - ultimate trophy, loves cut bait and glow grubs
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(33, 5, 2.0),  -- Cut Bait: LOVES
(33, 10, 2.0), -- Glow Grubs: LOVES (rare + deep)
(33, 4, 1.2),  -- Minnows: likes
(33, 8, 1.0),  -- Shrimp: good
(33, 6, 0.5),  -- Leeches: poor
(33, 1, 0.3),  -- Earthworms: poor
(33, 3, 0.3),  -- Crickets: poor
(33, 2, 0.3),  -- Grasshoppers: poor
(33, 7, 0.3),  -- Corn: poor
(33, 9, 0.3);  -- Bread Dough: poor

-- Spectral Jellyfish (34) - mythical, only glow grubs
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(34, 10, 2.0), -- Glow Grubs: LOVES (only real option)
(34, 8, 0.5),  -- Shrimp: poor (slight bioluminescence)
(34, 5, 0.3),  -- Cut Bait: poor
(34, 4, 0.3),  -- Minnows: poor
(34, 6, 0.3),  -- Leeches: poor
(34, 1, 0.3),  -- Earthworms: poor
(34, 3, 0.3),  -- Crickets: poor
(34, 2, 0.3),  -- Grasshoppers: poor
(34, 7, 0.3),  -- Corn: poor
(34, 9, 0.3);  -- Bread Dough: poor

-- ═══════════════════════════════════════
-- MULTI-WATER FISH
-- ═══════════════════════════════════════

-- Eel (35) - river+lake, loves worms and glow grubs
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity) VALUES
(35, 1, 2.0),  -- Earthworms: LOVES
(35, 10, 1.5), -- Glow Grubs: really likes (nocturnal)
(35, 6, 1.2),  -- Leeches: likes
(35, 5, 1.0),  -- Cut Bait: good
(35, 4, 0.8),  -- Minnows: okay
(35, 8, 0.8),  -- Shrimp: okay
(35, 3, 0.5),  -- Crickets: poor
(35, 2, 0.3),  -- Grasshoppers: poor
(35, 7, 0.3),  -- Corn: poor
(35, 9, 0.3);  -- Bread Dough: poor
