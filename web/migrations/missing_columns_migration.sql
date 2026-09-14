-- ============================================================
-- MISSING COLUMNS — reconstructs columns absent from migration history
-- ============================================================
-- quest_group and quest_tier were added directly to the live database
-- and never captured in a committed migration. tiered_daily_quests_migration.sql
-- assumes they already exist ("quest_group and quest_tier already exist on
-- quests table"), and includes/Quest.php queries both, so a database built
-- purely from the committed migrations was missing them.
--
-- Must run before tiered_daily_quests_migration.sql.
--
-- quest_group: name of a repeatable-quest family (daily_catch, big_fish,
--              rare_haul, bait_collector, weekly_angler). NULL for one-time quests.
-- quest_tier:  1-5 within a group; accepting any tier blocks the other tiers
--              in the same group.
-- ============================================================

ALTER TABLE quests
    ADD COLUMN IF NOT EXISTS quest_group VARCHAR(32) DEFAULT NULL AFTER repeat_cooldown,
    ADD COLUMN IF NOT EXISTS quest_tier  TINYINT UNSIGNED DEFAULT NULL AFTER quest_group;

CREATE INDEX IF NOT EXISTS idx_quest_group ON quests (quest_group, quest_tier);

-- ── bait_types.min_level ──────────────────────────────────
-- Same gap: magnet_migration.sql inserts the Magnet bait with a min_level of 8
-- ("Level 8+" in its own description), but no committed migration adds the
-- column. Without it that insert fails and the level gate is lost.
ALTER TABLE bait_types
    ADD COLUMN IF NOT EXISTS min_level INT UNSIGNED NOT NULL DEFAULT 1 AFTER rarity_mod;
