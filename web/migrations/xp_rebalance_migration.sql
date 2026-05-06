-- ============================================================
-- XP REBALANCE
-- - Fish XP scaled by rarity + water type
-- - Steep XP curve after level 10
-- - Junk gives XP by water type
-- - Line breaks give tiny XP by water type
-- ============================================================

-- ── Update rarity tier base XP (higher for rare+) ──
UPDATE rarity_tiers SET base_xp = 8   WHERE id = 1;  -- common
UPDATE rarity_tiers SET base_xp = 20  WHERE id = 2;  -- uncommon
UPDATE rarity_tiers SET base_xp = 55  WHERE id = 3;  -- rare
UPDATE rarity_tiers SET base_xp = 150 WHERE id = 4;  -- epic
UPDATE rarity_tiers SET base_xp = 500 WHERE id = 5;  -- legendary

-- ── Steeper XP curve after level 10 ──
-- Levels 1-10: gradual ramp (unchanged mostly)
-- Levels 11-20: exponential growth
UPDATE levels SET xp_required = 0     WHERE level = 1;
UPDATE levels SET xp_required = 100   WHERE level = 2;
UPDATE levels SET xp_required = 300   WHERE level = 3;
UPDATE levels SET xp_required = 600   WHERE level = 4;
UPDATE levels SET xp_required = 1000  WHERE level = 5;
UPDATE levels SET xp_required = 1500  WHERE level = 6;
UPDATE levels SET xp_required = 2200  WHERE level = 7;
UPDATE levels SET xp_required = 3000  WHERE level = 8;
UPDATE levels SET xp_required = 4000  WHERE level = 9;
UPDATE levels SET xp_required = 5500  WHERE level = 10;
UPDATE levels SET xp_required = 8000  WHERE level = 11;
UPDATE levels SET xp_required = 12000 WHERE level = 12;
UPDATE levels SET xp_required = 18000 WHERE level = 13;
UPDATE levels SET xp_required = 26000 WHERE level = 14;
UPDATE levels SET xp_required = 38000 WHERE level = 15;
UPDATE levels SET xp_required = 55000 WHERE level = 16;
UPDATE levels SET xp_required = 78000 WHERE level = 17;
UPDATE levels SET xp_required = 110000 WHERE level = 18;
UPDATE levels SET xp_required = 155000 WHERE level = 19;
UPDATE levels SET xp_required = 220000 WHERE level = 20;
