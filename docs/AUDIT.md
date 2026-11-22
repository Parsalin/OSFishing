# OSFishing — audit worklist

Only what is still open. When an item is finished, tick it, add a
**Fixed YYYY-MM-DD:** note saying how, and move the whole item to
`docs/AUDIT_ARCHIVE.md` (same number, so commit messages still point
somewhere). A partly fixed item stays here with a **Partly fixed** note
saying what is left. In-world (LSL) fixes only reach players once the updated
objects are handed out.

## OSFishing — best-practices audit (2026-11-19)

### 🔴 High

- [ ] **1. In-world endpoints act as any player named in a `uuid` param** [BP 2]
  No token, no signature. A hand-made request can spend another player's
  points or wreck their things: `shop_buy`, `shop_sell`, `shop_deposit`,
  `shop_withdraw`, `shop_set_modifier`, `admin_shop_restock` (admin check is
  on the *claimed* uuid), `buff_activate`, `buff_buy`, `craft_item`,
  `butcher_fish`, `spot_delete`, `spot_update`, `spot_add_junk`,
  `spot_remove_junk`, `spot_level_up`, `spot_restore`, `trophy_for_plaque`,
  plus `spot_register`/`shop_register`/`gather_register` via `owner_key`.
  - [x] **Step 1 (done, log-only):** `InWorldAuth::check` runs for all 28
    actions (`INWORLD_UUID_ACTIONS` in the router). Owner actions need the
    sim's owner header to equal the uuid (or the uuid to be an admin); toucher
    actions need only come from a sim. Failures → Logic Log
    `inworld_unverified`. Turn on `InWorldAuth::ENFORCE` once the log shows no
    real object failing. Headers can be forged, so this only stops the naive
    request.
  - [ ] **Step 2: player actions move into the HUD.** An object can't submit
    a HUD-signed request for the toucher: `requireHUD` requires the sim's
    owner header to equal the signer, and an object's owner is the object's
    owner, not the toucher. Relaying replies through the HUD fails too
    (chat messages cap at 1,024 bytes; `butcher_list` is bigger). So:
    - Vendor touch → vendor `llRegionSayTo(toucher, …, "VENDOR|<listing_id>")`;
      the **HUD** shows the Buy/Sell/quantity dialogs and calls `shop_buy` /
      `shop_sell` signed. The vendor keeps polling `shop_listing_status`.
    - Fishing-spot buffs and the PrepTable (butcher, craft) → the HUD opens
      its media panel on a portal page (`web_buff_*`, `web_butcher_*` exist;
      add `web_craft_*`). The click on a page the player is logged into is the
      consent.
      **Spots: done in script (2026-11-19).** A long press sends `SPOT_OPEN` and
      the HUD opens the `spot` page; buffs go through `web_buff_activate`. The
      new FishingSpot no longer calls `buff_inventory` / `buff_activate`, but
      spots already rezzed still do, so those uuid branches stay until
      they're replaced.
    - Then the server drops the uuid branch of those actions.
    The cost: every deployed vendor, spot and PrepTable needs its new script,
    and the HUD needs a release. Needs in-world testing.
  - [ ] **Step 3: object tokens for owner actions** (spot settings, vendor
    stock, gather/shop registration), issued at register and bound like admin
    objects (object key, owner, address, grid). Replaces the header check.
  - [ ] Spot `DELIVER_JUNK` should be confirmed by the server (pending junk
    delivery recorded by `confirm_catch`, claimed once by the spot). Today the
    spot only checks the request comes from the recipient's HUD (item 9), so a
    scripted fake HUD could still farm a spot's junk items for itself.


## Deep scan (2026-11-21)

### 🟠 High

- [ ] **38. Remote fishing.** The HUD takes `SPOT|<id>|…` from *any* object on
  the shared channel (no owner or distance check), and the server never
  checks that a cast comes from the spot's region, though every HUD request
  carries the sim-stamped `X-SecondLife-Region`. `cast` also ignores
  `is_public`, so a private spot is fishable by id. Fix: compare region + grid
  to the spot's (log-only first), honour `is_public`, check the sender in the
  HUD listen.
  **Partly fixed 2026-11-21:** `is_public` was a misreading — private spots are fishable by design ("works, but isn't shown on the map"). Cast region / grid vs the spot is checked LOG-ONLY (Logic Log `cast_wrong_region`); enforce once it shows no real casts failing. The HUD (needs the new HUD) accepts `SPOT|` only from an object within 64 m that is where it says the spot is.

- [ ] **43. HUD panel login rides in the URL.** `token_id + sig + ts` sit in
  the page URL (the nginx access log keeps them), are reusable for 1 h, and
  `abs()` accepts a future `ts` (up to 2 h); `web_auto_login` mints an 8 h
  session per use. Fix: one-time exchange code, reject future `ts`,
  `history.replaceState` to strip it, `Referrer-Policy: no-referrer`, don't
  log that query string.
  **Partly fixed 2026-11-21:** `Referrer-Policy: same-origin` (49) keeps the URL from other sites. Still to do: one-time exchange code, reject a future `ts`, strip it with `history.replaceState`, keep it out of the access log.

### 🟡 Medium

- [ ] **48. Backups and jobs fail silently, on one box.** Off-site copy still
  missing (item 3). Backup and sweep failures only reach `~/logs`; raise an
  admin alert.
  **Partly fixed 2026-11-21:** backup.sh and sweep.php raise an admin alert on failure (`bin/alert.php`). Off-site copy still missing (item 3).

