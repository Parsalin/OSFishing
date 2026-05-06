-- ============================================================
-- BUTCHERING SYSTEM: Fish Chunks + Special Baits
-- ============================================================

-- Fish Chunks - universal bait from butchering
INSERT IGNORE INTO bait_types (name, description, shop_price) VALUES
('Fish Chunks', 'Chopped fish pieces. Predators love them.', 0);

-- Special baits - one per water type, cannot be bought (NULL price = not in shop)
INSERT IGNORE INTO bait_types (name, description, shop_price) VALUES
('Shimmering Minnow', 'A tiny iridescent minnow found inside a pond fish. Irresistible to legendary pond dwellers.', NULL),
('River Pearl', 'A lustrous pearl formed inside a river fish. Legendary river fish can''t resist.', NULL),
('Deep Lake Grub', 'A fat luminous grub from a lake fish''s belly. Lures legendary lake monsters.', NULL),
('Abyssal Eye', 'A glowing eye from a deep-sea predator. Draws legendary ocean beasts.', NULL);

-- Add affinities for Fish Chunks (good for predator fish)
-- We'll use moderate-high affinity for predator species
-- Get the bait_id for Fish Chunks dynamically
SET @chunks_id = (SELECT id FROM bait_types WHERE name = 'Fish Chunks');

-- Fish Chunks affinities - good for predators, decent for everything
INSERT INTO fish_bait_affinity (fish_species_id, bait_id, affinity_weight) 
SELECT fs.id, @chunks_id, 
  CASE 
    WHEN fs.name IN ('Largemouth Bass', 'Catfish', 'Northern Pike', 'Muskie', 'Walleye', 'Striped Bass', 'Barracuda', 'Tuna', 'Swordfish', 'Shark') THEN 3.0
    WHEN fs.name IN ('Trout', 'Salmon', 'Perch', 'Carp', 'Eel') THEN 2.0
    ELSE 1.0
  END
FROM fish_species fs
ON DUPLICATE KEY UPDATE affinity_weight = VALUES(affinity_weight);

-- Special bait affinities - extremely high for legendary fish of matching water type
SET @shimmer_id = (SELECT id FROM bait_types WHERE name = 'Shimmering Minnow');
SET @pearl_id = (SELECT id FROM bait_types WHERE name = 'River Pearl');
SET @grub_id = (SELECT id FROM bait_types WHERE name = 'Deep Lake Grub');
SET @eye_id = (SELECT id FROM bait_types WHERE name = 'Abyssal Eye');

-- Shimmering Minnow: huge bonus for pond legendary, decent for other pond fish
INSERT INTO fish_bait_affinity (fish_species_id, bait_id, affinity_weight)
SELECT fs.id, @shimmer_id,
  CASE WHEN fs.rarity_id >= 5 THEN 10.0 WHEN fs.rarity_id >= 4 THEN 5.0 ELSE 2.0 END
FROM fish_species fs
WHERE fs.id IN (SELECT DISTINCT fsh.id FROM fish_species fsh 
  JOIN fish_spot_availability fsa ON fsa.fish_species_id = fsh.id
  JOIN fishing_spots fsp ON fsp.id = fsa.spot_id
  WHERE fsp.water_type_id = 1)
ON DUPLICATE KEY UPDATE affinity_weight = VALUES(affinity_weight);

-- River Pearl: huge bonus for river legendary
INSERT INTO fish_bait_affinity (fish_species_id, bait_id, affinity_weight)
SELECT fs.id, @pearl_id,
  CASE WHEN fs.rarity_id >= 5 THEN 10.0 WHEN fs.rarity_id >= 4 THEN 5.0 ELSE 2.0 END
FROM fish_species fs
WHERE fs.id IN (SELECT DISTINCT fsh.id FROM fish_species fsh
  JOIN fish_spot_availability fsa ON fsa.fish_species_id = fsh.id
  JOIN fishing_spots fsp ON fsp.id = fsa.spot_id
  WHERE fsp.water_type_id = 2)
ON DUPLICATE KEY UPDATE affinity_weight = VALUES(affinity_weight);

-- Deep Lake Grub: huge bonus for lake legendary
INSERT INTO fish_bait_affinity (fish_species_id, bait_id, affinity_weight)
SELECT fs.id, @grub_id,
  CASE WHEN fs.rarity_id >= 5 THEN 10.0 WHEN fs.rarity_id >= 4 THEN 5.0 ELSE 2.0 END
FROM fish_species fs
WHERE fs.id IN (SELECT DISTINCT fsh.id FROM fish_species fsh
  JOIN fish_spot_availability fsa ON fsa.fish_species_id = fsh.id
  JOIN fishing_spots fsp ON fsp.id = fsa.spot_id
  WHERE fsp.water_type_id = 3)
ON DUPLICATE KEY UPDATE affinity_weight = VALUES(affinity_weight);

-- Abyssal Eye: huge bonus for ocean legendary
INSERT INTO fish_bait_affinity (fish_species_id, bait_id, affinity_weight)
SELECT fs.id, @eye_id,
  CASE WHEN fs.rarity_id >= 5 THEN 10.0 WHEN fs.rarity_id >= 4 THEN 5.0 ELSE 2.0 END
FROM fish_species fs
WHERE fs.id IN (SELECT DISTINCT fsh.id FROM fish_species fsh
  JOIN fish_spot_availability fsa ON fsa.fish_species_id = fsh.id
  JOIN fishing_spots fsp ON fsp.id = fsa.spot_id
  WHERE fsp.water_type_id = 4)
ON DUPLICATE KEY UPDATE affinity_weight = VALUES(affinity_weight);
