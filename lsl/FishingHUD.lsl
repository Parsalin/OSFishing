// ============================================================
// FISHING HUD - Main Controller (v3 - minimal bar + media panel)
// ============================================================
// Wear on HUD Bottom. Build the prims with
// lsl/Extra Tools/FishingHUD_Builder.lsl; layout and textures are in
// lsl/Textures/HUD/BUILD.md. The root prim (link 1) is the bar.
//
// REQUIRED LINK NAMES:
//   "HUD_Logo"    — OSFishing logo: opens / closes the panel
//   "HUD_Bait"    — bait chip: count floats above it; tap = Tackle page
//   "HUD_Cast"    — FISH / STOP button
//   "HUD_Text"    — invisible anchor for status + message hover text
//
// OPTIONAL (the fight still works on hover text alone without them):
//   "HUD_Track", "HUD_Meter", "HUD_Fish" — fight strip above the bar
//      (meter = cast power, then line tension; fish = what the fish is doing)
//   "PanelBack", "PanelMedia", "PanelClose", "PanelOpen", "PanelSwap"
//      — the media panel. PanelSwap switches between the live fishing view
//        (served by this script from its own URL) and the website menu.
//   "TutorialText", "TutorialPointer" — the tutorial
//
// CHANNELS:
//   -7710001 : HUD -> Rod
//   -7710002 : Rod -> HUD
//   -7710003 : HUD -> Bobber
//   -7710004 : Bobber -> HUD
//   -7710005 : Fishing Spot -> HUD
// ============================================================

// ── Configuration (HARDCODED - no notecard) ──
string  gApiUrl = "https://osfishing.flamesfall.net/api/";

// ── Pairing State (lives only in script memory) ──
integer gTokenId      = 0;
string  gToken        = "";
integer gNonce        = 0;
string  gPairingCode  = "";
integer gPairing      = FALSE;
float   gPairPollRate = 5.0;
integer gPairTimeout  = 0;
integer gLinksetDataAvailable = TRUE;  // Auto-detected on first use

// ── Callback URL (server push) ──
string  gCallbackUrl  = "";        // Our HTTPS (or HTTP) URL for server pushes
key     gUrlRequest   = NULL_KEY;  // Request key from llRequestSecureURL
integer gTriedPlainUrl = FALSE;  // Fell back from llRequestSecureURL to llRequestURL

// ── Tutorial State ──
integer gTutorialActive    = FALSE;  // Is the tutorial currently running?
integer gTutorialStep      = 0;      // Current step (0..N)
integer gTutorialAsked     = FALSE;  // Have we asked "skip or continue?"
integer gTutorialDialogCh  = 0;
integer gTutorialDialogHandle = 0;
// Tutorial linked-prim references (resolved at startup by name)
integer TUT_LINK_TEXT      = 0;      // TutorialText prim
integer TUT_LINK_POINTER   = 0;      // TutorialPointer prim

// ── LinksetData wrappers (graceful fallback if not supported) ──
// Returns 1 on success, 0 on failure
// ── Grid detection ────────────────────────────────────────
// Never call osGetGridName() / osGetGridLoginURI(). On a grid that denies the
// function the call does not return empty — it raises an OSSL Permission Error
// that HALTS the script ("Script must be Reset to re-enable"). In state_entry
// that bricks the object on rez with no timer and no retry path, and LSL has
// no try/catch, so there is no safe guarded form of the call.
// Hostname is always readable; the server normalizes it to a grid.
string detectGrid() {
    string h = llGetSimulatorHostname();
    if (h == "") h = llGetEnv("simulator_hostname");
    if (h == "") return "unknown";
    if (llSubStringIndex(h, ":") == -1) h += ":8002";
    return h;
}

integer safeLDWrite(string ldKey, string val) {
    if (!gLinksetDataAvailable) return 0;
    integer result = llLinksetDataWrite(ldKey, val);
    // 0 = success in LSL/OpenSim
    if (result != 0) {
        // Verify via read-back — some OpenSim builds return non-zero on success
        if (llLinksetDataRead(ldKey) == val) {
            return 1;
        }
        return 0;
    }
    return 1;
}

string safeLDRead(string ldKey) {
    if (!gLinksetDataAvailable) return "";
    return llLinksetDataRead(ldKey);
}

safeLDDelete(string ldKey) {
    if (!gLinksetDataAvailable) return;
    llLinksetDataDelete(ldKey);
}

// ── Save current pairing to persistent storage ──
// ── Display cache (avoids "loading..." after region restart) ──
saveDisplayCache() {
    safeLDWrite("hud_cache_bait", gEquippedBait);
    safeLDWrite("hud_cache_baitqty", (string)gBaitRemaining);
    safeLDWrite("hud_cache_line", gEquippedLine);
    safeLDWrite("hud_cache_level", (string)gLevel);
    safeLDWrite("hud_cache_xp", (string)gXP);
    safeLDWrite("hud_cache_xptn", (string)gXpToNext);
    safeLDWrite("hud_cache_xptot", (string)gXpLevelTotal);
    safeLDWrite("hud_cache_pts", (string)gPoints);
}

loadDisplayCache() {
    string b = safeLDRead("hud_cache_bait");
    if (b != "") gEquippedBait = b;
    string bq = safeLDRead("hud_cache_baitqty");
    if (bq != "") gBaitRemaining = (integer)bq;
    string ln = safeLDRead("hud_cache_line");
    if (ln != "") gEquippedLine = ln;
    string lv = safeLDRead("hud_cache_level");
    if (lv != "") gLevel = (integer)lv;
    string xp = safeLDRead("hud_cache_xp");
    if (xp != "") gXP = (integer)xp;
    string xtn = safeLDRead("hud_cache_xptn");
    if (xtn != "") gXpToNext = (integer)xtn;
    string xtot = safeLDRead("hud_cache_xptot");
    if (xtot != "") gXpLevelTotal = (integer)xtot;
    string pts = safeLDRead("hud_cache_pts");
    if (pts != "") gPoints = (integer)pts;
}

saveToken() {
    if (gToken == "" || gTokenId == 0) return;
    if (!gLinksetDataAvailable) return;
    integer ok1 = safeLDWrite("fishing_token", gToken);
    integer ok2 = safeLDWrite("fishing_token_id", (string)gTokenId);
    integer ok3 = safeLDWrite("fishing_nonce", (string)gNonce);
    integer ok4 = safeLDWrite("fishing_owner_uuid", (string)llGetOwner());
    if (ok1 && ok2 && ok3 && ok4) {
        hudInfo("Pairing saved. Won't need to re-pair on attach.");
    }
}

// ── Try to load pairing from persistent storage ──
// Returns TRUE if a valid token was loaded
integer loadToken() {
    string tok = safeLDRead("fishing_token");
    string tid = safeLDRead("fishing_token_id");
    string non = safeLDRead("fishing_nonce");
    string savedUuid = safeLDRead("fishing_owner_uuid");

    // If the HUD changed hands, clear everything
    if (savedUuid != "" && savedUuid != (string)llGetOwner()) {
        clearToken();
        return FALSE;
    }

    if (tok != "" && tid != "" && (integer)tid > 0) {
        gToken = tok;
        gTokenId = (integer)tid;
        gNonce = (integer)non;
        return TRUE;
    }
    return FALSE;
}

// ── Clear persistent pairing (on revoke/error) ──
clearToken() {
    gToken = "";
    gTokenId = 0;
    gNonce = 0;
    safeLDDelete("fishing_token");
    safeLDDelete("fishing_token_id");
    safeLDDelete("fishing_nonce");
    safeLDDelete("fishing_owner_uuid");
    // Clear display cache too
    safeLDDelete("hud_cache_bait");
    safeLDDelete("hud_cache_baitqty");
    safeLDDelete("hud_cache_line");
    safeLDDelete("hud_cache_level");
    safeLDDelete("hud_cache_xp");
    safeLDDelete("hud_cache_xptn");
    safeLDDelete("hud_cache_xptot");
    safeLDDelete("hud_cache_pts");
}

// ── Persist nonce as it advances (so reattach doesn't replay) ──
saveNonce() {
    if (!gLinksetDataAvailable) return;
    if (gToken == "") return;
    safeLDWrite("fishing_nonce", (string)gNonce);
}

// ── Callback URL: request a secure URL for server push ──
requestCallbackUrl() {
    // Release any existing URL first
    if (gCallbackUrl != "") {
        llReleaseURL(gCallbackUrl);
        gCallbackUrl = "";
    }
    gTriedPlainUrl = FALSE;   // fresh attempt: allow the plain-HTTP fallback again
    gUrlRequest = llRequestSecureURL();
    // If llRequestSecureURL isn't supported, the http_request event
    // will fire with URL_REQUEST_DENIED and we'll try llRequestURL.
}

// ── Register callback URL with the server ──
registerCallbackUrl() {
    if (gCallbackUrl == "" || gToken == "") return;
    apiCall("register_callback", "callback_url=" + llEscapeURL(gCallbackUrl));
}

// ── Communication Channels ──
integer CH_HUD_TO_ROD    = -7710001;
integer CH_ROD_TO_HUD    = -7710002;
integer CH_HUD_TO_BOBBER = -7710003;
integer CH_BOBBER_TO_HUD = -7710004;
integer CH_SPOT_TO_HUD   = -7710005;
integer CH_GATHER_HUD   = -7710006;  // Gather point <-> HUD
integer CH_TOURNAMENT   = -7710007;  // Tournament board catch notifications

// ── Link Number Cache (populated on scan; -1 = not present) ──
integer LINK_ROOT        = -1;   // the bar
integer LINK_LOGO        = -1;
integer LINK_BAIT        = -1;
integer LINK_CAST        = -1;
integer LINK_TEXT        = -1;
integer LINK_TRACK       = -1;
integer LINK_METER       = -1;
integer LINK_FISH        = -1;
integer LINK_PANEL_BACK  = -1;
integer LINK_PANEL_MEDIA = -1;
integer LINK_PANEL_CLOSE = -1;
integer LINK_PANEL_OPEN  = -1;
integer LINK_PANEL_SWAP  = -1;

// ── Textures (in the root prim; see lsl/Textures/HUD/BUILD.md) ──
string  TEX_LOGO      = "hud_logo_sheet";   // 2 cells: normal, panel open
string  TEX_BUTTONS   = "hud_buttons";      // 2 x 4 cells
string  TEX_ICONS     = "hud_icons";        // 8 x 4 cells
string  TEX_MEDIA_LOADING     = "hud_media_loading";
string  TEX_MEDIA_UNAVAILABLE = "hud_media_unavailable";

// hud_buttons cells
integer BTN_FISH       = 0;
integer BTN_STOP       = 1;
integer BTN_FISH_DIM   = 2;   // no spot nearby / no bait
integer BTN_BAIT       = 3;
integer BTN_BAIT_LOW   = 4;
integer BTN_BAIT_EMPTY = 5;
integer BTN_MENU       = 6;   // PanelSwap while the fishing view is showing
integer BTN_FISHING    = 7;   // PanelSwap while the website is showing
// hud_icons cells
integer ICON_FISH_CALM  = 0;
integer ICON_FISH_LEFT  = 1;
integer ICON_FISH_RIGHT = 2;
integer ICON_FISH_JUMP  = 3;
integer ICON_FISH_TIRED = 4;
integer ICON_JUNK       = 5;
integer BAIT_LOW_AT     = 5;  // amber chip at or below this many

// ── Layout ──
vector  HIDDEN_SIZE     = <0.01, 0.01, 0.01>;
float   HOME_GAP        = 0.0444;   // bar's bottom edge ~32px above the screen edge
integer MEDIA_FACE      = 4;        // PanelMedia face that shows the page
integer MEDIA_W_PX      = 640;
integer MEDIA_H_PX      = 724;

// ── Panel state ──
integer gPanelOpen      = FALSE;
integer gPanelFishView  = FALSE;    // TRUE = live fishing view, FALSE = website
string  gPanelPage      = "dashboard";
// ── Text state (one hover text: message on top, status under it) ──
string  gStatusText     = "";
vector  gStatusColor    = <1.0, 1.0, 1.0>;
string  gInfoMsg        = "";
integer gInfoSeq        = 0;
float   gInfoFadeTime   = 0.0;
integer gInfoVisible    = FALSE;
integer gNibbleSeq      = 0;        // bumps on each nibble, for the fishing view
integer gStripShown     = -1;       // fight strip visible? (-1 = unknown)
string  gLastRarity     = "";       // rarity name of the last catch

integer gLinksScanned = FALSE;

// ── Player State ──
key     gOwner          = NULL_KEY;
string  gOwnerName      = "";
integer gLevel          = 1;
integer gXP             = 0;
integer gXpToNext       = 100;  // XP remaining until next level
integer gXpLevelTotal  = 100;  // Total XP span for current level
integer gPoints         = 0;
string  gEquippedBait   = "None";
integer gEquippedBaitId = 0;
integer gBaitRemaining  = 0;

// ── Equipped Line ──
string  gEquippedLine   = "Twine";
float   gLineWeight     = 1.5;
float   gLineVisibility = 2.0;
string  gEquippedRod    = "Bamboo Rod";
integer gEquippedRodId  = 1;
integer gRegistered     = FALSE;

// ── State Machine ──
integer STATE_IDLE      = 0;
integer STATE_CHARGING  = 1;
integer STATE_CAST      = 2;
integer STATE_NIBBLE    = 3;
integer STATE_BITE      = 4;
integer STATE_HOOKSET   = 5;
integer STATE_FIGHT     = 6;
integer STATE_LANDING   = 7;
integer STATE_VICTORY   = 9;   // After catch, holding controls for delay
integer STATE_MENU      = 8;
integer STATE_REELING   = 10;  // Reeling line back in during wait
integer gState = 0;

// ── Cast Variables ──
float   gCastPower   = 0.0;
float   gChargeRate  = 0.4;
integer gCharging    = FALSE;
float   gReelInDist  = 0.0;   // Current bobber distance during reel-in

// ── Fishing Spot ──
integer gCurrentSpotId    = 0;
string  gCurrentSpotName  = "";
string  gCurrentWaterType = "pond";  // pond/river/lake/ocean
integer gCurrentSpotLevel = 1;
vector  gSpotPos          = ZERO_VECTOR;
float   gSpotRadius       = 0.0;          // 0 = no radius received, skip bad cast check

// ── Wait-for-Bite State (new scheduled-roll system) ──
// Scheduled bite opportunities. Each schedule entry fires NIBBLE or BITE.
list    gSchedTimes        = [];    // Floats: seconds into current minute when something fires
list    gSchedFishJson     = [];    // Strings: JSON blob for real bite, empty string for fake nibble
integer gSchedIndex        = 0;     // Next entry to fire
integer gCurrentMinute     = 1;     // Which minute we're on
float   gMinuteElapsed     = 0.0;   // Seconds into current minute (resets at minute boundaries)
float   gCastTotalElapsed  = 0.0;   // Total seconds since cast began (for reel_in + afk cutoff)
integer gDeadWater         = FALSE; // Server said no fish possible
float   gBiteWindow        = 10.0;  // Seconds to set the hook when real bite fires
integer gBiteActive        = FALSE; // TRUE during the bite-window timer
string  gCatchToken        = "";    // Server-signed token for currently hooked fish

// ── Catch Data ──
string  gFishName      = "";
float   gFishWeight    = 0.0;

// ── Format weight to 2 decimal places ──
string fmtWeight(float w) {
    string s = (string)w;
    integer dot = llSubStringIndex(s, ".");
    if (dot == -1) return s + ".00";
    string dec = llGetSubString(s, dot + 1, dot + 2);
    if (llStringLength(dec) < 2) dec += "0";
    return llGetSubString(s, 0, dot) + dec;
}
integer gFishSpeciesId = 0;
integer gRarityId      = 0;

