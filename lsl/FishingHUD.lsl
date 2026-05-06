// ============================================================
// FISHING HUD - Main Controller (v2 - Named Links)
// ============================================================
// Attach to HUD Center or HUD Bottom.
//
// LINK NAMING:
// Name each prim in your HUD with one of these exact names
// (via Edit > General tab > Name field). Link them in any order.
// On first run, the script scans for these and caches link numbers.
// The root prim does not need a name — it's automatically link 1.
//
// REQUIRED LINK NAMES:
//   "HUD_Root"          — (no longer required - root is auto-detected)
//   "HUD_StartButton"   — Start Fishing button
//   "HUD_BaitButton"    — Bait menu button
//   "HUD_FishButton"    — Fish inventory button
//   "HUD_QuestButton"   — Quest menu button
//   "HUD_ShopButton"    — Shop menu button
//   "HUD_PowerBar"      — Power bar fill (stretches during cast charge)
//   "HUD_TensionBar"    — Tension bar fill (stretches during fight)
//   "HUD_FishIcon"      — Fish direction indicator (moves L/R during fight)
//   "HUD_StatusText"    — Status text display prim (hover text)
//
// OPTIONAL LINK NAMES (used if present):
//   "HUD_PointsDisplay" — Points counter display
//   "HUD_LevelDisplay"  — Level display
//   "HUD_BaitIcon"      — Current bait icon
//
// CHANNELS:
//   -7710001 : HUD -> Rod
//   -7710002 : Rod -> HUD
//   -7710003 : HUD -> Bobber
//   -7710004 : Bobber -> HUD
//   -7710005 : Fishing Spot -> HUD
// ============================================================

// ── Configuration (HARDCODED - no notecard) ──
string  gApiUrl = "https://sp.wa.darkheartsos.net/fishing/api/";

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

// ── Link Number Cache (populated on scan) ──
integer LINK_ROOT           = -1;
integer LINK_START_BTN      = -1;
integer LINK_BAIT_BTN       = -1;
integer LINK_FISH_BTN       = -1;
integer LINK_QUEST_BTN      = -1;
integer LINK_SHOP_BTN       = -1;
integer LINK_POWER_BAR      = -1;
integer LINK_TENSION_BAR    = -1;
integer LINK_XP_BAR         = -1;   // Optional XP progress bar

// ── Bar sizing config ──
// Bars stretch along Y axis only. Set these to match your HUD prim layout.
// X and Z remain constant (set them in-world on the prim).
float POWER_BAR_MIN_Y   = 0.001;   // Y size at 0% power (smallest visible)
float POWER_BAR_MAX_Y   = 0.50;    // Y size at 100% power — adjust to your HUD
float TENSION_BAR_MIN_Y = 0.001;   // Y size at 0% tension
float TENSION_BAR_MAX_Y = 0.50;    // Y size at 100% tension — adjust to your HUD
float XP_BAR_MIN_Y      = 0.001;   // Y size at 0% XP progress
float XP_BAR_MAX_Y      = 0.50;    // Y size at 100% XP progress
integer LINK_FISH_ICON      = -1;
integer LINK_STATUS_TEXT    = -1;
integer LINK_POINTS_DISPLAY = -1;
integer LINK_LEVEL_DISPLAY  = -1;
integer LINK_BAIT_ICON      = -1;
integer LINK_MEDIA_PANEL    = -1;   // Media browser panel (optional)
integer LINK_INFO          = -1;   // Info text prim (fading messages)

// ── Media Panel Config ──
// The panel slides out to show web portal pages. Adjust positions to your HUD.
vector  MEDIA_HIDDEN_POS  = <0.0, 0.0, -0.5>;     // Tucked behind/under HUD
vector  MEDIA_SHOWN_POS   = <0.0, 0.5, 0.45>;      // Attach-point relative
integer gMediaOpen        = FALSE;
string  gMediaPage        = "";
float   gInfoFadeTime     = 0.0;
integer gInfoVisible      = FALSE;

// ── Required link names (script will error if these aren't found) ──
list REQUIRED_LINKS = [
    "HUD_StartButton",
    "HUD_BaitButton",
    "HUD_FishButton",
    "HUD_QuestButton",
    "HUD_ShopButton",
    "HUD_PowerBar",
    "HUD_TensionBar",
    "HUD_FishIcon",
    "HUD_StatusText"
];

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
integer gCurrentSpotId   = 0;
string  gCurrentSpotName = "";
string  gCurrentWaterType = "pond";  // pond/river/lake/ocean
vector  gSpotPos         = ZERO_VECTOR;
float   gSpotRadius      = 0.0;          // 0 = no radius received, skip bad cast check

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

