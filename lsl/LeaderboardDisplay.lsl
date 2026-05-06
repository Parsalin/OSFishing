// ============================================================
// LEADERBOARD DISPLAY - Rendered with osSetDynamicTextureData
// ============================================================
// Draws a styled leaderboard on prim face 0.
// Touch → dialog to select scope, metric, water type.
// Auto-refreshes every 5 minutes. Top 10.
// ============================================================

string  gApiUrl = "https://sp.wa.darkheartsos.net/fishing/api/";

string fmtWeight(float w) {
    // Round to 2 decimal places
    integer cents = (integer)(w * 100.0 + 0.5);
    integer whole = cents / 100;
    integer dec = cents % 100;
    string ds = (string)dec;
    if (dec < 10) ds = "0" + ds;
    return (string)whole + "." + ds;
}

key     gHttpReq    = NULL_KEY;
string  gMetric     = "weight";
string  gScope      = "world";
string  gWaterType  = "";
string  gScopeName  = "World";
string  gMetricName = "Heaviest Catch";
string  gWaterName  = "All Waters";
string  gRegion     = "";
string  gGridName   = "";

// Spot linking
integer gSpotId     = 0;       // 0 = not linked to a spot
string  gSpotName   = "";
list    gNearbySpots = [];     // Strided: [spot_id, spot_name]
integer CH_SPOT_TO_HUD = -7710005;

integer gDialogCh     = 0;
integer gDialogHandle = 0;
string  gDialogMode   = "";
integer gDialogTick   = 0;
integer gRenderFace   = 0;

list    gEntries = [];  // Each: "name|value|detail"

// Colors matching web portal
string COL_BG       = "ff1a1e1a";
string COL_BG2      = "ff222822";
string COL_ACCENT   = "ffd4884e";
string COL_GOLD     = "ffffcc00";
string COL_SILVER   = "ffcccccc";
string COL_BRONZE   = "ffcd7f32";
string COL_TEXT     = "ffe0ddd5";
string COL_MUTED    = "ff908880";
string COL_BORDER   = "ff3a423a";

fetchLeaderboard() {
    string body = "action=leaderboard&metric=" + gMetric + "&scope=" + gScope + "&limit=10";
    if (gScope == "grid" && gGridName != "") body += "&scope_value=" + llEscapeURL(gGridName);
    else if (gScope == "sim" && gRegion != "") body += "&scope_value=" + llEscapeURL(gRegion);
    if (gWaterType != "") body += "&water_type=" + llEscapeURL(gWaterType);
    if (gSpotId > 0) body += "&spot_id=" + (string)gSpotId;
    gHttpReq = llHTTPRequest(gApiUrl, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 8192], body);
}