// ── Fight Variables ──
float   gFightDistance    = 50.0;
vector  gAnchorPos       = ZERO_VECTOR;  // Where the bobber landed (fight center)
float   gFightAngle      = 0.0;          // Current angle offset in radians (0 = center)
float   gFightAngleTarget = 0.0;         // Target angle (shifts on rush left/right)
float   gLineStrength     = 100.0;
float   gTension          = 0.0;

// Fish state machine
integer FISH_STRUGGLING   = 0;   // Jumping/thrashing - DON'T reel
integer FISH_RUSHING_LEFT = 1;   // Pulling left - counter with right
integer FISH_RUSHING_RIGHT= 2;   // Pulling right - counter with left
integer FISH_EXHAUSTED    = 3;   // Tired - reel as much as possible

integer gFishState        = 3;   // Start exhausted briefly so player can react
float   gFishStateTime    = 0.0; // How long in current state
float   gFishStateMinDur  = 2.0; // Minimum duration in any state
integer gIsJunkFight      = FALSE; // Deadweight junk fight mode
float   gJunkTensionRate  = 10.0;  // How fast tension builds when reeling junk
float   gFishStaminaMax   = 100.0;
float   gFishStamina      = 100.0;
float   gFishPower        = 1.0; // Strength multiplier - bigger fish = more power
float   gFishUnpredict    = 0.5;
float   gFightTick        = 0.5;
float   gFightElapsed     = 0.0;
float   gReelRate         = 2.0;

integer gPlayerReeling   = FALSE;
integer gPlayerLineOut   = FALSE;  // Page Up during fight = let line out
integer gPlayerDirection = 0;    // -1 left, 0 none, +1 right

// ── Bobber / Rod ──
key     gBobberKey = NULL_KEY;
key     gRodKey    = NULL_KEY;   // learned from the rod's ROD_HELLO

// Send a command to our rod. To its key once known; until then (or after
// it's gone) llSay on the rod channel, which the worn rod always hears and
// other players' rods ignore (they check the owner). Never
// llRegionSayTo(avatar): that may not reach attachment scripts.
toRod(string msg) {
    if (gRodKey != NULL_KEY && llGetListLength(llGetObjectDetails(gRodKey, [OBJECT_POS])) > 0) {
        llRegionSayTo(gRodKey, CH_HUD_TO_ROD, msg);
    } else {
        gRodKey = NULL_KEY;
        llSay(CH_HUD_TO_ROD, msg);
    }
}

// ── Gather point we're gathering at (replies go to its key, not the region) ──
key     gGatherKey  = NULL_KEY;
integer gGatherId   = 0;

// TRUE if object `obj` is within `range` metres of us. Used to ignore
// messages on shared channels from objects elsewhere in the region.
integer isNear(key obj, float range) {
    list d = llGetObjectDetails(obj, [OBJECT_POS]);
    if (llGetListLength(d) == 0) return FALSE;
    return llVecDist(llGetPos(), llList2Vector(d, 0)) <= range;
}

// ── HTTP ──
key     gHttpReq    = NULL_KEY;
string  gHttpAction = "";

// ── Dialog ──
integer gDialogCh     = 0;
integer gDialogHandle = 0;

// ══════════════════════════════════════════════════════════
// LINK SCANNING
// ══════════════════════════════════════════════════════════

// Scan all prims in the link set and match names to variables.
// Called on state_entry and on CHANGED_LINK events.
scanLinks() {
    if (gPanelOpen) closePanel();   // with the old link numbers, before they reset
    LINK_ROOT = LINK_LOGO = LINK_BAIT = LINK_CAST = LINK_TEXT = -1;
    LINK_TRACK = LINK_METER = LINK_FISH = -1;
    LINK_PANEL_BACK = LINK_PANEL_MEDIA = LINK_PANEL_CLOSE = LINK_PANEL_OPEN = LINK_PANEL_SWAP = -1;

    integer numPrims = llGetNumberOfPrims();
    integer i;

    // Root is always link 1 (LINK_THIS for a single prim).
    LINK_ROOT = 1;
    if (numPrims == 1) LINK_ROOT = LINK_THIS;
    for (i = 2; i <= numPrims; i++) {
        string name = llGetLinkName(i);
        if (name == "HUD_Logo")            LINK_LOGO = i;
        else if (name == "HUD_Bait")       LINK_BAIT = i;
        else if (name == "HUD_Cast")       LINK_CAST = i;
        else if (name == "HUD_Text")       LINK_TEXT = i;
        else if (name == "HUD_Track")      LINK_TRACK = i;
        else if (name == "HUD_Meter")      LINK_METER = i;
        else if (name == "HUD_Fish")       LINK_FISH = i;
        else if (name == "PanelBack")      LINK_PANEL_BACK = i;
        else if (name == "PanelMedia")     LINK_PANEL_MEDIA = i;
        else if (name == "PanelClose")     LINK_PANEL_CLOSE = i;
        else if (name == "PanelOpen")      LINK_PANEL_OPEN = i;
        else if (name == "PanelSwap")      LINK_PANEL_SWAP = i;
    }

    list missing = [];
    if (LINK_LOGO == -1) missing += ["HUD_Logo"];
    if (LINK_BAIT == -1) missing += ["HUD_Bait"];
    if (LINK_CAST == -1) missing += ["HUD_Cast"];
    if (LINK_TEXT == -1) missing += ["HUD_Text"];

    if (llGetListLength(missing) > 0) {
        llOwnerSay("╔═══════════════════════════════════════════");
        llOwnerSay("║ HUD LINK SCAN - MISSING PRIMS");
        llOwnerSay("╠═══════════════════════════════════════════");
        llOwnerSay("║ The following link names were not found:");
        integer mi;
        for (mi = 0; mi < llGetListLength(missing); mi++) {
            llOwnerSay("║   • " + llList2String(missing, mi));
        }
        llOwnerSay("╠═══════════════════════════════════════════");
        llOwnerSay("║ Build the HUD with FishingHUD_Builder (see");
        llOwnerSay("║ lsl/Textures/HUD/BUILD.md), or name the prims");
        llOwnerSay("║ by hand, then touch the HUD to rescan.");
        llOwnerSay("╚═══════════════════════════════════════════");
        gLinksScanned = FALSE;
        return;
    }

    // Remember the built size/position of every prim the script hides or
    // moves, while they're still at their built layout (the builder clears
    // these and resets this script). After that, hidden prims come back from
    // the record, not from their current (shrunken) size.
    list remember = [LINK_TRACK, "HUD_Track", LINK_METER, "HUD_Meter", LINK_FISH, "HUD_Fish",
                     LINK_TEXT, "HUD_Text",
                     LINK_PANEL_BACK, "PanelBack", LINK_PANEL_MEDIA, "PanelMedia",
                     LINK_PANEL_CLOSE, "PanelClose", LINK_PANEL_OPEN, "PanelOpen",
                     LINK_PANEL_SWAP, "PanelSwap"];
    for (i = 0; i < llGetListLength(remember); i += 2) {
        integer lk = llList2Integer(remember, i);
        string nm = llList2String(remember, i + 1);
        if (lk != -1 && safeLDRead("hud_size_" + nm) == "") {
            list p = llGetLinkPrimitiveParams(lk, [PRIM_SIZE, PRIM_POS_LOCAL]);
            vector sz = llList2Vector(p, 0);
            if (sz.y > HIDDEN_SIZE.y * 1.5 || sz.z > HIDDEN_SIZE.z * 1.5 || nm == "HUD_Text") {
                safeLDWrite("hud_size_" + nm, (string)sz);
                safeLDWrite("hud_pos_" + nm, (string)llList2Vector(p, 1));
            }
        }
    }

    gLinksScanned = TRUE;
    gStripShown = -1;
    findTutorialPrims();
    if (!gTutorialActive) hidePrim(TUT_LINK_POINTER);
    hidePanelPrims();
    updateStrip();
    updateCastButton();
    setLogo(FALSE);
}

// ── Built layout records ──
vector builtSize(integer link, string name) {
    string s = safeLDRead("hud_size_" + name);
    if (s != "") return (vector)s;
    return llList2Vector(llGetLinkPrimitiveParams(link, [PRIM_SIZE]), 0);
}

vector builtPos(integer link, string name) {
    string s = safeLDRead("hud_pos_" + name);
    if (s != "") return (vector)s;
    return llList2Vector(llGetLinkPrimitiveParams(link, [PRIM_POS_LOCAL]), 0);
}

// "Hidden" = tiny AND transparent: a transparent prim still catches clicks.
hidePrim(integer link) {
    if (link < 2) return;   // never the root; 0 / -1 = prim not present
    llSetLinkPrimitiveParamsFast(link, [PRIM_SIZE, HIDDEN_SIZE,
        PRIM_COLOR, ALL_SIDES, <1.0, 1.0, 1.0>, 0.0, PRIM_TEXT, "", ZERO_VECTOR, 0.0]);
}

showPrim(integer link, string name) {
    if (link < 2) return;
    llSetLinkPrimitiveParamsFast(link, [PRIM_SIZE, builtSize(link, name),
        PRIM_POS_LOCAL, builtPos(link, name), PRIM_COLOR, ALL_SIDES, <1.0, 1.0, 1.0>, 1.0]);
}

// Pick a cell on a sheet texture: cols x rows grid, cell = row * cols + col.
setCell(integer link, string tex, integer cols, integer rows, integer cell) {
    if (link == -1) return;
    if (llGetInventoryType(tex) != INVENTORY_TEXTURE) return;
    float rx = 1.0 / cols;
    float ry = 1.0 / rows;
    integer col = cell % cols;
    integer row = cell / cols;
    llSetLinkPrimitiveParamsFast(link, [PRIM_TEXTURE, ALL_SIDES, tex, <rx, ry, 0.0>,
        <(col + 0.5) * rx - 0.5, 0.5 - (row + 0.5) * ry, 0.0>, 0.0]);
}

setLogo(integer opened) {
    setCell(LINK_LOGO, TEX_LOGO, 2, 1, opened);
}

// FISH / STOP / dimmed FISH (nothing to fish at yet)
updateCastButton() {
    integer cell = BTN_FISH;
    if (gState != STATE_IDLE && gState != STATE_MENU) cell = BTN_STOP;
    else if (gCurrentSpotId == 0 || gEquippedBaitId == 0 || gBaitRemaining <= 0) cell = BTN_FISH_DIM;
    setCell(LINK_CAST, TEX_BUTTONS, 2, 4, cell);
}

// Bait chip: icon on the chip, name and count floating above it.
updateBaitChip() {
    if (LINK_BAIT == -1) return;
    integer cell = BTN_BAIT;
    string txt;
    vector col = <1.0, 1.0, 1.0>;
    if (gToken == "") {
        txt = "";
    } else if (gEquippedBaitId == 0 || gBaitRemaining <= 0) {
        cell = BTN_BAIT_EMPTY;
        col = <1.0, 0.45, 0.42>;
        if (gEquippedBaitId == 0) txt = "No bait - tap";
        else txt = gEquippedBait + ": 0";
    } else {
        if (gBaitRemaining <= BAIT_LOW_AT) {
            cell = BTN_BAIT_LOW;
            col = <1.0, 0.75, 0.3>;
        }
        txt = gEquippedBait + " ×" + (string)gBaitRemaining;
    }
    setCell(LINK_BAIT, TEX_BUTTONS, 2, 4, cell);
    llSetLinkPrimitiveParamsFast(LINK_BAIT, [PRIM_TEXT, txt, col, 1.0]);
}

// ── Hover text: the latest message on top, the status line under it ──
renderText() {
    if (LINK_TEXT == -1) return;
    string t = gInfoMsg;
    if (gStatusText != "") {
        if (t != "") t += "\n";
        t += gStatusText;
    }
    vector col = gStatusColor;
    if (gInfoMsg != "") col = <0.95, 0.97, 1.0>;
    llSetLinkPrimitiveParamsFast(LINK_TEXT, [PRIM_TEXT, t, col, 1.0]);
}

setStatus(string msg, vector col) {
    gStatusText = msg;
    gStatusColor = col;
    renderText();
}

// ══════════════════════════════════════════════════════════
// UTILITY FUNCTIONS
// ══════════════════════════════════════════════════════════


// ── Build authenticated request body ──
// Signature = SHA256("uuid:timestamp:nonce:action:token")
string buildAuthBody(string action, string extraParams) {
    gNonce++;
    saveNonce();  // Persist immediately so a crash doesn't replay
    string uuid = (string)gOwner;
    string ts   = (string)llGetUnixTime();
    string n    = (string)gNonce;
    string sig  = llSHA256String(uuid + ":" + ts + ":" + n + ":" + action + ":" + gToken);

    string body = "action=" + llEscapeURL(action) +
                  "&uuid=" + llEscapeURL(uuid) +
                  "&token_id=" + (string)gTokenId +
                  "&timestamp=" + ts +
                  "&nonce=" + n +
                  "&signature=" + llEscapeURL(sig);
    if (extraParams != "") body += "&" + extraParams;
    return body;
}

// ── Authenticated API call (requires pairing) ──
key apiCall(string action, string extraParams) {
    if (gToken == "") {
        llOwnerSay("HUD not paired yet. Cannot make request.");
        return NULL_KEY;
    }
    gHttpAction = action;
    return llHTTPRequest(gApiUrl, [
        HTTP_METHOD, "POST",
        HTTP_MIMETYPE, "application/x-www-form-urlencoded",
        HTTP_BODY_MAXLENGTH, 16384
    ], buildAuthBody(action, extraParams));
}

// ── Unauthenticated API call (pairing flow only) ──
key apiCallPublic(string action, string extraParams) {
    gHttpAction = action;
    string body = "action=" + llEscapeURL(action);
    if (extraParams != "") body += "&" + extraParams;
    return llHTTPRequest(gApiUrl, [
        HTTP_METHOD, "POST",
        HTTP_MIMETYPE, "application/x-www-form-urlencoded",
        HTTP_BODY_MAXLENGTH, 4096
    ], body);
}

// ── Pairing flow helpers ──
startupCheck() {
    // Try to load a saved token first
    if (loadToken()) {
        // Load cached display values for instant rendering
        loadDisplayCache();
        updateMainDisplay();
        showStatus("Restoring session...");
        gHttpReq = apiCall("hud_status", "");
        return;
    }
    // No saved token — start fresh: check if account exists
    gHttpReq = apiCallPublic("check_account", "uuid=" + llEscapeURL((string)gOwner) + "&grid_name=" + llEscapeURL(detectGrid()));
}
requestPairing() {
    gHttpReq = apiCallPublic("pair_request", "uuid=" + llEscapeURL((string)gOwner) + "&grid_name=" + llEscapeURL(detectGrid()));
}
pollPairingStatus() {
    if (gPairingCode == "") return;
    if (llGetUnixTime() > gPairTimeout) {
        // Only expire if no pending HTTP request — avoid race condition
        // where the last poll's response arrives after we declare expiry
        gPairing = FALSE;
        gPairingCode = "";
        restoreTimer();
        showStatus("Pairing expired - touch to retry");
        return;
    }
    gHttpReq = apiCallPublic("pair_status",
        "uuid=" + llEscapeURL((string)gOwner) +
        "&code=" + llEscapeURL(gPairingCode));
}

showStatus(string msg) {
    setStatus("🎣 " + msg, <1.0, 0.85, 0.4>);
}

// ─────────────────────────────────────────────────────────
// TUTORIAL — find linked prims, dispatch instructions
// ─────────────────────────────────────────────────────────

// Pointer overlay offset on the X axis (depth).
// HUD plane is at X=0; pointer sits slightly forward toward camera.
float POINTER_FORWARD_X = -0.012;   // one layer in front of the button it circles