// ── Bobber ──
key     gBobberKey = NULL_KEY;

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
    // Reset all link cache
    LINK_ROOT = LINK_START_BTN = LINK_BAIT_BTN = LINK_FISH_BTN = -1;
    LINK_QUEST_BTN = LINK_SHOP_BTN = LINK_POWER_BAR = LINK_TENSION_BAR = -1;
    LINK_FISH_ICON = LINK_STATUS_TEXT = LINK_POINTS_DISPLAY = -1;
    LINK_LEVEL_DISPLAY = LINK_BAIT_ICON = -1;

    integer numPrims = llGetNumberOfPrims();
    integer i;

    // Single-prim object — treat it as root only
    if (numPrims == 1) {
        LINK_ROOT = LINK_THIS;
        // In single-prim mode, route everything to the root
        LINK_STATUS_TEXT = LINK_THIS;
        gLinksScanned = TRUE;
        return;
    }

    // Multi-prim: scan from link 1 through numPrims
    // Root is always link 1 - no need to look it up
    LINK_ROOT = 1;
    for (i = 1; i <= numPrims; i++) {
        string name = llGetLinkName(i);

        if (name == "HUD_StartButton")        LINK_START_BTN = i;
        else if (name == "HUD_BaitButton")    LINK_BAIT_BTN = i;
        else if (name == "HUD_FishButton")    LINK_FISH_BTN = i;
        else if (name == "HUD_QuestButton")   LINK_QUEST_BTN = i;
        else if (name == "HUD_ShopButton")    LINK_SHOP_BTN = i;
        else if (name == "HUD_PowerBar")      LINK_POWER_BAR = i;
        else if (name == "HUD_TensionBar")    LINK_TENSION_BAR = i;
        else if (name == "HUD_FishIcon")      LINK_FISH_ICON = i;
        else if (name == "HUD_StatusText")    LINK_STATUS_TEXT = i;
        else if (name == "HUD_PointsDisplay") LINK_POINTS_DISPLAY = i;
        else if (name == "HUD_LevelDisplay")  LINK_LEVEL_DISPLAY = i;
        else if (name == "HUD_BaitIcon")      LINK_BAIT_ICON = i;
        else if (name == "XP_Bar")            LINK_XP_BAR = i;
        else if (name == "HUD_MediaPanel")   LINK_MEDIA_PANEL = i;
        else if (name == "HUD_Info")         LINK_INFO = i;
    }

    // Verify all required links are present (root is always present)
    list missing = [];
    if (LINK_START_BTN == -1)   missing += ["HUD_StartButton"];
    if (LINK_BAIT_BTN == -1)    missing += ["HUD_BaitButton"];
    if (LINK_FISH_BTN == -1)    missing += ["HUD_FishButton"];
    if (LINK_QUEST_BTN == -1)   missing += ["HUD_QuestButton"];
    if (LINK_SHOP_BTN == -1)    missing += ["HUD_ShopButton"];
    if (LINK_POWER_BAR == -1)   missing += ["HUD_PowerBar"];
    if (LINK_TENSION_BAR == -1) missing += ["HUD_TensionBar"];
    if (LINK_FISH_ICON == -1)   missing += ["HUD_FishIcon"];
    if (LINK_STATUS_TEXT == -1) missing += ["HUD_StatusText"];

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
        llOwnerSay("║ Edit each prim and set its Name field in");
        llOwnerSay("║ the General tab to match. Then touch the HUD");
        llOwnerSay("║ to rescan, or detach and reattach.");
        llOwnerSay("╚═══════════════════════════════════════════");
        gLinksScanned = FALSE;
        return;
    }

    // Success — brief confirmation
    gLinksScanned = TRUE;
    showPlayButton();  // Ensure play face is showing on startup
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
    gHttpReq = apiCallPublic("check_account", "uuid=" + llEscapeURL((string)gOwner));
}
requestPairing() {
    gHttpReq = apiCallPublic("pair_request", "uuid=" + llEscapeURL((string)gOwner) + "&grid_name=" + llEscapeURL(osGetGridName()));
}
pollPairingStatus() {
    if (gPairingCode == "") return;
    if (llGetUnixTime() > gPairTimeout) {
        // Only expire if no pending HTTP request — avoid race condition
        // where the last poll's response arrives after we declare expiry
        gPairing = FALSE;
        gPairingCode = "";
        llSetTimerEvent(0.0);
        showStatus("Pairing expired - touch to retry");
        return;
    }
    gHttpReq = apiCallPublic("pair_status",
        "uuid=" + llEscapeURL((string)gOwner) +
        "&code=" + llEscapeURL(gPairingCode));
}

showStatus(string msg) {
    safeLinkParams(LINK_STATUS_TEXT, [PRIM_TEXT, "🎣 " + msg, <1.0, 0.85, 0.4>, 1.0]);
}

// ─────────────────────────────────────────────────────────
// TUTORIAL — find linked prims, dispatch instructions
// ─────────────────────────────────────────────────────────

// Pointer overlay offset on the X axis (depth).
// HUD plane is at X=0; pointer sits slightly forward toward camera.
float POINTER_FORWARD_X = -0.01;

