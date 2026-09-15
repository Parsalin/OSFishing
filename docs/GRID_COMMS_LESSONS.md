# Lessons from Fertility: site ↔ grid communication

Handover notes from the Fertility V2 project (sibling repo, same stack: LSL in-world
clients + PHP/nginx/MariaDB backend on flamesfall.net, multiple OpenSim grids).

Everything here was learned the hard way — each item cost real debugging time or
shipped a live bug. Items marked **ACTION** are things I checked in the OSFishing
source and believe apply right now; the rest are patterns worth knowing before you
hit them.

---

## 1. OSSL calls can *halt your script*, not fail gracefully — **ACTION, urgent**

This is the single most expensive lesson Fertility learned.

Fertility's `detectGrid()` used to call `osGetGridLoginURI()` first. On a grid that
denies that OSSL function (found in practice on Kurtopia) the call **does not return
empty**. It raises an OSSL Permission Error that **halts the script**:

```
Script must be Reset to re-enable
```

In `state_entry`, on attach, that bricks the object dead. Nothing is left running —
no timer, no `attach`, no `on_rez` — so there is no retry path and no way to even
detect the failure remotely. LSL has no try/catch and no way to probe an OSSL
permission before calling it, so **there is no safe guarded form of the call.**

The rule Fertility adopted: *assume any grid may deny any OSSL function, and that
denial is fatal, not recoverable.* Grid detection is now hostname-only:

1. `llGetSimulatorHostname()`
2. `llGetEnv("simulator_hostname")`
3. fallback `"unknown"`

…with `":8002"` appended unless the hostname already carries a port. The server
normalizes to a base domain on arrival, so it doesn't need a precise value.

**What I found in OSFishing:** `osGetGridName()` is called in 10 places across 6
scripts:

- `lsl/LeaderboardDisplay.lsl:186` — **in `state_entry`, unguarded.** This is the
  exact shape of the Kurtopia bug. On a grid that denies it, the leaderboard prim
  is bricked on rez.
- `lsl/FishingHUD.lsl:531` — in the `pair_request` path, so pairing fails on such
  a grid.
- `lsl/FishingSpot.lsl` — 6 call sites
- `lsl/BaitVendor.lsl:232`, `lsl/ShopRegister.lsl:59`

`osGetGridName()` is usually lower-threat than `osGetGridLoginURI()`, so it may well
be permitted everywhere you currently operate. The risk is the grid you haven't
deployed to yet. Suggested fix: one shared `detectGrid()` helper per script using the
hostname chain above, and let the server normalize. If you want the pretty grid name
for display, fetch it server-side from your own grids table keyed by hostname — don't
ask the sim for it.

Note the asymmetry that makes this urgent: you support grids whose config you don't
control, and the failure is silent from your side. You find out from a player report.

## 2. `osMakeNotecard` fails *completely* silently when blocked

Related but distinct: no error, no event, nothing. If the region's OSSL threat level
or estate permissions block it, the notecard simply never appears.

Fertility uses it in two places and never trusts a bare call. The pattern:

- write the notecard
- watch for `changed(CHANGED_INVENTORY)`
- **plus** a timer fallback checking `llGetInventoryType(name) == INVENTORY_NOTECARD`
- if still missing after a few seconds, `llOwnerSay` a clear warning

When it fires, the fix is region/estate-side (enable the function in OSSL config for
the object owner), not in script logic — so the warning needs to say that, or the
owner will file it as a script bug.

## 3. `llGetNumberOfAgents` does not exist in OpenSim

Use `llListGetLength(llGetAgentList(AGENT_LIST_REGION, []))`, minus 1 to exclude self.

## 4. `llJsonGetValue` does not return `"true"` for JSON `true` in OpenSim

Do **not** check `== "true"`. Fertility's rules:

- for a response's success: use the **HTTP status code** as authoritative, not a body field
- for presence of an action/key: check `!= JSON_INVALID`
- for errors: check for presence of the `["error"]` key

## 5. `llJsonGetValue` fails silently on deep or large JSON

This one shaped Fertility's whole push-delivery design. OpenSim's JSON parser gives
up on large/deeply-nested bodies **without an error** — the value just comes back
empty and you assume the server sent nothing.

