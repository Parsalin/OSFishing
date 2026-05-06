// ============================================================
// FISHING SPOT v2 — Setup Wizard + Owner/Admin Management
// ============================================================
// On first touch: Setup wizard
//   1. Fetch player info (level, available water types, admin)
//   2. Water type selection
//   3. Name prompt
//   4. If admin: System spot?
//   5. Public or Private?
//   6. Activate now?
//   7. Register with server
//
// Owner/Admin touch after setup: Management menu
//   Activate/Deactivate, Rename, Public/Private, Add Junk, Delete
//
// Non-owner touch: Shows spot info
// ============================================================

string gApiUrl = "https://sp.wa.darkheartsos.net/fishing/api/";

// ── Spot State ──
integer gSpotId       = 0;
string  gSpotName     = "";
string  gWaterType    = "";
integer gIsActive     = FALSE;
integer gIsPublic     = TRUE;
integer gIsSystem     = FALSE;
integer gRegistered   = FALSE;
integer gSetupDone    = FALSE;

// ── Player info (from setup query) ──
integer gPlayerIsAdmin = FALSE;
list    gAvailableWater = [];
integer gSpotCount     = 0;
list    gArchivedSpotIds = [];
list    gArchivedSpotLabels = [];
integer gSpotLimit     = 0;

// ── Setup wizard state ──
string  gSetupStep     = "";  // "water", "name", "system", "public", "activate"
integer gDialogCh      = 0;
integer gDialogHandle  = 0;
integer gTextCh        = 0;
integer gTextHandle    = 0;
key     gSetupPlayer   = NULL_KEY;

// ── HTTP ──
key     gHttpReq       = NULL_KEY;
string  gHttpAction    = "";

// ── Server push (HTTP-in) ──
string  gCallbackUrl   = "";
key     gUrlReq        = NULL_KEY;
integer gCallbackRegistered = FALSE;

// ── Active buffs (cached locally, expires_at compared to llGetUnixTime) ──
list    gActiveBuffs   = [];  // Flat: [buff_type, label, effect, expires_at, ...]

// ── Region-empty optimization ──
integer gPlayersPresent = TRUE;
float   gNormalTimer   = 60.0;
float   gIdleTimer     = 600.0;

// ── Heartbeat (every 30 min, regardless of player presence) ──
integer gLastHeartbeat = 0;
integer HEARTBEAT_INTERVAL = 1800;  // 30 minutes

// ── Sensor ──
float   gRange         = 30.0;
float   gScanRate      = 10.0;
list    gInRange       = [];

// ── Channels ──
integer CH_SPOT_TO_HUD = -7710005;

// ── Buff item selection ──
list    gBuffItemIds    = [];
list    gBuffItemLabels = [];
integer gHasActiveBuffs = FALSE;
string  gPendingJunkName = "";
integer gTouchStartTime  = 0;
key     gTouchKey        = NULL_KEY;

// ── Helpers ──

// Cache last-set text so we skip llSetText when nothing changed.
// llSetText marks the prim "dirty" for persistence on every call;
// reducing calls means fewer save events during a sim's normal operation,
// which reduces the chance of a corrupted save during shutdown.
string  gLastText      = "";
vector  gLastTextColor = <0,0,0>;
float   gLastTextAlpha = 0.0;

setTextSafe(string text, vector color, float alpha) {
    if (text == gLastText && color == gLastTextColor && alpha == gLastTextAlpha) return;
    gLastText      = text;
    gLastTextColor = color;
    gLastTextAlpha = alpha;
    llSetText(text, color, alpha);
}

setTextLabel() {
    if (!gSetupDone) {
        setTextSafe("🎣 Fishing Spot\n(Touch to set up)", <1.0, 0.8, 0.3>, 1.0);
    } else if (!gIsActive) {
        setTextSafe("🎣 " + gSpotName + "\n" + gWaterType + " (Inactive)", <0.5, 0.5, 0.5>, 0.8);
    } else {
        // Count junk items in inventory
        integer junkCount = llGetInventoryNumber(INVENTORY_OBJECT);
        string icons = "";
        if (junkCount > 0) icons += "🎁";
        if (gHasActiveBuffs) icons += " ✨";
        setTextSafe("🎣 " + gSpotName + "\n" + gWaterType + "\n" + icons, <0.3, 0.9, 0.5>, 1.0);
    }
}

// ── Active buff management (push-driven) ──

addOrUpdateBuff(string buffType, string label, string effect, integer expiresAt) {
    // Remove existing entry for this buff type
    integer i;
    for (i = 0; i < llGetListLength(gActiveBuffs); i += 4) {
        if (llList2String(gActiveBuffs, i) == buffType) {
            gActiveBuffs = llDeleteSubList(gActiveBuffs, i, i + 3);
            jump removed;
        }
    }
    @removed;
    gActiveBuffs += [buffType, label, effect, expiresAt];
    gHasActiveBuffs = TRUE;
}

removeBuff(string buffType) {
    integer i;
    for (i = 0; i < llGetListLength(gActiveBuffs); i += 4) {
        if (llList2String(gActiveBuffs, i) == buffType) {
            gActiveBuffs = llDeleteSubList(gActiveBuffs, i, i + 3);
            jump done;
        }
    }
    @done;
    gHasActiveBuffs = (llGetListLength(gActiveBuffs) > 0);
}

// Check for locally-tracked expirations — no HTTP needed
checkBuffExpirations() {
    integer now = llGetUnixTime();
    integer i;
    integer changed = FALSE;
    list newBuffs = [];
    for (i = 0; i < llGetListLength(gActiveBuffs); i += 4) {
        integer expiresAt = (integer)llList2String(gActiveBuffs, i + 3);
        if (now < expiresAt) {
            newBuffs += llList2List(gActiveBuffs, i, i + 3);
        } else {
            string label = llList2String(gActiveBuffs, i + 1);
            llSay(0, "💨 " + label + " has worn off on " + gSpotName + ".");
            changed = TRUE;
        }
    }
    if (changed) {
        gActiveBuffs = newBuffs;
        gHasActiveBuffs = (llGetListLength(gActiveBuffs) > 0);
        setTextLabel();
    }
}

