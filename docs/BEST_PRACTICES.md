# Best practices

Lessons from building Fertility and OSFishing (same stack, same VPS), written as
general rules. Each line gives the rule, then in brackets where it was learned
and what breaking it cost. Project-specific detail lives in each repo's
CLAUDE.md; this file is the "why we do it this way" list.

Grid-specific LSL ↔ PHP detail: `docs/GRID_COMMS_LESSONS.md`.

---

## 1. Trust and authority

1. **The server decides; clients only ask.** Outcomes, rewards, currency,
   rolls, timers and anything derived from them are computed server-side.
   Never trust a value a client reports about itself (stamina, cooldowns,
   counts). [Fertility trusted HUD-reported stamina and cooldown values; the
   cooldown variables were never even set.]
2. **Every request that acts for a user must prove it is that user.** A user
   id or UUID in a parameter is a claim, not proof. Require a token, a signed
   request or a session. [OSFishing `password_reset` took a UUID and set that
   player's password — full account takeover with one request. Fertility's
   legacy endpoint had a hard-coded admin password.]
3. **Headers are claims too.** `X-SecondLife-*` headers are set by real sims
   but can be forged by hand. Use them as a cross-check on top of a secret,
   never as the only check, and require them rather than checking only when
   present. [Fertility and OSFishing both skipped the owner-key check when the
   header was missing.]
