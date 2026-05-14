// ============================================================
// FishingSpot — Fishing spot prim: setup, management, buffs
// ============================================================
// Persistence: LinksetData keys "spot_id" and "spot_owner"
// Buff delivery: server push via llRequestURL (primary)
//               + poll fallback every BUFF_POLL_INTERVAL seconds
// ============================================================

string  gApiUrl = "https://sp.wa.darkheartsos.net/fishing/api/";

// ── Canonical spot state ──
integer gSpotId        = 0;
string  gSpotName      = "";
string  gWaterType     = "";
integer gIsActive      = FALSE;
integer gIsPublic      = TRUE;
integer gIsSystem      = FALSE;
integer gRegistered    = FALSE;
integer gSetupDone     = FALSE;
integer gSpotLevel     = 1;
integer gSpotLevelReady = FALSE;

// ── HTTP ──
key     gHttpReq    = NULL_KEY;
string  gHttpAction = "";

// ── Server push (llRequestURL) ──
string  gCallbackUrl = "";

// ── Active buff tracking ──
list    gActiveBuffNames   = [];
integer gHasActiveBuffs    = FALSE;
integer gLastBuffPoll      = 0;
integer BUFF_POLL_INTERVAL = 300;

// ── Region-empty idle optimization ──
integer gPlayersPresent = TRUE;
float   gNormalTimer    = 60.0;
float   gIdleTimer      = 600.0;

// ── Heartbeat ──
integer gLastHeartbeat     = 0;
integer HEARTBEAT_INTERVAL = 1800;

// ── Sensor ──
float gScanRate = 10.0;
list  gInRange  = [];

// ── Channel ──
integer CH_SPOT_TO_HUD = -7710005;

// ── Touch hold detection ──
integer gTouchStartTime = 0;
key     gTouchKey       = NULL_KEY;

// ── Text cache ──
string  gLastText      = "";
vector  gLastTextColor = <0,0,0>;
float   gLastTextAlpha = 0.0;

// ── Dialog / wizard state ──
string  gSetupStep    = "";
key     gSetupPlayer  = NULL_KEY;
integer gDialogCh     = 0;
integer gDialogHandle = 0;
integer gTextCh       = 0;
integer gTextHandle   = 0;

// ── Player/spot info from server ──
integer gPlayerIsAdmin      = FALSE;
list    gAvailableWater     = [];
integer gSpotCount          = 0;
integer gSpotLimit          = 0;
list    gArchivedSpotIds    = [];
list    gArchivedSpotLabels = [];
list    gRecoverableSpotTypes = [];

// ── Manage state ──
string  gPendingJunkName = "";

// ── Buff state ──
list    gBuffItemIds     = [];
list    gBuffItemLabels  = [];
string  gPendingBuffInfo = "";

// ============================================================
// Helpers
// ============================================================

cleanupDialog() {
    if (gDialogHandle) { llListenRemove(gDialogHandle); gDialogHandle = 0; }
    if (gTextHandle)   { llListenRemove(gTextHandle);   gTextHandle   = 0; }
}

float computeRange() {
    vector sz = llGetScale();
    float r = sz.x;
    if (sz.y > r) r = sz.y;
    if (sz.z > r) r = sz.z;
    return r + 20.0;
}

setTextSafe(string text, vector color, float alpha) {
    if (text == gLastText && color == gLastTextColor && alpha == gLastTextAlpha) return;
    gLastText      = text;
    gLastTextColor = color;
    gLastTextAlpha = alpha;
    llSetText(text, color, alpha);
}

setTextLabel() {
    if (!gSetupDone) {
        setTextSafe("Fishing Spot\n(Touch to set up)", <1.0, 0.8, 0.3>, 1.0);
    } else if (!gIsActive) {
        string levelTag = " (Lvl " + (string)gSpotLevel + " - Inactive)";
        setTextSafe(gSpotName + "\n" + gWaterType + levelTag, <0.5, 0.5, 0.5>, 0.8);
    } else {
        string levelTag = " (Lvl " + (string)gSpotLevel + ")";
        if (gSpotLevelReady) levelTag = " (Lvl " + (string)gSpotLevel + " - LEVEL UP!)";
        integer junkCount = llGetInventoryNumber(INVENTORY_OBJECT);
        string extra = "";
        if (junkCount > 0)   extra += "[Junk]";
        if (gHasActiveBuffs) extra += " [Buffs]";
        vector col = gSpotLevelReady ? <1.0, 0.9, 0.1> : <0.3, 0.9, 0.5>;
        setTextSafe(gSpotName + "\n" + gWaterType + levelTag + "\n" + extra, col, 1.0);
    }
}

sendHeartbeat() {
    if (gSpotId <= 0) return;
    gLastHeartbeat = llGetUnixTime();
    gHttpAction    = "heartbeat";
    gHttpReq       = llHTTPRequest(gApiUrl, [
        HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
    ], "action=prim_heartbeat&prim_uuid=" + (string)llGetKey());
}

pollBuffStatus() {
    if (gSpotId <= 0 || !gIsActive) return;
    gLastBuffPoll = llGetUnixTime();
    gHttpAction   = "buff_poll";
    gHttpReq      = llHTTPRequest(gApiUrl, [
        HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
    ], "action=buff_spot_status&spot_id=" + (string)gSpotId);
}

registerCallback() {
    if (gCallbackUrl == "" || gSpotId <= 0) return;
    llHTTPRequest(gApiUrl, [
        HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
    ], "action=register_spot_callback" +
       "&spot_id="      + (string)gSpotId +
       "&prim_uuid="    + llEscapeURL((string)llGetKey()) +
       "&callback_url=" + llEscapeURL(gCallbackUrl) +
       "&region="       + llEscapeURL(llGetRegionName()) +
       "&grid_name="    + llEscapeURL(osGetGridName()));
}

integer regionHasPlayers() {
    return (llGetListLength(llGetAgentList(AGENT_LIST_REGION, [])) > 0);
}