Consequence: Fertility deliberately does **not** merge queued push actions into the
main `tick` response, because that body is already large and the actions vanish.
They ride on the smaller `region_scan` response instead. If you have a big status
payload and a small event payload, keep them on separate endpoints rather than
merging for efficiency — the merge is where the data disappears.

## 6. Emoji need `llUnescapeURL`, not `\x` escapes

OpenSim does not support `\x` hex escapes in string literals. Initialize emoji globals
once at startup:

```lsl
gEmojiEyes  = llUnescapeURL("%F0%9F%91%80");  // 👀
gEmojiLock  = llUnescapeURL("%F0%9F%94%92");  // 🔒
```

I see literal emoji in OSFishing dialog strings (`🏆` in `LeaderboardDisplay.lsl`),
which is fine if your toolchain preserves UTF-8 through upload — Fertility hit trouble
specifically with `\x` escapes, not with literal characters. Worth a visual check
in-world on each grid.

## 7. In-world script names differ from repo filenames, and `llSetScriptState` fails silently

Fertility's repo has `FertilityHUD_Main.lsl`; the in-world script inventory name is
`Fertility - Main`. `llSetScriptState("WrongName", ...)` is a **silent no-op** — no
error, nothing. A typo'd script name breaks a mode toggle with zero feedback.

Fertility defines `SCRIPT_NAME_MAIN` / `SCRIPT_NAME_NPC` constants in every script
that does this, and never hardcodes the name inline. Worth doing if any OSFishing
script enables/disables another.

## 8. Never gate startup behind a one-shot flag another script must set

Fertility's NPC script used to wait in an inert state for an `npc_activate` linkset
flag written by the main script and deleted on read. A plain script reset cleared the
flag with nothing left to set it again — the script sat silently dead until manually
re-triggered.

Now it self-activates on `state_entry`/`on_rez`. `llSetScriptState(name, TRUE)` already
refires `state_entry` when transitioning a stopped script to running, so the flag bought
nothing.

Generalized rule: **make the recovery path the default path.** Any state that only gets
set once, by someone else, is a state you can't recover into after a reset.

## 9. Static config in notecards, changing state in linkset data — never mix

Fertility's split, enforced strictly:

- **Notecard** = static, user-configured settings only. Written once at first run;
  the user may hand-edit and reset to apply.
- **Linkset data** = everything that changes over time.