// Locate the TutorialText and TutorialPointer child prims by name.
findTutorialPrims() {
    TUT_LINK_TEXT = 0;
    TUT_LINK_POINTER = 0;
    integer total = llGetNumberOfPrims();
    integer i;
    for (i = 1; i <= total; i++) {
        string nm = llGetLinkName(i);
        if (nm == "TutorialText") TUT_LINK_TEXT = i;
        else if (nm == "TutorialPointer") TUT_LINK_POINTER = i;
    }
}

// Find a child prim by name and return its local position, or
// ZERO_VECTOR if not found.
vector getLinkLocalPos(string name) {
    integer total = llGetNumberOfPrims();
    integer i;
    for (i = 1; i <= total; i++) {
        if (llGetLinkName(i) == name) {
            list p = llGetLinkPrimitiveParams(i, [PRIM_POS_LOCAL]);
            return llList2Vector(p, 0);
        }
    }
    return ZERO_VECTOR;
}

// Set TutorialText prim's hover text directly.
tutSetText(string text) {
    if (TUT_LINK_TEXT > 0) {
        if (text == "") {
            llSetLinkPrimitiveParamsFast(TUT_LINK_TEXT,
                [PRIM_TEXT, "", <1.0, 1.0, 1.0>, 0.0]);
        } else {
            llSetLinkPrimitiveParamsFast(TUT_LINK_TEXT,
                [PRIM_TEXT, text, <0.4, 1.0, 0.6>, 1.0]);
        }
    }
}

// Move and show the TutorialPointer over a named button.
// buttonName must match the actual prim name of the button.
tutSetPointer(string buttonName) {
    if (TUT_LINK_POINTER == 0) return;

    // Tackle is the bait chip; every other page lives behind the logo.
    string targetPrim = "";
    if (buttonName == "tackle")      targetPrim = "HUD_Bait";
    else if (buttonName == "play")   targetPrim = "HUD_Cast";
    else if (buttonName == "fish" || buttonName == "quests" || buttonName == "shop") targetPrim = "HUD_Logo";

    if (targetPrim == "") {
        hidePrim(TUT_LINK_POINTER);
        return;
    }

    vector btnPos = getLinkLocalPos(targetPrim);
    if (btnPos == ZERO_VECTOR && targetPrim != "HUD_Logo") {
        // Couldn't find button — bail silently
        return;
    }
    // A highlight ring a little bigger than the button it circles.
    vector btnSize = ZERO_VECTOR;
    integer bi;
    for (bi = 2; bi <= llGetNumberOfPrims(); bi++)
        if (llGetLinkName(bi) == targetPrim)
            btnSize = llList2Vector(llGetLinkPrimitiveParams(bi, [PRIM_SIZE]), 0);
    float ring = btnSize.z * 1.35;
    if (btnSize.y * 0.8 > ring) ring = btnSize.y * 0.8;
    if (ring < 0.04) ring = 0.04;

    llSetLinkPrimitiveParamsFast(TUT_LINK_POINTER, [
        PRIM_SIZE, <0.01, ring, ring>,
        PRIM_POS_LOCAL, <btnPos.x + POINTER_FORWARD_X, btnPos.y, btnPos.z>,
        PRIM_COLOR, ALL_SIDES, <1.0, 1.0, 1.0>, 1.0,
        PRIM_TEXT, "👆", <1.0, 0.9, 0.2>, 1.0
    ]);
}

// Tutorial step instruction text — central registry.
// Returns instruction text and what button to point at, separated by '|'.
// Buttons: tackle | fish | play | quests | shop | hide
string tutorialStepInfo(integer step) {
    if (step == 0)  return "Welcome to OSFishing! Click the ring to begin.|play";
    if (step == 1)  return "The logo opens your menu. Click the ring for Quests.|quests";
    if (step == 2)  return "Accept any quest, then click the ring to continue.|quests";
    if (step == 3)  return "The bait chip opens your Tackle. Click the ring.|tackle";
    if (step == 4)  return "Equip a bait, then click the ring to continue.|tackle";
    if (step == 5)  return "Walk near a fishing spot. Look for 🎣 in the world.|hide";
    if (step == 6)  return "Click FISH to start fishing.|play";
    if (step == 7)  return "Hold PgUp (or E/W) to charge, release to cast.|hide";
    if (step == 8)  return "Bite? PgDn to hook! Then follow the fish:\nreel when it tires, steer against its runs.|hide";
    if (step == 9)  return "Caught one! Click the ring to see your fish.|fish";
    if (step == 10) return "Click the ring to open the Shop.|shop";
    if (step == 11) return "Sell a fish, then click the ring to continue.|shop";
    if (step == 12) return "All done! Click the pointer to finish.|play";
    return "|hide";
}

// Display the current tutorial step.
showTutorialStep() {
    string info = tutorialStepInfo(gTutorialStep);
    integer pipeIdx = llSubStringIndex(info, "|");
    string text = llGetSubString(info, 0, pipeIdx - 1);
    string ptr  = llGetSubString(info, pipeIdx + 1, -1);
    tutSetText("📘 Tutorial (" + (string)(gTutorialStep + 1) + "/13)\n" + text);
    tutSetPointer(ptr);
}

// Advance the tutorial. Updates server-side, then displays new step.
advanceTutorial() {
    gTutorialStep++;
    if (gTutorialStep >= 13) {
        // Done!
        finishTutorial(FALSE);
        return;
    }
    showTutorialStep();
    gHttpReq = apiCall("hud_tutorial_set_step", "step=" + (string)gTutorialStep);
}

// Finish or skip the tutorial.
finishTutorial(integer skipped) {
    gTutorialActive = FALSE;
    tutSetText("");
    tutSetPointer("hide");
    if (skipped) {
        hudInfo("Tutorial skipped. You can restart it from the website Settings page.");
    } else {
        hudInfo("Tutorial complete! Have fun fishing.");
    }
    gHttpReq = apiCall("hud_tutorial_complete", "");
}

// Start (or resume) the tutorial.
startTutorial(integer fromStep) {
    findTutorialPrims();
    if (TUT_LINK_TEXT == 0 && TUT_LINK_POINTER == 0) {
        // Tutorial child prims not present — silently skip
        gTutorialActive = FALSE;
        gHttpReq = apiCall("hud_tutorial_complete", "");
        return;
    }
    gTutorialActive = TRUE;
    gTutorialStep = fromStep;
    showTutorialStep();
}

// Ask "Continue tutorial from step X, restart, or skip?"
showTutorialResumeDialog(integer savedStep) {
    cleanupTutorialDialog();
    gTutorialDialogCh = -1 - (integer)llFrand(999999.0);
    gTutorialDialogHandle = llListen(gTutorialDialogCh, "", gOwner, "");
    string msg = "📘 Tutorial\n\nYou were on step " + (string)(savedStep + 1) + " of 13.\n\nResume where you left off, restart from the beginning, or skip the tutorial?";
    llDialog(gOwner, msg, ["Resume", "Restart", "Skip"], gTutorialDialogCh);
}

// Ask first-timers if they want the tutorial.
showTutorialOfferDialog() {
    cleanupTutorialDialog();
    gTutorialDialogCh = -1 - (integer)llFrand(999999.0);
    gTutorialDialogHandle = llListen(gTutorialDialogCh, "", gOwner, "");
    llDialog(gOwner,
        "📘 Welcome to OSFishing!\n\nWould you like a quick tutorial on how everything works?",
        ["Start Tutorial", "Skip"], gTutorialDialogCh);
}

cleanupTutorialDialog() {
    if (gTutorialDialogHandle) {
        llListenRemove(gTutorialDialogHandle);
        gTutorialDialogHandle = 0;
    }
}

showPairingCode(string code) {
    string display = "🎣 PAIR ME\n\n";
    display += "Code: " + code + "\n\n";
    display += "Visit the web portal:\n";
    display += "Settings > Pair HUD\n";
    display += "Enter the code above";
    setStatus(display, <0.4, 1.0, 0.6>);
    llOwnerSay("═══════════════════════════════════");
    llOwnerSay("PAIRING CODE: " + code);
    llOwnerSay("Visit the web portal, go to Settings > Pair HUD,");
    llOwnerSay("and enter the code above within 5 minutes.");
    llOwnerSay("═══════════════════════════════════");

    // Also push a clickable URL — opens the portal directly with code prefilled
    string portalUrl = gApiUrl;
    integer apiIdx = llSubStringIndex(portalUrl, "/api/");
    if (apiIdx != -1) {
        portalUrl = llGetSubString(portalUrl, 0, apiIdx - 1);
    }
    string pairUrl = portalUrl + "/?pair=" + llEscapeURL(code);
    llLoadURL(gOwner,
        "Pair your HUD with the website. Click below to open the portal — your pairing code (" +
        code + ") will be filled in for you. You'll need to log in if you aren't already.",
        pairUrl);
}

string jsonGet(string json, string akey) {
    string search = "\"" + akey + "\"";
    integer idx = llSubStringIndex(json, search);
    if (idx == -1) return "";
    idx += llStringLength(search);
    integer colonIdx = llSubStringIndex(llGetSubString(json, idx, idx + 5), ":");
    idx += colonIdx + 1;
    string rest = llStringTrim(llGetSubString(json, idx, idx + 2048), STRING_TRIM_HEAD);
    if (llGetSubString(rest, 0, 0) == "\"") {
        // Quoted string value: read up to the first UNescaped quote, undoing
        // \" \\ \/ \n \t on the way. (Stopping at the first quote cut values
        // like  He said \"hi\"  short.) \uXXXX is left as-is: the server
        // sends raw UTF-8 (JSON_UNESCAPED_UNICODE).
        // Names differ from the loop below: OpenSim compiles LSL to C#,
        // which rejects an inner-block name reused by the outer scope.
        string sOut = "";
        integer sLen = llStringLength(rest);
        integer sPos = 1;
        while (sPos < sLen) {
            // Copy the plain run up to the next quote or backslash in one go.
            string tail = llGetSubString(rest, sPos, -1);
            integer q = llSubStringIndex(tail, "\"");
            integer b = llSubStringIndex(tail, "\\");
            if (q == -1) return "";                        // unterminated
            if (b == -1 || q < b) {
                if (q > 0) sOut += llGetSubString(tail, 0, q - 1);
                return sOut;
            }
            if (b > 0) sOut += llGetSubString(tail, 0, b - 1);
            string e = llGetSubString(tail, b + 1, b + 1);
            if (e == "n") sOut += "\n";
            else if (e == "t") sOut += "\t";
            else if (e == "u") sOut += "\\u";              // left encoded
            else if (e != "r") sOut += e;                  // \" \\ \/
            sPos += b + 2;
        }
        return "";
    }
    integer i;
    string c;
    string val = "";
    for (i = 0; i < llStringLength(rest) && i < 64; i++) {
        c = llGetSubString(rest, i, i);
        if (c == "," || c == "}" || c == "]" || c == "\n") jump done_parse;
        val += c;
    }
    @done_parse;
    return llStringTrim(val, STRING_TRIM);
}

integer jsonGetInt(string json, string akey) { return (integer)jsonGet(json, akey); }
float jsonGetFloat(string json, string akey) { return (float)jsonGet(json, akey); }

// ── Safe link params helper — only sets if link exists ──
safeLinkParams(integer link, list params) {
    if (link != -1) llSetLinkPrimitiveParamsFast(link, params);
}

// ── Safe animation helper — silently skips if not in inventory ──
safePlayAnim(string animName) {
    if (animName == "") return;
    if (llGetInventoryType(animName) == INVENTORY_ANIMATION) {
        if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
            llStartAnimation(animName);
        }
    }
}

safeStopAnim(string animName) {
    if (animName == "") return;
    if (llGetInventoryType(animName) == INVENTORY_ANIMATION) {
        if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
            llStopAnimation(animName);
        }
    }
}

// ── Safe sound helper — silently skips if not in inventory ──
safePlaySound(string soundName, float volume) {
    if (soundName == "") return;
    if (llGetInventoryType(soundName) == INVENTORY_SOUND) {
        llPlaySound(soundName, volume);
    }
}

safeLoopSound(string soundName, float volume) {
    if (soundName == "") return;
    if (llGetInventoryType(soundName) == INVENTORY_SOUND) {
        llLoopSound(soundName, volume);
    }
}

safeStopSound() {
    llStopSound();
}

// ── HUD Display Updates ──

// Status line, bait chip, FISH button and fight strip for the current state.
// The status line shows the spot you're at; the fight, victory and pairing
// set their own status and are left alone here.
updateMainDisplay() {
    if (!gLinksScanned) return;
    if (gToken != "" && !gPairing && gState != STATE_FIGHT && gState != STATE_VICTORY && gState != STATE_LANDING) {
        if (gCurrentSpotId != 0) setStatus("📍 " + gCurrentSpotName, <0.8, 0.9, 1.0>);
        else setStatus("", <1.0, 1.0, 1.0>);
    }
    updateBaitChip();
    updateCastButton();
    updateStrip();
}

// ── Fight strip: track + meter + fish, just above the bar ──
// Shown while charging a cast (meter = power) and during a fight (meter =
// line tension, fish = what the fish is doing). Hidden while the panel is
// open: the fishing view shows the same thing bigger, and a website page
// would cover it (panelForFishing() swaps a website page out first).
integer stripWanted() {
    if (LINK_TRACK == -1 || gPanelOpen) return FALSE;
    return (gState == STATE_CHARGING || gState == STATE_HOOKSET || gState == STATE_FIGHT);
}

updateStrip() {
    if (!gLinksScanned) return;
    integer want = stripWanted();
    if (want == gStripShown) return;
    gStripShown = want;
    if (want) {
        showPrim(LINK_TRACK, "HUD_Track");
        hidePrim(LINK_METER);           // drawn by setMeter as it fills
        if (gState == STATE_FIGHT) placeFish();
        else hidePrim(LINK_FISH);
    } else {
        hidePrim(LINK_TRACK);
        hidePrim(LINK_METER);
        hidePrim(LINK_FISH);
    }
}

// Fill the meter from its left end (+Y is screen left).
setMeter(float pct, vector col) {
    if (LINK_METER == -1 || gStripShown != TRUE) return;
    if (pct > 1.0) pct = 1.0;
    if (pct < 0.01) { hidePrim(LINK_METER); return; }
    vector full = builtSize(LINK_METER, "HUD_Meter");
    vector pos  = builtPos(LINK_METER, "HUD_Meter");
    float w = full.y * pct;
    pos.y += (full.y - w) / 2.0;
    llSetLinkPrimitiveParamsFast(LINK_METER, [PRIM_SIZE, <full.x, w, full.z>,
        PRIM_POS_LOCAL, pos, PRIM_COLOR, ALL_SIDES, col, 1.0]);
}

// The fish swims along the track toward the side it's running to, and its
// picture says what to do: running left / right, thrashing, or tired.
placeFish() {
    if (LINK_FISH == -1 || gStripShown != TRUE) return;
    vector fsz = builtSize(LINK_FISH, "HUD_Fish");
    vector pos = builtPos(LINK_FISH, "HUD_Fish");
    vector tsz = builtSize(LINK_TRACK, "HUD_Track");
    float off = gFightAngle / 0.524;            // -1 (right) .. +1 (left)
    if (off > 1.0) off = 1.0;
    if (off < -1.0) off = -1.0;
    pos.y += off * (tsz.y - fsz.y) / 2.0;

    integer cell = ICON_FISH_CALM;
    if (gIsJunkFight) cell = ICON_JUNK;
    else if (gFishState == FISH_RUSHING_LEFT)  cell = ICON_FISH_LEFT;
    else if (gFishState == FISH_RUSHING_RIGHT) cell = ICON_FISH_RIGHT;
    else if (gFishState == FISH_EXHAUSTED)     cell = ICON_FISH_TIRED;
    else if (gFishState == FISH_STRUGGLING) {
        cell = ICON_FISH_JUMP;
        if (((integer)(gFightElapsed / gFightTick)) % 2) pos.z += fsz.z * 0.3;
    }
    llSetLinkPrimitiveParamsFast(LINK_FISH, [PRIM_SIZE, fsz, PRIM_POS_LOCAL, pos,
        PRIM_COLOR, ALL_SIDES, <1.0, 1.0, 1.0>, 1.0]);
    setCell(LINK_FISH, TEX_ICONS, 8, 4, cell);
}