drawLeaderboard() {
    integer W = 512;
    integer H = 512;
    string d = "";

    // Background
    d += "PenColour " + COL_BG + ";MoveTo 0,0;FillRectangle 512,512;";

    // Title bar
    d += "PenColour " + COL_ACCENT + ";MoveTo 0,0;FillRectangle 512,56;";

    // Trophy cup icon
    d += "PenColour ffffffff;PenSize 2;";
    d += "MoveTo 16,10;LineTo 16,34;LineTo 44,34;LineTo 44,10;LineTo 16,10;";
    d += "MoveTo 12,10;LineTo 48,10;";
    d += "MoveTo 12,14;LineTo 6,14;LineTo 6,26;LineTo 12,26;";
    d += "MoveTo 48,14;LineTo 54,14;LineTo 54,26;LineTo 48,26;";
    d += "MoveTo 24,34;LineTo 24,40;";
    d += "MoveTo 36,34;LineTo 36,40;";
    d += "MoveTo 20,40;LineTo 40,40;";

    // Title
    d += "PenColour ffffffff;FontName Arial;FontSize 17;";
    d += "MoveTo 62,6;Text LEADERBOARD;";
    string subtitle = gScopeName + "  |  " + gMetricName + "  |  " + gWaterName;
    if (gSpotId > 0 && gSpotName != "") subtitle = gSpotName + "  |  " + gMetricName;
    d += "FontSize 10;MoveTo 62,30;Text " + subtitle + ";";

    // Column headers
    d += "PenColour " + COL_MUTED + ";FontSize 9;";
    d += "MoveTo 16,64;Text #;";
    d += "MoveTo 46,64;Text PLAYER;";
    if (gMetric == "weight") {
        d += "MoveTo 320,64;Text WEIGHT;";
        d += "MoveTo 420,64;Text SPECIES;";
    } else {
        d += "MoveTo 320,64;Text TOTAL;";
        d += "MoveTo 420,64;Text BEST;";
    }

    // Header divider
    d += "PenColour " + COL_BORDER + ";PenSize 1;MoveTo 10,80;LineTo 502,80;";

    // Rows
    integer count = llGetListLength(gEntries);
    integer i;
    integer rowY = 84;
    integer rowH = 40;

    for (i = 0; i < 10; i++) {
        integer y = rowY + i * rowH;

        // Alternating bg
        if (i % 2 == 1) {
            d += "PenColour " + COL_BG2 + ";MoveTo 0," + (string)y + ";FillRectangle 512," + (string)rowH + ";";
        }

        if (i >= count) {
            d += "PenColour " + COL_MUTED + ";FontSize 11;MoveTo 46," + (string)(y + 12) + ";Text -;";
        } else {
            string entry = llList2String(gEntries, i);
            list parts = llParseString2List(entry, ["|"], []);
            string pName   = llList2String(parts, 0);
            string pValue  = llList2String(parts, 1);
            string pDetail = llList2String(parts, 2);

            // Rank with medal color
            string rc = COL_TEXT;
            if (i == 0) rc = COL_GOLD;
            else if (i == 1) rc = COL_SILVER;
            else if (i == 2) rc = COL_BRONZE;

            d += "PenColour " + rc + ";FontSize 14;FontName Arial;";
            d += "MoveTo 18," + (string)(y + 10) + ";Text " + (string)(i + 1) + ";";

            // Name (truncate)
            if (llStringLength(pName) > 22) pName = llGetSubString(pName, 0, 21) + "..";
            d += "PenColour " + COL_TEXT + ";FontSize 12;";
            d += "MoveTo 46," + (string)(y + 12) + ";Text " + pName + ";";

            // Value
            d += "PenColour " + COL_ACCENT + ";FontSize 12;";
            d += "MoveTo 320," + (string)(y + 12) + ";Text " + pValue + ";";

            // Detail
            if (pDetail != "") {
                if (llStringLength(pDetail) > 11) pDetail = llGetSubString(pDetail, 0, 10) + "..";
                d += "PenColour " + COL_MUTED + ";FontSize 10;";
                d += "MoveTo 420," + (string)(y + 14) + ";Text " + pDetail + ";";
            }
        }
    }

    // Bottom divider
    integer bY = rowY + 10 * rowH;
    d += "PenColour " + COL_BORDER + ";PenSize 1;MoveTo 10," + (string)bY + ";LineTo 502," + (string)bY + ";";

    // Footer
    d += "PenColour " + COL_MUTED + ";FontSize 8;";
    d += "MoveTo 14," + (string)(bY + 6) + ";Text Touch to change view  |  Refreshes every 5 min;";

    // Outer border
    d += "PenColour " + COL_ACCENT + ";PenSize 2;MoveTo 1,1;Rectangle 510,510;";

    osSetDynamicTextureDataBlendFace("", "vector", d, "width:512,height:512", 0, 0, 0, 255, gRenderFace);
}

showMainMenu(key av) {
    gDialogCh = -1 - (integer)llFrand(1000000.0);
    gDialogHandle = llListen(gDialogCh, "", av, "");
    gDialogMode = "main";
    gDialogTick = 30;
    string spotInfo = "";
    if (gSpotId > 0) spotInfo = "\nLinked: " + gSpotName;
    llDialog(av, "\n🏆 Leaderboard\n\n" + gScopeName + " | " + gMetricName + " | " + gWaterName + spotInfo,
             ["Scope", "Metric", "Water", "Spot", "Refresh", "Close"], gDialogCh);
}

