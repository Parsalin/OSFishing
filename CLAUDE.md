# OSFishing — OpenSim Fishing Game

A multiplayer fishing game for OpenSim grids. Server-authoritative gameplay with LSL clients, web portal for inventory/quests/leaderboards, and full multi-grid support.

Live at: https://osfishing.flamesfall.net/ (served at the domain root, no subpath)
Previous host, now dead: https://sp.wa.darkheartsos.net/fishing/
Primary grid: Darkhearts Playground (admin: Matthew Stevenson, player_id 1)
Player base migrated from the old server 2026-09-14 — 32 players with full
history/inventory. See `deploy/old-server-capture/MIGRATION.md`.
Secondary approved grid: #1337 Fresh - MetaVerse

## Architecture

### Server stack
The old host (`sp.wa.darkheartsos.net`, user `spuser2`, `/var/www/html/fishing/`,
DB `fishing_game`/`fishing_user`) is gone. Any of those names in docs or scripts is
stale by definition. Full details: `deploy/SERVER-HANDOFF.md`.

- VPS `flamesfall.net` (InterServer, Debian 13), shared with Fertility but fully
  isolated (own Unix user, SSH key, FPM pool, DB). Never reach into `/var/www/fertility`.
- Nginx + **PHP 8.4-FPM** (pool `osfishing`, log `/var/log/php8.4-fpm-osfishing.log`) + **MariaDB**
- SSH: `ssh -i ~/.ssh/osfishing_deploy osfishing@flamesfall.net "<command>"` (key only)
- Root-level work (nginx, /etc, packages) that `osfishing` can't do — it may
  only `sudo systemctl reload php8.4-fpm`: use Claude's sudo account,
  `ssh -i ~/.ssh/flamesfall_claude claude@flamesfall.net "sudo -n <command>"`.
  Use it only when `osfishing` can't do the job. Routing snippet changes:
  `sudo bash /var/www/osfishing/repo/deploy/nginx/install-snippet.sh`.
- App root `/var/www/osfishing/`; repo checkout `/var/www/osfishing/repo/`;
  live tree `/var/www/osfishing/web/` (docroot `web/public/`)
- Database `osfishing`, user `osfishing`; password in `/home/osfishing/secrets/db.txt`
  and the server-only `web/config.php`. Never hardcode it.
- **The host and MariaDB run on America/New_York.** The app runs in UTC (see rules).
- Code layout (live tree):
  - `public/api/index.php` — single-entry router for all API requests
  - `includes/` — class files; `includes/bootstrap.php` is loaded first by everything
  - `config.php` — DB connection + constants (gitignored, server-only)
  - `public/index.html` — SPA portal; `public/admin/index.html` — admin panel
  - `public/pair.php` — auto-pair landing; `public/recover.php` — recovery login link
  - `bin/migrate.php`, `bin/find_account.php` — CLI tools

### Deploy
```bash
git push
ssh -i ~/.ssh/osfishing_deploy osfishing@flamesfall.net 'bash /var/www/osfishing/repo/deploy/deploy.sh main'
```
`deploy.sh` `php -l`s the repo tree and runs its `bin/migrate.php` (live
config via a gitignored symlink) BEFORE syncing, then syncs the whole tree
(never copy single files) and reloads PHP-FPM. OPcache revalidates
timestamps, so the sync is what puts code live; a failed check or migration
stops the deploy with the old code still serving. Migrations therefore run
while the old code is live: they must keep it working (add now, drop in a
later deploy). Schema changes are only ever made by a new file in
`web/migrations/versioned/` (PHP returning `function (PDO $pdo): void`, named
`YYYY_MM_DD_NN_what.php`, idempotent) — never by hand on the server.
`php bin/migrate.php --status` lists pending ones.

