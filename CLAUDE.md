# OSFishing — OpenSim Fishing Game

A multiplayer fishing game for OpenSim grids. Server-authoritative gameplay with LSL clients, web portal for inventory/quests/leaderboards, and full multi-grid support.

Live at: https://sp.wa.darkheartsos.net/fishing/
Primary grid: Darkhearts Playground (admin: Matthew Stevenson, player_id 1)
Secondary approved grid: #1337 Fresh - MetaVerse

## Architecture

### Server stack
- Ubuntu, user `spuser2`, Nginx + PHP 8.3 + MySQL
- Web root: `/var/www/html/fishing/`
- Database: `fishing_game`, user `fishing_user`
- Code layout:
  - `/var/www/html/fishing/api/index.php` — single-entry router for all API requests
  - `/var/www/html/fishing/includes/` — class files (Auth, Player, Fishing, Quest, etc.)
  - `/var/www/html/fishing/config.php` — DB connection
  - `/var/www/html/fishing/index.html` (or `fishing-portal.html`) — SPA web portal
  - `/var/www/html/fishing/admin.html` — admin panel
  - `/var/www/html/fishing/pair.php` — auto-pair landing page

### Trust model
- Server is authoritative for all game decisions: catch outcomes, XP, currency, item rolls
- HUD authenticates via HMAC-SHA256 signed requests with replay-protection nonces
- Every API call signs `uuid:timestamp:nonce:action:token` with the per-pairing token
- Tokens issued via 6-digit pairing codes claimed on the website

## In-world objects

### LSL scripts
- **FishingHUD** — main player HUD, attached to screen. Five buttons: tackle, fish inventory, play, quests, shop.
- **FishingRod** — held attachment, plays cast/idle animations. Has `Line_Emitter` child for future particle line.
- **FishingBobber** — temp-rezzed object, sits in water during cast/landing/fight.
- **FishingSpot** — placed in regions where fish are. Has a setup wizard (water type, name, public/private), maintains state in LinksetData, registers callback URL with server for buff push notifications.
- **BaitGatherPoint** — interactable in-world object that produces bait per gather attempt.
- **LeaderboardDisplay** — shows top 10 by category, rotates every few seconds.
- **TournamentBoard** — tracks tournament submissions (currently broken — schema mismatch).
- **ButcherTable** — sit-to-use, butcher fish for parts and craft special baits.
- **BaitVendor** — buyable bait (system-supplied) with cooldowns, price tags.
- **ShopRegister** — placed by players to register a "shop" location at their parcel.
- **PasswordReset** — UUID-verified in-world kiosk for password reset.
- **TrophyPlaque** + **TrophyText** + **TrophyFish** — in-world trophy display, plaque rezzes a fish mesh at calibrated scale and rotation, with name/weight/rarity on a floating text plaque.

### Tutorial child prims (in HUD)
- **TutorialText** — scriptless, named prim. Root sets PRIM_TEXT directly via `llSetLinkPrimitiveParamsFast`.
- **TutorialPointer** — scriptless, named prim. Root positions/colors it; root detects clicks via `llDetectedLinkNumber` in its existing touch_start.

### Channels
- HUD↔Rod: -7710001/-7710002
- HUD↔Bobber: -7710003/-7710004
- Spot→HUD: -7710005
- Gather/Butcher: -7710006
- Tournament: -7710007
- Trophy: Fish→Plaque -7720001 (FISH_READY), Plaque→Fish -7720002 (FISH_SCALE)

## Database (key tables)

### Player
- `players` — uuid, username, password_hash, display_name, level, xp, fishing_points, equipped_rod/bait/line, home_grid, tutorial_completed, tutorial_step, spot_limit_override
- `player_quests` — accepted quests with status and progress

### Fishing
- `fish_species` — name, water_type, rarity_id, base_xp_per_lb, base_value_per_lb, weight_min/max, etc.
- `rarity_tiers` — common (5 XP, 50% catch weight), uncommon (15, 25%), rare (40, 10%), epic (100, 3%), legendary (300, 0.5%)
- `water_types` — pond (lvl 1+), river (3+), lake (5+), ocean (10+)
- `bait_types` — earthworms, crickets, leeches, shrimp, glow grubs, magnets, etc.
- `bait_affinity` — fish×bait catch-rate and rarity-mod modifiers (350+ entries)
- `line_types` — twine through titanium leader, 7 tiers
- `catch_log` — full catch history, indexed by player, spot, fish, time
- `player_fish` — held inventory, separate `status='saved'` for trophies
- `fishing_spots` — owner, water type, region, pos, public/system flags, archived flag, archived_at

### Spots & ownership
- `fishing_spots` — players own spots, count toward limit (active+non-archived only). Spots can be system-owned.
- Archive instead of delete: `archived=1, archived_at=NOW(), is_active=0` — preserves catch_log/leaderboard references
- Restoration: rezzing a fresh prim in same region offers archive recovery dialog
- Spot_limit_override per player allows admins to grant extra slots

### Multi-grid
- `grids` — name, status (pending/approved/denied), nickname, hop_gateway (e.g., "playground.darkheartsos.com:8002")
- `hud_tokens` — per-grid pairing tokens (player_id, grid_name, token, last_nonce, callback_url, is_active, revoked_at)
- `hud_pair_attempts` — audit log of every pair_request (UUID, grid, IP, succeeded)
- `hud_exclusion_triggers` — log when 60s mutual-exclusion lockout fires (player_id, triggered_uuid, blocking_uuid)