// ── Callback URL registration ──

requestCallbackUrl() {
    if (gUrlReq != NULL_KEY) llReleaseURL(gCallbackUrl);
    gUrlReq = llRequestURL();
}

registerCallbackWithServer() {
    if (gCallbackUrl == "" || gSpotId <= 0) return;
    vector regCorner = llGetRegionCorner();
    gHttpAction = "register_cb";
    gHttpReq = llHTTPRequest(gApiUrl, [
        HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
    ], "action=register_prim_callback&prim_uuid=" + (string)llGetKey() +
       "&prim_type=fishing_spot&ref_id=" + (string)gSpotId +
       "&callback_url=" + llEscapeURL(gCallbackUrl) +
       "&region_name=" + llEscapeURL(llGetRegionName()) +
       "&grid_name=" + llEscapeURL(osGetGridName()));
}

// Heartbeat — tells the server "I'm still alive". If the server doesn't
// see one within ~3 missed intervals (90 min) it can mark the spot as
// inactive and stop pushing buffs/events to a dead URL.
sendHeartbeat() {
    if (gSpotId <= 0) return;
    gLastHeartbeat = llGetUnixTime();
    gHttpAction = "heartbeat";
    gHttpReq = llHTTPRequest(gApiUrl, [
        HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
    ], "action=prim_heartbeat&prim_uuid=" + (string)llGetKey());
}

// ── Region-empty check ──

integer regionHasPlayers() {
    list agents = llGetAgentList(AGENT_LIST_REGION, []);
    return (llGetListLength(agents) > 0);
}

notifyPlayer(key av) {
    if (!gRegistered || !gIsActive) return;
    vector pos = llGetPos();
    vector primSize = llGetScale();
    float radius = primSize.x;
    if (primSize.y > radius) radius = primSize.y;
    if (radius < 5.0) radius = 5.0;

    string msg = "SPOT|" + (string)gSpotId + "|" + gSpotName + "|" +
                 (string)pos.x + "|" + (string)pos.y + "|" + (string)pos.z +
                 "|" + gWaterType + "|" + (string)radius;
    llRegionSayTo(av, CH_SPOT_TO_HUD, msg);
}

cleanupDialog() {
    if (gDialogHandle) { llListenRemove(gDialogHandle); gDialogHandle = 0; }
    if (gTextHandle) { llListenRemove(gTextHandle); gTextHandle = 0; }
}

integer isOwnerOrAdmin(key who) {
    return (who == llGetOwner() || gPlayerIsAdmin);
}

// ── Setup wizard steps ──

showArchiveRecoveryMenu(key who) {
    gSetupStep = "archive_recovery";
    gSetupPlayer = who;
    cleanupDialog();
    gDialogCh = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", who, "");

    list buttons = [];
    integer total = llGetListLength(gArchivedSpotLabels);
    integer end = total;
    if (end > 9) end = 9;  // Leave room for "Fresh Setup" button
    integer i;
    for (i = 0; i < end; i++) {
        buttons += [llList2String(gArchivedSpotLabels, i)];
    }
    buttons += ["Fresh Setup", "Cancel"];

    llDialog(who,
        "♻️ Archived spots found in this region!\n\nRestore one to keep its catch history (number in parens), or start fresh.",
        buttons, gDialogCh);
}

showWaterMenu() {
    gSetupStep = "water";
    cleanupDialog();
    gDialogCh = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");

    list buttons = [];
    integer i;
    for (i = 0; i < llGetListLength(gAvailableWater); i++) {
        string wt = llList2String(gAvailableWater, i);
        // Capitalize
        string cap = llToUpper(llGetSubString(wt, 0, 0)) + llGetSubString(wt, 1, -1);
        buttons += [cap];
    }
    buttons += ["Cancel"];

    llDialog(gSetupPlayer,
        "🎣 Fishing Spot Setup\n\nSpots: " + (string)gSpotCount + "/" + (string)gSpotLimit +
        "\n\nSelect water type:",
        buttons, gDialogCh);
}

showNamePrompt() {
    gSetupStep = "name";
    cleanupDialog();
    gTextCh = -1 - (integer)llFrand(999999.0);
    gTextHandle = llListen(gTextCh, "", gSetupPlayer, "");
    llTextBox(gSetupPlayer, "🎣 Name this fishing spot:", gTextCh);
}

showSystemMenu() {
    gSetupStep = "system";
    cleanupDialog();
    gDialogCh = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");
    llDialog(gSetupPlayer,
        "🎣 System Spot?\n\nSystem spots have no owner limit and are managed by admins.",
        ["Yes", "No", "Cancel"], gDialogCh);
}

showPublicMenu() {
    gSetupStep = "public";
    cleanupDialog();
    gDialogCh = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");
    llDialog(gSetupPlayer,
        "🎣 Visibility\n\nPublic spots appear on the grid map.\nPrivate spots work but aren't listed.",
        ["Public", "Private", "Cancel"], gDialogCh);
}

showActivateMenu() {
    gSetupStep = "activate";
    cleanupDialog();
    gDialogCh = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");
    llDialog(gSetupPlayer,
        "🎣 Activate Now?\n\n" + gSpotName + " (" + gWaterType + ")\n" +
        (gIsPublic ? "Public" : "Private") + (gIsSystem ? " | System" : ""),
        ["Activate", "Later", "Cancel"], gDialogCh);
}

// ── Register with server ──
registerWithServer(integer activate) {
    vector pos = llGetPos();
    string region = llGetRegionName();
    vector regionCorner = llGetRegionCorner();

    string body = "action=spot_register" +
                  "&name=" + llEscapeURL(gSpotName) +
                  "&water_type=" + llEscapeURL(gWaterType) +
                  "&region=" + llEscapeURL(region) +
                  "&grid_name=" + llEscapeURL(osGetGridName()) +
                  "&pos_x=" + (string)pos.x +
                  "&pos_y=" + (string)pos.y +
                  "&pos_z=" + (string)pos.z +
                  "&owner_key=" + llEscapeURL((string)llGetOwner()) +
                  "&is_public=" + (string)gIsPublic +
                  "&is_system=" + (string)gIsSystem +
                  "&region_x=" + (string)((integer)(regionCorner.x / 256.0)) +
                  "&region_y=" + (string)((integer)(regionCorner.y / 256.0)) +
                  "&activate=" + (string)activate;

    gHttpAction = "register";
    gHttpReq = llHTTPRequest(gApiUrl, [
        HTTP_METHOD, "POST",
        HTTP_MIMETYPE, "application/x-www-form-urlencoded",
        HTTP_BODY_MAXLENGTH, 4096
    ], body);
}