- [ ] **51. Session / credential hygiene.** `webLogin` returns `session_id`
  in the JSON (undoes HttpOnly); sessions are stored raw (could be sha256 like
  login tokens); `requireHUD` logs the *expected* signature on a mismatch.
  **Partly fixed 2026-11-21:** `session_id` no longer in the login JSON; the expected signature is no longer logged. Sessions are still stored raw.

- [ ] **53. The schedule reveals every fish before it bites.** `cast` and
  `roll_minute` return species, weight and rarity for every bite up front, so
  a scripted HUD can skip commons. Send times + an opaque id; reveal on hook.
  Needs a HUD release (a new `hook` action); old HUDs read the fish from the
  schedule, so the server must keep sending it until they're retired.

- [ ] **54. "Same region" without the grid.** Leaderboard `sim` scope
  (`byWeight`, `byCatches`, `buildSubqueryWhere`); `bait_gather_points` has no
  grid column at all; `registerShop` / `registerPoint` position matches.
  **Partly fixed 2026-11-21:** leaderboard `sim` scope matches the grid when the board sends it (LeaderboardDisplay now does; older boards get region-only). Still open: `bait_gather_points` has no grid column.

- [ ] **57. Unauthenticated spam / leaks.** `grid_check` and the pairing
  routes register any `grid_name` string (no format or length check) → grid
  rows and daily pending alerts; `submit_question` has no throttle;
  `spot_list_junk` / `web_spot_detail` show private spots' position and junk.
  **Partly fixed 2026-11-21:** grid names must be `host[:port]` (`Grid::findOrRegister`); `submit_question` throttled (5 an hour) and capped at 2,000 characters. Private spots' position / junk on the spot page: still open (decide what a private spot hides).

### ⚪ Low / data / docs

- [ ] **58. Live data.** Player 9 has bait 22 equipped, which doesn't exist
  (no FK on `equipped_bait_id`). 1,589 migrated catches have `spot_id` NULL —
  old spots weren't brought over, so per-spot history from the old server is
  gone (recoverable from `deploy/old-server-capture/`?).
  **Partly fixed 2026-11-21:** migration `2026_11_21_05` clears equipped bait that doesn't exist. The 1,589 catches with no spot are still unresolved.

- [ ] **59. Hard-coded ids.** Starter bait 9, Cut Bait 5, water types 1–4 in
  the junk / line-break XP tables, rarity ids in recipes. Look up by name.

- [ ] **60. Time.** `date('G')` / `date('n')` in Fishing and Quest (UTC via
  bootstrap, but the rule is `gmdate`). Daily quests reset at 00:00 UTC (7 pm
  New York) — say so on the quest page.
  **2026-11-21:** `date()` → `gmdate()` done (Fishing, Quest). The quest-page note is still to do.

- [ ] **61. Docs drift.** CLAUDE.md rarity XP 5/15/40/100/300 vs DB
  8/20/55/150/500; "HMAC-SHA256" signing is really SHA-256 of
  `message:token`; the heartbeat and `deleteSpot` comments.
  **Partly fixed 2026-11-21:** CLAUDE.md rarity XP and the signing description corrected; the heartbeat comment is gone with item 36. `deleteSpot` comment still to check.

- [ ] **62. Small bugs.** `trackCatch` re-sends "Quest ready" for every later
  quest once any update happened; timed quests can be claimed after expiry
  (none use `time_limit` yet); GatherPoint / Butcher grants skip
  `MAX_BAIT_STACK`; `buyBait` charges full price when the 999 cap eats the
  bait; cut / butcher / craft mark fish `sold` (skews sold stats); legacy
  columns `players.setup_token` / `token_expires`.
  **Partly fixed 2026-11-21:** `trackCatch` "Quest ready" repeat; `MAX_BAIT_STACK` on butcher / gather / vendor; web shop no longer charges for bait the cap would eat. Still open: timed quests after expiry, `sold` status for butcher / craft, legacy columns.

## Leftovers from archived items

- [ ] **3 → off-site backup.** Nightly dumps (`deploy/backup.sh`) stay on the
  VPS, so a lost server loses them too. Needs an off-site copy (rclone to B2
  or similar, as Fertility's `03-backup.sh`), which needs root or a remote's
  credentials. Failures now raise an admin alert (item 48).
- [ ] **32 → announcements reach no player.** Admins can create them again,
  but nothing shows them: no HUD poll, no portal banner, and nothing sends
  the `announcement` push the HUD understands. Decide where they show.
- [ ] **In-world objects to hand out.** HUD `2026.11.21.02` (SPOT check,
  item 38) and the updated LeaderboardDisplay (sim scope, item 54). Old
  copies keep working; the fixes only apply once players have the new ones.

## Suggested order

1. Decisions: where announcements show (32); switching on the remote-fishing
   check once the Logic Log shows `cast_wrong_region` is clean (38); the
   object-token design for in-world endpoints (1).
2. Next HUD release: hide the fish until it's hooked (53) and a one-time
   exchange code for the panel login (43), shipped together.
3. Server-only cleanups: hashed sessions (51), gather-point grid column
   (54), private-spot details (57), hard-coded ids (59), the remaining small
   bugs (62), docs (60, 61).
4. The off-site backup.
