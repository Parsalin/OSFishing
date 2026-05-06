-- Add shop_enabled flag to bait_types
ALTER TABLE bait_types ADD COLUMN shop_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER shop_price;

-- Set prices for all bait that don't have one yet
-- Common baits: low price, all enabled by default
UPDATE bait_types SET shop_price = 5,   shop_enabled = 1 WHERE name = 'Earthworms';
UPDATE bait_types SET shop_price = 5,   shop_enabled = 1 WHERE name = 'Nightcrawlers';
UPDATE bait_types SET shop_price = 5,   shop_enabled = 1 WHERE name = 'Corn Kernels';
UPDATE bait_types SET shop_price = 8,   shop_enabled = 1 WHERE name = 'Bread Dough';
UPDATE bait_types SET shop_price = 8,   shop_enabled = 1 WHERE name = 'Minnows';
UPDATE bait_types SET shop_price = 10,  shop_enabled = 1 WHERE name = 'Crickets';
UPDATE bait_types SET shop_price = 10,  shop_enabled = 1 WHERE name = 'Grasshoppers';
UPDATE bait_types SET shop_price = 12,  shop_enabled = 1 WHERE name = 'Crayfish';
UPDATE bait_types SET shop_price = 12,  shop_enabled = 1 WHERE name = 'Leeches';
UPDATE bait_types SET shop_price = 15,  shop_enabled = 1 WHERE name = 'Salmon Eggs';
UPDATE bait_types SET shop_price = 15,  shop_enabled = 1 WHERE name = 'Shrimp';
UPDATE bait_types SET shop_price = 18,  shop_enabled = 1 WHERE name = 'Cut Bait';
UPDATE bait_types SET shop_price = 20,  shop_enabled = 1 WHERE name = 'Squid';
UPDATE bait_types SET shop_price = 25,  shop_enabled = 1 WHERE name = 'Clams';
UPDATE bait_types SET shop_price = 300, shop_enabled = 1 WHERE name = 'Magnet';

-- Special/crafted baits: price set but NOT enabled for shop sale
UPDATE bait_types SET shop_price = 0, shop_enabled = 0 WHERE name = 'Fish Chunks';
UPDATE bait_types SET shop_price = 50, shop_enabled = 0 WHERE name = 'Shimmering Minnow';
UPDATE bait_types SET shop_price = 50, shop_enabled = 0 WHERE name = 'River Pearl';
UPDATE bait_types SET shop_price = 50, shop_enabled = 0 WHERE name = 'Deep Lake Grub';
UPDATE bait_types SET shop_price = 50, shop_enabled = 0 WHERE name = 'Abyssal Eye';

-- Any remaining bait with no price gets a default
UPDATE bait_types SET shop_price = 10 WHERE shop_price IS NULL OR shop_price = 0 AND name != 'Fish Chunks';
