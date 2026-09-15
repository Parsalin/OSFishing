# Old-server data capture (sp.wa.darkheartsos.net)

Captured 2026-09-14 during a short window when the old server was turned back on.
The old `fishing_game` database was **not** deleted after all — it is intact and
the API answers queries.

## What this is

These are raw JSON responses from the old server's **public (unauthenticated)**
API. They are the most that could be pulled without database credentials or a
logged-in session.

## The hard limit

The old server's API exposes almost no bulk data without auth. Player
inventories, password hashes, XP, quest progress, catch logs, and the reference
tables (fish/bait/line/quest definitions) are all behind `requireHUD`,
`requireWeb`, or `requireAdmin` and could **not** be reached this way. A full
migration still needs a `mysqldump` or SSH access to that host — see the parent
task notes.

## What was recovered

**13 distinct players** (union of every leaderboard). Only the fields the
leaderboards return — no credentials, no inventories:

| Display name | Username | Level | Points | Catches | Best wt |
|---|---|---|---|---|---|
| User Name | 1337 | 10 | 4367 | 166 | 54.79 |
| Soleil Basset | Soleil | 10 | 3941 | 294 | 28.41 |
| Matthew Stevenson | MrStevenson | 9 | 3197 | 249 | 25.00 |
| Joseph Dalgarno | Joe_Cool | 9 | 2369 | 186 | 29.09 |
| Its Juna | Juna | 10 | 2300 | 279 | 54.48 |
| Kenna Matona | Kenna | 4 | 1136 | 49 | 9.79 |
| Nicole Marks | — | 4 | 830 | 34 | 8.07 |
| Casen Matona | Casen | 6 | 772 | 137 | 13.05 |
| Kevin Snowpaw | — | 2 | 603 | 21 | 5.66 |
| Carly Nuzzle | — | 2 | 475 | — | — |
| Emilico Donut | Emilico | — | — | 70 | 18.02 |
| Paula Young | PaulaYoung | — | — | — | — |
| Willy Matona | King_William | — | — | — | — |

(Usernames marked — did not appear in a leaderboard that returns username;
levels/points/catches marked — mean that player was outside the top 10 for
that metric. All of it is partial by nature.)

Fish species seen in catch records: Carp, Koi, River Sturgeon, Snapping Turtle,
Yellowfin Tuna. Regions: Coastal Seashells, Fuggle Forest,
Ground_Zero_X2000Y2002, Kings Landing, Vedema.

## Files

- `leaderboard.json` — top 10 biggest catches (display_name, username, level,
  fish, weight, caught_at, water_type, region, rarity)
- `leaderboard_points.json` — top 10 by fishing_points
- `leaderboard_catches.json` — top 10 by total_catches
- `leaderboard_biggest.json` — biggest catches with fish detail
- `leaderboard_rarest.json` — rarest-catch counts
- `web_spot_leaderboard.json` / `grid_map.json` — spot & region data (ignore per
  the task; kept only because they came free and name regions)
- `list_rods.json` — returned a DB error on the old server (schema drift)

Endpoints that returned HTTP 500 on the old server and so captured nothing:
`leaderboard_level`, `leaderboard_quests`, `leaderboard_records`, `tournaments`
— the same undefined-method bugs still open on the new server.