// ── Owner/Admin management menu ──
showManageMenu(key who) {
    cleanupDialog();
    gSetupPlayer = who;
    gDialogCh = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", who, "");
    gSetupStep = "manage";

    list buttons = ["Use Buff", "Add Junk", "Edit", "Close"];

    llDialog(who,
        "🎣 " + gSpotName + "\n" +
        gWaterType + " | " + (gIsActive ? "Active" : "Inactive") +
        " | " + (gIsPublic ? "Public" : "Private") +
        (gIsSystem ? " | System" : "") +
        "\nID: " + (string)gSpotId,
        buttons, gDialogCh);
}

// ── Edit submenu ──
showEditMenu(key who) {
    cleanupDialog();
    gSetupPlayer = who;
    gDialogCh = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", who, "");
    gSetupStep = "edit";

    string activeBtn = "Activate";
    if (gIsActive) activeBtn = "Deactivate";
    string pubBtn = "Make Public";
    if (gIsPublic) pubBtn = "Make Private";

    llDialog(who,
        "🎣 Edit: " + gSpotName,
        [activeBtn, "Rename", pubBtn, "List Junk", "Delete", "Back"],
        gDialogCh);
}

// ── Junk item management ──
showJunkPrompt() {
    gSetupStep = "add_junk";
    cleanupDialog();
    gTextCh = -1 - (integer)llFrand(999999.0);
    gTextHandle = llListen(gTextCh, "", gSetupPlayer, "");

    // List inventory objects
    integer count = llGetInventoryNumber(INVENTORY_OBJECT);
    string info = "Enter the name of an object in this prim's inventory to add as junk loot.\n\nInventory objects:";
    integer i;
    for (i = 0; i < count && i < 10; i++) {
        info += "\n• " + llGetInventoryName(INVENTORY_OBJECT, i);
    }
    if (count == 0) info += "\n(none — drop objects into this prim first)";

    llTextBox(gSetupPlayer, info, gTextCh);
}

// ── LinksetData Keys ──
string LD_SPOT_ID     = "fs_spot_id";
string LD_SPOT_NAME   = "fs_spot_name";
string LD_WATER_TYPE  = "fs_water_type";
string LD_IS_ACTIVE   = "fs_is_active";
string LD_IS_PUBLIC   = "fs_is_public";
string LD_IS_SYSTEM   = "fs_is_system";
string LD_SETUP_DONE  = "fs_setup_done";
string LD_OWNER_UUID  = "fs_owner_uuid";

// ── Save spot config to LinksetData ──
saveSpotData() {
    llLinksetDataWrite(LD_SPOT_ID, (string)gSpotId);
    llLinksetDataWrite(LD_SPOT_NAME, gSpotName);
    llLinksetDataWrite(LD_WATER_TYPE, gWaterType);
    llLinksetDataWrite(LD_IS_ACTIVE, (string)gIsActive);
    llLinksetDataWrite(LD_IS_PUBLIC, (string)gIsPublic);
    llLinksetDataWrite(LD_IS_SYSTEM, (string)gIsSystem);
    llLinksetDataWrite(LD_SETUP_DONE, "1");
    llLinksetDataWrite(LD_OWNER_UUID, (string)llGetOwner());
}

// ── Load spot config from LinksetData ──
// Returns TRUE if valid saved data exists for this owner
integer loadSpotData() {
    string savedOwner = llLinksetDataRead(LD_OWNER_UUID);
    if (savedOwner == "" || savedOwner != (string)llGetOwner()) {
        clearSpotData();
        return FALSE;
    }

    string sid = llLinksetDataRead(LD_SPOT_ID);
    if (sid == "" || (integer)sid <= 0) return FALSE;

    string done = llLinksetDataRead(LD_SETUP_DONE);
    if (done != "1") return FALSE;

    gSpotId    = (integer)sid;
    gSpotName  = llLinksetDataRead(LD_SPOT_NAME);
    gWaterType = llLinksetDataRead(LD_WATER_TYPE);
    gIsActive  = (integer)llLinksetDataRead(LD_IS_ACTIVE);
    gIsPublic  = (integer)llLinksetDataRead(LD_IS_PUBLIC);
    gIsSystem  = (integer)llLinksetDataRead(LD_IS_SYSTEM);

    if (gSpotName == "") return FALSE;

    return TRUE;
}

// ── Clear all saved data ──
clearSpotData() {
    llLinksetDataDelete(LD_SPOT_ID);
    llLinksetDataDelete(LD_SPOT_NAME);
    llLinksetDataDelete(LD_WATER_TYPE);
    llLinksetDataDelete(LD_IS_ACTIVE);
    llLinksetDataDelete(LD_IS_PUBLIC);
    llLinksetDataDelete(LD_IS_SYSTEM);
    llLinksetDataDelete(LD_SETUP_DONE);
    llLinksetDataDelete(LD_OWNER_UUID);
}