// Off-screen position used when hiding the pointer.
vector OFFSET_HIDDEN = <0.0, 0.0, -100.0>;

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

    string targetPrim = "";
    if (buttonName == "tackle")      targetPrim = "HUD_BaitButton";
    else if (buttonName == "fish")   targetPrim = "HUD_FishButton";
    else if (buttonName == "play")   targetPrim = "HUD_StartButton";
    else if (buttonName == "quests") targetPrim = "HUD_QuestButton";
    else if (buttonName == "shop")   targetPrim = "HUD_ShopButton";

    if (targetPrim == "") {
        // Hide
        llSetLinkPrimitiveParamsFast(TUT_LINK_POINTER, [
            PRIM_POS_LOCAL, OFFSET_HIDDEN,
            PRIM_COLOR, ALL_SIDES, <1.0, 1.0, 1.0>, 0.0,
            PRIM_TEXT, "", <1.0, 1.0, 1.0>, 0.0
        ]);
        return;
    }

    vector btnPos = getLinkLocalPos(targetPrim);
    if (btnPos == ZERO_VECTOR) {
        // Couldn't find button — bail silently
        return;
    }

    llSetLinkPrimitiveParamsFast(TUT_LINK_POINTER, [
        PRIM_POS_LOCAL, <POINTER_FORWARD_X, btnPos.y, btnPos.z>,
        PRIM_COLOR, ALL_SIDES, <1.0, 1.0, 0.0>, 0.85,
        PRIM_TEXT, "👆", <1.0, 1.0, 0.0>, 1.0
    ]);
}