updateFightDisplay() {
    if (!gLinksScanned) return;
    updateStrip();

    float pct = gTension / gLineStrength;
    if (pct > 1.0) pct = 1.0;
    vector color;
    if (pct < 0.5) color = <pct * 2, 1.0 - pct, 0.0>;
    else color = <1.0, 1.0 - (pct - 0.5) * 2, 0.0>;
    if (pct < 0.03) pct = 0.03;   // keep a sliver showing: the meter is live
    setMeter(pct, color);
    placeFish();

    string fightText = gFishName + " " + fmtWeight(gFishWeight) + "lb\n";
    fightText += "Dist: " + (string)llRound(gFightDistance) + "m | Tension: " + (string)llRound(gTension / gLineStrength * 100) + "%";
    setStatus(fightText, <0.9, 0.95, 1.0>);

    // What to do right now (also shown big in the fishing view)
    string fishLabel = "";
    if (gIsJunkFight)                          fishLabel = "HEAVY! Reel in short bursts (PgDn), ease off with PgUp";
    else if (gFishState == FISH_STRUGGLING)    fishLabel = "THRASHING — let go of reel!";
    else if (gFishState == FISH_RUSHING_LEFT)  fishLabel = "RUNNING LEFT — steer RIGHT (→ / D)";
    else if (gFishState == FISH_RUSHING_RIGHT) fishLabel = "RUNNING RIGHT — steer LEFT (← / A)";
    else if (gFishState == FISH_EXHAUSTED)     fishLabel = "TIRED — REEL NOW! (PgDn / S)";
    hudInfo(fishLabel);
}

// Cast power on the meter while charging
showPowerBar(float power) {
    setMeter(power, <0.35, 0.7, 1.0>);
}

hidePowerBar() {
    setMeter(0.0, ZERO_VECTOR);
}

// Clear the fight strip now (the next state change redraws what it needs).
hideFightDisplay() {
    if (gStripShown == TRUE) {
        hidePrim(LINK_TRACK);
        hidePrim(LINK_METER);
        hidePrim(LINK_FISH);
    }
    gStripShown = FALSE;
}

// ══ Media panel ══
// Two views: the website (portal embed, any page) and the live fishing view,
// which this script serves itself from its HTTP-in URL (see http_request).
// The fishing view needs that URL, so on a grid that denies HTTP-in the
// panel only has the website and the fight stays on the bar.

string portalBase() {
    string u = gApiUrl;
    integer apiIdx = llSubStringIndex(u, "api/");
    if (apiIdx != -1) u = llGetSubString(u, 0, apiIdx - 1);
    return u;   // ends in "/"
}

string portalEmbedUrl(string page) {
    string ts = (string)llGetUnixTime();
    string authSig = llSHA256String((string)gTokenId + ":" + ts + ":" + gToken);
    return portalBase() + "?embed=1&page=" + page +
           "&token_id=" + (string)gTokenId +
           "&sig=" + llEscapeURL(authSig) +
           "&ts=" + ts;
}

string fishViewUrl() {
    string u = gCallbackUrl;
    if (llGetSubString(u, -1, -1) != "/") u += "/";
    return u + "fish";
}

integer canFishView() {
    return (gCallbackUrl != "" && LINK_PANEL_MEDIA != -1);
}

hidePanelPrims() {
    hidePrim(LINK_PANEL_BACK);
    hidePrim(LINK_PANEL_MEDIA);
    hidePrim(LINK_PANEL_CLOSE);
    hidePrim(LINK_PANEL_OPEN);
    hidePrim(LINK_PANEL_SWAP);
}

// PanelSwap: "MENU" while the fishing view shows, "FISHING" while the website does.
updateSwap() {
    if (LINK_PANEL_SWAP == -1) return;
    if (gPanelOpen && canFishView()) {
        showPrim(LINK_PANEL_SWAP, "PanelSwap");
        if (gPanelFishView) setCell(LINK_PANEL_SWAP, TEX_BUTTONS, 2, 4, BTN_MENU);
        else setCell(LINK_PANEL_SWAP, TEX_BUTTONS, 2, 4, BTN_FISHING);
    } else {
        hidePrim(LINK_PANEL_SWAP);
    }
}

// While the panel is open the message text moves beside the bar (screen
// left of it), so it doesn't float over the page.
moveText(integer panelOpen) {
    if (LINK_TEXT == -1) return;
    vector pos = builtPos(LINK_TEXT, "HUD_Text");
    if (panelOpen) {
        vector bar = llList2Vector(llGetLinkPrimitiveParams(LINK_ROOT, [PRIM_SIZE]), 0);
        pos = <pos.x, bar.y * 0.8, 0.0>;
    }
    llSetLinkPrimitiveParamsFast(LINK_TEXT, [PRIM_POS_LOCAL, pos]);
}

// Load a URL on the media face (pauses the script ~1s, like every media call).
setPanelMedia(string url) {
    if (LINK_PANEL_MEDIA == -1) return;
    if (llGetInventoryType(TEX_MEDIA_LOADING) == INVENTORY_TEXTURE)
        llSetLinkPrimitiveParamsFast(LINK_PANEL_MEDIA, [PRIM_TEXTURE, MEDIA_FACE, TEX_MEDIA_LOADING,
            <1.0, 1.0, 0.0>, ZERO_VECTOR, 0.0]);
    safeLDWrite("hud_media_on", "1");
    integer status = llSetLinkMedia(LINK_PANEL_MEDIA, MEDIA_FACE, [
        PRIM_MEDIA_CURRENT_URL,          url,
        PRIM_MEDIA_HOME_URL,             url,
        PRIM_MEDIA_AUTO_PLAY,            TRUE,
        PRIM_MEDIA_FIRST_CLICK_INTERACT, TRUE,
        PRIM_MEDIA_AUTO_SCALE,           TRUE,
        PRIM_MEDIA_AUTO_ZOOM,            FALSE,
        PRIM_MEDIA_WIDTH_PIXELS,         MEDIA_W_PX,
        PRIM_MEDIA_HEIGHT_PIXELS,        MEDIA_H_PX,
        PRIM_MEDIA_PERMS_INTERACT,       PRIM_MEDIA_PERM_OWNER,
        PRIM_MEDIA_PERMS_CONTROL,        PRIM_MEDIA_PERM_NONE
    ]);
    if (status != STATUS_OK) {
        if (llGetInventoryType(TEX_MEDIA_UNAVAILABLE) == INVENTORY_TEXTURE)
            llSetLinkPrimitiveParamsFast(LINK_PANEL_MEDIA, [PRIM_TEXTURE, MEDIA_FACE, TEX_MEDIA_UNAVAILABLE,
                <1.0, 1.0, 0.0>, ZERO_VECTOR, 0.0]);
        llOwnerSay("The panel couldn't load (media error " + (string)status + ").");
    }
}

// Open the panel on the fishing view (fishView TRUE) or a website page.
openPanel(integer fishView, string page) {
    if (LINK_PANEL_MEDIA == -1) {
        // No panel prims: the website in the viewer's browser instead
        if (!fishView) llLoadURL(gOwner, "Open OSFishing", portalBase() + "?page=" + page);
        return;
    }
    if (!gPanelOpen) {
        gPanelOpen = TRUE;
        showPrim(LINK_PANEL_BACK, "PanelBack");
        showPrim(LINK_PANEL_MEDIA, "PanelMedia");
        showPrim(LINK_PANEL_CLOSE, "PanelClose");
        showPrim(LINK_PANEL_OPEN, "PanelOpen");
        setLogo(TRUE);
        moveText(TRUE);
        updateStrip();
    }
    gPanelFishView = fishView;
    if (fishView) {
        setPanelMedia(fishViewUrl());
    } else {
        if (page != "") gPanelPage = page;
        setPanelMedia(portalEmbedUrl(gPanelPage));
    }
    updateSwap();
}

closePanel() {
    if (!gPanelOpen) return;
    gPanelOpen = FALSE;
    gPanelFishView = FALSE;
    if (LINK_PANEL_MEDIA != -1) llClearLinkMedia(LINK_PANEL_MEDIA, MEDIA_FACE);
    safeLDDelete("hud_media_on");
    hidePanelPrims();
    setLogo(FALSE);
    moveText(FALSE);
    updateStrip();
    // The strip is back on the bar: redraw it for the current state
    if (gState == STATE_FIGHT) updateFightDisplay();
    else if (gState == STATE_CHARGING) showPowerBar(gCastPower);
}

// Mid-cast moments where a website page must not cover the bar's strip.
integer fightBusy() {
    return (gState == STATE_CHARGING || gState == STATE_BITE || gState == STATE_HOOKSET
         || gState == STATE_FIGHT || gState == STATE_LANDING);
}

toggleMediaPanel(string page) {
    if (gPanelOpen && !gPanelFishView && gPanelPage == page) closePanel();
    else openPanel(FALSE, page);
}

// A cast is starting or a fish is biting: the player must be able to see
// the fight. A website page would hide the strip, so show the fishing view
// instead, or close the panel if this grid can't serve it.
panelForFishing() {
    if (!gPanelOpen || gPanelFishView) return;
    if (canFishView()) openPanel(TRUE, "");
    else closePanel();
}

// ── Live state for the fishing view (GET <url>/s) ──
string fishViewState() {
    float dist = 0.0;
    if (gState == STATE_FIGHT || gState == STATE_HOOKSET || gState == STATE_LANDING) dist = gFightDistance;
    else if (gState == STATE_REELING) dist = gReelInDist;
    else if (gAnchorPos != ZERO_VECTOR) {
        vector v = gAnchorPos - llGetPos();
        v.z = 0.0;
        dist = llVecMag(v);
    }
    float cap = 50.0;
    if (gCurrentWaterType == "river") cap = 40.0;
    else if (gCurrentWaterType == "lake") cap = 75.0;
    else if (gCurrentWaterType == "ocean") cap = 100.0;
    float tension = 0.0;
    if (gLineStrength > 0.0) tension = gTension / gLineStrength;
    float stamina = 0.0;
    if (gFishStaminaMax > 0.0) stamina = gFishStamina / gFishStaminaMax;
    float biteLeft = 0.0;
    if (gState == STATE_BITE && gBiteActive) biteLeft = gBiteDeadline - llGetTime();
    return llList2Json(JSON_OBJECT, [
        "s", gState, "fs", gFishState, "jk", gIsJunkFight,
        "t", tension, "d", dist, "cap", cap, "a", gFightAngle, "sta", stamina,
        "pw", gCastPower, "bl", biteLeft, "bw", gBiteWindow,
        "f", gFishName, "w", gFishWeight, "r", gRarityId, "rn", gLastRarity,
        "m", gInfoMsg, "mq", gInfoSeq, "ns", gNibbleSeq,
        "rl", gPlayerReeling, "lo", gPlayerLineOut, "dir", gPlayerDirection,
        "bn", gEquippedBait, "bq", gBaitRemaining,
        "sp", gCurrentSpotName, "wt", gCurrentWaterType, "lv", gLevel
    ]);
}

// The page itself is a shell: the code comes from the website, the data
// from this script. Same-origin polling, so no CORS and no mixed content.
string fishViewShell() {
    return "<!doctype html><html><head><meta charset=\"utf-8\"><title>OSFishing</title></head>"
        + "<body style=\"margin:0;background:#082A66\"><div id=\"fv\"></div><script src=\""
        + portalBase() + "hud/fishview.js?v=" + (string)(llGetUnixTime() / 3600)
        + "\"></script></body></html>";
}

// ── Info text: a message that fades after a few seconds ──
hudInfo(string msg) {
    if (LINK_TEXT == -1) {
        llOwnerSay(msg);  // Fallback if the HUD isn't built yet
        return;
    }
    gInfoMsg = msg;
    gInfoSeq++;
    renderText();
    gInfoVisible = TRUE;
    gInfoFadeTime = llGetTime() + 6.0;  // Fade after 6 seconds
    // States with no running timer of their own need one for the fade.
    if (gState == STATE_IDLE || gState == STATE_MENU || gState == STATE_LANDING) {
        restoreTimer();
    }
}

clearInfo() {
    gInfoMsg = "";
    gInfoVisible = FALSE;
    renderText();
}

// ── FISH / STOP button ──
showPlayButton() {
    updateCastButton();
}

showStopButton() {
    setCell(LINK_CAST, TEX_BUTTONS, 2, 4, BTN_STOP);
}

// Check info fade in timer (call from timer event)
checkInfoFade() {
    if (gInfoVisible && llGetTime() > gInfoFadeTime) {
        clearInfo();
        // Drop back to whatever the current state needs. Setting 0 here
        // used to kill the pairing poll if a notice faded mid-pairing.
        restoreTimer();
    }
}

// ── The one place that decides the timer rate ──
// Every state change calls this instead of llSetTimerEvent directly, so no
// path can stop a timer another feature still needs (pairing poll, fade).
// One-shot waits (bite window, victory pause) keep a deadline, so a
// recompute mid-wait doesn't restart them. Also keeps the fight strip in
// step with the state.
float   gBiteDeadline    = 0.0;
float   gVictoryDeadline = 0.0;

float untilDeadline(float deadline) {
    float left = deadline - llGetTime();
    if (left < 0.05) left = 0.05;
    return left;
}

restoreTimer() {
    updateStrip();
    float rate = 0.0;
    if (gPairing)                        rate = gPairPollRate;
    else if (gState == STATE_CHARGING || gState == STATE_CAST || gState == STATE_REELING)
                                         rate = 0.1;
    else if (gState == STATE_FIGHT)      rate = gFightTick;
    else if (gState == STATE_BITE && gBiteActive) rate = untilDeadline(gBiteDeadline);
    else if (gState == STATE_VICTORY)    rate = untilDeadline(gVictoryDeadline);
    else if (gInfoVisible)               rate = 2.0;   // just the notice fade
    llSetTimerEvent(rate);
}

// ── Dialog Helper ──
openDialog(string prompt, list buttons) {
    if (gDialogHandle) llListenRemove(gDialogHandle);
    gDialogCh = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", gOwner, "");
    llDialog(gOwner, prompt, buttons, gDialogCh);
    gState = STATE_MENU;
}

// ── Cleanup ──
// Request server to finalize the cast and determine bait loss,
// then end the fishing session.
doReelIn(integer caughtFish) {
    doReelInFull(caughtFish, FALSE);
}

doReelInFull(integer caughtFish, integer lineBroken) {
    if (gCastTotalElapsed <= 0.0) {
        // No active cast - just reset to ready
        resetToReady();
        return;
    }

    gHttpReq = apiCall("reel_in",
        "cast_elapsed=" + (string)gCastTotalElapsed +
        "&caught_fish=" + (string)caughtFish +
        "&line_broken=" + (string)lineBroken);

    // Clear fight/cast state but stay in session
    safeStopAnim("fishing_wait");
    safeStopSound();
    resetToReady();
}