default {
    state_entry() {
        // Listen for junk delivery requests from HUDs
        llListen(CH_SPOT_TO_HUD, "", NULL_KEY, "");
        // Listen on channel 0 for owner emergency commands
        llListen(0, "", llGetOwner(), "");

        // Request callback URL for server pushes
        requestCallbackUrl();

        // Try to restore from LinksetData
        if (loadSpotData()) {
            gSetupDone = TRUE;
            gRegistered = TRUE;
            setTextLabel();

            // Re-register with server to sync and confirm still valid
            registerWithServer(gIsActive);

            // Force a heartbeat on first timer tick (set last to 0)
            gLastHeartbeat = 0;

            if (gIsActive) {
                llSensorRepeat("", NULL_KEY, AGENT, gRange, PI, gScanRate);
                llSetTimerEvent(gNormalTimer);  // 60s — checks region empty, runs buff expiration
            } else {
                // Even inactive spots should heartbeat occasionally so the server
                // knows they're still alive (just turned off).
                llSetTimerEvent(gIdleTimer);
            }
            return;
        }

        // No saved data — fresh setup needed
        gSetupDone = FALSE;
        gRegistered = FALSE;
        gIsActive = FALSE;
        setTextLabel();
    }

    touch_start(integer n) {
        gTouchStartTime = llGetUnixTime();
        gTouchKey = llDetectedKey(0);
    }

    touch_end(integer n) {
        key who = gTouchKey;
        gTouchKey = NULL_KEY;
        if (who == NULL_KEY) return;

        integer holdTime = llGetUnixTime() - gTouchStartTime;

        // ── Not set up yet: check for archived spots first, then start wizard ──
        if (!gSetupDone) {
            if (who != llGetOwner()) {
                llRegionSayTo(who, 0, "This fishing spot hasn't been set up yet.");
                return;
            }
            gSetupPlayer = who;
            gHttpAction = "archived_check";
            gHttpReq = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST",
                HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                HTTP_BODY_MAXLENGTH, 4096
            ], "action=spot_archived_list&uuid=" + llEscapeURL((string)who) +
               "&region=" + llEscapeURL(llGetRegionName()) +
               "&grid_name=" + llEscapeURL(osGetGridName()));
            return;
        }

        // ── Short tap: refresh text + ping HUD ──
        if (holdTime < 3) {
            setTextLabel();
            if (gRegistered && gIsActive) {
                notifyPlayer(who);
            }
            return;
        }

        // ── Long press (3+ seconds): open menu ──
        if (who == llGetOwner()) {
            showManageMenu(who);
        } else {
            // Non-owner long press: show buff/junk info + use buff
            gSetupPlayer = who;
            if (gRegistered && gIsActive) notifyPlayer(who);
            gHttpAction = "buff_status";
            gHttpReq = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                HTTP_BODY_MAXLENGTH, 4096
            ], "action=buff_spot_status&spot_id=" + (string)gSpotId);
        }
    }

    listen(integer ch, string name, key id, string msg) {
        // ── Owner emergency commands on channel 0 ──
        if (ch == 0 && id == llGetOwner()) {
            if (msg == "/spot reset" || msg == "/spot wipe") {
                clearSpotData();
                gSpotId = 0;
                gSpotName = "";
                gWaterType = "";
                gIsActive = FALSE;
                gSetupDone = FALSE;
                gRegistered = FALSE;
                gActiveBuffs = [];
                gHasActiveBuffs = FALSE;
                setTextLabel();
                llOwnerSay("⚠️ Spot data wiped. Touch the prim to begin setup.");
                return;
            }
            return;
        }

        // ── Junk delivery from HUD ──
        if (ch == CH_SPOT_TO_HUD) {
            list parts = llParseString2List(msg, ["|"], []);
            if (llList2String(parts, 0) == "DELIVER_JUNK") {
                integer reqSpotId = (integer)llList2String(parts, 1);
                if (reqSpotId != gSpotId) return;  // Not for us
                string itemName = llList2String(parts, 2);
                key recipient = (key)llList2String(parts, 3);
                // Check if item exists in inventory
                if (llGetInventoryType(itemName) == INVENTORY_OBJECT) {
                    llGiveInventory(recipient, itemName);
                }
            }
            // Also handle LEADERBOARD_PING if leaderboards are listening
            return;
        }

        // ── Setup wizard and management dialogs ──
        // ── Archive recovery ──
        if (gSetupStep == "archive_recovery") {
            cleanupDialog();
            if (msg == "Cancel") { gSetupStep = ""; return; }
            if (msg == "Fresh Setup") {
                // Proceed to normal setup
                gHttpAction = "setup_info";
                gHttpReq = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST",
                    HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                    HTTP_BODY_MAXLENGTH, 4096
                ], "action=spot_setup_info&uuid=" + llEscapeURL((string)gSetupPlayer) +
                   "&grid_name=" + llEscapeURL(osGetGridName()));
                return;
            }
            // Match label to an archived spot ID
            integer idx = llListFindList(gArchivedSpotLabels, [msg]);
            if (idx == -1) { gSetupStep = ""; return; }
            string sid = llList2String(gArchivedSpotIds, idx);
            // Send restore request
            vector pos = llGetPos();
            gHttpAction = "restore";
            gHttpReq = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST",
                HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                HTTP_BODY_MAXLENGTH, 4096
            ], "action=spot_restore&uuid=" + llEscapeURL((string)gSetupPlayer) +
               "&spot_id=" + sid +
               "&prim_uuid=" + (string)llGetKey() +
               "&pos_x=" + (string)pos.x +
               "&pos_y=" + (string)pos.y +
               "&pos_z=" + (string)pos.z +
               "&region=" + llEscapeURL(llGetRegionName()) +
               "&grid_name=" + llEscapeURL(osGetGridName()));
            gSetupStep = "";
            return;
        }

        // ── Setup wizard ──
        if (gSetupStep == "water") {
            cleanupDialog();
            if (msg == "Cancel") { gSetupStep = ""; return; }
            gWaterType = llToLower(msg);
            showNamePrompt();
            return;
        }

        if (gSetupStep == "name") {
            cleanupDialog();
            gSpotName = msg;
            if (gPlayerIsAdmin) {
                showSystemMenu();
            } else {
                gIsSystem = FALSE;
                showPublicMenu();
            }
            return;
        }

        if (gSetupStep == "system") {
            cleanupDialog();
            if (msg == "Cancel") { gSetupStep = ""; return; }
            gIsSystem = (msg == "Yes");
            showPublicMenu();
            return;
        }

        if (gSetupStep == "public") {
            cleanupDialog();
            if (msg == "Cancel") { gSetupStep = ""; return; }
            gIsPublic = (msg == "Public");
            showActivateMenu();
            return;
        }

        if (gSetupStep == "activate") {
            cleanupDialog();
            if (msg == "Cancel") { gSetupStep = ""; return; }
            integer act = (msg == "Activate");
            gIsActive = act;
            registerWithServer(act);
            return;
        }

        // ── Management menu ──
        if (gSetupStep == "manage") {
            cleanupDialog();
            if (msg == "Close") { gSetupStep = ""; return; }
            if (msg == "Use Buff") {
                gHttpAction = "buff_status";
                gHttpReq = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                    HTTP_BODY_MAXLENGTH, 4096
                ], "action=buff_spot_status&spot_id=" + (string)gSpotId);
                return;
            }
            if (msg == "Add Junk") {
                showJunkPrompt();
                return;
            }
            if (msg == "Edit") {
                showEditMenu(gSetupPlayer);
                return;
            }
            return;
        }

        // ── Edit submenu ──
        if (gSetupStep == "edit") {
            cleanupDialog();
            if (msg == "Back") { showManageMenu(gSetupPlayer); return; }

            if (msg == "Activate") {
                gHttpAction = "update";
                gHttpReq = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                    HTTP_BODY_MAXLENGTH, 4096
                ], "action=spot_update&uuid=" + llEscapeURL((string)llGetOwner()) +
                   "&spot_id=" + (string)gSpotId + "&is_active=1");
                gIsActive = TRUE;
                return;
            }
            if (msg == "Deactivate") {
                gHttpAction = "update";
                gHttpReq = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                    HTTP_BODY_MAXLENGTH, 4096
                ], "action=spot_update&uuid=" + llEscapeURL((string)llGetOwner()) +
                   "&spot_id=" + (string)gSpotId + "&is_active=0");
                gIsActive = FALSE;
                return;
            }
            if (msg == "Rename") {
                gSetupStep = "rename";
                gTextCh = -1 - (integer)llFrand(999999.0);
                gTextHandle = llListen(gTextCh, "", gSetupPlayer, "");
                llTextBox(gSetupPlayer, "Enter new name for this spot:", gTextCh);
                return;
            }
            if (msg == "Make Public") {
                gIsPublic = TRUE;
                gHttpAction = "update";
                gHttpReq = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                    HTTP_BODY_MAXLENGTH, 4096
                ], "action=spot_update&uuid=" + llEscapeURL((string)llGetOwner()) +
                   "&spot_id=" + (string)gSpotId + "&is_public=1");
                return;
            }
            if (msg == "Make Private") {
                gIsPublic = FALSE;
                gHttpAction = "update";
                gHttpReq = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                    HTTP_BODY_MAXLENGTH, 4096
                ], "action=spot_update&uuid=" + llEscapeURL((string)llGetOwner()) +
                   "&spot_id=" + (string)gSpotId + "&is_public=0");
                return;
            }
            if (msg == "List Junk") {
                gHttpAction = "list_junk";
                gHttpReq = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                    HTTP_BODY_MAXLENGTH, 4096
                ], "action=spot_list_junk&spot_id=" + (string)gSpotId);
                return;
            }
            if (msg == "Delete") {
                gSetupStep = "confirm_delete";
                gDialogCh = -1 - (integer)llFrand(999999.0);
                gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");
                llDialog(gSetupPlayer, "⚠️ Delete this fishing spot?\nThis cannot be undone!",
                         ["Yes Delete", "Cancel"], gDialogCh);
                return;
            }
            return;
        }

        // ── Rename ──
        if (gSetupStep == "rename") {
            cleanupDialog();
            gSpotName = msg;
            gHttpAction = "update";
            gHttpReq = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                HTTP_BODY_MAXLENGTH, 4096
            ], "action=spot_update&uuid=" + llEscapeURL((string)llGetOwner()) +
               "&spot_id=" + (string)gSpotId + "&name=" + llEscapeURL(gSpotName));
            return;
        }

        // ── Add junk: step 1 — name entered ──
        if (gSetupStep == "add_junk") {
            cleanupDialog();
            string itemName = msg;
            if (llGetInventoryType(itemName) != INVENTORY_OBJECT) {
                llOwnerSay("Object '" + itemName + "' not found in this prim's inventory.");
                showManageMenu(gSetupPlayer);
                return;
            }
            gPendingJunkName = itemName;
            gSetupStep = "add_junk_rarity";
            gDialogCh = -1 - (integer)llFrand(999999.0);
            gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");
            llDialog(gSetupPlayer,
                "🎁 How rare is '" + itemName + "'?\n\n" +
                "Common — fished up often\n" +
                "Uncommon — less frequent\n" +
                "Rare — hard to get\n" +
                "Legendary — extremely rare find",
                ["Common", "Uncommon", "Rare", "Legendary", "Cancel"], gDialogCh);
            return;
        }

        // ── Add junk: step 2 — rarity selected ──
        if (gSetupStep == "add_junk_rarity") {
            cleanupDialog();
            if (msg == "Cancel") { gPendingJunkName = ""; showManageMenu(gSetupPlayer); return; }

            string rarityWeight = "5.0";  // Common: high weight = more likely
            string rarityLabel = "common";
            if (msg == "Uncommon") { rarityWeight = "2.0"; rarityLabel = "uncommon"; }
            else if (msg == "Rare") { rarityWeight = "0.5"; rarityLabel = "rare"; }
            else if (msg == "Legendary") { rarityWeight = "0.1"; rarityLabel = "legendary"; }

            gHttpAction = "add_junk";
            gHttpReq = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                HTTP_BODY_MAXLENGTH, 4096
            ], "action=spot_add_junk&uuid=" + llEscapeURL((string)llGetOwner()) +
               "&spot_id=" + (string)gSpotId +
               "&item_name=" + llEscapeURL(gPendingJunkName) +
               "&rarity_weight=" + rarityWeight +
               "&rarity_label=" + llEscapeURL(rarityLabel));
            gPendingJunkName = "";
            return;
        }

        // ── Confirm delete ──
        if (gSetupStep == "confirm_delete") {
            cleanupDialog();
            if (msg == "Yes Delete") {
                gHttpAction = "delete";
                gHttpReq = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                    HTTP_BODY_MAXLENGTH, 4096
                ], "action=spot_delete&uuid=" + llEscapeURL((string)llGetOwner()) +
                   "&spot_id=" + (string)gSpotId);
            } else {
                showManageMenu(gSetupPlayer);
            }
            return;
        }

        // ── Buff menu (non-owner) ──
        if (gSetupStep == "buff_menu") {
            cleanupDialog();
            if (msg == "Use Buff") {
                // Fetch player's buff inventory
                gHttpAction = "buff_inv";
                gHttpReq = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                    HTTP_BODY_MAXLENGTH, 4096
                ], "action=buff_inventory&uuid=" + llEscapeURL((string)gSetupPlayer));
            }
            return;
        }

        // ── Buff pick ──
        if (gSetupStep == "buff_pick") {
            cleanupDialog();
            if (msg == "Back") return;

            // Match label to ID
            integer count = llGetListLength(gBuffItemLabels);
            integer i;
            for (i = 0; i < count; i++) {
                if (llList2String(gBuffItemLabels, i) == msg) {
                    string buffId = llList2String(gBuffItemIds, i);
                    gHttpAction = "buff_activate";
                    gHttpReq = llHTTPRequest(gApiUrl, [
                        HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                        HTTP_BODY_MAXLENGTH, 4096
                    ], "action=buff_activate&uuid=" + llEscapeURL((string)gSetupPlayer) +
                       "&spot_id=" + (string)gSpotId + "&buff_item_id=" + buffId);
                    return;
                }
            }
            return;
        }
    }

    http_response(key req, integer status, list meta, string body) {
        if (req != gHttpReq) return;

        if (status != 200) {
            string preview = body;
            if (llStringLength(preview) > 200) preview = llGetSubString(preview, 0, 199);
            llOwnerSay("Spot API error (HTTP " + (string)status + ") on action [" + gHttpAction + "]: " + preview);
            // 410 = spot was deleted/archived; clear local state so the prim prompts for fresh setup
            if (gHttpAction == "register" && (status == 410 || status == 404)) {
                clearSpotData();
                gSetupDone = FALSE;
                gRegistered = FALSE;
                gSpotId = 0;
                gIsActive = FALSE;
                llSensorRemove();
                setTextLabel();
            }
            return;
        }

        string ok = llJsonGetValue(body, ["success"]);
        if (ok != "true" && ok != "1" && ok != JSON_TRUE) {
            string err = llJsonGetValue(body, ["error"]);
            if (err == JSON_INVALID) err = "Unknown error";
            llOwnerSay("Spot error: " + err);
            return;
        }

        if (gHttpAction == "archived_check") {
            // Check if there are archived spots we could restore
            string archJson = llJsonGetValue(body, ["archived"]);
            integer hasArchived = (archJson != JSON_INVALID && archJson != "[]" && archJson != "");

            if (hasArchived) {
                // Build list of archived spots for menu
                gArchivedSpotIds = [];
                gArchivedSpotLabels = [];
                integer i = 0;
                while (i < 10) {
                    string entry = llJsonGetValue(archJson, [i]);
                    if (entry == JSON_INVALID) jump archive_done;
                    string sid = llJsonGetValue(entry, ["id"]);
                    string sname = llJsonGetValue(entry, ["name"]);
                    string wt = llJsonGetValue(entry, ["water_type"]);
                    string catches = llJsonGetValue(entry, ["catch_count"]);
                    if (catches == JSON_INVALID) catches = "0";
                    gArchivedSpotIds += [sid];
                    string label = sname;
                    if (llStringLength(label) > 14) label = llGetSubString(label, 0, 13);
                    label += " (" + catches + ")";
                    gArchivedSpotLabels += [label];
                    i++;
                }
                @archive_done;

                if (llGetListLength(gArchivedSpotIds) > 0) {
                    showArchiveRecoveryMenu(gSetupPlayer);
                    return;
                }
            }

            // No archived spots — proceed to fresh setup
            gHttpAction = "setup_info";
            gHttpReq = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST",
                HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                HTTP_BODY_MAXLENGTH, 4096
            ], "action=spot_setup_info&uuid=" + llEscapeURL((string)gSetupPlayer) +
               "&grid_name=" + llEscapeURL(osGetGridName()));
            return;
        }

        if (gHttpAction == "restore") {
            gSpotId = (integer)llJsonGetValue(body, ["spot_id"]);
            string nm = llJsonGetValue(body, ["name"]);
            // Re-fetch full spot data and resume normal operation
            gHttpAction = "status";
            gHttpReq = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                HTTP_BODY_MAXLENGTH, 4096
            ], "action=spot_status&spot_id=" + (string)gSpotId);
            llOwnerSay("✅ Restored '" + nm + "'! Catch history preserved.");
            return;
        }

        if (gHttpAction == "setup_info") {
            // Parse player info for wizard
            gPlayerIsAdmin = (llJsonGetValue(body, ["is_admin"]) == "1");
            gSpotCount = (integer)llJsonGetValue(body, ["spot_count"]);
            gSpotLimit = (integer)llJsonGetValue(body, ["spot_limit"]);

            gAvailableWater = [];
            string wtJson = llJsonGetValue(body, ["water_types"]);
            integer i = 0;
            while (i < 10) {
                string wt = llJsonGetValue(wtJson, [i]);
                if (wt == JSON_INVALID) jump wt_done;
                gAvailableWater += [wt];
                i++;
            }
            @wt_done;

            if (llGetListLength(gAvailableWater) == 0) {
                llOwnerSay("No water types available at your level.");
                return;
            }

            if (gSpotCount >= gSpotLimit && !gPlayerIsAdmin) {
                llOwnerSay("Spot limit reached (" + (string)gSpotCount + "/" + (string)gSpotLimit + "). Level up for more.");
                return;
            }

            showWaterMenu();
        }
        else if (gHttpAction == "register") {
            gSpotId = (integer)llJsonGetValue(body, ["spot_id"]);
            gRegistered = TRUE;
            gSetupDone = TRUE;
            saveSpotData();
            setTextLabel();
            llOwnerSay("✅ " + gSpotName + " registered! (ID " + (string)gSpotId + ")");

            // Register our callback URL so the server can push us updates
            if (gCallbackUrl != "") registerCallbackWithServer();

            if (gIsActive) {
                llSensorRepeat("", NULL_KEY, AGENT, gRange, PI, gScanRate);
                llSetTimerEvent(gNormalTimer);
            }
        }
        else if (gHttpAction == "update") {
            saveSpotData();
            setTextLabel();
            llOwnerSay("✅ Spot updated.");
            if (gIsActive && !gRegistered) {
                // Edge case
            } else if (gIsActive) {
                llSensorRepeat("", NULL_KEY, AGENT, gRange, PI, gScanRate);
            } else {
                llSensorRemove();
                // Send UNSPOT to everyone in range
                integer i;
                for (i = 0; i < llGetListLength(gInRange); i++) {
                    llRegionSayTo(llList2Key(gInRange, i), CH_SPOT_TO_HUD, "UNSPOT|" + (string)gSpotId);
                }
                gInRange = [];
            }
            showManageMenu(gSetupPlayer);
        }
        else if (gHttpAction == "delete") {
            llOwnerSay("🗑️ Spot deleted.");
            clearSpotData();
            gSetupDone = FALSE;
            gRegistered = FALSE;
            gSpotId = 0;
            gIsActive = FALSE;
            llSensorRemove();
            setTextLabel();
        }
        else if (gHttpAction == "add_junk") {
            llOwnerSay("✅ Junk item added.");
            showManageMenu(gSetupPlayer);
        }
        else if (gHttpAction == "list_junk") {
            string junkJson = llJsonGetValue(body, ["junk_items"]);
            if (junkJson == JSON_INVALID || junkJson == "[]") {
                llOwnerSay("No junk items. Drop objects into this prim then use Add Junk.");
            } else {
                integer i = 0;
                llOwnerSay("=== Junk Loot Table ===");
                while (i < 20) {
                    string entry = llJsonGetValue(junkJson, [i]);
                    if (entry == JSON_INVALID) jump junk_done;
                    string jName = llJsonGetValue(entry, ["item_name"]);
                    string jRarity = llJsonGetValue(entry, ["rarity_label"]);
                    if (jRarity == JSON_INVALID) jRarity = "common";
                    llOwnerSay("  - " + jName + ", " + jRarity);
                    i++;
                }
                @junk_done;
            }
            showManageMenu(gSetupPlayer);
        }
        else if (gHttpAction == "status") {
            string active = llJsonGetValue(body, ["is_active"]);
            gIsActive = (active == "1" || active == "true" || active == JSON_TRUE);
            setTextLabel();
        }
        else if (gHttpAction == "buff_status") {
            string isBuff = llJsonGetValue(body, ["is_buffed"]);
            gHasActiveBuffs = (isBuff == "true" || isBuff == "1" || isBuff == JSON_TRUE);
            setTextLabel();

            string buffsJson = llJsonGetValue(body, ["buffs"]);
            string info = "🎣 " + gSpotName + " — " + gWaterType + "\n\n";

            if (gHasActiveBuffs) {
                info += "Active Buffs:\n";
                integer i = 0;
                while (i < 9) {
                    string entry = llJsonGetValue(buffsJson, [i]);
                    if (entry == JSON_INVALID) jump buff_done;
                    string bName = llJsonGetValue(entry, ["buff_name"]);
                    string bMins = llJsonGetValue(entry, ["mins_remaining"]);
                    info += "  " + bName + " — " + bMins + "min / 120max\n";
                    i++;
                }
                @buff_done;
            } else {
                info += "No active buffs.\n";
            }

            // Check for junk items — fetch list if any exist
            integer junkCount = llGetInventoryNumber(INVENTORY_OBJECT);
            if (junkCount > 0) {
                gPendingJunkName = info;  // Stash buff info
                gHttpAction = "show_junk_dialog";
                gHttpReq = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                    HTTP_BODY_MAXLENGTH, 4096
                ], "action=spot_list_junk&spot_id=" + (string)gSpotId);
                return;
            }

            info += "\nUse a buff item on this spot?";
            gSetupStep = "buff_menu";
            cleanupDialog();
            gDialogCh = -1 - (integer)llFrand(999999.0);
            gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");
            llDialog(gSetupPlayer, info, ["Use Buff", "Close"], gDialogCh);
        }
        else if (gHttpAction == "show_junk_dialog") {
            string info = gPendingJunkName;
            gPendingJunkName = "";

            string junkJson = llJsonGetValue(body, ["junk_items"]);
            if (junkJson != JSON_INVALID && junkJson != "[]") {
                info += "\n🎁 Junk Loot:\n";
                integer i = 0;
                while (i < 10) {
                    string entry = llJsonGetValue(junkJson, [i]);
                    if (entry == JSON_INVALID) jump junk_dialog_done;
                    string jName = llJsonGetValue(entry, ["item_name"]);
                    string jRarity = llJsonGetValue(entry, ["rarity_label"]);
                    if (jRarity == JSON_INVALID) jRarity = "common";
                    info += "  - " + jName + ", " + jRarity + "\n";
                    i++;
                }
                @junk_dialog_done;
            }

            info += "\nUse a buff item on this spot?";
            gSetupStep = "buff_menu";
            cleanupDialog();
            gDialogCh = -1 - (integer)llFrand(999999.0);
            gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");
            llDialog(gSetupPlayer, info, ["Use Buff", "Close"], gDialogCh);
        }
        else if (gHttpAction == "buff_inv") {
            // Show player's buff items to pick from
            string invJson = llJsonGetValue(body, ["buff_inventory"]);
            if (invJson == JSON_INVALID || invJson == "[]") {
                llRegionSayTo(gSetupPlayer, 0, "You don't have any buff items.");
                return;
            }

            gBuffItemIds = [];
            gBuffItemLabels = [];
            integer i = 0;
            while (i < 9) {
                string entry = llJsonGetValue(invJson, [i]);
                if (entry == JSON_INVALID) jump inv_done;
                string bId = llJsonGetValue(entry, ["buff_item_id"]);
                string bName = llJsonGetValue(entry, ["name"]);
                string bQty = llJsonGetValue(entry, ["quantity"]);
                gBuffItemIds += [bId];
                string label = bName;
                if (llStringLength(label) > 18) label = llGetSubString(label, 0, 17);
                label += " x" + bQty;
                gBuffItemLabels += [label];
                i++;
            }
            @inv_done;

            gSetupStep = "buff_pick";
            cleanupDialog();
            gDialogCh = -1 - (integer)llFrand(999999.0);
            gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");

            list buttons = gBuffItemLabels + ["Back"];
            llDialog(gSetupPlayer, "Select a buff item to use:", buttons, gDialogCh);
        }
        else if (gHttpAction == "buff_activate") {
            string msg = llJsonGetValue(body, ["message"]);
            if (msg == JSON_INVALID) msg = "Buff activated!";
            llRegionSayTo(gSetupPlayer, 0, "✅ " + msg);
            gHasActiveBuffs = TRUE;
            setTextLabel();
        }
        else if (gHttpAction == "buff_status_silent") {
            string isBuff = llJsonGetValue(body, ["is_buffed"]);
            gHasActiveBuffs = (isBuff == "true" || isBuff == "1" || isBuff == JSON_TRUE);
            setTextLabel();
        }
    }

    sensor(integer num) {
        if (!gRegistered || !gIsActive) return;
        list currentlyInRange = [];
        integer i;
        for (i = 0; i < num; i++) currentlyInRange += [llDetectedKey(i)];

        // Notify new arrivals
        for (i = 0; i < num; i++) {
            key av = llDetectedKey(i);
            if (llListFindList(gInRange, [av]) == -1) notifyPlayer(av);
        }

        // Notify departures
        integer oldCount = llGetListLength(gInRange);
        for (i = 0; i < oldCount; i++) {
            key av = llList2Key(gInRange, i);
            if (llListFindList(currentlyInRange, [av]) == -1) {
                llRegionSayTo(av, CH_SPOT_TO_HUD, "UNSPOT|" + (string)gSpotId);
            }
        }

        gInRange = currentlyInRange;
    }

    no_sensor() {
        integer i;
        integer count = llGetListLength(gInRange);
        for (i = 0; i < count; i++) {
            llRegionSayTo(llList2Key(gInRange, i), CH_SPOT_TO_HUD, "UNSPOT|" + (string)gSpotId);
        }
        gInRange = [];
    }

    timer() {
        if (!gRegistered) return;

        integer now = llGetUnixTime();

        // Check region for players — go idle if empty
        integer hadPlayers = gPlayersPresent;
        gPlayersPresent = regionHasPlayers();

        if (gPlayersPresent && !hadPlayers) {
            // Just woke up — heartbeat to server
            sendHeartbeat();
            llSetTimerEvent(gNormalTimer);
        } else if (!gPlayersPresent && hadPlayers) {
            llSetTimerEvent(gIdleTimer);
        }

        // Periodic 30-min heartbeat regardless of player activity.
        // Tells the server "I'm still here" so it knows the prim is alive.
        if (now - gLastHeartbeat >= HEARTBEAT_INTERVAL) {
            sendHeartbeat();
        }

        // Local buff expiration check (no HTTP)
        if (gHasActiveBuffs) checkBuffExpirations();
    }

    // ── Server push handler ──
    http_request(key id, string method, string body) {
        if (method == URL_REQUEST_GRANTED) {
            gCallbackUrl = body;
            gCallbackRegistered = FALSE;
            // Register with server now that we have a URL
            if (gSpotId > 0) registerCallbackWithServer();
            return;
        }

        if (method == URL_REQUEST_DENIED) {
            llOwnerSay("⚠️ Could not get URL — server pushes disabled. Falling back to polling.");
            return;
        }

        if (method == "POST") {
            string pushType = llJsonGetValue(body, ["type"]);

            if (pushType == "buff_active") {
                string buffType   = llJsonGetValue(body, ["buff_type"]);
                string label      = llJsonGetValue(body, ["buff_label"]);
                string effect     = llJsonGetValue(body, ["effect_text"]);
                string activator  = llJsonGetValue(body, ["activator_display_name"]);
                string spotName   = llJsonGetValue(body, ["spot_name"]);
                integer expiresAt = (integer)llJsonGetValue(body, ["expires_at"]);
                integer durMins   = (integer)llJsonGetValue(body, ["duration_minutes"]);
                integer totalMins = (integer)llJsonGetValue(body, ["stacked_total_minutes"]);

                addOrUpdateBuff(buffType, label, effect, expiresAt);
                setTextLabel();

                // Local broadcast
                string durText;
                if (totalMins > durMins) {
                    durText = (string)durMins + " minutes added, of a maximum of 120 minutes (" + (string)totalMins + " active)";
                } else {
                    durText = (string)durMins + " minutes";
                }
                llSay(0, activator + " uses " + label + " on " + spotName +
                      " (" + effect + "). Will last " + durText + ".");

                llHTTPResponse(id, 200, "OK");
                return;
            }

            if (pushType == "buff_expired") {
                string buffType = llJsonGetValue(body, ["buff_type"]);
                string label    = llJsonGetValue(body, ["buff_label"]);
                string spotName = llJsonGetValue(body, ["spot_name"]);
                removeBuff(buffType);
                setTextLabel();
                llSay(0, "💨 " + label + " has worn off on " + spotName + ".");
                llHTTPResponse(id, 200, "OK");
                return;
            }

            if (pushType == "spot_deactivated") {
                gIsActive = FALSE;
                setTextLabel();
                llHTTPResponse(id, 200, "OK");
                return;
            }

            llHTTPResponse(id, 200, "OK");
        }
    }

    on_rez(integer p) {
        // Check if owner changed — if so, clear everything
        string savedOwner = llLinksetDataRead(LD_OWNER_UUID);
        if (savedOwner != "" && savedOwner != (string)llGetOwner()) {
            clearSpotData();
        }
        llResetScript();
    }

    changed(integer change) {
        if (change & CHANGED_OWNER) {
            clearSpotData();
            llResetScript();
            return;
        }
        if (change & CHANGED_REGION_RESTART) {
            // Region just came back up. Our script kept running through it,
            // but the server may have marked us inactive if heartbeats stopped.
            // Re-acquire callback URL (always lost on restart) and re-register.
            requestCallbackUrl();
            if (gRegistered) {
                registerWithServer(gIsActive);
                sendHeartbeat();
            }
        }
    }
}
