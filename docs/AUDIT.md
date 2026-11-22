# OSFishing — best-practices audit (2026-11-19)

A scan of the repo against `docs/BEST_PRACTICES.md` (numbers in brackets refer
to it). Ticked items are fixed; the commit subject names the item number.
In-world (LSL) fixes only reach players once the updated objects are handed out.

## 🔴 High

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
- [x] **2. Web registration takes any typed avatar UUID** [BP 2, 8]
  `register.php` now needs the HUD's live pairing code for that UUID, creates
  the account in one transaction and claims the code, so the HUD pairs at once.
- [x] **3. No scheduled database backup** [BP 43]
  `deploy/backup.sh` nightly at 03:23 (host time) to `~osfishing/backups`,
  14 days. **Still on-server only**: a lost VPS loses the backups too. Needs
  an off-site copy (rclone to B2 or similar, as Fertility's `03-backup.sh`)
  and that needs root or a remote's credentials.
- [x] **18. `pair_status` could be brute-forced** (new) [BP 2, 5]
  Now: `pair_status` needs the sim's owner header to equal `uuid`, and wrong
  codes are capped at 10 per avatar / 30 per address in 15 min; `pair_request`
  at 10 per avatar / 60 per address (each one voids the avatar's older codes,
  so unlimited requests could stop someone pairing); wrong codes on the portal
  claim and `pair.php` at 10 per account. `includes/Throttle.php`, rows in
  `login_attempts` under prefixed keys.

## 🟠 Medium

- [x] **4. No login throttling** — 5 failures per username or 20 per address
  in 15 min → 429 (`login_attempts`).
- [x] **5. Pushes run inside the request, one by one** [BP 35]
  `PushQueue::sendLater` outbox, sent after `fastcgi_finish_request()` with
  curl_multi (URLs in parallel, one URL in order). Flush-on-contact also runs
  after the reply.
- [x] **6. HUD timer has many owners** [BP 52]
  One `restoreTimer()`; bite window and victory pause are deadlines.
- [x] **7. BaitVendor draws (OSSL) before starting its timer** [BP 49]
  Timer and fetch now come first, but that can't save a halted script. The
  draw is bracketed by a LinksetData mark: if the last draw halted the
  script, after a reset the vendor shows hover text instead. LeaderboardDisplay,
  TournamentBoard and TrophyText could take the same guard.
- [x] **8. `llRegionSayTo(<avatar key>, channel, …)` to reach scripts** [BP 55]
  The rod announces `ROD_HELLO` and the HUD sends to its key (fallback
  `llSay`); the bobber announces with `llSay` and the HUD checks the owner.
  Spots, gather points and the PrepTable still use `llRegionSayTo(avatar)` to
  reach the HUD. Gathering working in-world would show this grid delivers it.
- [x] **9. Fixed shared channels** (-7710001…-7710007) [BP 56]
  Kept the numbers (renumbering breaks every deployed object); added sender
  checks instead: spot `DELIVER_JUNK` only from the recipient's HUD, gather
  `GATHER_RESULT` only from the sitter's HUD for that point, HUD gather
  messages only from objects within 20 m, and the result goes to the asking
  point's key instead of every point in the region.
- [x] **19. Duplicate listens in the HUD** (new) — `hud_status`'s reply
  handler re-opened the spot/bobber/gather listens on every refresh; removed
  (state_entry opens them once).

## 🟡 Low / cleanup

- [x] **10.** Session cookies are `Secure` everywhere.
- [x] **11.** No CORS headers on the API.
- [x] **12.** `check_account` returns only `exists`.
- [x] **13.** `bin/sweep.php` daily at 03:41 (managed crontab block in
  `deploy.sh`).
- [x] **14.** Legacy auth/setup removed, including the live `/setup` nginx
  rewrite (snippet reinstalled 2026-11-19; `/setup` now 404s).
- [x] **15.** `jsonGet` reads escaped strings; pushes send raw UTF-8.
- [x] **16.** Portal snippets use text nodes.
- [x] **17.** Tournaments: the code's design is canonical; migration 10
  rebuilt the empty table. Five routes calling Leaderboard methods that don't
  exist were removed. Tournaments still have no admin UI.
- [x] **20.** (new) Bans didn't apply to existing portal sessions or paired
  HUDs; `requireWeb`, `webLogin` and `requireHUD` now refuse them.

## Checked and fine

- Every LSL `llHTTPRequest` sets `HTTP_BODY_MAXLENGTH` (BP 38).
- No `== "true"` JSON checks without `JSON_TRUE` (BP 51).
- No `osGetGridName`/`osGetGridLoginURI` calls; LeaderboardDisplay,
  TournamentBoard and TrophyText set timers/listens before OSSL (BP 49).
- No runtime schema changes in `includes/`; all via migrations (BP 16).
- Fish inventory sort/order are whitelisted (BP 26).
- No `NOW()`/`date()` left; one collation; UTC session (BP 14, 15).
  (Not quite: see item 60.)
- API starts no PHP sessions (BP 31).

---

# Deep scan (2026-11-21)

Every PHP class, the router, public pages, LSL entry points, deploy/nginx,
and the live database. Method: all 548 SQL string literals were server-side
`PREPARE`d against the live schema (never executed) — 5 are broken, listed
below; data checks were read-only queries. Nothing was exploited to confirm
it; each item was confirmed by reading the code path. Numbers continue from
the list above. **Exploit** = a player, often with only their own HUD or a
hand-made request, can farm or wreck something.

## 🔴 Critical — exploitable now

- [ ] **21. Catch tokens can be confirmed again and again.**
  `Fishing::validateCatchToken` checks signature, player and age (10 min)
  but nothing records that a token was used, so one legendary's token can be
  sent to `confirm_catch` repeatedly: a new fish, XP, quest progress and
  catch_log row each time. Fix: store a token id (or its hash) under a
  UNIQUE key when confirmed; refuse a second use.
- [ ] **22. `roll_minute` hands out fish without a cast.** No cast session
  is needed and any `minute` is accepted; every call returns 1–3 freshly
  signed catch tokens. With 21 that is unlimited fish. `generateCastToken`
  makes a `cast_token` nothing checks (signed with the fallback
  `'devsecret'`). Fix: a server-side cast row (spot, bait, line, started_at,
  last minute, closed); `roll_minute` only for an open cast, only `last+1`,
  only after 60 s; catch tokens carry the cast id and bite time, and
  `confirm_catch` refuses before the bite and after the cast closes.
- [ ] **23. `confirm_catch` trusts the client's `spot_id` and
  `cast_distance`.** The token carries `sid` but it's ignored, so a catch can
  be credited to any spot: its buffs (double hook, blessing, golden hour),
  spot XP, tournament standings, "at spot X" quests. `cast_distance` comes
  from the HUD and adds up to +10 % weight (the server's own cast caps at
  25 m; the bonus scales to 50 m). Fix: use the token's spot and the cast
  row's distance.
- [ ] **24. Free XP from `line_break`.** 2–8 XP per call, no cast or hooked
  fish needed. Fix: only for an open cast with a hooked fish, once.
- [ ] **25. Bait use is the client's choice.** `reel_in caught_fish=1` never
  uses bait, and `confirm_catch` uses whatever bait is equipped *now*, not the
  bait the roll was made with (roll with Glow Grubs, swap to Bread Dough,
  confirm). Fix: bind bait to the cast row; decide consumption server-side.
- [ ] **26. `bait_gather` = unlimited bait of any type.** The HUD picks
  `bait_id`, and the cooldown is keyed by a free-text `spot_key`, so a new
  key per call skips it (Magnet, Abyssal Eye, …). The current HUD doesn't
  call it: remove the route and `Bait::gather`.
- [ ] **27. Negative quantities.** `shop_buy quantity=-10` *gains* points and
  stock; `shop_sell` with a negative quantity *gains* bait;
  `web_shop_buy_bait bundles=0` (or negative) skips the web daily limit (the
  router counts the limit from the raw value, `buyBait` clamps it to 1).
  Fix: refuse `< 1` everywhere and cap the top end.
- [ ] **28. Player shops mint bait and points.** `shop_deposit` adds stock
  without taking it from the owner; `shop_register_listing` (no auth at all)
  creates a listing starting at `stock = max_stock` of anything; buying from
  your own shop pays you back; selling into a shop mints 50 % in new points.
  So: buy your own stock for free, sell it back, repeat. Fix: deposit and
  withdraw move real inventory; listings are owner-authenticated and start
  empty for player shops; no buying from your own shop; transactions.
- [ ] **29. Anyone can create system gather points.** `gather_register` with
  no `owner_key` makes a *system* point (no limit) for any bait type, and a
  re-register within 3 m takes over someone else's point. `gather_tick` has
  no per-player cooldown, and its `FOR UPDATE` runs outside a transaction (no
  lock). Fix: verified owner required, system points admin-only, only
  gatherable bait (`gather_method`), per-player cooldown, transaction.
- [ ] **30. Prim callbacks are unauthenticated → hijack and SSRF.**
  `register_prim_callback` takes any `prim_uuid` / `prim_type` / `ref_id` /
  URL and the server then POSTs there (and `Grid::checkPush` probes it): a
  real spot's pushes can be redirected, the server can be aimed at
  `127.0.0.1` or `169.254.169.254`, and on a grid whose sims report private
  addresses the caller's own IP can become that grid's `public_host` (every
  push for the grid then goes to it). `unregister_prim_callback` and
  `prim_heartbeat` work on any prim. The HUD's `register_callback` URL isn't
  checked at all (curl also speaks `file://`, `gopher://`). Fix: object-key
  header must equal `prim_uuid` and its owner must own `ref_id`;
  `CURLOPT_PROTOCOLS_STR = "http,https"`; refuse loopback / private /
  link-local targets except through an admin-set `public_host`.

## 🔴 Broken features (schema drift, dead code)

- [ ] **31. Vendor transactions table doesn't match the code.** Table:
  `item_ref_id, points_spent, purchased_at`. Code writes `shop_id,
  listing_id, action, item_name, points_amount` and reads `st.shop_id`,
  `created_at` (in the live error log). Every vendor buy/sell applies the
  purchase, *then* fails on the log insert (no transaction) → the player sees
  "A database error occurred" and may retry → double purchase. The
  transactions view always errors. Fix: migration to the intended shape +
  transaction.
- [ ] **32. Admin announcements can't be created.** The admin panel calls
  `admin_create/toggle_announcement` (`title, body, is_active`); the table is
  `message, priority, starts_at` (what the HUD's `announcements` reads and
  the older `admin_announce` writes). Pick one design.
- [ ] **33. Admin fish editor "Base XP" field errors.** `Admin::updateFish`
  allows `base_xp`, which lives on `rarity_tiers`, not `fish_species`.
- [ ] **34. Half the fishing rules are dead code.** The live roll is
  `generateMinuteSchedule` → `rollFishFromPool`; `rollCatch` is never called.
  So none of these do anything today: **Lucky Lure Oil** (players destroy a
  legendary fish to craft it), the **spot-level loot modifier** (the reward
  for levelling a spot), bait `catch_rate_mod` / `rarity_mod` (tuned on 10
  baits, e.g. Glow Grubs ×2.5 rarity), night / dawn-dusk fish (6 species),
  Salmon's fall season, the `bioluminescent_tide` event fish, required bait.
  `getEligibleFish` also ignores `fish_species.is_active`. Fix: move these
  into the live roll; delete `rollCatch`, `generateFightParams`,
  `calculateBiteWait`, `generateNibbleTimes`, `generateCastToken`. Player
  visible → changelog.
- [ ] **35. Junk and double-hook payouts are wrong.** A junk catch awards a
  *different* random item (`ORDER BY RAND()`) than the one the HUD fought,
  writes no catch_log row and uses no bait. Double Hook returns doubled XP
  but awards only the single amount.
- [ ] **36. Spots stay dark after a region outage.** `cleanupStale` sets
  `is_active = 0` after 90 min without a heartbeat; `heartbeat()`'s comment
  says it turns the spot back on, but the code doesn't, so nobody can fish
  there until the owner notices. Fix: track "auto-paused" separately from the
  owner's switch and clear it on heartbeat / registration.
- [ ] **37. Buff expiry notifies one spot, deletes them all.**
  `getSpotBuffs` and `getActiveMultipliers` `DELETE` every expired buff in the
  table but push `buff_expired` only to the spot being read; every other spot
  keeps showing its expired buffs. Fix: expire per spot (or in the sweep) and
  queue a push for each.

## 🟠 High

- [ ] **38. Remote fishing.** The HUD takes `SPOT|<id>|…` from *any* object on
  the shared channel (no owner or distance check), and the server never
  checks that a cast comes from the spot's region, though every HUD request
  carries the sim-stamped `X-SecondLife-Region`. `cast` also ignores
  `is_public`, so a private spot is fishable by id. Fix: compare region + grid
  to the spot's (log-only first), honour `is_public`, check the sender in the
  HUD listen.
- [ ] **39. Re-registering near someone else's spot rewrites it.**
  `PairingAuth::registerSpot` updates any active spot within 3 m without
  checking the owner, and that path sets `is_active` without the spot-limit
  check. `ShopSystem::registerShop` does the same by region only (no grid, no
  owner).
- [ ] **40. `InWorldAuth` owner rule checks the wrong key.** It passes when
  `isAdmin($uuid)` — the *claimed* actor — so once enforced, any request that
  claims the admin's UUID passes. Should be `isAdmin($ownerKey)`. Also add the
  missing unauthenticated actions: `shop_register_listing`,
  `register_prim_callback`, `unregister_prim_callback`, `prim_heartbeat`.
- [ ] **41. Grid approval is fail-open and client-claimed.** `pair_request`,
  `spot_register`, `spot_setup_info`, `spot_restore` skip `requireApproved`
  when `grid_name` is left out; `grid_name` is only a parameter, so a denied
  grid's script can claim an approved host (SimOrigin is log-only). Denying a
  grid doesn't stop HUDs already paired there (`requireHUD` never checks the
  token's grid). Fix: require `grid_name`, check grid status in `requireHUD`,
  enforce SimOrigin once clean.
- [ ] **42. Check-then-write races (double rewards).** Quest `claimRewards` /
  `completeQuest` (double-click, or HUD + web, pays twice),
  `confirmSpotLevelUp` (two levels), `Butcher::butcher` (double chunks),
  `Buff::buyFromShop` (check then deduct → negative points), `Buff::activate`
  decrement without `quantity > 0`, `ShopSystem::buy`. `Player::awardXP` uses
  `FOR UPDATE` outside a transaction (no lock; lost XP under concurrency).
  Fix: `UPDATE … WHERE <guard>` and check `rowCount()` before granting;
  transactions.
- [ ] **43. HUD panel login rides in the URL.** `token_id + sig + ts` sit in
  the page URL (the nginx access log keeps them), are reusable for 1 h, and
  `abs()` accepts a future `ts` (up to 2 h); `web_auto_login` mints an 8 h
  session per use. Fix: one-time exchange code, reject future `ts`,
  `history.replaceState` to strip it, `Referrer-Policy: no-referrer`, don't
  log that query string.
- [ ] **44. `web_register` isn't throttled.** The 6-digit code for a known
  UUID can be guessed with no attempt cap to squat a new player's avatar.
  Use `Throttle` as `web_pair_claim` does.
- [ ] **45. The web shop sells bait it shouldn't.** `Shop::buyBait` ignores
  `shop_enabled` and bait `min_level`: Glow Grubs (gather-only), Magnet
  (level 8), and Fish Chunks at price 0 (free). HUD `shop_buy_bait` skips the
  web daily limit entirely.
- [ ] **46. Crafting can eat your best fish.** `Buff::craft` takes the
  lightest fish with rarity ≥ required, so a Chum Bucket can consume an epic
  or legendary. Not in a transaction.

## 🟡 Medium

- [ ] **47. Deploy isn't atomic.** rsync puts new code live *before*
  migrations, and `opcache.validate_timestamps = 1`, so a failed migration
  leaves new code running on the old schema (CLAUDE.md says the opposite).
  Fix: migrate from the repo tree first, or release dirs + symlink swap.
- [ ] **48. Backups and jobs fail silently, on one box.** Off-site copy still
  missing (item 3). Backup and sweep failures only reach `~/logs`; raise an
  admin alert.
- [ ] **49. No HTTP hardening.** `osfishing-limits.conf` has routing only —
  no `limit_req` anywhere. No HSTS, `X-Content-Type-Options`,
  `frame-ancestors` / `X-Frame-Options` (the admin panel is frameable),
  `Referrer-Policy`.
- [ ] **50. Error handling and partial writes.** The router catches
  `\Exception`, not `\Throwable`, so TypeErrors (`web_quest_turnin` with a
  non-array `fish_ids`, a JSON body putting arrays into `trim()`) give blank
  500s. Multi-step writes with no transaction: `confirmCatch`, ShopSystem,
  Butcher, craft, GatherPoint, `webRegister`'s starter kit.
- [ ] **51. Session / credential hygiene.** `webLogin` returns `session_id`
  in the JSON (undoes HttpOnly); sessions are stored raw (could be sha256 like
  login tokens); `requireHUD` logs the *expected* signature on a mismatch.
- [ ] **52. Nonce check isn't atomic.** Two requests can read the same
  `last_nonce`; use `UPDATE … SET last_nonce = :n WHERE id = :t AND
  last_nonce < :n` and check `rowCount()`.
- [ ] **53. The schedule reveals every fish before it bites.** `cast` and
  `roll_minute` return species, weight and rarity for every bite up front, so
  a scripted HUD can skip commons. Send times + an opaque id; reveal on hook.
- [ ] **54. "Same region" without the grid.** Leaderboard `sim` scope
  (`byWeight`, `byCatches`, `buildSubqueryWhere`); `bait_gather_points` has no
  grid column at all; `registerShop` / `registerPoint` position matches.
- [ ] **55. Indexes and query cost.** `catch_log` has no `spot_id` index, yet
  per-spot `COUNT(*)` subqueries run per row (spot leaderboard, archived list,
  spot page) — add `(spot_id, caught_at)`. Duplicate indexes:
  `hud_tokens.token` ×2, `players.username` ×2, `players.uuid` ×2.
  `rollFishFromPool` queries `rarity_tiers` and one affinity row per fish per
  roll (N+1).
- [ ] **56. Tables that only grow.** The sweep skips `logic_error_log`,
  `admin_alerts`, `admin_object_log`, `spot_xp_daily`, `gather_cooldowns`,
  `daily_purchase_limits`.
- [ ] **57. Unauthenticated spam / leaks.** `grid_check` and the pairing
  routes register any `grid_name` string (no format or length check) → grid
  rows and daily pending alerts; `submit_question` has no throttle;
  `spot_list_junk` / `web_spot_detail` show private spots' position and junk.

## ⚪ Low / data / docs

- [ ] **58. Live data.** Player 9 has bait 22 equipped, which doesn't exist
  (no FK on `equipped_bait_id`). 1,589 migrated catches have `spot_id` NULL —
  old spots weren't brought over, so per-spot history from the old server is
  gone (recoverable from `deploy/old-server-capture/`?).
- [ ] **59. Hard-coded ids.** Starter bait 9, Cut Bait 5, water types 1–4 in
  the junk / line-break XP tables, rarity ids in recipes. Look up by name.
- [ ] **60. Time.** `date('G')` / `date('n')` in Fishing and Quest (UTC via
  bootstrap, but the rule is `gmdate`). Daily quests reset at 00:00 UTC (7 pm
  New York) — say so on the quest page.
- [ ] **61. Docs drift.** CLAUDE.md rarity XP 5/15/40/100/300 vs DB
  8/20/55/150/500; "HMAC-SHA256" signing is really SHA-256 of
  `message:token`; the heartbeat and `deleteSpot` comments.
- [ ] **62. Small bugs.** `trackCatch` re-sends "Quest ready" for every later
  quest once any update happened; timed quests can be claimed after expiry
  (none use `time_limit` yet); GatherPoint / Butcher grants skip
  `MAX_BAIT_STACK`; `buyBait` charges full price when the 999 cap eats the
  bait; cut / butcher / craft mark fish `sold` (skews sold stats); legacy
  columns `players.setup_token` / `token_expires`.

## Suggested order

1. Close the farming holes as one piece of work: a server-side **cast
   session** fixes 21–25 and 53 together, and makes 38 cheap.
2. Quantity / ownership guards and transactions in ShopSystem, GatherPoint,
   Quest, Butcher, Buff (26–29, 42, 45, 46): small, server-only, no HUD
   release.
3. Lock down the unauthenticated object endpoints (30, 40) and repair the
   broken tables (31–33) with migrations.
4. Port the dead rules into the live roll (34–37) with a changelog entry —
   the biggest player-visible win.
5. Deploy / ops (47–49), then the rest.