4. **Bind a credential to everything it can be bound to.** For long-lived
   machine credentials: the token, the object it was issued to, its owner,
   the address it was issued from, its environment (grid), and an allow-list
   of actions. Anything mismatching revokes or pauses it and alerts a human.
   Store only a hash of the token. [Admin objects: script memory survives in a
   taken copy and a grid owner can read it; the connecting address is the one
   thing a stolen token can't bring along.]
5. **Hand a secret out once, then never again.** Pairing/approval flows issue
   the token on the first poll after approval and record that it was issued.
6. **One-time links are consumed atomically** (`UPDATE … WHERE used_at IS
   NULL`, check rows affected) and expire fast. Never GET one to "test" it.
7. **Don't let one credential type answer for another.** Separate kinds of
   token (main HUD vs doctor HUD, player vs object) must never be returned or
   accepted in place of each other. [Fertility's Doctor HUD briefly received
   the Main HUD's live token.]
8. **Linking an identity needs explicit, informed consent.** When a new
   avatar/device joins an account, show exactly what joins what and require a
   confirm step; entering someone else's code must not silently hand them your
   account.
9. **Trust identities only where they are verifiable.** An identity minted on
   a system you don't control (a UUID from another grid) is not proof of who
   someone is there. Recovery and other high-value actions only accept an
   identity on the system where it was verified.
10. **Fail closed, and log when you do.** An unknown action, missing
    allow-list entry or unexpected state should be refused — and recorded, so
    a fail-closed default that bites a real feature gets noticed.
    [Fertility's procedure-level check failed *open* to level 1, so a
    specialist-only procedure was available to everyone.]
11. **Roll out a new security check in log-only mode first**, watch the log,
    then enforce. (SimOrigin.)
12. **Remove temporary endpoints the moment the job is done** (upload
    receivers, debug routes, one-off import scripts). [`public/_recv.php` from
    the 2026-09 migration sat in the web root until a `--delete` deploy.]
13. **Never reveal hidden state through a side channel.** If players aren't
    meant to know something, it must not appear in any reply, push, page,
    hint text, count or timing — and sensitive rows reach templates through
    one presenter function only. [Fertility's tick reply carried neighbours'
    real cycle stage; profile counts gave away undiscovered pregnancies.]

## 2. Data, time and schema

14. **One time zone everywhere: UTC.** Pin both the app and the DB session to
    UTC at bootstrap, and still write UTC functions explicitly
    (`UTC_TIMESTAMP()`, `gmdate()`). Display in the viewer's zone at the very
    edge. Watch the traps: `FROM_UNIXTIME`, `UNIX_TIMESTAMP(col)`, column
    defaults and `ON UPDATE` all go through the session zone. [Both projects
    mixed server-local and UTC; a 5-minute code lived 5 hours, implantation
    fired 4 hours early, players looked 4 hours offline.]
15. **One collation for the whole database, stated explicitly on every
    `CREATE TABLE`.** Mixed collations turn ordinary comparisons into errors,
    not slow queries. Don't trust the server default. [OSFishing: half the
    tables were `uca1400`, half `unicode_ci`; the Grids tab and every same-grid
    query failed.]
16. **Schema changes go through versioned, idempotent migrations run by the
    deploy — never by hand, never per request.** [Fertility ran 245 schema
    statements on every request (0.5–0.9 s each), and hand-made DB changes
    never reached the code, so a rebuilt database was missing columns.]
17. **Never do schema work or hidden queries in constructors** or other code
    that runs on every request. [~190 ms per request in Fertility repository
    constructors.]
18. **An allow-list that drops unknown fields must say so.** Silent no-ops on
    "column not allowed" hid lost writes for months. Log the dropped field.
    When adding a column, add it to every allow-list that guards it.
19. **Use real sentinel values, not NULL, for "never set" states that code
    compares against** — a cast can silently turn NULL into 0 and make a
    "not set yet" check unreachable.
20. **A presence/"last seen" column is written by heartbeats only.** Don't
    piggy-back it on other writes, and don't use a gameplay timer as a
    presence signal.
21. **Timers and counters stored as "since" timestamps: reset them to *now*
    when you set the derived value by hand**, or the next tick re-applies all
    the elapsed time. [Fertility doubled a term day overnight.]
22. **A TTL must outlast the longest gap between refreshes**, not the
    nominal interval.
23. **Pick one identity for "the same X" and compare only that.** Grid
    identity is the base domain, not the host; "same region" is region AND
    grid; an account is not an avatar. Write one helper and use it
    everywhere. [Region names repeat across grids; one grid runs many sim
    hosts; account-keyed and avatar-keyed tables were joined directly and
    silently matched nothing.]
24. **Keep the key spaces apart.** If one table is keyed by account and
    another by device/avatar, bridge them explicitly and never join them
    directly — and name the columns so the difference is visible.
25. **Archive, don't delete, anything history points at**, and when you do
    archive an identity, free its unique keys so the name/UUID can be reused.

## 3. SQL and database gotchas

26. **Parameterise everything; whitelist anything that can't be a
    parameter** (ORDER BY column, sort direction, LIMIT as cast int).
27. **With native prepares, a named placeholder can be used only once per
    statement.** Use `:a1`/`:a2` for the same value twice.
28. **Avoid reserved words as names** (`procedure`), even as aliases.
29. **Check the exact DB engine/version before using newer syntax**
    (`CAST(x AS JSON)` isn't MariaDB 10.6).
30. **Atomic claims for "only once" work**: `UPDATE … SET claimed_at=now WHERE
    id=? AND (claimed_at IS NULL OR claimed_at < ?)`, then check rows affected.
    Use it for once-a-day jobs, one-time tokens and per-grid checks.
31. **Prune what grows**: sessions, one-time codes, attempt logs, queues.
    Every table that only grows needs an expiry or a sweep. [Fertility
    collected 316,000 empty session files.]

## 4. Server → client delivery (pushes)

32. **Never assume the client can be reached.** Queue on no address; send
    live when there is one; requeue on failure. Some environments can never
    receive (no HTTP-in): deliver on the client's next request instead.
33. **Delivered means HTTP 2xx, nothing less.** An error body is not
    delivery. Only a definitive "gone" (404/410) forgets the address; a
    timeout or 5xx means "not now".
34. **A failed delivery must never change game state** (presence, targets,
    links). [One slow sim made Fertility players vanish and lose targets.]
35. **Send pushes after the reply, in parallel, with short timeouts** — never
    one by one inside the request. [A region entry with six neighbours could
    take 18 s.]
36. **Queues keep the latest of each state-type and every discrete event**,
    with a cap and an age limit. Collapse repeated status messages, or
    offline users come back to hundreds of them.
37. **Edge-triggered events must be evaluated somewhere that doesn't need the
    user online, and the result queued** — and background jobs queue, they
    never push live. [Fertility's weekly pregnancy message was lost for
    anyone offline at the boundary.]
38. **Keep replies to constrained clients small** (LSL cuts replies at
    2,048 bytes unless the request raises the limit; a truncated JSON reply is
    lost whole, silently). Raise the limit on every request *and* keep replies
    small.
39. **Don't escape what the client won't unescape.** Simple client-side
    parsers don't undo `\/`; send JSON with unescaped slashes, and make the
    client parser tolerant anyway. [The HUD pair link arrived as
    `https:\/\/…` and the viewer rejected it.]
40. **Detect unreachable destinations automatically and tell an admin**
    (private/LAN addresses, firewalls), and support a per-destination
    override (public host) rather than code changes.

## 5. Deployment and operations

41. **Deploy whole branches with one script, never individual files.** The
    script syncs with delete, syntax-checks before going live, runs
    migrations, and only then reloads. A failure stops it before the reload.
    [Single-file copies left the server running weeks-stale code.]
42. **A deploy script that updates itself runs its *old* copy.** Bash has
    already read the file; changes to the deploy script take effect on the
    next deploy. When you change it, run the new steps by hand once.
    [OSFishing's first migration deploy skipped the migrations.]
43. **Take a database backup before any data-rewriting migration**, and keep
    a scheduled backup job. A migration that rewrites data checks its own
    preconditions (e.g. which time zone the data is really in) and refuses to
    guess.
44. **Server-only config is code too.** Keep a committed template
    (`config.example.php`) in step with every change made by hand on the
    server, and back up the live file before editing it.
45. **Never let a client-facing URL depend on anything temporary.** Code
    shipped to devices you can't update (in-world HUDs) is effectively
    permanent: hard-code the final domain, stay on 443, keep routes stable.
46. **Isolate apps that share a server**: own user, keys, process pool,
    database user, session store, `open_basedir`. Secrets readable only by
    their own app.
47. **Keep static and junk traffic out of the app runtime** (favicon,
    scanner probes): let the web server answer or 404 them.
48. **Document what's actually running, not what's planned.** Note
    explicitly when a hardening/backup step is written but not active, so a
    rate limit or ban is never blamed for a problem when it isn't on.

## 6. LSL / in-world scripts

49. **Assume any optional platform call can be denied — and that denial may
    be fatal.** A denied OSSL call halts the script. Never call one in
    `state_entry` before listeners/timers/requests are set up; prefer a
    non-optional alternative (hostname over `osGetGridName`).
50. **Verify "fire-and-forget" calls that fail silently** (`osMakeNotecard`,
    `llSetScriptState` with a wrong name): check the result afterwards and
    tell the owner.
51. **Use the platform's real JSON parser for structured data; test JSON
    booleans against `JSON_TRUE`, presence against `JSON_INVALID`**, and
    prefer the HTTP status for success.
52. **One owner for the timer.** Every place that sets the timer rate goes
    through one function that knows all the reasons the timer runs (pairing
    poll, fades, heartbeat, sleep). Work from deadlines, not "this tick". A
    cosmetic fade must never stop a functional poll. [Fertility's sleeping
    HUD polled forever; a notice fade paused its heartbeat.]
53. **Startup must be self-sufficient**: gate on something that exists (a
    notecard, a token), never on a one-shot flag another script must set.
54. **Static config in notecards, changing state in linkset data.** Never mix.
55. **Don't target an avatar key to reach a script.** `llRegionSayTo(avatar,
    …)` may reach the viewer's chat but not listening scripts on some grids;
    address the object, or announce with `llSay`.
56. **Derive private channels from the owner key**, not fixed constants, so
    other people's objects don't hear each other.
57. **Check who's talking**: filter listens by owner/object, and rate-limit
    anything a stranger can trigger.
58. **Never let one bad item abort a batch**: skip it and carry on.
59. **Emoji and special characters via `llUnescapeURL`**, not `\x` escapes.
60. **Don't let a device's version prompt or update offer depend on a
    server update reaching it** — devices in the field may never update.

Animations and poses (60a-60d are enforced by
`lsl/Animations/make_animations.py`, which refuses to build an animation
that breaks one):

60a. **A bone you don't move is free.** Any bone left at the T-pose (the
    reference frame) is dropped from the animation, so other animations (AO,
    stands) still move it. A still or full-body pose must move every bone a
    little off T-pose, even the "neutral" ones (stomach, neck, collars,
    hands). A layered animation leaves out only the bones it means to leave
    free, and still holds every bone it owns (`fishing_reel` holds the whole
    left arm, wrist and collar included). Decide the nudge per bone over the
    whole animation, never per frame: a per-frame check makes moving bones
    snap to the nudge as they pass through T-pose, a visible twitch. (Morphs
    and shape sliders are a different thing; this is animations and poses.)
60b. **A loop's first real frame equals its last.** Restart the loop on a
    keyframe whose pose is the same as the last one, never at a percentage
    that lands between keys, and carry the motion's speed across the seam.
60c. **Smooth keyframes, not eased segments.** Set a few poses and join them
    with a curve that keeps moving through each one (Catmull-Rom). Easing in
    and out of every keyframe stops the motion dead at each key and looks
    robotic. Keys every 0.1 s plus one per pose is plenty; the viewer fills in.
60d. **Not too fast:** no joint turns faster than 400°/s. A 0.15 s hook-set
    snap hit 1,250°/s and looked like a glitch, not a jerk.

Animations posed on a rig (Blender + Avastar; the gather pair, 2026-11-21).
Scripts and details in `lsl/Animations/blender/` and its README:

60e. **Stay inside the rig's rotation limits unless you mean not to.** The
    Avastar rig has a Limit Rotation on every body bone (elbow: bend on one
    axis, twist ±10°; head pitch to 40°): the range a real joint moves in.
    The export doesn't clamp, so a bone posed past its limit ships exactly
    as posed, unnatural twist and all. Going outside a limit is allowed
    only on purpose, used carefully, and always pointed out to Matthew
    (which bone, how far, why). Print each bone's excess over its limits
    for every frame before exporting; anything over a few degrees is either
    fixed or flagged. `lsl/Animations/check_twist.py` checks the twist of
    every `.anim` against the rig's ranges, and the generator runs it after
    every build. Twist past the limits is what made the first gather
    animations look way off.
60f. **Turn a bone from where its parent leaves it, not from its rest
    pose.** Aiming a bone straight at a direction from rest throws away its
    parent's roll and adds twist far past the limits (collars 60° over, an
    elbow twisted 97° on a ±10° axis). Reset the bone, then turn it the
    shortest way from its current frame (`aim_rel`). For a hinge joint
    (elbow, knee), also roll the bone above it about its own axis until the
    bend lands on the hinge (`arm_hinge`).
60g. **Spread a bend along the chain.** Don't let one joint take the whole
    turn: a neck pointed back up from a chest pointed at the ground folds
    51° at its base and looks crunched even with the head at the right
    angle. Put the neck on the bisector of chest and head (or share the
    angle between them), and look close and from the side at the neck,
    wrists and shoulders before shipping.
60h. **Pose on a rig with standard proportions.** The `.anim` stores joint
    angles, not bone lengths, so any contact solved by position (hands in
    the dirt, kneecaps on the ground, a hand under the chin) only holds for
    the proportions it was solved on. A rig with joint offsets (arms 31%
    long) puts the hands in the dirt in Blender and 9 cm above it in-world.
    If a rig looks off, compare its bone lengths, rolls and constraints with
    a freshly created rig of the same type. Check contacts by the numbers
    (knee, toe and fingertip heights), not by eye.
60i. **Priority 4 for the base, 5 for what plays over it,** written into the
    header and every joint. **Ship `.anim` only**: it carries priority, loop,
    ease and hand pose, so we control every upload option. A `.bvh` takes
    them from the upload window (priority default 3, which loses to the
    default sit). Make `.bvh` files only when Matthew asks for them.
60j. **Sit-based animations, the AVsitter way** (`[AV]sitA.lsl`): stop
    `"sit"` once when animation permission comes back, then start the pose;
    to change pose, start the new one before stopping the old. Don't stop
    every animation on a timer: it fights AOs and kills face animations.
    Fit the sitter to their height by measuring where they ended up and
    correcting, not by trusting the sit target, whose offset varies by grid.

## 7. Web and admin UI

61. **Admin tools are first-class.** Every grid/device/object problem the
    server can detect should become an admin alert with a link to the place
    to fix it, and every alert type needs a one-click resolution (approve,
    clear, accept address, revoke).
62. **Group what is one thing.** Show a grid once even when it has ten
    hosts; settings apply to the whole thing. [Darkhearts appeared as several
    grids, each needing its own approval.]
63. **Restore a session from its cookie on page load.** Don't make users log
    in again on every reload, and never strand a login link on a login form.
64. **Admin pages always show every setting**, with "(default)" when the
    value isn't stored, rather than hiding unsaved rows.
65. **Build the DOM with text nodes; keep raw HTML to constants.** Never
    put server-supplied strings into `innerHTML`.
66. **Cookies: `HttpOnly`, `Secure`, `SameSite=Lax`** at minimum.
67. **Times are shown in the viewer's zone**, stored and sent in UTC.
68. **Admin entry points are visible only to admins** and still enforced
    server-side.

## 8. Working practice

69. **Find the account first.** Resolve any name/UUID with the canonical
    lookup before touching data; never guess an id from memory.
70. **Verify the behaviour, not just the syntax.** A clean lint doesn't
    mean the page draws: boot the script with stubs, or exercise the flow
    end to end, before calling it done. [An admin-page edit passed the syntax
    check and blanked the whole panel.]
71. **When splicing by markers, check nothing else lives between them.**
    Replacing "from A to B" silently deletes whatever sits between. Diff the
    list of functions/sections before and after.
72. **Keep the player-facing changelog in the same commit as the change**,
    hidden until published, in plain language, never spoiling discoveries.
73. **Keep docs honest and current.** Mark stale facts as stale (old hosts,
    old paths) rather than leaving them to mislead; record why a rule exists
    next to the rule.
74. **Read large reference files by search, not whole.** Grep for the
    section you need and read only those lines.
75. **Separate "self-repair" logs from gameplay logs.** A log of silent
    fixes and fail-closed refusals is only useful if normal events never go
    in it.
76. **Show the change before making it when it touches live data or
    shared config**, and take the backup first.