// Tutorial step instruction text — central registry.
// Returns instruction text and what button to point at, separated by '|'.
// Buttons: tackle | fish | play | quests | shop | hide
string tutorialStepInfo(integer step) {
    if (step == 0)  return "Welcome to OSFishing! Click the pointer to begin.|play";
    if (step == 1)  return "Click Quests to see what's available.|quests";
    if (step == 2)  return "Accept any quest, then click the pointer to continue.|quests";
    if (step == 3)  return "Click Tackle to manage your gear.|tackle";
    if (step == 4)  return "Equip a bait, then click the pointer to continue.|tackle";
    if (step == 5)  return "Walk near a fishing spot. Look for 🎣 in the world.|hide";
    if (step == 6)  return "Click Play to start fishing.|play";
    if (step == 7)  return "Hold E or PgUp to charge cast, release to throw.|hide";
    if (step == 8)  return "Watch the bar! Hold E/PgUp when it's near center.|hide";
    if (step == 9)  return "Caught one! Click Fish Inventory to see it.|fish";
    if (step == 10) return "Click Shop to sell your catch.|shop";
    if (step == 11) return "Sell a fish, then click the pointer to continue.|shop";
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
    safeLinkParams(LINK_STATUS_TEXT, [PRIM_TEXT, display, <0.4, 1.0, 0.6>, 1.0]);
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
        // Quoted string value. Find the next " after the opening one.
        integer endQuote = llSubStringIndex(llGetSubString(rest, 1, -1), "\"");
        if (endQuote <= 0) return "";  // Empty string or missing close quote
        return llGetSubString(rest, 1, endQuote);
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

updateMainDisplay() {
    if (!gLinksScanned) return;

    string text = "🎣 OSFishing\n";
    text += "Level " + (string)gLevel + " | XP: " + (string)gXP;

    safeLinkParams(LINK_STATUS_TEXT, [PRIM_TEXT, text, <0.9, 0.95, 1.0>, 1.0]);

    // Show XP progress bar only when not fishing
    if (gState == STATE_IDLE) showXPBar();
    else hideXPBar();
}

updateFightDisplay() {
    if (!gLinksScanned) return;

    float pct = gTension / gLineStrength;
    if (pct > 1.0) pct = 1.0;

    vector color;
    if (pct < 0.5) color = <pct * 2, 1.0 - pct, 0.0>;
    else color = <1.0, 1.0 - (pct - 0.5) * 2, 0.0>;

    float barY = TENSION_BAR_MIN_Y + pct * (TENSION_BAR_MAX_Y - TENSION_BAR_MIN_Y);
    if (LINK_TENSION_BAR != -1) {
        list curSize = llGetLinkPrimitiveParams(LINK_TENSION_BAR, [PRIM_SIZE]);
        vector sz = llList2Vector(curSize, 0);
        sz.y = barY;
        safeLinkParams(LINK_TENSION_BAR, [
            PRIM_SIZE, sz,
            PRIM_COLOR, ALL_SIDES, color, 1.0
        ]);
    }

    // ── Fish icon positioning ──
    // The fish icon sits at HOME_POS by default (positioned above the HUD).
    // Movement during fight is applied as an OFFSET from home.
    // Adjust FISH_HOME_POS to reposition where the fish "lives" on your HUD.
    vector FISH_HOME_POS = <0.0, 0.0, 0.12>;   // 12cm above HUD root - tune as needed
    vector fishPos = FISH_HOME_POS;

    if (gFishState == FISH_RUSHING_LEFT)       fishPos = FISH_HOME_POS + <-0.08, 0.0, 0.0>;
    else if (gFishState == FISH_RUSHING_RIGHT) fishPos = FISH_HOME_POS + < 0.08, 0.0, 0.0>;
    else if (gFishState == FISH_STRUGGLING)    fishPos = FISH_HOME_POS + < 0.0, 0.0, 0.04>;
    // FISH_EXHAUSTED and default: sits at home position

    safeLinkParams(LINK_FISH_ICON, [PRIM_POS_LOCAL, fishPos]);

    // Build the floating text — minimal on status
    string fightText = gFishName + " " + fmtWeight(gFishWeight) + "lb\n";
    fightText += "Dist: " + (string)llRound(gFightDistance) + "m | Tension: " + (string)llRound(pct * 100) + "%";
    safeLinkParams(LINK_STATUS_TEXT, [PRIM_TEXT, fightText, <0.9, 0.95, 1.0>, 1.0]);

    // Show fish state and player action guide on HUD_Info
    string fishLabel = "";
    if (gFishState == FISH_STRUGGLING)         fishLabel = "STRUGGLING — Stop reeling!";
    else if (gFishState == FISH_RUSHING_LEFT)  fishLabel = "RUSHING LEFT — Pull RIGHT!";
    else if (gFishState == FISH_RUSHING_RIGHT) fishLabel = "RUSHING RIGHT — Pull LEFT!";
    else if (gFishState == FISH_EXHAUSTED)     fishLabel = "EXHAUSTED — REEL NOW!";
    hudInfo(fishLabel);
}

showPowerBar(float power) {
    if (LINK_POWER_BAR == -1) return;
    float barY = POWER_BAR_MIN_Y + power * (POWER_BAR_MAX_Y - POWER_BAR_MIN_Y);
    list curSize = llGetLinkPrimitiveParams(LINK_POWER_BAR, [PRIM_SIZE]);
    vector sz = llList2Vector(curSize, 0);
    sz.y = barY;
    safeLinkParams(LINK_POWER_BAR, [
        PRIM_SIZE, sz,
        PRIM_COLOR, ALL_SIDES, <0.2, 0.6, 1.0>, 1.0
    ]);
}

hidePowerBar() {
    if (LINK_POWER_BAR == -1) return;
    list curSize = llGetLinkPrimitiveParams(LINK_POWER_BAR, [PRIM_SIZE]);
    vector sz = llList2Vector(curSize, 0);
    sz.y = POWER_BAR_MIN_Y;
    safeLinkParams(LINK_POWER_BAR, [
        PRIM_SIZE, sz,
        PRIM_COLOR, ALL_SIDES, <0.2, 0.6, 1.0>, 0.0
    ]);
}

showXPBar() {
    if (LINK_XP_BAR == -1) return;
    float pct = 0.0;
    if (gXpLevelTotal > 0 && gXpToNext >= 0) {
        integer earned = gXpLevelTotal - gXpToNext;
        if (earned < 0) earned = 0;
        pct = (float)earned / (float)gXpLevelTotal;
    } else if (gXpToNext <= 0) {
        pct = 1.0;  // Max level
    }
    if (pct < 0.0) pct = 0.0;
    if (pct > 1.0) pct = 1.0;

    float barY = XP_BAR_MIN_Y + pct * (XP_BAR_MAX_Y - XP_BAR_MIN_Y);
    list curSize = llGetLinkPrimitiveParams(LINK_XP_BAR, [PRIM_SIZE]);
    vector sz = llList2Vector(curSize, 0);
    sz.y = barY;
    safeLinkParams(LINK_XP_BAR, [
        PRIM_SIZE, sz,
        PRIM_COLOR, ALL_SIDES, <0.4, 0.7, 1.0>, 1.0
    ]);
}

hideXPBar() {
    if (LINK_XP_BAR == -1) return;
    list curSize = llGetLinkPrimitiveParams(LINK_XP_BAR, [PRIM_SIZE]);
    vector sz = llList2Vector(curSize, 0);
    sz.y = XP_BAR_MIN_Y;
    safeLinkParams(LINK_XP_BAR, [
        PRIM_SIZE, sz,
        PRIM_COLOR, ALL_SIDES, <0.4, 0.7, 1.0>, 0.0
    ]);
}

// ── Media Panel: slide out and load a web page ──
showMediaPanel(string page) {
    if (LINK_MEDIA_PANEL == -1) return;

    // Build the portal URL with embed mode and auto-auth
    string portalUrl = gApiUrl;
    integer apiIdx = llSubStringIndex(portalUrl, "/api/");
    if (apiIdx != -1) portalUrl = llGetSubString(portalUrl, 0, apiIdx);

    // Generate a signature for auto-login
    string ts = (string)llGetUnixTime();
    string authSig = llSHA256String((string)gTokenId + ":" + ts + ":" + gToken);

    string url = portalUrl + "/?embed=1&page=" + page +
                 "&token_id=" + (string)gTokenId +
                 "&sig=" + llEscapeURL(authSig) +
                 "&ts=" + ts;

    // Slide the panel into view
    safeLinkParams(LINK_MEDIA_PANEL, [PRIM_POSITION, MEDIA_SHOWN_POS]);

    // Set the media on the front face (face 0)
    llSetLinkMedia(LINK_MEDIA_PANEL, 4, [
        PRIM_MEDIA_CURRENT_URL, url,
        PRIM_MEDIA_HOME_URL, url,
        PRIM_MEDIA_AUTO_SCALE, TRUE,
        PRIM_MEDIA_WIDTH_PIXELS, 640,
        PRIM_MEDIA_HEIGHT_PIXELS, 640,
        PRIM_MEDIA_PERMS_INTERACT, PRIM_MEDIA_PERM_OWNER,
        PRIM_MEDIA_PERMS_CONTROL, PRIM_MEDIA_PERM_OWNER
    ]);

    gMediaOpen = TRUE;
    gMediaPage = page;
}

hideMediaPanel() {
    if (LINK_MEDIA_PANEL == -1) return;
    safeLinkParams(LINK_MEDIA_PANEL, [PRIM_POSITION, MEDIA_HIDDEN_POS]);
    llClearLinkMedia(LINK_MEDIA_PANEL, 4);
    gMediaOpen = FALSE;
    gMediaPage = "";
}

// ── Info prim: show a message that fades after a few seconds ──
hudInfo(string msg) {
    if (LINK_INFO == -1) {
        llOwnerSay(msg);  // Fallback if no info prim
        return;
    }
    safeLinkParams(LINK_INFO, [PRIM_TEXT, msg, <0.9, 0.9, 0.8>, 1.0]);
    gInfoVisible = TRUE;
    gInfoFadeTime = llGetTime() + 6.0;  // Fade after 6 seconds
    // If we're idle (no timer running), start a light timer for fade
    if (gState == STATE_IDLE) {
        llSetTimerEvent(2.0);
    }
}

clearInfo() {
    if (LINK_INFO == -1) return;
    safeLinkParams(LINK_INFO, [PRIM_TEXT, "", ZERO_VECTOR, 0.0]);
    gInfoVisible = FALSE;
}

// ── Play/Stop button rotation ──
// Play visible: rotation <0, 0, 0> (face 4 forward)
// Stop visible: rotation <0, 0, 190> degrees (face 2 forward)
showPlayButton() {
    if (LINK_START_BTN == -1) return;
    safeLinkParams(LINK_START_BTN, [
        PRIM_ROT_LOCAL, llEuler2Rot(<0.0, 0.0, 0.0> * DEG_TO_RAD)
    ]);
}

showStopButton() {
    if (LINK_START_BTN == -1) return;
    safeLinkParams(LINK_START_BTN, [
        PRIM_ROT_LOCAL, llEuler2Rot(<0.0, 0.0, 190.0> * DEG_TO_RAD)
    ]);
}

// Check info fade in timer (call from timer event)
checkInfoFade() {
    if (gInfoVisible && llGetTime() > gInfoFadeTime) {
        clearInfo();
        // If idle, stop the timer we started just for fading
        if (gState == STATE_IDLE) {
            llSetTimerEvent(0.0);
        }
    }
}

toggleMediaPanel(string page) {
    if (gMediaOpen && gMediaPage == page) {
        hideMediaPanel();
    } else {
        showMediaPanel(page);
    }
}

hideFightDisplay() {
    if (LINK_TENSION_BAR != -1) {
        list curSize = llGetLinkPrimitiveParams(LINK_TENSION_BAR, [PRIM_SIZE]);
        vector sz = llList2Vector(curSize, 0);
        sz.y = TENSION_BAR_MIN_Y;
        safeLinkParams(LINK_TENSION_BAR, [
            PRIM_SIZE, sz,
            PRIM_COLOR, ALL_SIDES, <1.0, 1.0, 1.0>, 0.0
        ]);
    }
    // Return fish icon to its home position (above the HUD panel).
    // Must match FISH_HOME_POS in updateFightDisplay().
    safeLinkParams(LINK_FISH_ICON, [PRIM_POS_LOCAL, <0.0, 0.0, 0.09>]);
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

    llSetTimerEvent(0.0);
    hidePowerBar();
    hideFightDisplay();
    showPlayButton();  // Rotate back to play face

    if (gBobberKey != NULL_KEY) {
        llRegionSayTo(gBobberKey, CH_HUD_TO_BOBBER, "DESPAWN");
        gBobberKey = NULL_KEY;
    }

    llRegionSayTo(gOwner, CH_HUD_TO_ROD, "ROD_IDLE");
    updateMainDisplay();

    // If info text is still visible, keep a light timer to fade it
    if (gInfoVisible) {
        llSetTimerEvent(2.0);
    }
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

    llRegionSayTo(gOwner, CH_HUD_TO_ROD, "ROD_IDLE");

    // Go back to charging state — ready for next cast
    gState = STATE_CHARGING;
    gCastPower = 0.0;
    showPowerBar(0.0);
    hudInfo("Hold PAGE UP to cast again...");
    llSetTimerEvent(0.1);
    updateMainDisplay();
}

// ══════════════════════════════════════════════════════════
// DEFAULT STATE
// ══════════════════════════════════════════════════════════
default {
    state_entry() {
        gOwner = llGetOwner();
        gOwnerName = llKey2Name(gOwner);
        gState = STATE_IDLE;

        scanLinks();

        if (gLinksScanned) {
            hidePowerBar();
            hideFightDisplay();
        }

        // Start listening immediately
        llListen(CH_SPOT_TO_HUD, "", NULL_KEY, "");
        llListen(CH_BOBBER_TO_HUD, "", NULL_KEY, "");
            llListen(CH_GATHER_HUD, "", NULL_KEY, "");
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
            if (gLinksScanned) {
                hidePowerBar();
                hideFightDisplay();
                updateMainDisplay();
            }
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
            if (gLinksScanned) {
                hidePowerBar();
                hideFightDisplay();
                updateMainDisplay();
            }
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

            if (ptr == "quests") {
                if (gState != STATE_FIGHT) toggleMediaPanel("quests");
            } else if (ptr == "tackle") {
                if (gState != STATE_FIGHT && gState != STATE_CHARGING) toggleMediaPanel("tackle");
            } else if (ptr == "fish") {
                if (gState != STATE_FIGHT) toggleMediaPanel("fish");
            } else if (ptr == "shop") {
                if (gState != STATE_FIGHT) toggleMediaPanel("shop");
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
                    hideMediaPanel();
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

        if (link == LINK_START_BTN) {
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
                hudInfo("Equip bait first! Use the Bait button.");
                return;
            }
            
            gCastPower = 0.0;
            gCharging = FALSE;
            hideMediaPanel();
            showStopButton();  // Rotate to show stop face
            llRequestPermissions(gOwner,
                PERMISSION_TAKE_CONTROLS |
                PERMISSION_TRIGGER_ANIMATION |
                PERMISSION_TRACK_CAMERA |
                PERMISSION_CONTROL_CAMERA);
        }
        else if (link == LINK_BAIT_BTN) {
            if (gState == STATE_FIGHT || gState == STATE_CHARGING) return;
            toggleMediaPanel("tackle");
        }
        else if (link == LINK_FISH_BTN) {
            if (gState == STATE_FIGHT) return;
            toggleMediaPanel("fish");
        }
        else if (link == LINK_QUEST_BTN) {
            if (gState == STATE_FIGHT) return;
            toggleMediaPanel("quests");
        }
        else if (link == LINK_SHOP_BTN) {
            if (gState == STATE_FIGHT) return;
            toggleMediaPanel("shop");
        }
        else if (link == LINK_MEDIA_PANEL) {
            // Clicking the media panel itself does nothing — interaction
            // happens through the embedded web page
        }
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
            hudInfo("Hold PAGE UP to charge, release to cast...");
            gState = STATE_CHARGING;
            gCastPower = 0.0;
            showPowerBar(0.0);
            hideXPBar();
            llSetTimerEvent(0.1);
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
    // CONTROL_UP   = Page Up / E = charge cast, reset/cancel
    // CONTROL_DOWN = Page Down / C = set hook, reel during fight
    control(key id, integer held, integer change) {
        // ── CHARGING: hold Page Up to charge, release to cast ──
        if (gState == STATE_CHARGING) {
            if ((change & CONTROL_UP) && (held & CONTROL_UP)) {
                gCharging = TRUE;
                safePlayAnim("fishing_charge");
                safeLoopSound("cast_charge_loop", 0.5);
            }
            if ((change & CONTROL_UP) && !(held & CONTROL_UP)) {
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

                    llRegionSayTo(gOwner, CH_HUD_TO_ROD, "ROD_CAST|" + (string)gCastPower);

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

        // ── WAITING: Page Down to start reeling in ──
        if (gState == STATE_CAST) {
            if ((change & CONTROL_DOWN) && (held & CONTROL_DOWN)) {
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

        // ── REELING IN: hold Page Down to keep reeling, release to stop ──
        if (gState == STATE_REELING) {
            if (!(held & CONTROL_DOWN)) {
                // Released Page Down
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

        // ── BITE: Page Down to set the hook ──
        if (gState == STATE_BITE) {
            if ((change & CONTROL_DOWN) && (held & CONTROL_DOWN)) {
                if (gBiteActive) {
                    gState = STATE_HOOKSET;
                    safePlaySound("hook_set", 1.0);
                    hudInfo("HOOK SET! Fight is on!");

                    if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                        safePlayAnim("fishing_yank");
                    }

                    llRegionSayTo(gOwner, CH_HUD_TO_ROD, "ROD_HOOKSET|" + (string)gBobberKey);

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
                    llSetTimerEvent(gFightTick);
                } else {
                    hudInfo("Too early! Fish got spooked!");
                    doReelIn(FALSE);
                }
            }
            return;
        }

        // ── FIGHT: Page Down to reel, Page Up to let line out, Left/Right to steer ──
        if (gState == STATE_FIGHT) {
            integer wasReeling = gPlayerReeling;
            integer wasLineOut = gPlayerLineOut;

            if (held & CONTROL_DOWN) gPlayerReeling = TRUE;
            else gPlayerReeling = FALSE;

            if (held & CONTROL_UP) gPlayerLineOut = TRUE;
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

                        if (gBobberKey != NULL_KEY) {
                            llRegionSayTo(gBobberKey, CH_HUD_TO_BOBBER, "BITE");
                        }

                        safePlaySound("bite_alert", 1.0);
                        llSetTimerEvent(gBiteWindow);
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
            safePlaySound("bite_miss", 0.8);
            safePlayAnim("fishing_disappointed");
            hudInfo("Too slow! The fish got away...");
            // Go back to waiting - fish escaping doesn't end the cast
            gState = STATE_CAST;
            gBiteActive = FALSE;
            llSetTimerEvent(0.1);
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
                    llRegionSayTo(gOwner, CH_HUD_TO_ROD, "ROD_LINEBREAK");
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
                llRegionSayTo(gOwner, CH_HUD_TO_ROD, "ROD_LINEBREAK");
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
                llSetTimerEvent(0.0);
                return;
            }

            updateFightDisplay();

            float tPct = gTension / gLineStrength;
            llRegionSayTo(gOwner, CH_HUD_TO_ROD, "ROD_TENSION|" + (string)tPct);

            // Move bobber based on fight distance and angle
            if (gBobberKey != NULL_KEY) {
                // Gradually shift angle toward target (smooth arc, not snap)
                float angleDiff = gFightAngleTarget - gFightAngle;
                gFightAngle += angleDiff * 0.15;  // Ease toward target each tick

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
            }
            else if (method == URL_REQUEST_DENIED) {
                // Secure URL not supported — try plain HTTP
                gUrlRequest = llRequestURL();
            }
            return;
        }

        // Incoming push from the server
        if (method == "POST" || method == "PUT") {
            // Respond 200 immediately so server doesn't retry
            llHTTPResponse(id, 200, "OK");

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
                gHttpReq = apiCallPublic("check_account", "uuid=" + llEscapeURL((string)gOwner));
                return;
            }

            if (err != "") llOwnerSay("Error: " + err);
            else llOwnerSay("Server error (HTTP " + (string)status + ")");
            if (gState == STATE_CAST || gState == STATE_LANDING) resetToReady();
            return;
        }

        string success = jsonGet(body, "success");
        if (success != "true" && success != "1") {
            string err = jsonGet(body, "error");
            llOwnerSay("Error: " + err);
            if (gState == STATE_CAST) resetToReady();
            return;
        }

        if (gHttpAction == "check_account") {
            string exists = jsonGet(body, "exists");
            if (exists == "true" || exists == "1") {
                showStatus("Account found. Requesting pair code...");
                requestPairing();
            } else {
                // Direct user to the portal to register. Portal handles
                // the registration UI and uses ?uuid= to pre-fill if supported.
                string portalUrl = gApiUrl;
                integer apiIdx = llSubStringIndex(portalUrl, "/api/");
                if (apiIdx != -1) {
                    portalUrl = llGetSubString(portalUrl, 0, apiIdx - 1);
                }
                string regUrl = portalUrl + "/?register=1&uuid=" +
                                llEscapeURL((string)gOwner) +
                                "&name=" + llEscapeURL(gOwnerName);

                showStatus("No account - click the link to register");
                llLoadURL(gOwner,
                    "Welcome to OSFishing! You don't have an account yet. Click below to open the registration page in your browser, then touch the HUD again once registered.",
                    regUrl);
                llOwnerSay("A registration link has been offered: " + regUrl);
            }
            return;
        }

        if (gHttpAction == "pair_request") {
            gPairingCode = jsonGet(body, "pairing_code");
            integer expiresIn = jsonGetInt(body, "expires_in");
            string pairUrl  = jsonGet(body, "pair_url");
            gPairTimeout = llGetUnixTime() + expiresIn;
            gPairing = TRUE;
            showPairingCode(gPairingCode);
            // Offer the one-click pair URL
            if (pairUrl != "" && pairUrl != JSON_INVALID) {
                llLoadURL(gOwner,
                    "Click below to pair your HUD instantly. After logging in, your HUD will pair automatically. (Or use code " + gPairingCode + " manually under Settings > Pair HUD.)",
                    pairUrl);
                llOwnerSay("📎 One-click pair link offered (or use code " + gPairingCode + " manually). Pair link: " + pairUrl);
            }
            llSetTimerEvent(gPairPollRate);
            return;
        }

        if (gHttpAction == "pair_status") {
            string claimed = jsonGet(body, "claimed");
            if (claimed == "true" || claimed == "1") {
                gToken = jsonGet(body, "token");
                gTokenId = jsonGetInt(body, "token_id");
                gPairing = FALSE;
                gPairingCode = "";
                gNonce = 0;
                saveToken();  // Persist for next attach/reset
                llSetTimerEvent(0.0);
                hudInfo("HUD paired successfully!");
                showStatus("Paired! Loading profile...");
                gHttpReq = apiCall("hud_status", "");
            }
            return;
        }

        if (gHttpAction == "hud_status") {
            gRegistered = TRUE;
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
            llListen(CH_SPOT_TO_HUD, "", NULL_KEY, "");
            llListen(CH_BOBBER_TO_HUD, "", NULL_KEY, "");
            llListen(CH_GATHER_HUD, "", NULL_KEY, "");
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

        if (gHttpAction == "register") {
            // No longer used - registration happens on web portal
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
            gDeadWater = (dw == "true" || dw == "1");

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
                    llRegionSayTo(gOwner, CH_HUD_TO_ROD, "ROD_REZ_BOBBER|" +
                        (string)gAnchorPos + "|" + (string)gCastPower);
                    llSleep(1.5);
                    resetToReady();
                    return;
                }

                // Reset fight angle
                gFightAngle = 0.0;
                gFightAngleTarget = 0.0;

                llRegionSayTo(gOwner, CH_HUD_TO_ROD, "ROD_REZ_BOBBER|" +
                    (string)gAnchorPos + "|" + (string)gCastPower);

                gState = STATE_CAST;
                llSetTimerEvent(0.1);
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
            } else if (depleted == "true" || depleted == "1") {
                hudInfo("This spot is depleted. Come back later.");
            }

            // Send result back to the gather point object (for stock display)
            llRegionSay(CH_GATHER_HUD,
                "GATHER_RESULT|" + (string)gathered + "|" + baitName + "|" +
                (string)totalQty + "|" + depleted + "|" + (string)stockPct);
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
            safeLinkParams(LINK_STATUS_TEXT, [
                PRIM_TEXT,
                "🎉 CAUGHT IT!\n" + gFishName + "\n" +
                fmtWeight(gFishWeight) + " lbs (" + rarity + ")",
                <0.4, 1.0, 0.4>, 1.0
            ]);

            // Despawn bobber but KEEP controls for victory delay
            if (gBobberKey != NULL_KEY) {
                llRegionSayTo(gBobberKey, CH_HUD_TO_BOBBER, "DESPAWN");
                gBobberKey = NULL_KEY;
            }
            llRegionSayTo(gOwner, CH_HUD_TO_ROD, "ROD_IDLE");

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

            // Request junk item delivery from spot if one was rolled
            string junkItem = llJsonGetValue(body, ["junk_item"]);
            if (junkItem != JSON_INVALID && junkItem != JSON_NULL && junkItem != "" && junkItem != "null") {
                llRegionSay(CH_SPOT_TO_HUD, "DELIVER_JUNK|" + (string)gCurrentSpotId + "|" + junkItem + "|" + (string)gOwner);
            }

            // Enter victory state - 5 second delay before releasing controls
            gState = STATE_VICTORY;
            llSetTimerEvent(5.0);
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
                gCurrentSpotId  = (integer)llList2String(parts, 1);
                gCurrentSpotName = llList2String(parts, 2);
                gSpotPos = <(float)llList2String(parts, 3),
                            (float)llList2String(parts, 4),
                            (float)llList2String(parts, 5)>;
                string wt = llList2String(parts, 6);
                if (wt != "") gCurrentWaterType = wt;
                string rad = llList2String(parts, 7);
                if (rad != "") gSpotRadius = (float)rad;
                if (gSpotRadius < 5.0) gSpotRadius = 5.0;
                updateMainDisplay();
                // Tutorial: advance from "find a spot" step when first detected
                if (wasNoSpot && gTutorialActive && gTutorialStep == 5) {
                    advanceTutorial();
                }
            }
            else if (llList2String(parts, 0) == "UNSPOT") {
                integer unspotId = (integer)llList2String(parts, 1);
                // Only clear if we're linked to this specific spot and not actively fishing
                if (unspotId == gCurrentSpotId && gState == STATE_IDLE) {
                    gCurrentSpotId = 0;
                    gCurrentSpotName = "";
                    gSpotPos = ZERO_VECTOR;
                    gSpotRadius = 0.0;
                    updateMainDisplay();
                }
            }
            return;
        }

        if (ch == CH_BOBBER_TO_HUD) {
            list parts = llParseString2List(msg, ["|"], []);
            if (llList2String(parts, 0) == "BOBBER_READY") {
                gBobberKey = id;
            }
            return;
        }

        if (ch == CH_GATHER_HUD) {
            list parts = llParseString2List(msg, ["|"], []);
            string cmd = llList2String(parts, 0);
            if (cmd == "GATHER_TICK") {
                // Gather point is asking us to make an authenticated API call
                integer pointId = (integer)llList2String(parts, 1);
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
