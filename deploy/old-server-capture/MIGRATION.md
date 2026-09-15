# Player migration from the old server — 2026-09-14

Imported the full player base from the old `fishing_game` database (pulled via
`mysqldump` during the short window it was online) into the live `osfishing`
database. Raw dump: `oldfishing.sql` (not committed — contains password hashes).

## Result

- **32 players** imported (all of them). The live leaderboard now matches the
  old server exactly.
- **~4,900 rows** of history and inventory: 1589 held fish, 1589 catch-log
  entries, 101 bait stacks, 54 lines, 43 rods, 19 saved trophies.
- **Your account merged**: your new registration (id 1, uuid `6c87720f…`, admin)
  kept its uuid and admin flag and gained your old stats — level 9, 4561 XP,
  3197 points, 249 catches.
- Everything ran in one transaction against a pre-import backup
  (`/tmp/pre_import_backup.sql` on the server).

## Item / quest comparison (what you asked to see)

The reconstruction built from migrations matched the old server almost exactly:

| Table | Old | New (rebuilt) | Verdict |
|---|---|---|---|
| fish_species | 35 | 35 | **identical** — all 35 match by id + name |
| rod_types | 5 | 5 | identical |
| line_types | 7 | 7 | identical |
| rarity_tiers / water_types / levels / buff_items | = | = | identical |
| bait_types | 16 | 16 | one id shift: **Magnet was id 22 old, 16 new** |
| quests | 36 | 36 | ids 1–24 identical; dailies 29+ renumbered |
| quest_requirements | 51 | 52 | minor drift in the tiered dailies |
| quest_rewards | 63 | 65 | minor drift in the tiered dailies |
| **fish_bait_affinity** | **560** | **456** | **old is a strict superset — adopted** |

### The one real item change: fish_bait_affinity

The new table was a strict *subset* of the old (0 value differences, 0
new-only rows). The 104 missing rows were entirely the four special baits —
Shimmering Minnow, River Pearl, Deep Lake Grub, Abyssal Eye — which my
`butcher_migration.sql` rewrite had scoped too narrowly (by water type, ~7–10
fish each). The old server assigned them across ~26 fish each. **The import
replaced the affinity table with the old server's 560 rows** (magnet bait
remapped 22→16), which fixes that regression.

### Quest divergence — handled

Quests 1–24 map cleanly by id and title (the 12 one-time quests plus the first
daily tiers). Quests 29+ are the higher daily/weekly tiers, renumbered between
old and new (old ran to id 44, new to 40). Per decision, **player_quests were
imported only for quest_id ≤ 24** — preserving completed one-time achievements
— and the transient repeatable-daily acceptances (78 rows) were dropped. They
reset on cooldown anyway.

## Known follow-ups

- **Old UUIDs don't pair yet.** Each imported player has their old-grid uuid,
  so they pair from that grid. Your account is the exception: it holds the new
  flamesfall uuid, so your old Darkhearts uuid (`9a9304c2…`) returns
  `exists:false` until the alt-UUID table (TODO) is built.
- **Spots, gather points, shops were excluded** by request — remake in-world.
  `player_fish.spot_id` and `catch_log.spot_id` were set NULL on import, so held
  fish kept everything except their (now-gone) origin-spot link.