notifyPlayer(key av) {
    if (!gRegistered || !gIsActive) return;
    vector pos    = llGetPos();
    vector primSz = llGetScale();
    float  radius = primSz.x;
    if (primSz.y > radius) radius = primSz.y;
    if (radius < 5.0)      radius = 5.0;
    llRegionSayTo(av, CH_SPOT_TO_HUD,
        "SPOT|" + (string)gSpotId + "|" + gSpotName + "|" +
        (string)pos.x + "|" + (string)pos.y + "|" + (string)pos.z +
        "|" + gWaterType + "|" + (string)radius + "|" + (string)gSpotLevel);
}

saveSpotData() {
    llLinksetDataWrite("spot_id",    (string)gSpotId);
    llLinksetDataWrite("spot_owner", (string)llGetOwner());
}

clearSpotData() {
    llLinksetDataDelete("spot_id");
    llLinksetDataDelete("spot_owner");
}

resetToUnsetup() {
    if (gCallbackUrl != "") {
        string url = gCallbackUrl;
        gCallbackUrl = "";
        llReleaseURL(url);
    }
    gSpotId           = 0;
    gSpotName         = "";
    gWaterType        = "";
    gIsActive         = FALSE;
    gSetupDone        = FALSE;
    gRegistered       = FALSE;
    gActiveBuffNames  = [];
    gHasActiveBuffs   = FALSE;
    gSpotLevel        = 1;
    gSpotLevelReady   = FALSE;
    llSensorRemove();
    llSetTimerEvent(0.0);
    setTextLabel();
}

applyBuffPush(string bname) {
    if (bname == "" || bname == JSON_INVALID) return;
    if (llListFindList(gActiveBuffNames, [bname]) != -1) return;
    gActiveBuffNames += [bname];
    gHasActiveBuffs   = TRUE;
    llSay(0, "[Buff] " + bname + " is now active on " + gSpotName + ".");
    setTextLabel();
}

expireBuffPush(string bname) {
    if (bname == "" || bname == JSON_INVALID) return;
    integer idx = llListFindList(gActiveBuffNames, [bname]);
    if (idx == -1) return;
    gActiveBuffNames = llDeleteSubList(gActiveBuffNames, idx, idx);
    gHasActiveBuffs  = (llGetListLength(gActiveBuffNames) > 0);
    llSay(0, "[Buff] " + bname + " has worn off on " + gSpotName + ".");
    setTextLabel();
}

activateSpotSession() {
    setTextLabel();
    registerCallback();
    sendHeartbeat();
    if (gIsActive) {
        llSensorRepeat("", NULL_KEY, AGENT, computeRange(), PI, gScanRate);
        llSetTimerEvent(gNormalTimer);
        pollBuffStatus();
    } else {
        llSetTimerEvent(gIdleTimer);
    }
}

// ============================================================
// Setup wizard functions
// ============================================================

showArchiveRecoveryMenu(key who) {
    gSetupStep    = "archive_recovery";
    gSetupPlayer  = who;
    cleanupDialog();
    gDialogCh     = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", who, "");

    list    buttons = [];
    integer end     = llGetListLength(gArchivedSpotLabels);
    if (end > 10) end = 10;
    integer i;
    for (i = 0; i < end; i++) buttons += [llList2String(gArchivedSpotLabels, i)];

    string msg = "♻️ You have spots in this region that aren't active.\n" +
                 "Spots: " + (string)gSpotCount + "/" + (string)gSpotLimit +
                 "\n\nLoad one to keep its history, or place a new spot.";

    if (!gPlayerIsAdmin && gSpotCount >= gSpotLimit) {
        msg += "\n(At spot limit — load only)";
    } else {
        buttons += ["New Spot"];
    }
    buttons += ["Cancel"];

    llDialog(who, msg, buttons, gDialogCh);
}