stopFishing() {
    gState = STATE_IDLE;
    gCharging = FALSE;
    gBiteActive = FALSE;
    gPlayerReeling = FALSE;
    gPlayerLineOut = FALSE;
    gPlayerDirection = 0;
    gSchedTimes = [];
    gSchedFishJson = [];
    gSchedIndex = 0;
    gCastTotalElapsed = 0.0;
    safeStopAnim("fishing_cast"); safeStopAnim("fishing_charge");
    safeStopAnim("fishing_landed"); safeStopAnim("cheer");
    llStopSound();
    llReleaseControls();
    clearInfo();

    // Release camera control
    if (llGetPermissions() & PERMISSION_CONTROL_CAMERA) {
        llClearCameraParams();
    }

    hidePowerBar();
    hideFightDisplay();
    showPlayButton();  // Rotate back to play face

    if (gBobberKey != NULL_KEY) {
        llRegionSayTo(gBobberKey, CH_HUD_TO_BOBBER, "DESPAWN");
        gBobberKey = NULL_KEY;
    }

    toRod("ROD_IDLE");
    updateMainDisplay();
    restoreTimer();   // idle: only a notice fade (or pairing poll) remains
}

// ── Reset back to ready-to-cast state WITHOUT releasing controls/camera ──
// Used after a catch, missed bite, line break, etc.
// Player stays in the fishing session and can immediately cast again.
resetToReady() {
    gCharging = FALSE;
    gBiteActive = FALSE;
    gPlayerReeling = FALSE;
    gPlayerLineOut = FALSE;
    gPlayerDirection = 0;
    gSchedTimes = [];
    gSchedFishJson = [];
    gSchedIndex = 0;
    gCastTotalElapsed = 0.0;
    gAnchorPos = ZERO_VECTOR;
    gFightAngle = 0.0;
    gFightAngleTarget = 0.0;
    gIsJunkFight = FALSE;
    safeStopAnim("fishing_cast"); safeStopAnim("fishing_charge");
    safeStopAnim("fishing_landed"); safeStopAnim("cheer");
    safeStopAnim("fishing_wait");
    safeStopAnim("fishing_fight_steady");
    safeStopAnim("fishing_fight_lean_left");
    safeStopAnim("fishing_fight_lean_right");
    safeStopAnim("fishing_fight_strain");
    safeStopAnim("fishing_yank");
    safeStopAnim("fishing_nibble");
    safeStopAnim("fishing_disappointed");
    llStopSound();

    hidePowerBar();
    hideFightDisplay();

    if (gBobberKey != NULL_KEY) {
        llRegionSayTo(gBobberKey, CH_HUD_TO_BOBBER, "DESPAWN");
        gBobberKey = NULL_KEY;
    }

    toRod("ROD_IDLE");

    // Go back to charging state — ready for next cast
    gState = STATE_CHARGING;
    gCastPower = 0.0;
    showPowerBar(0.0);
    hudInfo("Hold PAGE UP to cast again...");
    restoreTimer();
    updateMainDisplay();
}

// ══════════════════════════════════════════════════════════
// DEFAULT STATE
// ══════════════════════════════════════════════════════════
// ── Handle one pushed payload ──
// Shared by live POSTs (http_request) and queued pushes returned
// inline by hud_status, so a HUD on a grid that denies llRequestURL()
// still processes every message the server had for it.
handlePush(string body) {
    // Parse the pushed data
    string pushType = jsonGet(body, "type");

    if (pushType == "equip_update") {
        // Server pushed updated equipment info
        string baitName = jsonGet(body, "bait_name");
        if (baitName != "") {
            gEquippedBait = baitName;
            gEquippedBaitId = jsonGetInt(body, "bait_id");
            gBaitRemaining = jsonGetInt(body, "bait_remaining");
        }
        string lineName = jsonGet(body, "line_name");
        if (lineName != "") {
            gEquippedLine = lineName;
            gLineWeight = jsonGetFloat(body, "line_weight");
            gLineVisibility = jsonGetFloat(body, "line_visibility");
        }
        integer pts = jsonGetInt(body, "fishing_points");
        if (pts > 0) gPoints = pts;
        integer xp = jsonGetInt(body, "xp");
        if (xp > 0) gXP = xp;
        integer lvl = jsonGetInt(body, "level");
        if (lvl > 0) gLevel = lvl;
        integer xtn = jsonGetInt(body, "xp_to_next");
        if (xtn > 0) gXpToNext = xtn;
        integer xlt = jsonGetInt(body, "xp_level_total");
        if (xlt > 0) gXpLevelTotal = xlt;

        updateMainDisplay();
        saveDisplayCache();
    }
    else if (pushType == "announcement") {
        string msg = jsonGet(body, "message");
        if (msg != "") hudInfo("📢 " + msg);
    }
    else if (pushType == "refresh") {
        // Full refresh requested — reload profile
        gHttpReq = apiCall("hud_status", "");
    }
    else if (pushType == "tutorial_event") {
        // Server-driven tutorial signals — kept for future use.
        // The visible tutorial flow advances on pointer clicks instead,
        // so the user can confirm when they've completed each step.
        string evt = jsonGet(body, "event");
        if (evt == "tutorial_restart") {
            // Owner restarted tutorial from website
            gTutorialAsked = TRUE;
            gTutorialStep = 0;
            startTutorial(0);
        }
    }
}

// First wear after a build or a new owner: put the bar at bottom centre,
// HOME_GAP above the screen edge. After that the wearer can move it.
placeOnScreen() {
    if (llGetAttached() != ATTACH_HUD_BOTTOM) return;
    if (safeLDRead("hud_placed") != "") return;
    vector bar = llList2Vector(llGetLinkPrimitiveParams(LINK_ROOT, [PRIM_SIZE]), 0);
    llSetPos(<0.0, 0.0, HOME_GAP + bar.z / 2.0>);
    safeLDWrite("hud_placed", "1");
}