default {
    state_entry() {
        gRegion = llGetRegionName();
        gGridName = osGetGridName();
        gEntries = [];
        drawLeaderboard();
        llSetText("", ZERO_VECTOR, 0.0);
        llListen(CH_SPOT_TO_HUD, "", NULL_KEY, "");  // Listen for nearby spots
        // Ping for spots by sending on spot channel
        llRegionSay(CH_SPOT_TO_HUD, "LEADERBOARD_PING");
        fetchLeaderboard();
        llSetTimerEvent(300.0);
    }

    touch_start(integer n) { showMainMenu(llDetectedKey(0)); }

    listen(integer ch, string name, key id, string msg) {
        // Listen for SPOT broadcasts from fishing spots
        if (ch == CH_SPOT_TO_HUD) {
            list parts = llParseString2List(msg, ["|"], []);
            if (llList2String(parts, 0) == "SPOT") {
                integer sid = (integer)llList2String(parts, 1);
                string sname = llList2String(parts, 2);
                // Add to nearby spots if not already there
                integer idx = llListFindList(gNearbySpots, [(string)sid]);
                if (idx == -1) {
                    gNearbySpots += [(string)sid, sname];
                    // Keep max 8 spots (16 entries in strided list)
                    if (llGetListLength(gNearbySpots) > 16) {
                        gNearbySpots = llList2List(gNearbySpots, 2, -1);
                    }
                }
            }
            return;
        }

        if (ch != gDialogCh) return;
        gDialogTick = 0;

        if (gDialogMode == "main") {
            if (msg == "Scope") {
                gDialogMode = "scope"; gDialogTick = 30;
                llDialog(id, "Select scope:", ["World", "Grid", "Sim", "Back"], gDialogCh);
            } else if (msg == "Metric") {
                gDialogMode = "metric"; gDialogTick = 30;
                llDialog(id, "Rank by:", ["Heaviest", "Most Caught", "Back"], gDialogCh);
            } else if (msg == "Water") {
                gDialogMode = "water"; gDialogTick = 30;
                llDialog(id, "Filter by water:", ["All Waters", "Pond", "River", "Lake", "Ocean", "Back"], gDialogCh);
            } else if (msg == "Spot") {
                gDialogMode = "spot"; gDialogTick = 30;
                // Build spot list for dialog
                list buttons = ["All Spots"];
                integer i;
                integer count = llGetListLength(gNearbySpots) / 2;
                for (i = 0; i < count && i < 8; i++) {
                    string sn = llList2String(gNearbySpots, i * 2 + 1);
                    if (llStringLength(sn) > 20) sn = llGetSubString(sn, 0, 19);
                    buttons += [sn];
                }
                buttons += ["Back"];
                string info = "Link to a fishing spot:";
                if (count == 0) info += "\n\nNo spots found nearby. Place the leaderboard near a fishing spot.";
                llDialog(id, info, buttons, gDialogCh);
            } else if (msg == "Refresh") {
                fetchLeaderboard(); llListenRemove(gDialogHandle); gDialogHandle = 0;
            } else { llListenRemove(gDialogHandle); gDialogHandle = 0; }
        }
        else if (gDialogMode == "scope") {
            if (msg == "World") { gScope = "world"; gScopeName = "World"; }
            else if (msg == "Grid") { gScope = "grid"; gScopeName = "Grid"; }
            else if (msg == "Sim") { gScope = "sim"; gScopeName = "Sim"; }
            else if (msg == "Back") { showMainMenu(id); return; }
            fetchLeaderboard(); llListenRemove(gDialogHandle); gDialogHandle = 0;
        }
        else if (gDialogMode == "metric") {
            if (msg == "Heaviest") { gMetric = "weight"; gMetricName = "Heaviest Catch"; }
            else if (msg == "Most Caught") { gMetric = "catches"; gMetricName = "Most Caught"; }
            else if (msg == "Back") { showMainMenu(id); return; }
            fetchLeaderboard(); llListenRemove(gDialogHandle); gDialogHandle = 0;
        }
        else if (gDialogMode == "water") {
            if (msg == "All Waters") { gWaterType = ""; gWaterName = "All Waters"; }
            else if (msg == "Pond") { gWaterType = "pond"; gWaterName = "Pond"; }
            else if (msg == "River") { gWaterType = "river"; gWaterName = "River"; }
            else if (msg == "Lake") { gWaterType = "lake"; gWaterName = "Lake"; }
            else if (msg == "Ocean") { gWaterType = "ocean"; gWaterName = "Ocean"; }
            else if (msg == "Back") { showMainMenu(id); return; }
            fetchLeaderboard(); llListenRemove(gDialogHandle); gDialogHandle = 0;
        }
        else if (gDialogMode == "spot") {
            if (msg == "All Spots") {
                gSpotId = 0; gSpotName = "";
            } else if (msg == "Back") {
                showMainMenu(id); return;
            } else {
                // Find spot by name
                integer i;
                integer count = llGetListLength(gNearbySpots) / 2;
                for (i = 0; i < count; i++) {
                    string sn = llList2String(gNearbySpots, i * 2 + 1);
                    if (llStringLength(sn) > 20) sn = llGetSubString(sn, 0, 19);
                    if (sn == msg) {
                        gSpotId = (integer)llList2String(gNearbySpots, i * 2);
                        gSpotName = llList2String(gNearbySpots, i * 2 + 1);
                        jump spot_found;
                    }
                }
                @spot_found;
            }
            fetchLeaderboard(); llListenRemove(gDialogHandle); gDialogHandle = 0;
        }
    }

    http_response(key req, integer status, list meta, string body) {
        if (req != gHttpReq) return;
        gEntries = [];

        if (status != 200) { drawLeaderboard(); return; }

        string lbJson = llJsonGetValue(body, ["leaderboard"]);
        if (lbJson == JSON_INVALID || lbJson == "[]") { drawLeaderboard(); return; }

        integer i = 0;
        while (i < 10) {
            string entry = llJsonGetValue(lbJson, [i]);
            if (entry == JSON_INVALID) jump pdone;

            string pn = llJsonGetValue(entry, ["display_name"]);
            if (pn == JSON_INVALID) pn = llJsonGetValue(entry, ["username"]);
            if (pn == JSON_INVALID) pn = "???";

            string val; string det;
            if (gMetric == "weight") {
                float w = (float)llJsonGetValue(entry, ["weight"]);
                val = fmtWeight(w) + " lb";
                det = llJsonGetValue(entry, ["fish_name"]);
                if (det == JSON_INVALID) det = "";
            } else {
                val = llJsonGetValue(entry, ["total_catches"]);
                if (val == JSON_INVALID) val = "0";
                val += " fish";
                float bw = (float)llJsonGetValue(entry, ["best_weight"]);
                det = fmtWeight(bw) + " lb";
            }
            gEntries += [pn + "|" + val + "|" + det];
            i++;
        }
        @pdone;
        drawLeaderboard();
        llSetTimerEvent(300.0);
    }

    timer() {
        if (gDialogTick > 0) {
            gDialogTick--;
            if (gDialogTick == 0 && gDialogHandle != 0) {
                llListenRemove(gDialogHandle); gDialogHandle = 0;
            }
        }
        // Skip refresh if region is empty
        if (llGetListLength(llGetAgentList(AGENT_LIST_REGION, [])) == 0) return;
        fetchLeaderboard();
    }

    on_rez(integer param) { llResetScript(); }
    changed(integer change) { if (change & (CHANGED_REGION | CHANGED_OWNER)) llResetScript(); }
}