showWaterMenu() {
    gSetupStep    = "water";
    cleanupDialog();
    gDialogCh     = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");

    list buttons = [];
    integer i;
    for (i = 0; i < llGetListLength(gAvailableWater); i++) {
        string wt  = llList2String(gAvailableWater, i);
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
    gSetupStep  = "name";
    cleanupDialog();
    gTextCh     = -1 - (integer)llFrand(999999.0);
    gTextHandle = llListen(gTextCh, "", gSetupPlayer, "");
    llTextBox(gSetupPlayer, "🎣 Name this fishing spot:", gTextCh);
}

showSystemMenu() {
    gSetupStep    = "system";
    cleanupDialog();
    gDialogCh     = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");
    llDialog(gSetupPlayer,
        "🎣 System Spot?\n\nSystem spots have no owner limit and are managed by admins.",
        ["Yes", "No", "Cancel"], gDialogCh);
}

showPublicMenu() {
    gSetupStep    = "public";
    cleanupDialog();
    gDialogCh     = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");
    llDialog(gSetupPlayer,
        "🎣 Visibility\n\nPublic spots appear on the grid map.\nPrivate spots work but aren't listed.",
        ["Public", "Private", "Cancel"], gDialogCh);
}

showActivateMenu() {
    gSetupStep    = "activate";
    cleanupDialog();
    gDialogCh     = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");
    llDialog(gSetupPlayer,
        "🎣 Activate Now?\n\n" + gSpotName + " (" + gWaterType + ")\n" +
        (gIsPublic ? "Public" : "Private") + (gIsSystem ? " | System" : ""),
        ["Activate", "Later", "Cancel"], gDialogCh);
}

registerWithServer(integer activate) {
    vector pos    = llGetPos();
    vector corner = llGetRegionCorner();
    gHttpAction   = "register";
    gHttpReq      = llHTTPRequest(gApiUrl, [
        HTTP_METHOD, "POST",
        HTTP_MIMETYPE, "application/x-www-form-urlencoded",
        HTTP_BODY_MAXLENGTH, 4096
    ], "action=spot_register" +
       "&name="       + llEscapeURL(gSpotName) +
       "&water_type=" + llEscapeURL(gWaterType) +
       "&region="     + llEscapeURL(llGetRegionName()) +
       "&grid_name="  + llEscapeURL(osGetGridName()) +
       "&pos_x="      + (string)pos.x +
       "&pos_y="      + (string)pos.y +
       "&pos_z="      + (string)pos.z +
       "&owner_key="  + llEscapeURL((string)llGetOwner()) +
       "&is_public="  + (string)gIsPublic +
       "&is_system="  + (string)gIsSystem +
       "&region_x="   + (string)((integer)(corner.x / 256.0)) +
       "&region_y="   + (string)((integer)(corner.y / 256.0)) +
       "&activate="   + (string)activate);
}

// ============================================================
// Manage functions
// ============================================================

showManageMenu(key who) {
    cleanupDialog();
    gSetupPlayer  = who;
    gSetupStep    = "manage";
    gDialogCh     = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", who, "");
    llDialog(who,
        "🎣 " + gSpotName + "\n" +
        gWaterType + " | Lvl " + (string)gSpotLevel +
        " | " + (gIsActive ? "Active" : "Inactive") +
        " | " + (gIsPublic ? "Public" : "Private") +
        (gIsSystem ? " | System" : "") +
        "\nID: " + (string)gSpotId,
        ["Use Buff", "Add Junk", "Edit", "Close"], gDialogCh);
}

showEditMenu(key who) {
    cleanupDialog();
    gSetupPlayer  = who;
    gSetupStep    = "edit";
    gDialogCh     = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", who, "");
    string activeBtn = gIsActive ? "Deactivate" : "Activate";
    string pubBtn    = gIsPublic ? "Make Private" : "Make Public";
    list editBtns = [activeBtn, "Rename", pubBtn, "List Junk"];
    if (gSpotLevelReady && !gIsSystem) editBtns += ["Level Up!"];
    editBtns += ["Delete", "Back"];
    llDialog(who, "🎣 Edit: " + gSpotName, editBtns, gDialogCh);
}

showJunkPrompt() {
    gSetupStep  = "add_junk";
    cleanupDialog();
    gTextCh     = -1 - (integer)llFrand(999999.0);
    gTextHandle = llListen(gTextCh, "", gSetupPlayer, "");

    integer count = llGetInventoryNumber(INVENTORY_OBJECT);
    string  info  = "Enter the name of an object in this prim's inventory to add as junk loot.\n\nInventory objects:";
    integer i;
    for (i = 0; i < count && i < 10; i++)
        info += "\n• " + llGetInventoryName(INVENTORY_OBJECT, i);
    if (count == 0) info += "\n(none — drop objects into this prim first)";
    llTextBox(gSetupPlayer, info, gTextCh);
}

// ============================================================
// Main state
// ============================================================

default {
    state_entry() {
        llListen(CH_SPOT_TO_HUD, "", NULL_KEY, "");
        llListen(0, "", llGetOwner(), "");
        llRequestURL();

        string storedId    = llLinksetDataRead("spot_id");
        string storedOwner = llLinksetDataRead("spot_owner");

        if (storedId == "" || storedOwner != (string)llGetOwner()) {
            gSetupDone  = FALSE;
            gRegistered = FALSE;
            gIsActive   = FALSE;
            setTextLabel();
            return;
        }

        gSpotId        = (integer)storedId;
        gSetupDone     = TRUE;
        gRegistered    = TRUE;
        gLastHeartbeat = 0;
        setTextSafe("Fishing Spot\n(Loading...)", <1.0, 0.8, 0.3>, 1.0);

        gHttpAction = "status";
        gHttpReq    = llHTTPRequest(gApiUrl, [
            HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
        ], "action=spot_status&spot_id=" + (string)gSpotId);
    }

    on_rez(integer p) {
        llResetScript();
    }

    changed(integer change) {
        if (change & CHANGED_OWNER) {
            clearSpotData();
            llResetScript();
            return;
        }
        if (change & CHANGED_REGION_RESTART) {
            gCallbackUrl = "";
            gHttpReq     = NULL_KEY;
            gHttpAction  = "";
            llRequestURL();
            if (gRegistered) sendHeartbeat();
        }
    }

    touch_start(integer n) {
        gTouchStartTime = llGetUnixTime();
        gTouchKey       = llDetectedKey(0);
    }

    touch_end(integer n) {
        key who = gTouchKey;
        gTouchKey = NULL_KEY;
        if (who == NULL_KEY) return;

        integer holdTime = llGetUnixTime() - gTouchStartTime;

        if (!gSetupDone) {
            if (who != llGetOwner()) {
                llRegionSayTo(who, 0, "This fishing spot hasn't been set up yet.");
                return;
            }
            gSetupPlayer = who;
            gHttpAction  = "archived_check";
            gHttpReq     = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST",
                HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                HTTP_BODY_MAXLENGTH, 4096
            ], "action=spot_archived_list&uuid=" + llEscapeURL((string)who) +
               "&region="    + llEscapeURL(llGetRegionName()) +
               "&grid_name=" + llEscapeURL(osGetGridName()));
            return;
        }

        if (holdTime < 3) {
            setTextLabel();
            if (gRegistered && gIsActive) notifyPlayer(who);
            return;
        }

        if (who == llGetOwner()) {
            showManageMenu(who);
        } else {
            if (gRegistered && gIsActive) notifyPlayer(who);
            gSetupPlayer = who;
            gHttpAction  = "buff_status";
            gHttpReq     = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
            ], "action=buff_spot_status&spot_id=" + (string)gSpotId);
        }
    }

    sensor(integer num) {
        if (!gRegistered || !gIsActive) return;
        list now = [];
        integer i;
        for (i = 0; i < num; i++) now += [llDetectedKey(i)];

        for (i = 0; i < num; i++) {
            key av = llDetectedKey(i);
            if (llListFindList(gInRange, [av]) == -1) notifyPlayer(av);
        }

        integer old = llGetListLength(gInRange);
        for (i = 0; i < old; i++) {
            key av = llList2Key(gInRange, i);
            if (llListFindList(now, [av]) == -1)
                llRegionSayTo(av, CH_SPOT_TO_HUD, "UNSPOT|" + (string)gSpotId);
        }
        gInRange = now;
    }

    no_sensor() {
        integer i;
        integer count = llGetListLength(gInRange);
        for (i = 0; i < count; i++)
            llRegionSayTo(llList2Key(gInRange, i), CH_SPOT_TO_HUD, "UNSPOT|" + (string)gSpotId);
        gInRange = [];
    }

    timer() {
        if (!gRegistered) return;
        integer now = llGetUnixTime();

        integer hadPlayers = gPlayersPresent;
        gPlayersPresent = regionHasPlayers();

        if (gPlayersPresent && !hadPlayers) {
            sendHeartbeat();
            llSetTimerEvent(gNormalTimer);
        } else if (!gPlayersPresent && hadPlayers) {
            llSetTimerEvent(gIdleTimer);
        }

        if (now - gLastHeartbeat >= HEARTBEAT_INTERVAL) sendHeartbeat();

        if (gIsActive && gPlayersPresent && now - gLastBuffPoll >= BUFF_POLL_INTERVAL)
            pollBuffStatus();
    }

    http_request(key id, string method, string body) {
        if (method == URL_REQUEST_GRANTED) {
            gCallbackUrl = body;
            registerCallback();
            return;
        }
        if (method == URL_REQUEST_DENIED) {
            gCallbackUrl = "";
            return;
        }

        llHTTPResponse(id, 200, "OK");

        string action = llJsonGetValue(body, ["action"]);
        string bname  = llJsonGetValue(body, ["buff_name"]);

        if (action == "buff_active")  { applyBuffPush(bname);  return; }
        if (action == "buff_expired") { expireBuffPush(bname); return; }
    }

    http_response(key req, integer status, list meta, string body) {
        if (req != gHttpReq) return;

        // ── Non-200: extract clean error message ──
        if (status != 200) {
            string err = llJsonGetValue(body, ["error"]);
            if (err == JSON_INVALID || err == "") err = "HTTP " + (string)status;

            if (gHttpAction == "delete") {
                llOwnerSay("⚠️ " + err + " — clearing local data.");
                clearSpotData();
                llResetScript();
                return;
            }
            if (gHttpAction == "status" && (status == 410 || status == 404)) {
                clearSpotData();
                resetToUnsetup();
                return;
            }
            if (gHttpAction != "heartbeat") llOwnerSay("⚠️ " + err);
            return;
        }

        // ── success=false ──
        string ok = llJsonGetValue(body, ["success"]);
        if (ok != "true" && ok != "1" && ok != JSON_TRUE) {
            string err = llJsonGetValue(body, ["error"]);
            if (err == JSON_INVALID || err == "") err = "Unknown error";

            if (gHttpAction == "delete") {
                llOwnerSay("⚠️ " + err + " — clearing local data.");
                clearSpotData();
                llResetScript();
                return;
            }
            if (gHttpAction != "heartbeat") llOwnerSay("⚠️ " + err);
            return;
        }

        // ── Spot status (on load / after restore) ──
        if (gHttpAction == "status") {
            string exists = llJsonGetValue(body, ["exists"]);
            if (exists == "false" || exists == JSON_FALSE) {
                clearSpotData();
                resetToUnsetup();
                llOwnerSay("Spot no longer found on server. Touch to set up fresh.");
                return;
            }

            string active = llJsonGetValue(body, ["is_active"]);
            gIsActive = (active == "1" || active == "true" || active == JSON_TRUE);

            string nm = llJsonGetValue(body, ["name"]);
            if (nm != JSON_INVALID && nm != "") gSpotName = nm;

            string wt = llJsonGetValue(body, ["water_type"]);
            if (wt != JSON_INVALID && wt != "") gWaterType = wt;

            string pub = llJsonGetValue(body, ["is_public"]);
            if (pub != JSON_INVALID) gIsPublic = (pub == "1" || pub == "true" || pub == JSON_TRUE);

            string sys = llJsonGetValue(body, ["is_system"]);
            if (sys != JSON_INVALID) gIsSystem = (sys == "1" || sys == "true" || sys == JSON_TRUE);

            string slvl = llJsonGetValue(body, ["spot_level"]);
            if (slvl != JSON_INVALID && slvl != "") gSpotLevel = (integer)slvl;

            string slvlr = llJsonGetValue(body, ["spot_level_ready"]);
            if (slvlr != JSON_INVALID) gSpotLevelReady = (slvlr == "1" || slvlr == "true" || slvlr == JSON_TRUE);

            activateSpotSession();
            return;
        }

        // ── Buff poll (fallback) ──
        if (gHttpAction == "buff_poll") {
            string buffsJson = llJsonGetValue(body, ["buffs"]);
            list newNames = [];

            if (buffsJson != JSON_INVALID && buffsJson != "[]" && buffsJson != "") {
                integer i = 0;
                while (i < 9) {
                    string entry = llJsonGetValue(buffsJson, [i]);
                    if (entry == JSON_INVALID) jump poll_done;
                    string bn = llJsonGetValue(entry, ["buff_name"]);
                    if (bn != JSON_INVALID && bn != "") newNames += [bn];
                    i++;
                }
                @poll_done;
            }

            integer i;
            for (i = 0; i < llGetListLength(newNames); i++) {
                string n = llList2String(newNames, i);
                if (llListFindList(gActiveBuffNames, [n]) == -1)
                    llSay(0, "[Buff] " + n + " is now active on " + gSpotName + ".");
            }
            for (i = 0; i < llGetListLength(gActiveBuffNames); i++) {
                string n = llList2String(gActiveBuffNames, i);
                if (llListFindList(newNames, [n]) == -1)
                    llSay(0, "[Buff] " + n + " has worn off on " + gSpotName + ".");
            }

            gActiveBuffNames = newNames;
            gHasActiveBuffs  = (llGetListLength(newNames) > 0);
            setTextLabel();
            return;
        }

        // ── Setup: archived_check ──
        if (gHttpAction == "archived_check") {
            gSpotCount     = (integer)llJsonGetValue(body, ["spot_count"]);
            gSpotLimit     = (integer)llJsonGetValue(body, ["spot_limit"]);
            gPlayerIsAdmin = (llJsonGetValue(body, ["is_admin"]) == "1");

            gArchivedSpotIds      = [];
            gArchivedSpotLabels   = [];
            gRecoverableSpotTypes = [];

            string archJson = llJsonGetValue(body, ["archived"]);
            if (archJson != JSON_INVALID && archJson != "[]" && archJson != "") {
                integer i = 0;
                while (i < 10) {
                    string entry = llJsonGetValue(archJson, [i]);
                    if (entry == JSON_INVALID) jump arch_done;
                    string sid     = llJsonGetValue(entry, ["id"]);
                    string sname   = llJsonGetValue(entry, ["name"]);
                    string catches = llJsonGetValue(entry, ["catch_count"]);
                    if (catches == JSON_INVALID) catches = "0";
                    gArchivedSpotIds      += [sid];
                    gRecoverableSpotTypes += ["archived"];
                    string label = sname;
                    if (llStringLength(label) > 10) label = llGetSubString(label, 0, 9);
                    gArchivedSpotLabels += [label + " [arc] (" + catches + ")"];
                    i++;
                }
                @arch_done;
            }

            string inactJson = llJsonGetValue(body, ["inactive"]);
            if (inactJson != JSON_INVALID && inactJson != "[]" && inactJson != "") {
                integer j = 0;
                while (llGetListLength(gArchivedSpotIds) < 10) {
                    string entry = llJsonGetValue(inactJson, [j]);
                    if (entry == JSON_INVALID) jump inact_done;
                    string sid     = llJsonGetValue(entry, ["id"]);
                    string sname   = llJsonGetValue(entry, ["name"]);
                    string catches = llJsonGetValue(entry, ["catch_count"]);
                    if (catches == JSON_INVALID) catches = "0";
                    gArchivedSpotIds      += [sid];
                    gRecoverableSpotTypes += ["inactive"];
                    string label = sname;
                    if (llStringLength(label) > 10) label = llGetSubString(label, 0, 9);
                    gArchivedSpotLabels += [label + " [off] (" + catches + ")"];
                    j++;
                }
                @inact_done;
            }

            if (gPlayerIsAdmin) {
                string sysJson = llJsonGetValue(body, ["system_spots"]);
                if (sysJson != JSON_INVALID && sysJson != "[]" && sysJson != "") {
                    integer k = 0;
                    while (llGetListLength(gArchivedSpotIds) < 10) {
                        string entry = llJsonGetValue(sysJson, [k]);
                        if (entry == JSON_INVALID) jump sys_done;
                        string sid     = llJsonGetValue(entry, ["id"]);
                        string sname   = llJsonGetValue(entry, ["name"]);
                        string catches = llJsonGetValue(entry, ["catch_count"]);
                        if (catches == JSON_INVALID) catches = "0";
                        string isArch  = llJsonGetValue(entry, ["archived"]);
                        gArchivedSpotIds      += [sid];
                        gRecoverableSpotTypes += ["system"];
                        string label = sname;
                        if (llStringLength(label) > 8) label = llGetSubString(label, 0, 7);
                        string tag = (isArch == "1") ? "[S-arc]" : "[S-off]";
                        gArchivedSpotLabels += [label + " " + tag + " (" + catches + ")"];
                        k++;
                    }
                    @sys_done;
                }
            }

            if (llGetListLength(gArchivedSpotIds) > 0) {
                showArchiveRecoveryMenu(gSetupPlayer);
                return;
            }

            if (!gPlayerIsAdmin && gSpotCount >= gSpotLimit) {
                llOwnerSay("Spot limit reached (" + (string)gSpotCount + "/" + (string)gSpotLimit + "). Level up for more.");
                return;
            }

            gHttpAction = "setup_info";
            gHttpReq    = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST",
                HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                HTTP_BODY_MAXLENGTH, 4096
            ], "action=spot_setup_info&uuid=" + llEscapeURL((string)gSetupPlayer) +
               "&grid_name=" + llEscapeURL(osGetGridName()));
            return;
        }

        // ── Setup: setup_info ──
        if (gHttpAction == "setup_info") {
            gPlayerIsAdmin = (llJsonGetValue(body, ["is_admin"]) == "1");
            gSpotCount     = (integer)llJsonGetValue(body, ["spot_count"]);
            gSpotLimit     = (integer)llJsonGetValue(body, ["spot_limit"]);

            gAvailableWater = [];
            string wtJson   = llJsonGetValue(body, ["water_types"]);
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
            return;
        }

        // ── Setup: register new spot ──
        if (gHttpAction == "register") {
            gSpotId        = (integer)llJsonGetValue(body, ["spot_id"]);
            gSetupDone     = TRUE;
            gRegistered    = TRUE;
            gLastHeartbeat = 0;
            saveSpotData();
            llOwnerSay("✅ " + gSpotName + " registered! (ID " + (string)gSpotId + ")");
            activateSpotSession();
            return;
        }

        // ── Setup: restore archived/inactive spot ──
        if (gHttpAction == "restore") {
            gSpotId        = (integer)llJsonGetValue(body, ["spot_id"]);
            string nm      = llJsonGetValue(body, ["name"]);
            if (nm == JSON_INVALID) nm = "";
            string restoreStatus = llJsonGetValue(body, ["status"]);
            if (restoreStatus == "inactive")
                llOwnerSay("✅ Loaded '" + nm + "'! Spot moved to this prim.");
            else
                llOwnerSay("✅ Restored '" + nm + "'! Catch history preserved.");
            gSetupDone     = TRUE;
            gRegistered    = TRUE;
            gLastHeartbeat = 0;
            saveSpotData();
            gHttpAction = "status";
            gHttpReq    = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
            ], "action=spot_status&spot_id=" + (string)gSpotId);
            return;
        }

        // ── Manage: update (activate/rename/public) ──
        if (gHttpAction == "update") {
            llOwnerSay("✅ Spot updated.");
            setTextLabel();
            if (gIsActive) {
                llSensorRepeat("", NULL_KEY, AGENT, computeRange(), PI, gScanRate);
                llSetTimerEvent(gNormalTimer);
            } else {
                llSensorRemove();
                integer i;
                for (i = 0; i < llGetListLength(gInRange); i++)
                    llRegionSayTo(llList2Key(gInRange, i), CH_SPOT_TO_HUD, "UNSPOT|" + (string)gSpotId);
                gInRange = [];
                llSetTimerEvent(gIdleTimer);
            }
            showManageMenu(gSetupPlayer);
            return;
        }

        // ── Manage: delete ──
        if (gHttpAction == "delete") {
            llOwnerSay("🗑️ Spot deleted.");
            clearSpotData();
            llResetScript();
            return;
        }

        // ── Manage: add_junk ──
        if (gHttpAction == "add_junk") {
            llOwnerSay("✅ Junk item added.");
            showManageMenu(gSetupPlayer);
            return;
        }

        // ── Manage: list_junk ──
        if (gHttpAction == "list_junk") {
            string junkJson = llJsonGetValue(body, ["junk_items"]);
            if (junkJson == JSON_INVALID || junkJson == "[]") {
                llOwnerSay("No junk items. Drop objects into this prim then use Add Junk.");
            } else {
                llOwnerSay("=== Junk Loot Table ===");
                integer i = 0;
                while (i < 20) {
                    string entry   = llJsonGetValue(junkJson, [i]);
                    if (entry == JSON_INVALID) jump junk_done;
                    string jName   = llJsonGetValue(entry, ["item_name"]);
                    string jRarity = llJsonGetValue(entry, ["rarity_label"]);
                    if (jRarity == JSON_INVALID) jRarity = "common";
                    llOwnerSay("  - " + jName + ", " + jRarity);
                    i++;
                }
                @junk_done;
            }
            showManageMenu(gSetupPlayer);
            return;
        }

        // ── Buff: buff_status ──
        if (gHttpAction == "buff_status") {
            string isBuff    = llJsonGetValue(body, ["is_buffed"]);
            string buffsJson = llJsonGetValue(body, ["buffs"]);

            string info = "🎣 " + gSpotName + " — " + gWaterType + "\n\n";
            if (isBuff == "true" || isBuff == "1" || isBuff == JSON_TRUE) {
                info += "Active Buffs:\n";
                integer i = 0;
                while (i < 9) {
                    string entry = llJsonGetValue(buffsJson, [i]);
                    if (entry == JSON_INVALID) jump buff_info_done;
                    info += "  " + llJsonGetValue(entry, ["buff_name"]) +
                            " — " + llJsonGetValue(entry, ["mins_remaining"]) + "min / 120max\n";
                    i++;
                }
                @buff_info_done;
            } else {
                info += "No active buffs.\n";
            }

            integer junkCount = llGetInventoryNumber(INVENTORY_OBJECT);
            if (junkCount > 0) {
                gPendingBuffInfo = info;
                gHttpAction      = "show_junk_dialog";
                gHttpReq         = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
                ], "action=spot_list_junk&spot_id=" + (string)gSpotId);
                return;
            }

            info += "\nUse a buff item on this spot?";
            gSetupStep    = "buff_menu";
            cleanupDialog();
            gDialogCh     = -1 - (integer)llFrand(999999.0);
            gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");
            llDialog(gSetupPlayer, info, ["Use Buff", "Close"], gDialogCh);
            return;
        }

        // ── Buff: show_junk_dialog ──
        if (gHttpAction == "show_junk_dialog") {
            string info      = gPendingBuffInfo;
            gPendingBuffInfo = "";

            string junkJson = llJsonGetValue(body, ["junk_items"]);
            if (junkJson != JSON_INVALID && junkJson != "[]") {
                info += "\n🎁 Junk Loot:\n";
                integer i = 0;
                while (i < 10) {
                    string entry   = llJsonGetValue(junkJson, [i]);
                    if (entry == JSON_INVALID) jump junk2_done;
                    string jRarity = llJsonGetValue(entry, ["rarity_label"]);
                    if (jRarity == JSON_INVALID) jRarity = "common";
                    info += "  - " + llJsonGetValue(entry, ["item_name"]) + ", " + jRarity + "\n";
                    i++;
                }
                @junk2_done;
            }

            info += "\nUse a buff item on this spot?";
            gSetupStep    = "buff_menu";
            cleanupDialog();
            gDialogCh     = -1 - (integer)llFrand(999999.0);
            gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");
            llDialog(gSetupPlayer, info, ["Use Buff", "Close"], gDialogCh);
            return;
        }

        // ── Buff: buff_inv ──
        if (gHttpAction == "buff_inv") {
            string invJson = llJsonGetValue(body, ["buff_inventory"]);
            if (invJson == JSON_INVALID || invJson == "[]") {
                llRegionSayTo(gSetupPlayer, 0, "You don't have any buff items.");
                return;
            }

            gBuffItemIds    = [];
            gBuffItemLabels = [];
            integer i = 0;
            while (i < 9) {
                string entry = llJsonGetValue(invJson, [i]);
                if (entry == JSON_INVALID) jump inv_done;
                string bId   = llJsonGetValue(entry, ["buff_item_id"]);
                string bName = llJsonGetValue(entry, ["name"]);
                string bQty  = llJsonGetValue(entry, ["quantity"]);
                gBuffItemIds += [bId];
                string label = bName;
                if (llStringLength(label) > 18) label = llGetSubString(label, 0, 17);
                label += " x" + bQty;
                gBuffItemLabels += [label];
                i++;
            }
            @inv_done;

            gSetupStep    = "buff_pick";
            cleanupDialog();
            gDialogCh     = -1 - (integer)llFrand(999999.0);
            gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");
            llDialog(gSetupPlayer, "Select a buff item to use:",
                gBuffItemLabels + ["Back"], gDialogCh);
            return;
        }

        // ── Buff: buff_activate ──
        if (gHttpAction == "buff_activate") {
            string msg = llJsonGetValue(body, ["message"]);
            if (msg == JSON_INVALID) msg = "Buff activated!";
            llRegionSayTo(gSetupPlayer, 0, "✅ " + msg);
            pollBuffStatus();
            return;
        }

        // ── Level up confirmed ──
        if (gHttpAction == "level_up") {
            integer newLvl = (integer)llJsonGetValue(body, ["new_level"]);
            if (newLvl > 0) gSpotLevel = newLvl;
            gSpotLevelReady = FALSE;
            setTextLabel();
            llSay(0, "[Spot] " + gSpotName + " has reached level " + (string)gSpotLevel + "!");
            showManageMenu(gSetupPlayer);
            return;
        }
    }

    listen(integer ch, string name, key id, string msg) {
        // ── Owner debug commands ──
        if (ch == 0 && id == llGetOwner()) {
            if (msg == "/spot reset" || msg == "/spot wipe") {
                clearSpotData();
                resetToUnsetup();
                llOwnerSay("Spot data wiped. Touch to begin setup.");
            }
            return;
        }

        // ── Junk delivery from HUD ──
        if (ch == CH_SPOT_TO_HUD) {
            list parts = llParseString2List(msg, ["|"], []);
            if (llList2String(parts, 0) == "DELIVER_JUNK") {
                integer reqSpotId = (integer)llList2String(parts, 1);
                if (reqSpotId != gSpotId) return;
                string itemName  = llList2String(parts, 2);
                key    recipient = (key)llList2String(parts, 3);
                if (llGetInventoryType(itemName) == INVENTORY_OBJECT)
                    llGiveInventory(recipient, itemName);
            }
            return;
        }

        // ── Setup: archive recovery menu ──
        if (gSetupStep == "archive_recovery") {
            cleanupDialog();
            if (msg == "Cancel") { gSetupStep = ""; return; }
            if (msg == "New Spot") {
                gHttpAction = "setup_info";
                gHttpReq    = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST",
                    HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                    HTTP_BODY_MAXLENGTH, 4096
                ], "action=spot_setup_info&uuid=" + llEscapeURL((string)gSetupPlayer) +
                   "&grid_name=" + llEscapeURL(osGetGridName()));
                return;
            }
            integer idx = llListFindList(gArchivedSpotLabels, [msg]);
            if (idx == -1) { gSetupStep = ""; return; }
            string sid = llList2String(gArchivedSpotIds, idx);
            vector pos = llGetPos();
            gHttpAction = "restore";
            gHttpReq    = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST",
                HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                HTTP_BODY_MAXLENGTH, 4096
            ], "action=spot_restore" +
               "&uuid="      + llEscapeURL((string)gSetupPlayer) +
               "&spot_id="   + sid +
               "&prim_uuid=" + (string)llGetKey() +
               "&pos_x="     + (string)pos.x +
               "&pos_y="     + (string)pos.y +
               "&pos_z="     + (string)pos.z +
               "&region="    + llEscapeURL(llGetRegionName()) +
               "&grid_name=" + llEscapeURL(osGetGridName()));
            gSetupStep = "";
            return;
        }

        // ── Setup: water type ──
        if (gSetupStep == "water") {
            cleanupDialog();
            if (msg == "Cancel") { gSetupStep = ""; return; }
            gWaterType = llToLower(msg);
            showNamePrompt();
            return;
        }

        // ── Setup: name ──
        if (gSetupStep == "name") {
            cleanupDialog();
            gSpotName = msg;
            if (gPlayerIsAdmin) showSystemMenu();
            else { gIsSystem = FALSE; showPublicMenu(); }
            return;
        }

        // ── Setup: system ──
        if (gSetupStep == "system") {
            cleanupDialog();
            if (msg == "Cancel") { gSetupStep = ""; return; }
            gIsSystem = (msg == "Yes");
            showPublicMenu();
            return;
        }

        // ── Setup: public ──
        if (gSetupStep == "public") {
            cleanupDialog();
            if (msg == "Cancel") { gSetupStep = ""; return; }
            gIsPublic = (msg == "Public");
            showActivateMenu();
            return;
        }

        // ── Setup: activate ──
        if (gSetupStep == "activate") {
            cleanupDialog();
            if (msg == "Cancel") { gSetupStep = ""; return; }
            gIsActive = (msg == "Activate");
            registerWithServer(gIsActive);
            return;
        }

        // ── Manage: main menu ──
        if (gSetupStep == "manage") {
            cleanupDialog();
            if (msg == "Close")    { gSetupStep = ""; return; }
            if (msg == "Use Buff") {
                gSetupPlayer = id;
                gHttpAction  = "buff_status";
                gHttpReq     = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
                ], "action=buff_spot_status&spot_id=" + (string)gSpotId);
                return;
            }
            if (msg == "Add Junk") { showJunkPrompt(); return; }
            if (msg == "Edit")     { showEditMenu(gSetupPlayer); return; }
            return;
        }

        // ── Manage: edit submenu ──
        if (gSetupStep == "edit") {
            cleanupDialog();
            if (msg == "Back") { showManageMenu(gSetupPlayer); return; }

            if (msg == "Activate" || msg == "Deactivate") {
                integer newActive = (msg == "Activate") ? TRUE : FALSE;
                gIsActive   = newActive;
                gHttpAction = "update";
                gHttpReq    = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
                ], "action=spot_update&uuid=" + llEscapeURL((string)llGetOwner()) +
                   "&spot_id=" + (string)gSpotId + "&is_active=" + (string)newActive);
                return;
            }

            if (msg == "Rename") {
                gSetupStep  = "rename";
                gTextCh     = -1 - (integer)llFrand(999999.0);
                gTextHandle = llListen(gTextCh, "", gSetupPlayer, "");
                llTextBox(gSetupPlayer, "Enter new name for this spot:", gTextCh);
                return;
            }

            if (msg == "Make Public" || msg == "Make Private") {
                gIsPublic   = (msg == "Make Public");
                gHttpAction = "update";
                gHttpReq    = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
                ], "action=spot_update&uuid=" + llEscapeURL((string)llGetOwner()) +
                   "&spot_id=" + (string)gSpotId + "&is_public=" + (string)gIsPublic);
                return;
            }

            if (msg == "List Junk") {
                gHttpAction = "list_junk";
                gHttpReq    = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
                ], "action=spot_list_junk&spot_id=" + (string)gSpotId);
                return;
            }

            if (msg == "Level Up!") {
                gSetupStep    = "confirm_level_up";
                gDialogCh     = -1 - (integer)llFrand(999999.0);
                gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");
                llDialog(gSetupPlayer,
                    "Level up " + gSpotName + " to level " + (string)(gSpotLevel + 1) + "?\n\n" +
                    "This will raise the minimum player level required to fish here.",
                    ["Confirm", "Cancel"], gDialogCh);
                return;
            }

            if (msg == "Delete") {
                gSetupStep    = "confirm_delete";
                gDialogCh     = -1 - (integer)llFrand(999999.0);
                gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");
                llDialog(gSetupPlayer, "⚠️ Delete this fishing spot?\nThis cannot be undone!",
                         ["Yes Delete", "Cancel"], gDialogCh);
                return;
            }
            return;
        }

        // ── Manage: confirm level up ──
        if (gSetupStep == "confirm_level_up") {
            cleanupDialog();
            if (msg == "Confirm") {
                gHttpAction = "level_up";
                gHttpReq    = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
                ], "action=spot_level_up&uuid=" + llEscapeURL((string)llGetOwner()) +
                   "&spot_id=" + (string)gSpotId);
            } else {
                showEditMenu(gSetupPlayer);
            }
            return;
        }

        // ── Manage: rename ──
        if (gSetupStep == "rename") {
            cleanupDialog();
            gSpotName   = msg;
            gHttpAction = "update";
            gHttpReq    = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
            ], "action=spot_update&uuid=" + llEscapeURL((string)llGetOwner()) +
               "&spot_id=" + (string)gSpotId + "&name=" + llEscapeURL(gSpotName));
            return;
        }

        // ── Manage: add junk step 1 — name ──
        if (gSetupStep == "add_junk") {
            cleanupDialog();
            if (llGetInventoryType(msg) != INVENTORY_OBJECT) {
                llOwnerSay("Object '" + msg + "' not found in this prim's inventory.");
                showManageMenu(gSetupPlayer);
                return;
            }
            gPendingJunkName = msg;
            gSetupStep       = "add_junk_rarity";
            gDialogCh        = -1 - (integer)llFrand(999999.0);
            gDialogHandle    = llListen(gDialogCh, "", gSetupPlayer, "");
            llDialog(gSetupPlayer,
                "🎁 How rare is '" + msg + "'?\n\n" +
                "Common — fished up often\n" +
                "Uncommon — less frequent\n" +
                "Rare — hard to get\n" +
                "Legendary — extremely rare find",
                ["Common", "Uncommon", "Rare", "Legendary", "Cancel"], gDialogCh);
            return;
        }

        // ── Manage: add junk step 2 — rarity ──
        if (gSetupStep == "add_junk_rarity") {
            cleanupDialog();
            if (msg == "Cancel") { gPendingJunkName = ""; showManageMenu(gSetupPlayer); return; }

            string rarityWeight = "5.0";
            string rarityLabel  = "common";
            if      (msg == "Uncommon")  { rarityWeight = "2.0"; rarityLabel = "uncommon"; }
            else if (msg == "Rare")      { rarityWeight = "0.5"; rarityLabel = "rare"; }
            else if (msg == "Legendary") { rarityWeight = "0.1"; rarityLabel = "legendary"; }

            gHttpAction = "add_junk";
            gHttpReq    = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
            ], "action=spot_add_junk&uuid=" + llEscapeURL((string)llGetOwner()) +
               "&spot_id="       + (string)gSpotId +
               "&item_name="     + llEscapeURL(gPendingJunkName) +
               "&rarity_weight=" + rarityWeight +
               "&rarity_label="  + llEscapeURL(rarityLabel));
            gPendingJunkName = "";
            return;
        }

        // ── Manage: confirm delete ──
        if (gSetupStep == "confirm_delete") {
            cleanupDialog();
            if (msg == "Yes Delete") {
                gHttpAction = "delete";
                gHttpReq    = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
                ], "action=spot_delete&uuid=" + llEscapeURL((string)llGetOwner()) +
                   "&spot_id=" + (string)gSpotId);
            } else {
                showManageMenu(gSetupPlayer);
            }
            return;
        }

        // ── Buff: buff menu ──
        if (gSetupStep == "buff_menu") {
            cleanupDialog();
            if (msg == "Use Buff") {
                gHttpAction = "buff_inv";
                gHttpReq    = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
                ], "action=buff_inventory&uuid=" + llEscapeURL((string)gSetupPlayer));
            }
            // "Close" — no action needed
            return;
        }

        // ── Buff: buff item pick ──
        if (gSetupStep == "buff_pick") {
            cleanupDialog();
            if (msg == "Back") { gSetupStep = ""; return; }

            integer i;
            integer count = llGetListLength(gBuffItemLabels);
            for (i = 0; i < count; i++) {
                if (llList2String(gBuffItemLabels, i) == msg) {
                    string buffId = llList2String(gBuffItemIds, i);
                    gHttpAction   = "buff_activate";
                    gHttpReq      = llHTTPRequest(gApiUrl, [
                        HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
                    ], "action=buff_activate&uuid=" + llEscapeURL((string)gSetupPlayer) +
                       "&spot_id=" + (string)gSpotId + "&buff_item_id=" + buffId);
                    return;
                }
            }
            return;
        }
    }
}
