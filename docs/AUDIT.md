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
      **Spots: done in script (2026-11-19).** A click sends `SPOT_OPEN` and
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
- API starts no PHP sessions (BP 31).
