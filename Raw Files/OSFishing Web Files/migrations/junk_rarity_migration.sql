-- Add rarity label to junk items
ALTER TABLE spot_junk_items
    ADD COLUMN rarity_label VARCHAR(16) NOT NULL DEFAULT 'common' AFTER rarity_weight;