default {
    state_entry() {
        gOwner = llGetOwner();
        gOwnerName = llKey2Name(gOwner);
        gState = STATE_IDLE;

        scanLinks();
        placeOnScreen();
        // A page left on the (now hidden) media face from before the reset
        // would keep loading in the viewer.
        if (LINK_PANEL_MEDIA != -1 && safeLDRead("hud_media_on") != "") {
            llClearLinkMedia(LINK_PANEL_MEDIA, MEDIA_FACE);
            safeLDDelete("hud_media_on");
        }

        // Start listening immediately
        llListen(CH_SPOT_TO_HUD, "", NULL_KEY, "");
        llListen(CH_BOBBER_TO_HUD, "", NULL_KEY, "");
        llListen(CH_GATHER_HUD, "", NULL_KEY, "");
        llListen(CH_ROD_TO_HUD, "", NULL_KEY, "");
        llSay(CH_HUD_TO_ROD, "ROD_PING");   // a rod already worn answers ROD_HELLO
        // Owner-only channel 0 commands (e.g. "/tutorial")
        llListen(0, "", gOwner, "");

        // Begin pairing check
        showStatus("Connecting to server...");
        startupCheck();
    }

    // Rescan if prims are added, removed, or renamed
    changed(integer change) {
        if (change & CHANGED_LINK) {
            
            scanLinks();
            if (gLinksScanned) updateMainDisplay();
        }
        if (change & CHANGED_OWNER) llResetScript();
        if (change & (CHANGED_REGION | CHANGED_TELEPORT)) {
            if (gToken != "") requestCallbackUrl();
        }
    }

    dataserver(key query, string data) {
        // Reserved for future use
    }

    on_rez(integer param) {
        llResetScript();
    }

    attach(key id) {
        if (id) llResetScript();
    }

    // ── HUD Button Touches ──
    touch_start(integer n) {
        if (llDetectedKey(0) != gOwner) return;

        // Re-attempt startup if not paired
        if (gToken == "" && !gPairing) {
            showStatus("Connecting to server...");
            startupCheck();
            return;
        }

        // Manual rescan if owner touches root while not scanned
        if (!gLinksScanned) {
            
            scanLinks();
            if (gLinksScanned) updateMainDisplay();
            return;
        }

        integer link = llDetectedLinkNumber(0);

        // Tutorial pointer click — overrides everything else
        if (gTutorialActive && TUT_LINK_POINTER > 0 && link == TUT_LINK_POINTER) {
            string info = tutorialStepInfo(gTutorialStep);
            integer pipeIdx = llSubStringIndex(info, "|");
            string ptr = llGetSubString(info, pipeIdx + 1, -1);

            // Trigger the equivalent of clicking the underlying button.
            // Special-case: step 12 is the "all done" final step — just advance,
            // don't start fishing even though the pointer overlays Play.
            if (gTutorialStep == 12) {
                advanceTutorial();
                return;
            }

            if (ptr == "quests" || ptr == "tackle" || ptr == "fish" || ptr == "shop") {
                if (fightBusy()) return;
                openPanel(FALSE, ptr);
            } else if (ptr == "play") {
                if (gTutorialStep == 6) {
                    if (gCurrentSpotId == 0) {
                        hudInfo("Walk near a fishing spot first!");
                        return;
                    }
                    if (gEquippedBaitId == 0 || gBaitRemaining <= 0) {
                        hudInfo("Equip bait first via the Tackle menu!");
                        return;
                    }
                    gCastPower = 0.0;
                    gCharging = FALSE;
                    showStopButton();
                    llRequestPermissions(gOwner,
                        PERMISSION_TAKE_CONTROLS |
                        PERMISSION_TRIGGER_ANIMATION |
                        PERMISSION_TRACK_CAMERA |
                        PERMISSION_CONTROL_CAMERA);
                }
            }
            advanceTutorial();
            return;
        }

        if (link == LINK_CAST) {
            // Toggle: if fishing, stop. If idle, start.
            if (gState != STATE_IDLE) {
                hudInfo("Reeling in...");
                stopFishing();
                return;
            }
            if (gCurrentSpotId == 0) {
                hudInfo("Walk near a fishing spot first!");
                return;
            }
            if (gEquippedBaitId == 0 || gBaitRemaining <= 0) {
                hudInfo("Equip bait first! Tap the bait chip.");
                return;
            }

            gCastPower = 0.0;
            gCharging = FALSE;
            showStopButton();
            llRequestPermissions(gOwner,
                PERMISSION_TAKE_CONTROLS |
                PERMISSION_TRIGGER_ANIMATION |
                PERMISSION_TRACK_CAMERA |
                PERMISSION_CONTROL_CAMERA);
        }
        else if (link == LINK_LOGO) {
            // Open / close the panel. While fishing it opens on the live
            // fishing view (when this grid lets the HUD serve it).
            if (gPanelOpen) closePanel();
            else if (gState != STATE_IDLE && gState != STATE_MENU && canFishView()) openPanel(TRUE, "");
            else if (fightBusy()) hudInfo("Finish this cast first - the menu would cover it.");
            else openPanel(FALSE, gPanelPage);
        }
        else if (link == LINK_BAIT) {
            if (fightBusy()) {
                hudInfo("Change bait between casts.");
                return;
            }
            toggleMediaPanel("tackle");
        }
        else if (link == LINK_PANEL_CLOSE) {
            closePanel();
        }
        else if (link == LINK_PANEL_OPEN) {
            llLoadURL(gOwner, "Open OSFishing in your web browser.", portalBase() + "?page=" + gPanelPage);
        }
        else if (link == LINK_PANEL_SWAP) {
            if (gPanelFishView) {
                if (fightBusy()) {
                    hudInfo("Finish this cast first - the menu would cover it.");
                    return;
                }
                openPanel(FALSE, gPanelPage);
            }
            else if (canFishView()) openPanel(TRUE, "");
        }
        // The media face itself: clicks go to the page.
    }

    // ── Permissions ──
    run_time_permissions(integer perm) {
        if (perm & PERMISSION_TAKE_CONTROLS) {
            // Grab ALL movement controls and don't pass them through.
            // Player stands still while fishing.
            llTakeControls(
                CONTROL_FWD | CONTROL_BACK |
                CONTROL_LEFT | CONTROL_RIGHT |
                CONTROL_ROT_LEFT | CONTROL_ROT_RIGHT |
                CONTROL_UP | CONTROL_DOWN |
                CONTROL_ML_LBUTTON | CONTROL_LBUTTON,
                TRUE,   // accept these controls
                FALSE   // do NOT pass through to avatar - they stand still
            );
            hudInfo("Hold PAGE UP / W / E to charge, release to cast...");
            gState = STATE_CHARGING;
            gCastPower = 0.0;
            panelForFishing();
            restoreTimer();
            showPowerBar(0.0);
            updateCastButton();
        }

        if (perm & PERMISSION_CONTROL_CAMERA) {
            // Set camera to focus on the fishing spot.
            // Positioned over the player's left shoulder, elevated, looking toward spot.
            vector myPos = llGetPos();
            vector lookAt = gSpotPos;
            if (lookAt == ZERO_VECTOR) lookAt = myPos + <2.0, 0.0, 0.0>;

            // Direction from player to spot
            vector dir = llVecNorm(lookAt - myPos);
            // Perpendicular vector for left offset (rotate dir 90° clockwise on Z)
            vector leftDir = <-dir.y, dir.x, 0.0>;
            // Camera position:
            //   - 5m behind player along spot direction
            //   - 2.5m to the LEFT of the player (over left shoulder)
            //   - 3m UP for elevated view
            vector camPos = myPos - dir * 5.0 + leftDir * 2.5 + <0.0, 0.0, 3.0>;

            llSetCameraParams([
                CAMERA_ACTIVE, TRUE,
                CAMERA_BEHINDNESS_ANGLE, 0.0,
                CAMERA_BEHINDNESS_LAG, 0.0,
                CAMERA_FOCUS, lookAt,
                CAMERA_FOCUS_LOCKED, TRUE,
                CAMERA_POSITION, camPos,
                CAMERA_POSITION_LOCKED, TRUE,
                CAMERA_PITCH, 0.0
            ]);
        }
    }

    // ── Keyboard / Mouse Controls ──
    // Charge / line-out : Page Up, E, or W (CONTROL_UP | CONTROL_FWD)
    // Reel / hook       : Page Down, C, or S (CONTROL_DOWN | CONTROL_BACK)
    control(key id, integer held, integer change) {
        integer chargeMask = CONTROL_UP | CONTROL_FWD;
        integer reelMask   = CONTROL_DOWN | CONTROL_BACK;

        // ── CHARGING: hold charge key to charge, release to cast ──
        if (gState == STATE_CHARGING) {
            if ((change & chargeMask) && (held & chargeMask)) {
                gCharging = TRUE;
                safePlayAnim("fishing_charge");
                safeLoopSound("cast_charge_loop", 0.5);
            }
            if ((change & chargeMask) && !(held & chargeMask)) {
                if (gCharging && gCastPower > 0.05) {
                    gCharging = FALSE;
                    safeStopSound();
                    safeStopAnim("fishing_charge");
                    safePlaySound("cast_release", 1.0);
                    hudInfo("Casting! Power: " + (string)llRound(gCastPower * 100) + "%");
                    hidePowerBar();

                    if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                        safePlayAnim("fishing_cast");
                    }

                    toRod("ROD_CAST|" + (string)gCastPower);

                    gHttpReq = apiCall("cast",
                        "spot_id=" + (string)gCurrentSpotId +
                        "&cast_power=" + (string)gCastPower
                    );
                    gState = STATE_CAST;
                } else {
                    gCharging = FALSE;
                    gCastPower = 0.0;
                    showPowerBar(0.0);
                }
            }
            return;
        }

        // ── WAITING: reel key to start reeling in ──
        if (gState == STATE_CAST) {
            if ((change & reelMask) && (held & reelMask)) {
                // Calculate current distance from player to bobber
                vector myPos = llGetPos();
                vector toAnchor = gAnchorPos - myPos;
                toAnchor.z = 0.0;
                gReelInDist = llVecMag(toAnchor);
                gState = STATE_REELING;
                hudInfo("Reeling in...");
                safePlaySound("reel_loop", 0.6);
                return;
            }
        }

        // ── REELING IN: hold reel key to keep reeling, release to stop ──
        if (gState == STATE_REELING) {
            if (!(held & reelMask)) {
                safeStopSound();
                if (gReelInDist <= 5.0) {
                    // Close enough — fully reeled in
                    doReelIn(FALSE);
                } else {
                    // Stopped mid-reel — resume fishing from current position
                    gAnchorPos = llGetPos() + llVecNorm(gAnchorPos - llGetPos()) * gReelInDist;
                    gAnchorPos.z = gSpotPos.z;
                    gState = STATE_CAST;
                    hudInfo("Line in the water...");
                    // Move bobber to new position
                    if (gBobberKey != NULL_KEY) {
                        llRegionSayTo(gBobberKey, CH_HUD_TO_BOBBER, "MOVE_TO|" + (string)gAnchorPos);
                    }
                }
                return;
            }
        }

        // ── BITE: reel key to set the hook ──
        if (gState == STATE_BITE) {
            if ((change & reelMask) && (held & reelMask)) {
                if (gBiteActive) {
                    gState = STATE_HOOKSET;
                    safePlaySound("hook_set", 1.0);
                    hudInfo("HOOK SET! Fight is on!");

                    if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                        safePlayAnim("fishing_yank");
                    }

                    toRod("ROD_HOOKSET|" + (string)gBobberKey);

                    gState = STATE_FIGHT;
                    gFightElapsed = 0.0;
                    gTension = 0.0;
                    gPlayerReeling = FALSE;
                    gPlayerDirection = 0;
                    gFishStamina = gFishStaminaMax;
                    gFishState = FISH_EXHAUSTED;  // Brief grace period to start
                    gFishStateTime = 0.0;
                    gFishStateMinDur = 1.5;       // Short opening before first rush

                    updateFightDisplay();
                    restoreTimer();
                } else {
                    hudInfo("Too early! Fish got spooked!");
                    doReelIn(FALSE);
                }
            }
            return;
        }

        // ── FIGHT: reel key to reel, charge key to let line out, Left/Right to steer ──
        if (gState == STATE_FIGHT) {
            integer wasReeling = gPlayerReeling;
            integer wasLineOut = gPlayerLineOut;

            if (held & reelMask) gPlayerReeling = TRUE;
            else gPlayerReeling = FALSE;

            if (held & chargeMask) gPlayerLineOut = TRUE;
            else gPlayerLineOut = FALSE;

            // Reeling and line out are mutually exclusive - reeling wins if both pressed
            if (gPlayerReeling) gPlayerLineOut = FALSE;

            // Start/stop reel loop sound on state change
            if (gPlayerReeling && !wasReeling) {
                safeLoopSound("reel_loop", 0.7);
                safePlayAnim("fishing_reel");
            } else if (!gPlayerReeling && wasReeling) {
                safeStopSound();
                safeStopAnim("fishing_reel");
            }

            // Line out: stop reel sound if we just started letting line out
            if (gPlayerLineOut && !wasLineOut) {
                safeStopSound();
                safeStopAnim("fishing_reel");
            }

            if (held & (CONTROL_LEFT | CONTROL_ROT_LEFT)) gPlayerDirection = -1;
            else if (held & (CONTROL_RIGHT | CONTROL_ROT_RIGHT)) gPlayerDirection = 1;
            else gPlayerDirection = 0;
        }
    }

    // ── Timer ──
    timer() {
        // Check info text fade
        checkInfoFade();

        // Pairing poll
        if (gPairing) {
            pollPairingStatus();
            return;
        }

        // Victory delay - hold controls/camera for 5s after catch then release
        if (gState == STATE_VICTORY) {
            if (llGetTime() < gVictoryDeadline) { restoreTimer(); return; }
            safeStopAnim("fishing_landed"); safeStopAnim("cheer");
            resetToReady();
            return;
        }

        if (gState == STATE_CHARGING && gCharging) {
            gCastPower += gChargeRate * 0.1;
            if (gCastPower > 1.0) gCastPower = 1.0;
            showPowerBar(gCastPower);
            return;
        }

        // ── REELING IN: move bobber toward player each tick ──
        if (gState == STATE_REELING) {
            gReelInDist -= 1.6;  // ~16m/s — 20% slower than base
            if (gReelInDist < 0.0) gReelInDist = 0.0;

            if (gReelInDist <= 5.0) {
                // Close enough — auto-complete reel-in
                safeStopSound();
                doReelIn(FALSE);
                return;
            }

            // Move bobber toward player
            if (gBobberKey != NULL_KEY) {
                vector myPos = llGetPos();
                vector dir = gAnchorPos - myPos;
                dir.z = 0.0;
                if (llVecMag(dir) > 0.1) dir = llVecNorm(dir);
                vector bobberPos = myPos + dir * gReelInDist;
                bobberPos.z = gSpotPos.z;
                llRegionSayTo(gBobberKey, CH_HUD_TO_BOBBER, "MOVE_TO|" + (string)bobberPos);
            }
            return;
        }

        if (gState == STATE_CAST) {
            gMinuteElapsed += 0.1;
            gCastTotalElapsed += 0.1;

            // ── AFK cutoff: auto-reset at 300s ──
            if (gCastTotalElapsed >= 300.0) {
                hudInfo("5 min idle. Cast again when ready.");
                resetToReady();
                return;
            }

            // ── Process scheduled events this minute ──
            if (gSchedIndex < llGetListLength(gSchedTimes)) {
                float schedTime = llList2Float(gSchedTimes, gSchedIndex);
                if (gMinuteElapsed >= schedTime) {
                    string fishJson = llList2String(gSchedFishJson, gSchedIndex);
                    gSchedIndex++;

                    if (fishJson == "") {
                        // Fake nibble
                        if (gBobberKey != NULL_KEY) {
                            llRegionSayTo(gBobberKey, CH_HUD_TO_BOBBER, "NIBBLE");
                        }
                        gNibbleSeq++;
                        safePlaySound("nibble_subtle", 0.6);
                        safePlayAnim("fishing_nibble");
                    } else {
                        // REAL BITE — load fish data and enter BITE state
                        gCatchToken    = llJsonGetValue(fishJson, ["catch_token"]);
                        gFishName      = llJsonGetValue(fishJson, ["fish_name"]);
                        gFishWeight    = (float)llJsonGetValue(fishJson, ["fish_weight"]);
                        gFishSpeciesId = (integer)llJsonGetValue(fishJson, ["fish_species_id"]);
                        gRarityId      = (integer)llJsonGetValue(fishJson, ["rarity_id"]);

                        // Fight params — distance starts from where the bobber actually is
                        vector myPos = llGetPos();
                        vector toAnchor = gAnchorPos - myPos;
                        toAnchor.z = 0.0;
                        gFightDistance = llVecMag(toAnchor);
                        if (gFightDistance < 5.0) gFightDistance = 5.0;

                        gLineStrength = (float)llJsonGetValue(fishJson, ["fight", "line_strength"]);
                        if (gLineStrength <= 0) gLineStrength = gLineWeight * 10.0;

                        gReelRate      = (float)llJsonGetValue(fishJson, ["fight", "reel_rate"]);
                        if (gReelRate <= 0) gReelRate = 2.0;

                        gFishStamina    = (float)llJsonGetValue(fishJson, ["fight", "fish_stamina"]);
                        if (gFishStamina <= 0) gFishStamina = 100.0;
                        gFishStaminaMax = gFishStamina;

                        gFishPower      = (float)llJsonGetValue(fishJson, ["fight", "fish_power"]);
                        if (gFishPower <= 0) gFishPower = 1.0;

                        gFishUnpredict = (float)llJsonGetValue(fishJson, ["fight", "fish_unpredictability"]);
                        gFightTick = (float)llJsonGetValue(fishJson, ["fight", "tick_rate"]);
                        if (gFightTick <= 0) gFightTick = 0.5;

                        // Check for junk fight
                        string isJunk = llJsonGetValue(fishJson, ["fight", "is_junk"]);
                        gIsJunkFight = (isJunk == "true" || isJunk == "1" || isJunk == JSON_TRUE);
                        if (gIsJunkFight) {
                            gJunkTensionRate = (float)llJsonGetValue(fishJson, ["fight", "tension_reel_rate"]);
                            if (gJunkTensionRate <= 0) gJunkTensionRate = 10.0;
                        }

                        gBiteWindow = (float)llJsonGetValue(fishJson, ["bite_window"]);
                        if (gBiteWindow < 10.0) gBiteWindow = 10.0;

                        gState = STATE_BITE;
                        gBiteActive = TRUE;
                        hudInfo("!! BITE !! Press PAGE DOWN to hook!");
                        panelForFishing();

                        if (gBobberKey != NULL_KEY) {
                            llRegionSayTo(gBobberKey, CH_HUD_TO_BOBBER, "BITE");
                        }

                        safePlaySound("bite_alert", 1.0);
                        gBiteDeadline = llGetTime() + gBiteWindow;
                        restoreTimer();
                        return;
                    }
                }
            }

            // ── Minute boundary: request next minute or warn ──
            if (gMinuteElapsed >= 60.0) {
                gCurrentMinute++;
                gMinuteElapsed = 0.0;
                gSchedIndex = 0;
                gSchedTimes = [];
                gSchedFishJson = [];

                if (gDeadWater) {
                    hudInfo("Nothing biting. (" + (string)(gCurrentMinute - 1) + " min)\nTry different bait or reel in.");
                } else {
                    // Request next minute's schedule from server
                    gHttpReq = apiCall("roll_minute",
                        "spot_id=" + (string)gCurrentSpotId +
                        "&minute=" + (string)gCurrentMinute);
                }
            }
            return;
        }

        if (gState == STATE_BITE && gBiteActive) {
            if (llGetTime() < gBiteDeadline) { restoreTimer(); return; }
            safePlaySound("bite_miss", 0.8);
            safePlayAnim("fishing_disappointed");
            hudInfo("Too slow! The fish got away...");
            // Go back to waiting - fish escaping doesn't end the cast
            gState = STATE_CAST;
            gBiteActive = FALSE;
            restoreTimer();
            return;
        }

        if (gState == STATE_FIGHT) {
            gFightElapsed += gFightTick;
            gFishStateTime += gFightTick;

            // ── Fish state transitions (skip for junk) ──
            // After minimum duration in current state, may transition based on stamina
            if (!gIsJunkFight && gFishStateTime >= gFishStateMinDur) {
                float staminaPct = gFishStamina / gFishStaminaMax;
                float r = llFrand(1.0);

                integer newState = gFishState;

                if (gFishState == FISH_EXHAUSTED) {
                    // Exhausted -> back to action when rested, or stays if depleted
                    if (staminaPct > 0.15 && r < 0.6) {
                        // Pick a rush direction or struggle
                        float pick = llFrand(1.0);
                        if (pick < 0.3) newState = FISH_STRUGGLING;
                        else if (pick < 0.65) newState = FISH_RUSHING_LEFT;
                        else newState = FISH_RUSHING_RIGHT;
                    }
                } else {
                    // Active state -> may exhaust based on stamina
                    float exhaustChance = 0.3 + (1.0 - staminaPct) * 0.5;
                    if (r < exhaustChance) {
                        newState = FISH_EXHAUSTED;
                    } else if (r < exhaustChance + 0.3) {
                        // Switch to a different active state
                        float pick = llFrand(1.0);
                        if (pick < 0.33) newState = FISH_STRUGGLING;
                        else if (pick < 0.66) newState = FISH_RUSHING_LEFT;
                        else newState = FISH_RUSHING_RIGHT;
                    }
                }

                if (newState != gFishState) {
                    gFishState = newState;
                    gFishStateTime = 0.0;
                    // Exhausted lasts 2-4s, active states last 3-6s
                    if (gFishState == FISH_EXHAUSTED) {
                        gFishStateMinDur = 2.0 + llFrand(2.0);
                    } else {
                        gFishStateMinDur = 3.0 + llFrand(3.0);
                    }

                    // Set bobber angle target + play state sound + set avatar anim
                    if (gFishState == FISH_RUSHING_LEFT) {
                        gFightAngleTarget = 0.524;   // +30 degrees — visually left from player
                        safePlaySound("fish_run_swoosh", 0.8);
                        safeStopAnim("fishing_fight_steady");
                        safeStopAnim("fishing_fight_lean_right");
                        safeStopAnim("fishing_fight_strain");
                        safePlayAnim("fishing_fight_lean_left");
                    }
                    else if (gFishState == FISH_RUSHING_RIGHT) {
                        gFightAngleTarget = -0.524;  // -30 degrees — visually right from player
                        safePlaySound("fish_run_swoosh", 0.8);
                        safeStopAnim("fishing_fight_steady");
                        safeStopAnim("fishing_fight_lean_left");
                        safeStopAnim("fishing_fight_strain");
                        safePlayAnim("fishing_fight_lean_right");
                    }
                    else if (gFishState == FISH_STRUGGLING) {
                        // Hold current angle (don't change target)
                        safePlaySound("fish_jump_splash", 1.0);
                        safeStopAnim("fishing_fight_steady");
                        safeStopAnim("fishing_fight_lean_left");
                        safeStopAnim("fishing_fight_lean_right");
                        safePlayAnim("fishing_fight_strain");
                    }
                    else if (gFishState == FISH_EXHAUSTED) {
                        gFightAngleTarget = 0.0;     // Drift back to center
                        safeStopAnim("fishing_fight_lean_left");
                        safeStopAnim("fishing_fight_lean_right");
                        safeStopAnim("fishing_fight_strain");
                        safePlayAnim("fishing_fight_steady");
                    }
                }
            }

            // ── Apply effects based on fish state and player input ──
            float reelAmount = 0.0;     // Distance reduction per tick
            float tensionDelta = 0.0;   // Tension change per tick
            float staminaDrain = 0.5;   // Base stamina loss

            // ── JUNK FIGHT: deadweight mechanics ──
            if (gIsJunkFight) {
                staminaDrain = 0.0;  // Junk never tires

                if (gPlayerReeling) {
                    reelAmount = gReelRate * 0.7;   // Pull it in
                    tensionDelta = gJunkTensionRate; // Tension builds fast!
                } else if (gPlayerLineOut) {
                    reelAmount = 0.0;               // Doesn't move away
                    tensionDelta = -8.0;            // Tension drops fast
                } else {
                    reelAmount = 0.0;
                    tensionDelta = -3.0;            // Slow tension decay
                }

                // Apply tension
                gTension += tensionDelta;
                if (gTension < 0.0) gTension = 0.0;

                // Line break check
                if (gTension >= gLineStrength) {
                    hudInfo("SNAP! Too heavy for your line!");
                    safePlaySound("line_snap", 1.0);
                    toRod("ROD_LINEBREAK");
                    // Report line break for XP + magnet loss
                    apiCall("line_break", "spot_id=" + (string)gCurrentSpotId);
                    doReelInFull(FALSE, TRUE);
                    return;
                }

                // Apply reel distance
                gFightDistance -= reelAmount;
                if (gFightDistance < 0.0) gFightDistance = 0.0;

                // Landed!
                if (gFightDistance <= 0) {
                    gState = STATE_LANDING;
                    hudInfo("Hauled it in! Confirming...");
                    safePlaySound("catch_success", 1.0);
                    gHttpReq = apiCall("confirm_catch",
                        "spot_id=" + (string)gCurrentSpotId +
                        "&catch_token=" + llEscapeURL(gCatchToken) +
                        "&fight_duration=" + (string)((integer)gFightElapsed) +
                        "&cast_distance=" + (string)((integer)gFightDistance)
                    );
                    return;
                }

                updateFightDisplay();

                // Move bobber (straight toward player, no angle shifts)
                if (gBobberKey != NULL_KEY) {
                    vector myPos = llGetPos();
                    vector toAnchor = gAnchorPos - myPos;
                    toAnchor.z = 0.0;
                    vector baseDir = llVecNorm(toAnchor);
                    vector bobberTarget = myPos + baseDir * gFightDistance;
                    bobberTarget.z = gSpotPos.z;
                    llRegionSayTo(gBobberKey, CH_HUD_TO_BOBBER, "MOVE_TO|" + (string)bobberTarget);
                }
                return;
            }

            // ── NORMAL FISH FIGHT ──

            // Weight-based tension scaling: bigger fish build tension faster.
            // weightFactor: 1.0 for small fish, up to ~3.0 for huge ones
            float weightFactor = 1.0 + (gFishWeight / 50.0);
            if (weightFactor > 3.0) weightFactor = 3.0;

            // Combined power factor (server-provided base + weight scaling)
            float powerFactor = gFishPower * weightFactor;

            if (gFishState == FISH_EXHAUSTED) {
                // Fish is tired - reel hard and fast, low tension
                if (gPlayerReeling) {
                    reelAmount = gReelRate * 1.05;       // 1.5 * 0.7 — 30% slower
                    tensionDelta = -1.0;                 // Tension actually drops
                    staminaDrain = 0.3;
                } else {
                    // Not reeling during exhaustion is wasted opportunity
                    tensionDelta = -2.0;
                    staminaDrain = 0.0;  // Fish recovers slowly if you don't reel
                }
            }
            else if (gFishState == FISH_STRUGGLING) {
                // Fish jumping/thrashing - DON'T reel
                if (gPlayerReeling) {
                    tensionDelta = 12.0 * powerFactor;    // Big tension spike
                    reelAmount = 0.0;
                } else {
                    // Correct response: stop reeling
                    tensionDelta = -3.0;                  // Tension recovery
                    staminaDrain = 1.5;                   // Fish tires from struggling
                }
            }
            else if (gFishState == FISH_RUSHING_LEFT) {
                // Fish pulling left - counter with right (CONTROL_RIGHT)
                if (gPlayerDirection == 1) {
                    // Correct counter
                    staminaDrain = 2.0;
                    tensionDelta = 1.0 * powerFactor;
                    if (gPlayerReeling) reelAmount = gReelRate * 0.56;  // 0.8 * 0.7
                } else if (gPlayerDirection == -1) {
                    // Wrong direction - line strain
                    tensionDelta = 8.0 * powerFactor;
                    if (gPlayerReeling) reelAmount = gReelRate * 0.14;  // 0.2 * 0.7
                } else {
                    // Not pulling - moderate strain
                    tensionDelta = 4.0 * powerFactor;
                    if (gPlayerReeling) reelAmount = gReelRate * 0.28;  // 0.4 * 0.7
                }
            }
            else if (gFishState == FISH_RUSHING_RIGHT) {
                // Fish pulling right - counter with left
                if (gPlayerDirection == -1) {
                    staminaDrain = 2.0;
                    tensionDelta = 1.0 * powerFactor;
                    if (gPlayerReeling) reelAmount = gReelRate * 0.56;  // 0.8 * 0.7
                } else if (gPlayerDirection == 1) {
                    tensionDelta = 8.0 * powerFactor;
                    if (gPlayerReeling) reelAmount = gReelRate * 0.14;  // 0.2 * 0.7
                } else {
                    tensionDelta = 4.0 * powerFactor;
                    if (gPlayerReeling) reelAmount = gReelRate * 0.28;  // 0.4 * 0.7
                }
            }

            // ── LINE OUT (Page Up): rapidly drop tension at cost of distance ──
            // Override above values when line-out is active
            if (gPlayerLineOut) {
                // Big tension drop - emergency release
                tensionDelta = -15.0;
                // Fish gains distance fast as line plays out
                reelAmount = -gReelRate * 2.0;  // Negative = distance increases
                // No stamina drain - fish doesn't have to fight us
                staminaDrain = -1.0;  // Fish actually recovers a bit
            }

            // Apply changes
            float prevTension = gTension;
            gTension += tensionDelta * gFightTick;
            gFightDistance -= reelAmount * gFightTick;
            gFishStamina -= staminaDrain * gFightTick;

            // Water-type-based distance cap (max line that can play out)
            float distCap = 50.0;  // pond default
            if (gCurrentWaterType == "river") distCap = 40.0;
            else if (gCurrentWaterType == "lake") distCap = 75.0;
            else if (gCurrentWaterType == "ocean") distCap = 100.0;

            // Clamp values
            if (gTension < 0) gTension = 0;
            if (gFightDistance < 0) gFightDistance = 0;
            if (gFightDistance > distCap) gFightDistance = distCap;
            if (gFishStamina < 0) gFishStamina = 0;
            if (gFishStamina > gFishStaminaMax) gFishStamina = gFishStaminaMax;

            // Tension warning sound when crossing 80% upward
            float prevPct = prevTension / gLineStrength;
            float curPct = gTension / gLineStrength;
            if (prevPct < 0.8 && curPct >= 0.8) {
                safePlaySound("line_strain", 0.9);
            }

            // Check failure: line snap
            if (gTension >= gLineStrength) {
                hudInfo("SNAP! Line broke! " + gFishName + " escaped!");
                safePlaySound("line_snap", 1.0);
                toRod("ROD_LINEBREAK");
                // Report line break for XP + magnet loss
                apiCall("line_break", "spot_id=" + (string)gCurrentSpotId);
                doReelInFull(FALSE, TRUE);
                return;
            }

            // Check success: fish landed
            if (gFightDistance <= 0) {
                gState = STATE_LANDING;
                hudInfo("Fish landed! Confirming catch...");
                safePlaySound("catch_success", 1.0);

                if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                    safePlayAnim("fishing_landed");
                }

                gHttpReq = apiCall("confirm_catch",
                    "catch_token=" + llEscapeURL(gCatchToken) +
                    "&spot_id=" + (string)gCurrentSpotId +
                    "&fight_duration=" + (string)llRound(gFightElapsed) +
                    "&cast_distance=" + (string)gCastPower
                );
                restoreTimer();   // LANDING: waits on confirm_catch
                return;
            }

            // Ease the fish's angle toward where it's running (smooth arc)
            gFightAngle += (gFightAngleTarget - gFightAngle) * 0.15;

            updateFightDisplay();

            float tPct = gTension / gLineStrength;
            toRod("ROD_TENSION|" + (string)tPct);

            // Move bobber based on fight distance and angle
            if (gBobberKey != NULL_KEY) {
                vector myPos = llGetPos();
                // Base direction: from player toward anchor
                vector toAnchor = gAnchorPos - myPos;
                toAnchor.z = 0.0;
                float baseDist = llVecMag(toAnchor);
                vector baseDir;
                if (baseDist > 0.1) baseDir = llVecNorm(toAnchor);
                else baseDir = <1.0, 0.0, 0.0>;

                // Rotate the direction by the current fight angle
                rotation rot = llEuler2Rot(<0.0, 0.0, gFightAngle>);
                vector fightDir = baseDir * rot;

                // Position = player + direction * distance
                vector bobberTarget = myPos + fightDir * gFightDistance;
                bobberTarget.z = gSpotPos.z;  // Keep at water level

                llRegionSayTo(gBobberKey, CH_HUD_TO_BOBBER,
                    "MOVE_TO|" + (string)bobberTarget);
            }
            return;
        }
    }

    // ── HTTP Request (incoming server push + URL grant) ──
    http_request(key id, string method, string body) {
        // URL grant response from llRequestSecureURL / llRequestURL
        if (id == gUrlRequest) {
            if (method == URL_REQUEST_GRANTED) {
                gCallbackUrl = body;
                registerCallbackUrl();
                // The fishing view lives at our URL, which just changed
                if (gPanelOpen && gPanelFishView) openPanel(TRUE, "");
                updateSwap();
            }
            else if (method == URL_REQUEST_DENIED) {
                if (!gTriedPlainUrl) {
                    // Secure URL not supported — try plain HTTP
                    gTriedPlainUrl = TRUE;
                    gUrlRequest = llRequestURL();
                } else {
                    // Both denied: this grid does not allow HTTP-in, so the
                    // server can never push to us. Tell it, so it queues
                    // instead of POSTing into a void — we collect on each
                    // hud_status instead.
                    gCallbackUrl = "";
                    if (gToken != "") apiCall("hud_no_http_in", "");
                    // No URL, no fishing view: the fight stays on the bar
                    if (gPanelOpen && gPanelFishView) closePanel();
                    updateSwap();
                }
            }
            return;
        }

        // Fishing view in the media panel: the page shell, and its live
        // state (polled a few times a second while the view is open).
        // Read-only: nothing here changes the game.
        if (method == "GET") {
            string path = llGetHTTPHeader(id, "x-path-info");
            if (path == "/s") {
                llSetContentType(id, CONTENT_TYPE_TEXT);
                llHTTPResponse(id, 200, fishViewState());
            } else if (path == "/fish" || path == "/fish/") {
                llSetContentType(id, CONTENT_TYPE_HTML);
                llHTTPResponse(id, 200, fishViewShell());
            } else {
                llHTTPResponse(id, 404, "Not found");
            }
            return;
        }

        // Incoming push from the server
        if (method == "POST" || method == "PUT") {
            // Respond 200 immediately so server doesn't retry
            llHTTPResponse(id, 200, "OK");

            handlePush(body);
            return;
        }

        // Respond 405 to anything else
        llHTTPResponse(id, 405, "Method Not Allowed");
    }

    // ── HTTP Response ──
    http_response(key req, integer status, list meta, string body) {
        if (req != gHttpReq) return;

        if (status != 200) {
            string err = jsonGet(body, "error");

            // Token was revoked, expired, or signature failed -- clear and re-pair
            if (gToken != "" && (status == 401 || status == 403)) {
                llOwnerSay("Saved pairing is no longer valid. Starting fresh pairing...");
                clearToken();
                showStatus("Re-pairing required...");
                gHttpReq = apiCallPublic("check_account", "uuid=" + llEscapeURL((string)gOwner) + "&grid_name=" + llEscapeURL(detectGrid()));
                return;
            }

            if (err != "") llOwnerSay("Error: " + err);
            else llOwnerSay("Server error (HTTP " + (string)status + ")");
            if (gState == STATE_CAST || gState == STATE_LANDING) resetToReady();
            return;
        }

        string success = jsonGet(body, "success");
        if (success != "true" && success != "1" && success != JSON_TRUE) {
            string err = jsonGet(body, "error");
            llOwnerSay("Error: " + err);
            if (gState == STATE_CAST) resetToReady();
            return;
        }

        if (gHttpAction == "check_account") {
            string exists = jsonGet(body, "exists");
            if (exists == "true" || exists == "1" || exists == JSON_TRUE) {
                showStatus("Account found. Requesting pair code...");
                requestPairing();
            } else {
                // No account for this avatar yet. Pair anyway: the pair link
                // lets the owner either log in to an account they already
                // have (adding this avatar to it, e.g. their avatar on another
                // grid) or create a new one.
                showStatus("New avatar - requesting pair link...");
                requestPairing();
            }
            return;
        }

        if (gHttpAction == "pair_request") {
            gPairingCode = jsonGet(body, "pairing_code");
            integer expiresIn = jsonGetInt(body, "expires_in");
            string pairUrl  = jsonGet(body, "pair_url");
            gPairTimeout = llGetUnixTime() + expiresIn;
            gPairing = TRUE;
            restoreTimer();   // start the pair poll before anything else
            showPairingCode(gPairingCode);
            // Offer the one-click pair URL
            if (pairUrl != "" && pairUrl != JSON_INVALID) {
                string newAv = jsonGet(body, "new_avatar");
                string pairMsg = "Click below to pair your HUD instantly. After logging in, your HUD will pair automatically. (Or use code " + gPairingCode + " manually under Settings > Pair HUD.)";
                if (newAv == "true" || newAv == "1" || newAv == JSON_TRUE)
                    pairMsg = "Welcome to OSFishing! This avatar isn't on an account yet. Click below, then log in to add it to your existing account, or create a new account.";
                llLoadURL(gOwner, pairMsg, pairUrl);
                llOwnerSay("📎 One-click pair link offered (or use code " + gPairingCode + " manually). Pair link: " + pairUrl);
            }
            return;
        }

        if (gHttpAction == "pair_status") {
            string claimed = jsonGet(body, "claimed");
            if (claimed == "true" || claimed == "1" || claimed == JSON_TRUE) {
                gToken = jsonGet(body, "token");
                gTokenId = jsonGetInt(body, "token_id");
                gPairing = FALSE;
                gPairingCode = "";
                gNonce = 0;
                saveToken();  // Persist for next attach/reset
                restoreTimer();
                hudInfo("HUD paired successfully!");
                showStatus("Paired! Loading profile...");
                gHttpReq = apiCall("hud_status", "");
            }
            return;
        }

        if (gHttpAction == "hud_status") {
            gRegistered = TRUE;

            // Drain any pushes the server queued while we were unreachable.
            // On a grid that denies llRequestURL() this is the ONLY way they
            // ever arrive, since we can never be POSTed to.
            string qp = jsonGet(body, "queued_pushes");
            if (qp != "" && qp != JSON_INVALID && qp != "[]") {
                integer qi = 0;
                string one = llJsonGetValue(qp, [qi]);
                while (one != JSON_INVALID && qi < 8) {
                    handlePush(one);
                    qi++;
                    one = llJsonGetValue(qp, [qi]);
                }
            }

            gLevel  = jsonGetInt(body, "level");
            gXP     = jsonGetInt(body, "xp");
            gPoints = jsonGetInt(body, "fishing_points");
            integer xtn = jsonGetInt(body, "xp_to_next");
            if (xtn > 0) gXpToNext = xtn;
            integer xlt = jsonGetInt(body, "xp_level_total");
            if (xlt > 0) gXpLevelTotal = xlt;

            // Parse equipped_bait nested object
            string baitJson = llJsonGetValue(body, ["equipped_bait"]);
            if (baitJson != JSON_INVALID && baitJson != JSON_NULL && baitJson != "" && baitJson != "null") {
                string bn = llJsonGetValue(baitJson, ["name"]);
                if (bn != JSON_INVALID && bn != "") {
                    gEquippedBait = bn;
                    gEquippedBaitId = (integer)llJsonGetValue(baitJson, ["id"]);
                    gBaitRemaining = (integer)llJsonGetValue(baitJson, ["quantity"]);
                }
            } else {
                gEquippedBait = "None";
                gEquippedBaitId = 0;
                gBaitRemaining = 0;
            }

            // Parse equipped_rod nested object (cosmetic display only)
            integer rodIdx = llSubStringIndex(body, "\"equipped_rod\"");
            if (rodIdx != -1) {
                string rodChunk = llGetSubString(body, rodIdx, rodIdx + 512);
                string rodName = jsonGet(rodChunk, "name");
                integer rodId = jsonGetInt(rodChunk, "id");
                if (rodName != "") {
                    gEquippedRod = rodName;
                    gEquippedRodId = rodId;
                }
            }

            // Parse equipped_line nested object
            string lineJson = llJsonGetValue(body, ["equipped_line"]);
            if (lineJson != JSON_INVALID && lineJson != JSON_NULL && lineJson != "") {
                string lineName = llJsonGetValue(lineJson, ["name"]);
                if (lineName != JSON_INVALID && lineName != "") {
                    gEquippedLine   = lineName;
                    gLineWeight     = (float)llJsonGetValue(lineJson, ["weight_lb"]);
                    gLineVisibility = (float)llJsonGetValue(lineJson, ["visibility"]);
                }
            }

            hudInfo("Welcome back, " + gOwnerName + "! Lvl " + (string)gLevel);
            // (Listens are all opened once in state_entry.)
            requestCallbackUrl();
            updateMainDisplay();
            saveDisplayCache();

            // Check tutorial state — first time? offer it.
            if (!gTutorialAsked) {
                gTutorialAsked = TRUE;
                gHttpReq = apiCall("hud_tutorial_state", "");
            }
            return;
        }

        if (gHttpAction == "hud_tutorial_state") {
            integer completed = jsonGetInt(body, "completed");
            integer step = jsonGetInt(body, "step");
            if (completed) {
                // Tutorial done; do nothing
                return;
            }
            gTutorialStep = step;  // Save for resume
            if (step > 0) {
                // Resume mid-tutorial
                showTutorialResumeDialog(step);
            } else {
                // Fresh; offer
                showTutorialOfferDialog();
            }
            return;
        }

        if (gHttpAction == "hud_tutorial_set_step" || gHttpAction == "hud_tutorial_complete") {
            // Just confirms — no action needed
            return;
        }

        if (gHttpAction == "cast" || gHttpAction == "roll_minute") {
            // Both cast and roll_minute return the same schedule format
            // Only cast returns bait_remaining — don't overwrite from roll_minute
            if (gHttpAction == "cast") {
                integer br = jsonGetInt(body, "bait_remaining");
                if (br > 0) gBaitRemaining = br;
            }

            // Parse schedule times (JSON array of floats)
            gSchedTimes = [];
            gSchedFishJson = [];
            gSchedIndex = 0;

            string schedArr = llJsonGetValue(body, ["schedule"]);
            string fishArr  = llJsonGetValue(body, ["schedule_fish"]);

            if (schedArr != JSON_INVALID && schedArr != "") {
                integer i = 0;
                string t = llJsonGetValue(body, ["schedule", i]);
                while (t != JSON_INVALID) {
                    gSchedTimes += [(float)t];
                    string fish = llJsonGetValue(body, ["schedule_fish", i]);
                    // Null entries in JSON become "" in LSL; keep as empty string for fake nibbles
                    if (fish == JSON_NULL || fish == JSON_INVALID) fish = "";
                    gSchedFishJson += [fish];
                    i++;
                    t = llJsonGetValue(body, ["schedule", i]);
                }
            }

            string dw = jsonGet(body, "dead_water");
            gDeadWater = (dw == "true" || dw == "1" || dw == JSON_TRUE);

            if (gHttpAction == "cast") {
                // First call: set up line data, rez bobber, play splash
                gCurrentMinute = 1;
                gCastTotalElapsed = 0.0;
                gMinuteElapsed = 0.0;

                // Line data for fight
                string lineJson = llJsonGetValue(body, ["line"]);
                if (lineJson != JSON_INVALID) {
                    gEquippedLine   = llJsonGetValue(body, ["line", "name"]);
                    gLineWeight     = (float)llJsonGetValue(body, ["line", "weight_lb"]);
                    gLineVisibility = (float)llJsonGetValue(body, ["line", "visibility"]);
                }

                // Calculate bobber landing position
                // Direction: from player toward the fishing spot
                // Distance: 10m minimum, scales with power up to max for water type
                float maxCastDist = 30.0;
                if (gCurrentWaterType == "river") maxCastDist = 25.0;
                else if (gCurrentWaterType == "lake") maxCastDist = 40.0;
                else if (gCurrentWaterType == "ocean") maxCastDist = 50.0;

                float castDist = 10.0 + gCastPower * (maxCastDist - 10.0);

                vector myPos = llGetPos();
                vector toSpot = gSpotPos - myPos;
                toSpot.z = 0.0;  // Flatten to horizontal
                float spotDist = llVecMag(toSpot);
                vector castDir;
                if (spotDist > 0.1) castDir = llVecNorm(toSpot);
                else castDir = <1.0, 0.0, 0.0>;  // Fallback if standing on spot

                gAnchorPos = myPos + castDir * castDist;
                gAnchorPos.z = gSpotPos.z;  // Water level

                // Check if the bobber landed within the fishing area
                vector anchorFlat = gAnchorPos;
                vector spotFlat = gSpotPos;
                anchorFlat.z = 0.0;
                spotFlat.z = 0.0;
                float landingDist = llVecMag(anchorFlat - spotFlat);

                if (gSpotRadius > 0.0 && landingDist > gSpotRadius) {
                    // Bad cast — too far from fishing area
                    hudInfo("Bad cast! Missed the fishing area.");
                    safePlaySound("bobber_splash", 0.5);
                    // Rez bobber briefly so player sees where it landed
                    toRod("ROD_REZ_BOBBER|" +
                        (string)gAnchorPos + "|" + (string)gCastPower);
                    llSleep(1.5);
                    resetToReady();
                    return;
                }

                // Reset fight angle
                gFightAngle = 0.0;
                gFightAngleTarget = 0.0;

                toRod("ROD_REZ_BOBBER|" +
                    (string)gAnchorPos + "|" + (string)gCastPower);

                gState = STATE_CAST;
                restoreTimer();
                safePlaySound("bobber_splash", 0.8);
                safePlayAnim("fishing_wait");

                if (gDeadWater) {
                    hudInfo("Line in the water... don't expect much here.");
                } else if (llGetListLength(gSchedTimes) == 0) {
                    hudInfo("Line in the water... nothing interested yet.");
                } else {
                    hudInfo("Line in the water... watch the bobber!");
                }
                updateMainDisplay();
            } else {
                // roll_minute response: just reset minute timer and continue
                gMinuteElapsed = 0.0;
            }
            return;
        }

        if (gHttpAction == "reel_in") {
            string msg = jsonGet(body, "message");
            gBaitRemaining = jsonGetInt(body, "bait_remaining");
            if (msg != "") hudInfo(msg);
            updateMainDisplay();
            return;
        }

        if (gHttpAction == "gather_tick") {
            // Relay result back to the gather point via channel
            integer gathered  = jsonGetInt(body, "gathered");
            string baitName   = jsonGet(body, "bait_name");
            integer totalQty  = jsonGetInt(body, "total_quantity");
            string depleted   = jsonGet(body, "depleted");
            integer stockPct  = jsonGetInt(body, "stock_pct");
            string questMsg   = jsonGet(body, "quest_msg");

            // Update our bait display if this is our equipped bait
            integer baitId = jsonGetInt(body, "bait_id");
            if (baitId == gEquippedBaitId && gathered > 0) {
                gBaitRemaining = totalQty;
                updateMainDisplay();
            }

            // Show gather result on HUD info prim
            if (gathered > 0) {
                string infoMsg = "Found " + (string)gathered + " " + baitName + "\nTotal in inventory: " + (string)totalQty;
                if (questMsg != "") infoMsg += "\n" + questMsg;
                hudInfo(infoMsg);
            } else if (depleted == "true" || depleted == "1" || depleted == JSON_TRUE) {
                hudInfo("This spot is depleted. Come back later.");
            }

            // Send result back to the gather point object (for stock display)
            // To that point only (it used to go to every point in the
            // region), naming the point so it can check.
            if (gGatherKey != NULL_KEY) {
                llRegionSayTo(gGatherKey, CH_GATHER_HUD,
                    "GATHER_RESULT|" + (string)gathered + "|" + baitName + "|" +
                    (string)totalQty + "|" + depleted + "|" + (string)stockPct + "|" + (string)gGatherId);
            }
            return;
        }

        if (gHttpAction == "confirm_catch") {
            string msg    = jsonGet(body, "message");
            string rarity = jsonGet(body, "rarity");
            integer xp    = jsonGetInt(body, "xp_awarded");
            integer pts   = jsonGetInt(body, "points_value");

            // STOP ALL SOUNDS (kills reel loop etc.)
            llStopSound();

            string levelUp = jsonGet(body, "new_level");
            if (levelUp != "" && levelUp != "0" && levelUp != "null") {
                gLevel = (integer)levelUp;
                hudInfo("🎉 LEVEL UP! Level " + levelUp + "!");
            }

            gXP += xp;

            // Show catch details on info prim
            string infoMsg = "CAUGHT: " + gFishName + " " + fmtWeight(gFishWeight) + "lb (" + rarity + ")\n+" + (string)pts + " pts | +" + (string)xp + " XP";

            // Tutorial: advance through cast/reel/caught steps
            if (gTutorialActive && (gTutorialStep == 7 || gTutorialStep == 8)) {
                // Skip directly to "you caught one"
                gTutorialStep = 8;
                advanceTutorial();
            }

            // Show quest progress if any
            string questMsg = llJsonGetValue(body, ["quest_msg"]);
            if (questMsg != "" && questMsg != JSON_INVALID && questMsg != JSON_NULL) {
                infoMsg += "\n" + questMsg;
            }

            hudInfo(infoMsg);

            // Show victory text on status prim
            gLastRarity = rarity;
            setStatus("🎉 CAUGHT IT!\n" + gFishName + "\n" +
                fmtWeight(gFishWeight) + " lbs (" + rarity + ")", <0.4, 1.0, 0.4>);

            // Despawn bobber but KEEP controls for victory delay
            if (gBobberKey != NULL_KEY) {
                llRegionSayTo(gBobberKey, CH_HUD_TO_BOBBER, "DESPAWN");
                gBobberKey = NULL_KEY;
            }
            toRod("ROD_IDLE");

            // Hide fight UI elements
            hideFightDisplay();

            // Stop ALL fight animations and sounds before victory
            llStopSound();
            if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                safeStopAnim("fishing_fight_steady");
                safeStopAnim("fishing_fight_lean_left");
                safeStopAnim("fishing_fight_lean_right");
                safeStopAnim("fishing_fight_strain");
                safeStopAnim("fishing_yank");
                safeStopAnim("fishing_wait");
                safeStopAnim("fishing_charge");
                safeStopAnim("fishing_cast");
                safeStopAnim("fishing_nibble");
                safePlayAnim("fishing_landed");
            }

            // Notify tournament boards of new catch
            llRegionSay(CH_TOURNAMENT, "CATCH|" + (string)gCurrentSpotId);

            // Spot level-up ready notification
            string slvlReady = llJsonGetValue(body, ["spot_level_ready"]);
            if (slvlReady == "1" || slvlReady == "true" || slvlReady == JSON_TRUE) {
                llOwnerSay("[Spot] " + gCurrentSpotName + " is ready to level up! Touch the spot to confirm.");
            }

            // Request junk item delivery from spot if one was rolled
            string junkItem = llJsonGetValue(body, ["junk_item"]);
            if (junkItem != JSON_INVALID && junkItem != JSON_NULL && junkItem != "" && junkItem != "null") {
                llRegionSay(CH_SPOT_TO_HUD, "DELIVER_JUNK|" + (string)gCurrentSpotId + "|" + junkItem + "|" + (string)gOwner);
            }

            // Enter victory state - 5 second delay before releasing controls
            gState = STATE_VICTORY;
            gVictoryDeadline = llGetTime() + 5.0;
            restoreTimer();
            return;
        }

        if (gHttpAction == "bait_equip") {
            gEquippedBait = jsonGet(body, "bait_name");
            gEquippedBaitId = jsonGetInt(body, "equipped_bait_id");
            gBaitRemaining = jsonGetInt(body, "quantity");
            hudInfo("Equipped: " + gEquippedBait + " (" + (string)gBaitRemaining + ")");
            updateMainDisplay();
            return;
        }

        if (gHttpAction == "bait_inventory") {
            hudInfo("Opening bait panel...");
            return;
        }

        if (gHttpAction == "shop_items") {
            hudInfo("Opening shop panel...");
            
            return;
        }

        if (gHttpAction == "fish_inventory_grouped") {
            hudInfo("Opening fish inventory...");
            return;
        }
    }

    // ── Listen Handler ──
    listen(integer ch, string name, key id, string msg) {
        // Owner channel-0 commands
        if (ch == 0 && id == gOwner) {
            if (msg == "/tutorial" || msg == "/tut") {
                if (gToken == "") {
                    llOwnerSay("HUD not paired yet — pair first to use the tutorial.");
                    return;
                }
                gTutorialAsked = TRUE;  // suppress auto-trigger
                // Reset and start
                gTutorialStep = 0;
                startTutorial(0);
                return;
            }
            return;
        }

        // Tutorial dialog response
        if (ch == gTutorialDialogCh) {
            cleanupTutorialDialog();
            if (msg == "Skip") {
                finishTutorial(TRUE);
            } else if (msg == "Start Tutorial") {
                startTutorial(0);
            } else if (msg == "Resume") {
                startTutorial(gTutorialStep);
            } else if (msg == "Restart") {
                gTutorialStep = 0;
                startTutorial(0);
            }
            return;
        }

        if (ch == CH_SPOT_TO_HUD) {
            list parts = llParseString2List(msg, ["|"], []);
            if (llList2String(parts, 0) == "SPOT") {
                integer wasNoSpot = (gCurrentSpotId == 0);
                gCurrentSpotId   = (integer)llList2String(parts, 1);
                gCurrentSpotName = llList2String(parts, 2);
                gSpotPos = <(float)llList2String(parts, 3),
                            (float)llList2String(parts, 4),
                            (float)llList2String(parts, 5)>;
                string wt = llList2String(parts, 6);
                if (wt != "") gCurrentWaterType = wt;
                string rad = llList2String(parts, 7);
                if (rad != "") gSpotRadius = (float)rad;
                if (gSpotRadius < 5.0) gSpotRadius = 5.0;
                string slvl = llList2String(parts, 8);
                if (slvl != "") gCurrentSpotLevel = (integer)slvl;
                updateMainDisplay();
                if (wasNoSpot) {
                    hudInfo("Spot: " + gCurrentSpotName + " (" + gCurrentWaterType + " Lvl " + (string)gCurrentSpotLevel + ")");
                }
                // Tutorial: advance from "find a spot" step when first detected
                if (wasNoSpot && gTutorialActive && gTutorialStep == 5) {
                    advanceTutorial();
                }
            }
            else if (llList2String(parts, 0) == "UNSPOT") {
                integer unspotId = (integer)llList2String(parts, 1);
                // Only clear if we're linked to this specific spot and not actively fishing
                if (unspotId == gCurrentSpotId && gState == STATE_IDLE) {
                    gCurrentSpotId    = 0;
                    gCurrentSpotName  = "";
                    gCurrentSpotLevel = 1;
                    gSpotPos          = ZERO_VECTOR;
                    gSpotRadius       = 0.0;
                    updateMainDisplay();
                }
            }
            return;
        }

        if (ch == CH_BOBBER_TO_HUD) {
            // Only our own bobber; the channel is shared by every player.
            if (llGetOwnerKey(id) != gOwner) return;
            list parts = llParseString2List(msg, ["|"], []);
            if (llList2String(parts, 0) == "BOBBER_READY") {
                gBobberKey = id;
            }
            return;
        }

        if (ch == CH_ROD_TO_HUD) {
            if (llGetOwnerKey(id) != gOwner) return;
            if (msg == "ROD_HELLO") gRodKey = id;
            return;
        }

        if (ch == CH_GATHER_HUD) {
            list parts = llParseString2List(msg, ["|"], []);
            string cmd = llList2String(parts, 0);
            // Only objects near us: anything in the region can message our
            // avatar, and a tick spends that point's stock in our name.
            if (!isNear(id, 20.0)) return;
            if (cmd == "GATHER_TICK") {
                // Gather point is asking us to make an authenticated API call
                integer pointId = (integer)llList2String(parts, 1);
                gGatherKey = id;
                gGatherId  = pointId;
                gHttpReq = apiCall("gather_tick", "point_id=" + (string)pointId);
            }
            else if (cmd == "GATHER_MSG") {
                // Display message from gather point on info prim
                string gatherMsg = llList2String(parts, 1);
                if (gatherMsg != "") hudInfo(gatherMsg);
            }
            return;
        }

        if (ch == gDialogCh) {
            llListenRemove(gDialogHandle);
            gDialogHandle = 0;

            if (msg == "Cancel") {
                gState = STATE_IDLE;
                return;
            }

            if (gHttpAction == "quest_menu") {
                if (msg == "Active") {
                    gHttpReq = apiCall("quest_active", "");
                } else if (msg == "Available") {
                    gHttpReq = apiCall("quest_available", "");
                } else if (msg == "Completed") {
                    hudInfo("Completed quests shown on web portal.");
                }
                gState = STATE_IDLE;
            }
            return;
        }
    }
}
