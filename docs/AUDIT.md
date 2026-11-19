# OSFishing — best-practices audit (2026-11-19)

A scan of the repo against `docs/BEST_PRACTICES.md` (numbers in brackets refer
to it). Nothing here is fixed yet unless marked. Tick items off as they land.

## 🔴 High

- [ ] **1. In-world endpoints act as any player named in a `uuid` param** [BP 2]
  No token, no signature. A hand-made request can spend another player's
  points or wreck their things: `shop_buy`, `shop_sell`, `shop_deposit`,
  `shop_withdraw`, `shop_set_modifier`, `admin_shop_restock` (admin check is
  on the *claimed* uuid), `buff_activate`, `buff_buy`, `craft_item`,
  `butcher_fish`, `spot_delete`, `spot_update`, `spot_add_junk`,
  `spot_remove_junk`, `spot_level_up`, `spot_restore`, `trophy_for_plaque`,
  plus `spot_register`/`shop_register`/`gather_register` via `owner_key`.
  Fix: actions done *by a player* go through the HUD's signed request (objects
  relay to the HUD, as gather points already do with `gather_tick`); actions
  done *by an object* get an object token bound like admin objects. Interim:
  require `X-SecondLife-Owner-Key` and match it to the object's registered
  owner.
- [ ] **2. Web registration takes any typed avatar UUID** [BP 2, 8]
  `web_register` creates an account for whatever UUID is entered, so anyone
  can squat a player's avatar UUID before they register (they then can't
  register, and the UUID is "theirs"). Fix: create accounts only through the
  HUD's pair link (the code proves the avatar), i.e. register.php requires a
  live pairing code for that UUID.
- [ ] **3. No scheduled database backup** [BP 43]
  `/var/backups/osfishing` exists but no job runs (SERVER-HANDOFF). Today's
  only backup is the manual `~/pre_utc_backup_20261119.sql`.

## 🟠 Medium

- [ ] **4. No login throttling** — unlimited password guesses per username/IP.
- [ ] **5. Pushes run inside the request, one by one** [BP 35]
  `PairingAuth::pushToPlayer` / `PrimCallback::pushToType` call
  `PushQueue::post` (3–5 s timeouts) before replying, e.g. on web equip/sell
  and buff activation. Move to an outbox sent after `fastcgi_finish_request()`
  with curl_multi, as Fertility's HudPushService does.
- [ ] **6. HUD timer has many owners** [BP 52]
  15 `llSetTimerEvent` call sites. `checkInfoFade()` sets the timer to 0 when
  idle, which also stops the pairing poll if a notice was fading (e.g. right
  after "Saved pairing is no longer valid") — the HUD then never sees the code
  being claimed. Route every rate change through one `restoreTimer()`.
- [ ] **7. BaitVendor draws (OSSL) before starting its timer** [BP 49]
  `state_entry` calls `renderDisplay()` → `osSetDynamicTextureData` before
  `llSetTimerEvent(300)`. On a grid that denies it the script halts with no
  timer. Move the timer (and any listens) first, as LeaderboardDisplay and
  TournamentBoard already do.
- [ ] **8. `llRegionSayTo(<avatar key>, channel, …)` to reach scripts** [BP 55]
  HUD → Rod (8 calls) and Bobber → HUD (`BOBBER_READY`). Fertility found
  avatar-targeted `llRegionSayTo` reaches the viewer's chat but not listening
  scripts on its grid. Verify in-world: if the rod animates and the bobber
  moves during a fight, this grid delivers it; if not, switch to the object
  key or `llSay`.
- [ ] **9. Fixed shared channels** (-7710001…-7710007) [BP 56]
  Everyone's HUD, rod, bobber, spots and gather points share them; messages
  can cross between players in one region where the receiver doesn't check
  the sender's owner. Derive per-owner channels.

## 🟡 Low / cleanup

- [ ] **10.** Session cookies set without `Secure` in `Auth::webLogin` and
  `web_auto_login` (recover.php has it). [BP 66]
- [ ] **11.** `Access-Control-Allow-Origin: *` on the API — nothing needs
  cross-origin access; remove or pin to the site.
- [ ] **12.** `check_account` returns the display name for any UUID
  (enumeration); return only exists/not.
- [ ] **13.** Nothing prunes expired `web_sessions`, `hud_pairing_codes`,
  `hud_pair_attempts`, `login_tokens`; `push_queue` sweeps only on a 5% roll
  of prim heartbeats. Add a daily sweep. [BP 31]
- [ ] **14.** Dead legacy auth/registration: `Auth::requireHUD` (shared
  `HUD_SECRET` HMAC, unused), `Player::register` (route removed),
  `web_setup` + `setup.php` (setup tokens only came from it), and the HUD's
  `gHttpAction == "register"` branch. Remove. [BP 12]
- [ ] **15.** HUD `jsonGet` is a hand parser (breaks on escaped quotes;
  slashes fixed 2026-11-19). Prefer `llJsonGetValue` for anything nested or
  user-supplied. [BP 51]
- [ ] **16.** Portal builds three snippets with `html:` + server values
  (fish value, rod stats, journal). Numbers today; switch to text nodes. [BP 65]
- [ ] **17.** Tournament schema mismatch (already in CLAUDE.md TODO).

## Checked and fine

- Every LSL `llHTTPRequest` sets `HTTP_BODY_MAXLENGTH` (BP 38).
- No `== "true"` JSON checks without `JSON_TRUE` (BP 51).
- No `osGetGridName`/`osGetGridLoginURI` calls; LeaderboardDisplay,
  TournamentBoard and TrophyText set timers/listens before OSSL (BP 49).
- No runtime schema changes in `includes/`; all via migrations (BP 16).
- Fish inventory sort/order are whitelisted (BP 26).
- No `NOW()`/`date()` left; one collation; UTC session (BP 14, 15).
- API starts no PHP sessions (BP 31).
