-- ============================================================
-- MAGNET BAIT - Junk fishing specialty bait
-- ============================================================

-- Add Magnet bait type
INSERT IGNORE INTO bait_types (name, description, shop_price, shop_quantity, catch_rate_mod, rarity_mod, min_level)
VALUES ('Magnet', 'A powerful magnet on a line. Attracts junk from the water but fish hate it. Level 8+.', 300, 5, 0.3, 0.1, 8);

-- Set very low affinity for ALL fish (magnet repels fish)
SET @mag_id = (SELECT id FROM bait_types WHERE name = 'Magnet' LIMIT 1);
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity)
SELECT id, @mag_id, 0.05 FROM fish_species
ON DUPLICATE KEY UPDATE affinity = 0.05;