**Before every commit — changelog check:** if the change alters anything a player
can see or do (HUD, website, mechanics, numbers, messages, fixes they'd notice), add
an entry to `web/includes/Changelog.php` **in the same commit**. Entries start hidden
until an admin publishes them on the portal's What's New page. Skip only admin-only,
internal, refactor or docs-only commits, and say so when reporting the commit. Plain
player language; never spoil a discovery; never rename or reuse a shipped `id`.

### Trust model
- Server is authoritative for all game decisions: catch outcomes, XP, currency, item rolls
- HUD authenticates by SHA-256 of `uuid:timestamp:nonce:action:token` (not an HMAC) with replay-protection nonces
- Every API call signs `uuid:timestamp:nonce:action:token` with the per-pairing token
- Tokens issued via 6-digit pairing codes claimed on the website

## Primary grid / test environment

- **Grid name**: Darkhearts Playground (nick: DHPG)
- **Login / Gatekeeper URI**: `http://playground.darkheartsos.com:8002/`
- **Simulator**: OpenSim-NGC Tranquillity 0.9.3.9333 (X64/Unix/DotNet)
- **Simulator hostname**: `regions03-2.wa.darkheartsos.net`
- **Physics engine**: BulletSim
- **Primary test region**: Hidden Seashells (estate ID 136), region corner `<247296, 247808, 0>`
- **Water height in test region**: 20 m
- **Region max prims**: 30 000 | **Agent limit**: 40
- **Script memory**: 1 MB per script
- **OSSL**: core enabled; `osGetRegionStats`, `osGetAvatarList` confirmed available; `osTeleportAgent` / NPC / media / terrain not probed
- **Matthew's avatar UUID (Darkhearts Playground)**: `9a9304c2-620d-496a-ba50-2bf45cf8dbd9`
- **Matthew's avatar UUID (grid used to register on flamesfall, 2026-09-14)**:
  `6c87720f-3285-45db-b02c-f4bec40ae5b2` — the account's primary avatar
  (`players.uuid`). The Darkhearts avatar `9a9304c2…` is not linked yet: pair a
  HUD on it while logged in and confirm the link. It must be linked before it can
  set up an admin object (the owner must be an avatar linked to an admin account).

> **Avatar UUIDs are per-grid.** An account can now hold several avatars
> (`player_uuids`) — see Account & Avatar Model.

## Account & Avatar Model

- **One account, many avatars.** `player_uuids` maps each avatar UUID to an
  account; a UUID belongs to at most one account. A hypergrid visitor keeps their
  UUID, so that is still one avatar. `players.uuid` is only the primary avatar.
- **Never look a player up by `players.uuid`.** Go through `player_uuids`
  (`PairingAuth::playerIdForUuid`, or `WHERE id = (SELECT pu.player_id FROM
  player_uuids pu WHERE pu.uuid = :u)`). A `players.uuid` match silently misses
  every linked avatar.
- **Linking** happens only by claiming a pairing code while logged in. A code from
  an avatar on no account returns `needs_confirm` first; the player must confirm
  "add this avatar to my account" (pair.php page or the portal's confirm step).
  An avatar on another account is refused. Removing an avatar revokes its HUDs;
  the last avatar can't be removed.
- `hud_tokens.avatar_uuid` binds a token to the avatar that paired it;
  `requireHUD` returns `uuid` = that avatar and `account_uuid` = the primary.
- **Finding an account (always do this first).** Whenever someone names a
  player by name, username or any UUID, resolve it before anything else — never
  guess a UUID or run ad hoc `LIKE` queries:
  `ssh -i ~/.ssh/osfishing_deploy osfishing@flamesfall.net "cd /var/www/osfishing/web && php bin/find_account.php <query>"`.
  Same lookup as the admin Players search (`Admin::findAccount`). Use the printed
  `player_id` for all game-data queries.
- **Never open a recovery link** (`/recover.php?token=…`) to test it: opening
  it uses it up. Login links are only ever issued by a recovery terminal; never
  add an API action that mints one for a UUID.

## Admin Objects & Account Recovery

`includes/AdminObjects.php`, admin panel **Objects** tab, ported from Fertility.

- **Pairing:** on reset the object calls `aobj_pair_request` (owner must be an
  avatar linked to an admin account) and opens `/admin/?tab=objects&approve=<code>`
  for its owner; an admin approves there (15 min). The object polls
  `aobj_pair_status`; the first poll after approval collects its token **once**.
  Only `sha256(token)` is stored; the token lives in script memory only, and
  `on_rez`/`CHANGED_OWNER` reset the script. Re-pairing an object revokes its old row.
- **Every request must match:** token; `X-SecondLife-Object-Key` (the approved
  object); owner (= `uuid` = `X-SecondLife-Owner-Key`) still an admin;
  `REMOTE_ADDR` equal to the approved address (never a forwarded header — there
  is no proxy); its grid identity; the type's `ALLOWED` actions. Wrong object /
  owner / demoted owner → revoked at once + alert. New address → refused
  (`address_changed`), one alert, admin clicks **Accept new address**. Disallowed
  action / wrong grid → refused + alert (once an hour per reason).
- **Why the address:** OpenSim saves script memory inside a taken copy, and a
  grid owner can read it from their database; headers are just headers.
- **Recovery (`aobj_recovery`):** only for an avatar linked on the terminal's
  own grid (`player_uuids.grid_identity`) — a hypergrid visitor could be a
  look-alike minted with someone's UUID. Not banned; one link per account per
  10 min. Returns `/recover.php?token=…` (15 min, once), opened with `llLoadURL`
  so only the toucher sees it. That session may change the password without the
  old one, once; a password change ends every other session.
- Terminals check in (`aobj_hello`) every 30 min; `recovery_terminals` lists
  those seen in 48 h on the login page with hop links.

## In-world objects

### LSL scripts
- **FishingHUD** — main player HUD, worn on HUD Bottom. A minimal bar: bait chip (count above it; tap = Tackle), OSFishing logo (tap = media panel with the website embed), FISH / STOP. Fight strip above the bar (tension meter + fish showing which way it runs). The panel's optional live **fishing view** is served by the HUD itself (`GET <hud url>/fish` shell → `web/public/hud/fishview.js`, which polls `GET <hud url>/s`). Built with `lsl/Extra Tools/FishingHUD_Builder.lsl`; layout, textures and sheet cells in `lsl/Textures/HUD/BUILD.md` (textures from `make_textures.py`).
  **`HUD_VERSION` auto-increments** (`YYYY.MM.DD.NN`): `.claude/bump_version.ps1`, a PostToolUse hook in `.claude/settings.json`, bumps it after every Edit/Write to `FishingHUD.lsl`. Never edit it by hand. The HUD sends it with every `hud_status`; it's stored in `hud_tokens.hud_version` (NULL = a HUD from before versioning) and shown per HUD under Settings → devices.
- **FishingRod** — held attachment, plays cast/idle animations. Has `Line_Emitter` child for future particle line.
- **FishingBobber** — temp-rezzed object, sits in water during cast/landing/fight.
- **FishingSpot** — placed in regions where fish are. Has a setup wizard (water type, name, public/private), maintains state in LinksetData, registers callback URL with server for buff push notifications.
  **Click** = target (`SPOT|…` to the toucher, the HUD fishes there). **Long press (1 s+, anyone)** = `llRegionSay(-7710005, "SPOT_OPEN|<spot id>|<toucher>")`; the toucher's HUD opens the portal `spot` page (`web_spot_detail`) in its panel: location + hop link, level/XP, buffs (use one via `web_buff_activate`), stats, recent catches, junk, and "you have caught these fish here" (every species of the water type, `??` until the viewer has caught it there — names stripped server-side); owner/admin also get settings (`web_spot_settings`, level up, remove junk, delete; Add junk = `web_spot_junk_prompt`, a live-only `junk_prompt` push so the prim asks for an object from its inventory). Every web change pushes `spot_refresh` (or `spot_removed`) to the prim, which re-reads `spot_status`. Owner chat **`/spot menu`** near the spot = the old in-prim menu (no-HUD fallback). Spot pushes are `{"type": …, "buff_label": …}`.
- **BaitGatherPoint** — interactable in-world object that produces bait per gather attempt.
- **LeaderboardDisplay** — shows top 10 by category, rotates every few seconds.
- **TournamentBoard** — shows a tournament's standings (schema fixed 2026-11-19, migration 10).
- **PrepTable** — sit-to-use, prep fish for parts and craft special baits.
- **BaitVendor** — buyable bait (system-supplied) with cooldowns, price tags.
- **ShopRegister** — placed by players to register a "shop" location at their parcel.
- **AccountRecoveryTerminal** — admin object (see Admin Objects). Touch for a one-time login link that lets you set a new password. Replaced PasswordReset, whose API action reset any player's password with no auth.
- **TrophyPlaque** + **TrophyText** + **TrophyFish** — in-world trophy display, plaque rezzes a fish mesh at calibrated scale and rotation, with name/weight/rarity on a floating text plaque.

### Tutorial child prims (in HUD)
- **TutorialText** — scriptless, named prim right of the bar. Root sets PRIM_TEXT directly via `llSetLinkPrimitiveParamsFast`.
- **TutorialPointer** — scriptless, named prim: a yellow ring the root sizes and moves over the button to press (pages behind the logo point at the logo, Tackle at the bait chip); root detects clicks via `llDetectedLinkNumber` in its existing touch_start.

### Channels
- HUD↔Rod: -7710001/-7710002
- HUD↔Bobber: -7710003/-7710004
- Spot→HUD: -7710005
- Gather/Butcher: -7710006
- Tournament: -7710007
- Trophy: Fish→Plaque -7720001 (FISH_READY), Plaque→Fish -7720002 (FISH_SCALE)

## Database (key tables)

### Player
- `players` — uuid (primary avatar), username, password_hash, display_name, level, xp, fishing_points, equipped_rod/bait/line, home_grid (a grid identity), tutorial_completed, tutorial_step, spot_limit_override
- `player_uuids` — every avatar on an account: uuid (UNIQUE), player_id, grid_identity (grid it plays from, set on link or first HUD use), avatar_name, linked_at, last_used_at
- `player_quests` — accepted quests with status and progress

### Fishing
- `fish_species` — name, water_type, rarity_id, base_xp_per_lb, base_value_per_lb, weight_min/max, etc.
- `rarity_tiers` — common (8 XP, 50% catch weight), uncommon (20, 25%), rare (55, 10%), epic (150, 3%), legendary (500, 0.5%)
- `water_types` — pond (lvl 1+), river (3+), lake (5+), ocean (10+)
- `bait_types` — earthworms, crickets, leeches, shrimp, glow grubs, magnets, etc.
- `bait_affinity` — fish×bait catch-rate and rarity-mod modifiers (350+ entries)
- `line_types` — twine through titanium leader, 7 tiers
- `catch_log` — full catch history, indexed by player, spot, fish, time
- `player_fish` — held inventory, separate `status='saved'` for trophies
- `fishing_casts` — the server's record of each cast (last 100 per player).
  `cast` opens one; roll_minute / confirm_catch / line_break / reel_in must
  match the open cast, which fixes spot, bait, line and power. Catch tokens
  carry the cast id and bite time; closing the cast makes them single-use.
  Bait use is decided only here, from the server's own times
- `fishing_spots` — owner, water type, region, pos, public/system flags, archived flag, archived_at

### Spots & ownership
- `fishing_spots` — players own spots, count toward limit (active+non-archived only). Spots can be system-owned.
- Archive instead of delete: `archived=1, archived_at=UTC_TIMESTAMP(), is_active=0` — preserves catch_log/leaderboard references
- Restoration: rezzing a fresh prim in same region offers archive recovery dialog
- Spot_limit_override per player allows admins to grant extra slots

### Multi-grid
- `grids` — one row per SIM HOST: grid_name (host:port as scripts report it), grid_identity (base domain — the grid), status (pending/approved/denied), nickname, hop_gateway (e.g., "playground.darkheartsos.com:8002"), notes, no_http_in, public_host, push_problem, push_checked_at
- `hud_tokens` — per-grid pairing tokens (player_id, avatar_uuid, grid_name, token, last_nonce, callback_url, no_http_in, is_active, revoked_at)
- `hud_pair_attempts` — audit log of every pair_request (UUID, grid, IP, succeeded)
- `hud_exclusion_triggers` — log when 60s mutual-exclusion lockout fires (player_id, triggered_uuid, blocking_uuid)

### Server push
- `prim_callbacks` — prim_uuid, prim_type, ref_id, callback_url, region, grid, last_seen
- A prim silent >90 min gets no pushes (they queue until its next heartbeat); rows are forgotten after 7 days. Spots are never switched off by the sweep. Registering (`register_spot_callback`, what FishingSpot sends) needs the prim's own object-key header and an owner who owns the spot
- Buffs pushed to spot via callback URL; HUD also has callback URL for tutorial events

### Admin & housekeeping
- `schema_migrations` — applied `web/migrations/versioned/` files
- `admin_alerts` — shared admin inbox (Alerts tab); `AdminAlerts::send()` with optional dedupe key + window
- `logic_error_log` — silent self-repairs / fail-closed checks (Logic Log tab)
- `admin_objects` / `admin_object_log` — approved in-world admin objects and their activity
- `login_tokens` — one-time recovery login links (sha256 only); `web_sessions.recovery_until`
- `changelog_published` / `changelog_seen` — What's New publish state and per-player last visit

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
- XP from rarity (Common 8, Uncommon 20, Rare 55, Epic 150, Legendary 500, per `rarity_tiers.base_xp`) plus a weight bonus
- Daily/weekly quests for bonus XP
- Levels gate water types: pond→river (3)→lake (5)→ocean (10)

## Recent major work

- 2026-11-19: Fertility's grid/admin rules ported — UTC everywhere + migration
  runner, admin alerts + logic log, grid identity / push checks, linked avatars,
  admin objects + recovery terminal (open password reset removed), sim-origin
  check, changelog
- Multi-grid pairing with per-grid tokens
- 60s mutual exclusion lockout (one HUD active across all grids)
- Hard kill on revoke vs soft deactivation
- Audit log of pair attempts and exclusion triggers (visible in admin)
- Tutorial system with TutorialText + TutorialPointer child prims
- Auto-pair URL with `/pair?uuid=X&code=Y` landing page
- Spot archive instead of delete, with recovery flow
- Map: pan/zoom/drag, hop:// URLs, home grid setting
- Heartbeat system: 30 min interval; a prim silent ~90 min stops getting pushes until it checks in again
- Bar visibility: only XP/Power/Tension shown when relevant
- Inactive/archived spots don't count toward player's spot limit

## Repository

- GitHub: https://github.com/Parsalin/OSFishing
- Branch: `main`
- Local path: `C:\Users\Matthew\Projects\OSFishing`

## Critical rules & gotchas (server)

- **All times are UTC.** `includes/bootstrap.php` (loaded by every entry point
  and class — never require `config.php` directly) sets PHP to UTC and the DB
  session to `+00:00`. Still write `UTC_TIMESTAMP()`/`UTC_DATE()` in SQL (never
  `NOW()`/`CURDATE()`) and `gmdate()` in PHP (never `date()`), so nothing depends
  on the session setting. The host and MariaDB are on America/New_York, so a
  script that skips bootstrap would silently write local time. New DATETIME
  columns use `DEFAULT UTC_TIMESTAMP()`. The website shows times in the viewer's
  zone (`fmtTime`/`fmtDay` in the portal and admin JS); API values stay UTC.
  Migration 02 converted all stored times on 2026-11-19.
- **Grid identity is the base domain.** Scripts report the sim hostname
  (`regions03-2.wa.darkheartsos.net:8002`); one grid runs many. `Grid::identityOf()`
  gives the grid (full host for shared hosting like `outworldz.net`, IPs and
  dotless names). Compare grids only with `Grid::sameGridSql()`, never raw
  `grid_name` equality. Any code that stores a new `grid_name` must call
  `Grid::findOrRegister()` so the host exists in `grids`.
- **Approval is per grid.** A new host of an approved/denied grid inherits that
  decision (+ nickname, hop gateway) and the admins get an alert; an unknown grid
  is pending and alerts once a day. Pending/denied grids can't pair or set up spots.
- **"Same region" means same region name AND same grid.** Region names repeat
  across grids. Every region-based query must also match the grid.
- **Pushes:** all go through `PushQueue::post`, which swaps in the grid's
  `public_host` for sims that report a private address. `Grid::checkPushLater()`
  runs after the reply whenever a HUD/prim registers a callback URL and flags
  `push_problem` (private / unreachable), finding a public host itself when it
  can. A HUD denied HTTP-in marks its grid `no_http_in` (first time alerts;
  Clear on the Grids tab re-arms it). Push payload `{"type":"ping"}` is the
  harmless test push every script ignores.
- **One collation: `utf8mb4_unicode_ci`.** Comparing text columns of two
  collations is an error (`Illegal mix of collations`), not a slow query —
  it broke the Grids tab and every same-grid check until migration 08 unified
  all tables on 2026-11-19. This MariaDB creates new tables as
  `utf8mb4_uca1400_ai_ci` even though the database default says `unicode_ci`,
  so **every `CREATE TABLE` must state `DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci`**, and a new text column on an existing table
  inherits the table's.
- **HUD requests must carry `X-SecondLife-Owner-Key`** (absent → `not_from_a_sim`,
  mismatch → `owner_mismatch`). curl tests against HUD actions must send it.
- **SimOrigin (log-only):** `pair_request`, `check_account`, `aobj_pair_request`,
  `spot_register`, `shop_register`, `gather_register` (when it sends a grid)
  check the connecting address against the sim hostname's DNS. Failures →
  `logic_error_log` `sim_origin_mismatch` + one alert per grid per day. Flip
  `SimOrigin::ENFORCE` once the log shows no real grid failing.
- **LSL replies are cut at 2,048 bytes** unless the request sets
  `HTTP_BODY_MAXLENGTH`; a cut-off JSON reply is lost whole and silently. Keep
  `/api` replies to in-world scripts small (hud_status already caps queued pushes at 4).
- **Admin alerts** (`AdminAlerts::send`) are for things an admin should act on.
  **Logic Log** (`LogicErrorLog::log`) is only for self-repairs and fail-closed
  checks, never normal gameplay. Categories in use: `sim_origin_mismatch`,
  `inworld_unverified` (InWorldAuth header checks, log-only; audit item 1),
  `catch_refused` (confirm_catch refused: replayed token, landed before the
  bite, cast closed or too old), `cast_wrong_region` (a cast from a region or
  grid other than the spot's, per the sim's `X-SecondLife-Region` header;
  log-only, audit item 38).
  Add a row here when adding a category. Neither helper ever throws.

## Repo layout

```
OSFishing/
├── config.example.php   — template; real config.php lives on the server, never committed
├── deploy/              — deploy script, nginx config, server handoff doc
│   ├── deploy.sh        — run on the server; pulls a branch, rsyncs, migrates, reloads
│   └── nginx/           — vhost + routing snippet (install as root)
├── lsl/                 — all in-world LSL scripts (*.lsl)
└── web/
    ├── config.php       — DB credentials + HUD_SECRET (gitignored, server-only)
    ├── bin/             — CLI: migrate.php, find_account.php (not web-served)
    ├── includes/        — PHP class files (bootstrap.php first)
    ├── migrations/      — schema.sql (baseline), versioned/ (applied by migrate.php),
    │                      historical *_migration.sql
    └── public/          — THE DOCROOT — only this directory is web-served
        ├── index.html   — web portal SPA
        ├── api/index.php— single-entry API router
        ├── admin/       — admin panel
        ├── hud/         — fishview.js (HUD media panel's live fishing view)
        ├── pair.php     — auto-pair / link-avatar landing page
        ├── recover.php  — one-time recovery login link
        └── register.php — sign-up (reached from the HUD's pair link)
```

**Only `web/public/` is served.** `config.php`, `includes/` and `migrations/`
sit one level above the docroot, so they cannot be requested over HTTP no
matter how nginx is configured. Anything added outside `public/` is private by
construction; anything added inside it is public. There is no `.htaccess` —
nginx ignores those; routing lives in `deploy/nginx/`.

## Best practices and audit

`docs/BEST_PRACTICES.md` is the general rule list learned across Fertility and
OSFishing (trust, time/schema, pushes, deploy, LSL, UI, working practice).
Follow it. `docs/AUDIT.md` is the current worklist of places OSFishing doesn't
yet (2026-11-19 scan); tick items off there as they're fixed.

## LSL / grid gotchas

Full set in `docs/GRID_COMMS_LESSONS.md` (from the sibling Fertility project).
The ones that bite hardest:

- **A denied OSSL call HALTS the script** — it does not return empty. In
  `state_entry` that bricks the prim on rez with no retry path, and LSL has no
  try/catch. Never call `osGetGridName()`/`osGetGridLoginURI()`; use the
  `detectGrid()` hostname helper. Where an OSSL call is unavoidable
  (`osSetDynamicTextureData*`), put every `llListen`/`llSetTimerEvent`/fetch
  **before** it in `state_entry`.
- **`llJsonGetValue` returns `JSON_TRUE`, not `"true"`**, for a JSON boolean —
  and fails silently on large/deep JSON. Prefer the HTTP status code for
  success; test presence with `!= JSON_INVALID`.
- **`gApiUrl` is effectively permanent** — HUDs in players' hands cannot be
  updated remotely. Never compile an in-world object against a temporary URL.
- **`llRegionSayTo(avatarKey, ...)` does not reach scripts**, only the viewer's
  chat. Use an object key, or have the object announce itself with `llSay`.
- **`llGetNumberOfAgents` does not exist** in OpenSim; use `llGetAgentList`.
- Ports: stay on 443. Arbitrary high ports are often blocked by sim outbound
  firewalls on grids you don't control, and the failure is silent.

## Conventions

- LSL scripts: `lsl/*.lsl`
- PHP classes: `web/includes/*.php`
- API router: `web/public/api/index.php`
- Web-servable files: `web/public/` only
- Database: build from `web/migrations/schema.sql`, then `php bin/migrate.php`.
  The loose `*_migration.sql` files are history and do not all replay cleanly;
  new schema changes go in `web/migrations/versioned/` only
- config.php is gitignored — edit `config.example.php` to document new constants
- Never read `~\Projects\Fertility\CLAUDE.md` whole (about 1,500 lines). Search
  it with Grep, or Read only the line range you need.

## Git workflow

After making changes, always:
1. `git add` the relevant files
2. `git commit` with a clear message describing what changed and why
3. `git push` to origin/main

## TODO / parked

- Camera revisit (move + set; or move-then-release; or chat command toggle)
- Big Mouth Billy Bass (animatronic wall fish)
- **Unauthenticated in-world endpoints trust a `uuid` param** (audit item 1).
  Step 1 done: `InWorldAuth` header cross-checks, log-only (Logic Log
  `inworld_unverified`); flip `InWorldAuth::ENFORCE` once no real object
  fails. Steps 2–3 (player actions through the HUD, object tokens) are
  designed in `docs/AUDIT.md` and need a decision + in-world testing.
- Tournaments have no admin UI yet (API: `admin_tournament_create/end/delete`).
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