### Server push
- `prim_callbacks` — prim_uuid, prim_type, ref_id, callback_url, region, grid, last_seen
- Stale callbacks (>90 min no heartbeat) get swept; fishing spots auto-deactivated
- Buffs pushed to spot via callback URL; HUD also has callback URL for tutorial events

### Tutorials, trophies, etc.
- `player_saved_fish` — separate trophy collection (referenced by status='saved' in player_fish)
- `tutorial_completed`, `tutorial_step` columns on `players`

## Game features

### Quests
- One-time quests (12 original)
- 20 tiered daily quests across 4 groups (5 tiers each): daily_catch, big_fish, rare_haul, bait_collector
- 4 weekly quests
- Group-aware repeatables: accepting any tier of a daily blocks all other tiers in same group

### Buffs
- 9 types: Chum (+25% bite), Lure Oil (+15% rarity), Growth Tonic (+20% weight), Angler's Blessing (+25% XP), Treasure Scent (+50% junk), Calm Waters (-20% fight), Bait Saver (25% save), Double Hook (30% double), Golden Hour (+10% all)
- Stack additively, 2hr cap per type
- Server pushes `buff_active` and `buff_expired` to fishing spot's callback URL
- Local cache on spot side (no polling)

### Lines (7 tiers)
- Twine (2.5lb, L0, free) → Titanium Leader (100lb, L20, 6000 pts)

### Junk side-catches
- Each spot has a junk_items inventory of physical objects
- Junk caught only on Treasure Scent buff active OR junk-only fights (deadweight, magnet trap)

### Trophies
- Save fish from inventory → trophy collection (separate from sellable inventory)
- Rez TrophyPlaque, touch to pick a saved fish to display
- Rezzes fish mesh at interpolated scale, rarity-tinted lighting
- Add notes, return trophy to inventory anytime

### Leveling
- XP from rarity (Common 5, Uncommon 15, Rare 40, Epic 100, Legendary 300)
- Daily/weekly quests for bonus XP
- Levels gate water types: pond→river (3)→lake (5)→ocean (10)

## Recent major work

- Multi-grid pairing with per-grid tokens
- 60s mutual exclusion lockout (one HUD active across all grids)
- Hard kill on revoke vs soft deactivation
- Audit log of pair attempts and exclusion triggers (visible in admin)
- Tutorial system with TutorialText + TutorialPointer child prims
- Auto-pair URL with `/fishing/pair?uuid=X&code=Y` landing page
- Spot archive instead of delete, with recovery flow
- Map: pan/zoom/drag, hop:// URLs, home grid setting
- Heartbeat system: 30 min interval, server marks inactive after ~90 min silence
- Bar visibility: only XP/Power/Tension shown when relevant
- Inactive/archived spots don't count toward player's spot limit

## Repository

- GitHub: https://github.com/Parsalin/OSFishing
- Branch: `main`
- Local path: `C:\Users\Matthew\Projects\OSFishing`

## Repo layout

```
OSFishing/
├── config.example.php   — template; real config.php lives on the server, never committed
├── lsl/                 — all in-world LSL scripts (*.lsl)
└── web/                 — server-side PHP/HTML/SQL, mirrors /var/www/html/fishing/ on the server
    ├── api/index.php    — single-entry API router
    ├── includes/        — PHP class files
    ├── migrations/      — SQL migration files (*_migration.sql)
    ├── index.html       — web portal SPA
    ├── admin/           — admin panel
    ├── pair.php         — auto-pair landing page
    ├── register.php     — player registration
    └── setup.php        — setup flow
```

## Conventions

- LSL scripts: `lsl/*.lsl`
- PHP classes: `web/includes/*.php`
- API router: `web/api/index.php`
- SQL migrations: `web/migrations/*_migration.sql`
- config.php is gitignored — edit `config.example.php` to document new constants

## Git workflow

After making changes, always:
1. `git add` the relevant files
2. `git commit` with a clear message describing what changed and why
3. `git push` to origin/main

## TODO / parked

- Camera revisit (move + set; or move-then-release; or chat command toggle)
- Big Mouth Billy Bass (animatronic wall fish)
- Tournament.php schema mismatch fix
- World events (admin-toggle double XP, rare spawns)
- More quests (seasonal, chains, achievements)
- Hover popup on map dots with hop links inline
- Loot table for gather points (multiple bait types per spot)
- Particle effects in priority order:
  - Line emitter (rod-tip → bobber via PSYS_PART_TARGET_POS_MASK)
  - Bobber landing splash
  - Ripple trail
  - Hookup splash
  - Fight struggle (looping)
  - Catch sparkle (scaled by rarity)
  - Line break debris
  - Subtle buff aura at spots
- Spot Watcher prim (auto-respawn missing spots) — parked
- Balance tuning

## User notes

- Matthew Stevenson — owner, admin, primary tester
- Builds for community across multiple grids
- Quick-iteration style: "continue" / "do it" common, hates preamble
- Strong design instincts; will spot bad UX immediately
- Honest pushback when work quality slips
- Cares about cheat-prevention (server-side decisions, never trust client)
- Distinguishes legitimate hypergrid travel from account sharing
- Wanted Big Mouth Billy Bass since day one