And the startup gate is **notecard existence**, not a one-shot flag (see #8): if the
notecard exists, read it and run; if not, run first-time setup. That makes the
notecard's presence alone authoritative for "is this configured," so a plain script
reset always recovers correctly.

Also: an **ownership change wipes linkset data automatically.** Fertility relies on
this — HUD copies need no preparation before hand-delivery. Make sure nothing you
need across an ownership transfer lives only in linkset data.

## 10. `llRegionSayTo` at an *avatar* key does not reach scripts

A real Fertility bug, silent and confusing. To hand a token from a HUD to a nearby
object, the object must announce itself with `llSay(channel, ...)`.
`llRegionSayTo(ownerKey, channel, ...)` delivers to the **viewer's chat**, not to
listening scripts — the HUD's `llListen` never fires.

`llRegionSayTo(objectKey, ...)` at an *object* key is fine; it's the avatar-key form
that's the trap.

## 11. Derive object↔object channels from the owner key

Fertility's standard, used identically across HUD, pump, givver, and props:

```lsl
integer menuChannel = (integer)("0x" + llGetSubString((string)llGetOwner(), 0, 6));
```

Two owners' objects then never collide on a busy sim. Pair it with an ownership check
on receive — Fertility's HUD does `llGetOwnerKey(id) == gOwner` before acting on any
channel message, so a stranger's object can't trigger a token issue.

I didn't find this derivation pattern in OSFishing; if your channels are fixed
constants (`CH_SPOT_TO_HUD` etc.), two players fishing near each other may cross
streams. Fixed channels are fine for genuinely broadcast traffic (a spot announcing
itself to anyone), but anything carrying a token or acting on a specific player should
be owner-derived.

## 12. Short-lived single-use tokens for in-world objects, consumed atomically

Fertility's object-token flow, for letting a prop read player state without holding
credentials of its own:

```
Object → llSay(channel, "TOKEN_REQUEST")
HUD    → POST issue_object_token → 32-char hex, 60s TTL
HUD    → llRegionSayTo(objectKey, channel, "TOKEN|<token>")
Object → POST object_query with that token → state returned, token consumed
```

The consuming query is a **single atomic UPDATE**:

```sql
UPDATE ... WHERE consumed_at IS NULL AND expires_at > UTC_TIMESTAMP()
```

Race-safe by construction — only one concurrent call wins. Don't do SELECT-then-UPDATE
here; two objects touched in the same frame will both pass the SELECT.

This matters for OSFishing because you have many in-world objects (spots, vendors,
registers, plaques) that need player context. Giving each one long-lived credentials
means every rezzed prop is a credential you can't revoke.

## 13. Cross-check the owner-key header on every request — **ACTION**

Fertility's API cross-checks `X-Secondlife-Owner-Key` against the `uuid` POST param on
every `/api` request; mismatch → `owner_mismatch` 401. The sim sets that header and a
script can't forge it, so it's a free identity assertion on top of the token.

`X-Secondlife-Region` (format `"Name (gridX, gridY)"`) is likewise the **authoritative**
region source — never trust a region name the payload claims, since that's script-supplied.
Fertility falls back to the payload value only when the header is absent (curl tests).

I grepped OSFishing's `web/` and `lsl/` for `X-Secondlife-Owner-Key` and found no hits.
If that's accurate, a token leaked from one player's HUD could be replayed with any
`uuid`. Cheap to add, and it catches the whole class.

**Do not** use `X-Secondlife-Shard` for grid identity — it contains only `"OpenSim"`,
the platform name, not a URL. Fertility made that mistake.

## 14. Nonce replay protection, and the `nonce=0` testing escape hatch

Fertility rejects any nonce ≤ the last seen value, **except** `nonce=0`, which skips the
replay check entirely and doesn't advance the counter. That's what makes `curl` testing
possible without poisoning a live player's counter — otherwise your first test permanently
desyncs their HUD.

I can see OSFishing already persists a nonce across reattach
(`FishingHUD.lsl:127` writes `fishing_nonce` to linkset data), which is the right
instinct. Worth confirming the server has an equivalent test escape hatch, or debugging
live accounts gets painful.

## 15. Queue pushes for grids that deny HTTP-in — don't assume a callback URL

Some grids deny `llRequestURL()` (returns `URL_REQUEST_DENIED`). Those HUDs can never
receive a server-initiated push.

Fertility's handling:

- HUD sets a `gNoHttpIn` flag and reports it to the server on registration
- server records it per-grid and shows an admin badge
- pushes for those players **queue in a DB column** instead of POSTing
- the queue flushes on the next inbound poll (`region_scan`), so delivery is pull-based
- poll interval tightens (30s instead of 60s) on those grids to cap the delay

The generalized rule Fertility now enforces for *every* push path:

> queue on empty callback URL; live POST on non-empty URL; **requeue on POST failure.**

Never write a bare `@file_get_contents($url, ...)` without checking the return value and
requeueing on `false`. Fertility has a documented "gap" tier where one service still does
this, and it's a known source of dropped messages.

Also suppress "push URL unavailable" spam: Fertility only warns if a push URL has
*previously* worked, then resets the flag, so sims that simply never support HTTP-in stay
quiet.

## 16. Edge-triggered events must be evaluated somewhere the player isn't required to be online

The worst class of bug in this category, because it's invisible and permanent.

Fertility's weekly pregnancy update fires exactly once, at the tick where a day counter
crosses a 7-day boundary. If the player's HUD wasn't online at that exact moment, the only
place the crossing got evaluated was the hourly cron — **and the cron discarded the actions
it returned.** The message was lost forever for that pregnancy. It never re-fires.

Found via a real player report: pregnant for many weeks, never received a single update.

Two rules out of it:

1. If a boundary crossing produces a message, the offline path must **queue** it, not drop it.
2. The cron must **never** attempt live HTTP pushes — there's no per-player timeout budget
   for 30+ players in one run. Queue always; flush-on-next-contact works whether the player
   is online now or in three days.

## 17. Deploy whole branches, never individual files

Not grid-specific, but it dominated Fertility's debugging time on the old host, so it's
worth flagging.

Files were pushed one at a time via `pscp` + `sudo cp`. An edit round could miss a file and
leave the server silently running stale code. Found in practice: two repository/service files
weeks out of date, and a missed front controller causing an immediate `ArgumentCountError` 500.

The fix was one script that:

- `rsync --delete` (so deletions propagate — a stale file left behind is as bad as a missing one)
- runs `php -l` over **every** PHP file *before* going live
- reloads PHP-FPM to clear OPcache

The diagnostic habit that came with it: **if a bug looks like "this method doesn't exist,"
check the deploy actually ran before suspecting the code.**

A related gap worth knowing: neither `php -l` nor an LSP catches a live route calling a
*deleted* method. Syntax checking is not reference checking.

## 18. Path, not port — and one router, not two

The architecture question you asked about. Fertility serves everything from one vhost at
the domain root, with `/api` as a route inside a single front controller
(`Web/public/index.php`), rather than a second `index.php` under `/api/`.

The benefit is **one place where dependencies are wired.** Fertility has a hard-won rule
about missed wiring sites: a shared logger was wired in the web entry point but forgotten in
the cron's separate instance, silently disabling an entire log category on the highest-volume
code path. Two front controllers mean two DI graphs, and the one you're not looking at drifts.

Fertility also carries a rule against path prefixes: the app used to be served under
`/fertilityV2` with an nginx alias block and a `SCRIPT_NAME` fastcgi_param hack. All of it was
deleted at migration, and the rule is now *don't reintroduce either, and don't "fix" routing by
re-adding a prefix.* Prefixes that exist for deployment reasons rather than routing reasons
become a permanent tax.

On ports specifically: ports 80/443 are near-universally permitted by sim outbound firewalls
and OpenSim's own `OutboundDisallowForUserScripts`; arbitrary high ports frequently are not.
Fertility talks to a grid it doesn't control over plain 443 for exactly this reason. Per #1,
a blocked port on someone else's grid is a *silent* failure you learn about from a player.

## 19. HUDs in players' hands are unreachable — the API URL is effectively permanent

Every Fertility HUD hardcodes the API URL, and when the old domain died, **no server-side
update path reached them.** Bumping a version number does nothing; the HUD can't ask a server
it can't resolve. Fresh HUDs had to be hand-delivered.

Consequences for OSFishing, where `gApiUrl` is hardcoded in all 14 scripts:

- **Never** compile an in-world object against a temporary URL. The other session suggested
  testing over `http://` before certbot finishes — reasonable for `curl`, but if a single HUD
  copy with that URL escapes into someone's inventory, you can't recall it. Test with `curl`
  against plain HTTP; only ever compile against the final `https://` URL.
- Keep a retarget script. Fertility has `deploy/retarget-lsl.sh` that rewrites the hardcoded
  URL across every LSL file, precisely because this is a 14-file edit done under pressure.
- Consider whether the URL should be a notecard setting rather than a constant (per #9,
  static config belongs in a notecard). That converts "re-issue every object" into "edit a
  notecard" — though it costs you a dataserver round-trip at startup and gives players a
  footgun. Fertility did *not* do this; it's the choice I'd revisit given how the migration went.

---

## Quick triage for OSFishing

Ordered by what I'd look at first, based on grepping the source:

1. **`osGetGridName()` in `LeaderboardDisplay.lsl:186` `state_entry`** — bricks the prim on
   any grid that denies it (#1). Same call in 5 other scripts.
2. **No `X-Secondlife-Owner-Key` cross-check** found in `web/` (#13) — a leaked token is
   replayable against any uuid.
3. **Fixed channel constants** (`CH_SPOT_TO_HUD`) rather than owner-derived (#11) — check for
   cross-talk between two players fishing near each other.
4. **Push/queue behavior** for grids denying HTTP-in (#15) and edge-triggered events on the
   offline path (#16).
5. **Deploy path** — confirm it's whole-tree with `--delete` and a pre-flight `php -l` (#17).

I checked 1–3 against the source directly; 4 and 5 I didn't verify and may already be handled.
