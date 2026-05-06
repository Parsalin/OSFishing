// ============================================================
// TOURNAMENT BOARD - Live tournament leaderboard display
// ============================================================
// Set object description to: tournament_id
// Example: 1
//
// OR set to: tournament_id,spot_id  to only listen for catches
// at a specific spot (otherwise listens to whole sim).
//
// Renders on face 0 using osSetDynamicTextureData.
// Updates every 5 min + on every catch broadcast from HUDs.
// ============================================================

string  gApiUrl = "https://sp.wa.darkheartsos.net/fishing/api/";

integer gTournamentId = 0;
integer gSpotFilter   = 0;       // 0 = all sim catches trigger refresh
key     gHttpReq      = NULL_KEY;
integer gRenderFace   = 0;

// Tournament data from server
string  gTournName    = "Tournament";
string  gTournMetric  = "weight";
string  gTournStatus  = "upcoming";
integer gSecsRemain   = 0;
integer gParticipants = 0;
string  gSpotName     = "";

list    gEntries      = [];  // Each: "name|value|detail"

// Channel for catch notifications from HUDs
integer CH_TOURNAMENT = -7710007;

// Colors
string COL_BG       = "ff1a1e1a";
string COL_BG2      = "ff222822";
string COL_ACCENT   = "ffd4884e";
string COL_RED      = "ffcc4444";
string COL_GREEN    = "ff5aaa6e";
string COL_GOLD     = "ffffcc00";
string COL_SILVER   = "ffcccccc";
string COL_BRONZE   = "ffcd7f32";
string COL_TEXT     = "ffe0ddd5";
string COL_MUTED    = "ff908880";
string COL_BORDER   = "ff3a423a";
string COL_TITLE_BG = "ff8b4513";  // Darker warm brown for tournament

parseConfig() {
    string desc = llGetObjectDesc();
    list parts = llParseString2List(desc, [","], []);
    gTournamentId = (integer)llList2String(parts, 0);
    if (llGetListLength(parts) > 1) {
        gSpotFilter = (integer)llList2String(parts, 1);
    }
}

fetchData() {
    if (gTournamentId <= 0) return;
    string body = "action=tournament_leaderboard&tournament_id=" + (string)gTournamentId;
    gHttpReq = llHTTPRequest(gApiUrl, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 8192], body);
}

string formatTime(integer secs) {
    if (secs <= 0) return "ENDED";
    integer h = secs / 3600;
    integer m = (secs % 3600) / 60;
    integer s = secs % 60;
    if (h > 0) return (string)h + "h " + (string)m + "m";
    if (m > 0) return (string)m + "m " + (string)s + "s";
    return (string)s + "s";
}

drawBoard() {
    integer W = 512;
    integer H = 512;
    string d = "";

    // Background
    d += "PenColour " + COL_BG + ";MoveTo 0,0;FillRectangle 512,512;";

    // ── Title bar (taller, more prominent) ──
    d += "PenColour " + COL_TITLE_BG + ";MoveTo 0,0;FillRectangle 512,74;";

    // Accent stripe at top
    d += "PenColour " + COL_ACCENT + ";MoveTo 0,0;FillRectangle 512,4;";

    // Tournament icon (trophy with star)
    d += "PenColour ffffffff;PenSize 2;";
    d += "MoveTo 16,12;LineTo 16,38;LineTo 46,38;LineTo 46,12;LineTo 16,12;";
    d += "MoveTo 12,12;LineTo 50,12;";
    d += "MoveTo 12,16;LineTo 6,16;LineTo 6,30;LineTo 12,30;";
    d += "MoveTo 50,16;LineTo 56,16;LineTo 56,30;LineTo 50,30;";
    d += "MoveTo 26,38;LineTo 26,44;";
    d += "MoveTo 36,38;LineTo 36,44;";
    d += "MoveTo 22,44;LineTo 40,44;";
    // Star on cup
    d += "PenSize 1;MoveTo 31,18;LineTo 33,24;LineTo 39,24;LineTo 34,28;LineTo 36,34;LineTo 31,30;LineTo 26,34;LineTo 28,28;LineTo 23,24;LineTo 29,24;LineTo 31,18;";

    // Tournament name
    string displayName = gTournName;
    if (llStringLength(displayName) > 28) displayName = llGetSubString(displayName, 0, 27) + "..";
    d += "PenColour ffffffff;FontName Arial;FontSize 16;";
    d += "MoveTo 64,8;Text " + displayName + ";";

    // Status + timer
    string statusText = "";
    string statusCol = COL_GREEN;
    if (gTournStatus == "active") {
        statusText = "LIVE  |  " + formatTime(gSecsRemain) + " remaining";
        statusCol = COL_GREEN;
    } else if (gTournStatus == "upcoming") {
        statusText = "STARTING SOON";
        statusCol = COL_ACCENT;
    } else {
        statusText = "TOURNAMENT ENDED";
        statusCol = COL_RED;
    }
    d += "PenColour " + statusCol + ";FontSize 11;";
    d += "MoveTo 64,30;Text " + statusText + ";";

    // Metric + participants
    string metricLabel = "Heaviest Catch";
    if (gTournMetric == "catches") metricLabel = "Most Caught";
    string infoLine = metricLabel;
    if (gSpotName != "") infoLine += "  |  " + gSpotName;
    infoLine += "  |  " + (string)gParticipants + " anglers";
    d += "PenColour " + COL_MUTED + ";FontSize 9;";
    d += "MoveTo 64,50;Text " + infoLine + ";";

    // ── LIVE indicator (pulsing dot effect) ──
    if (gTournStatus == "active") {
        d += "PenColour " + COL_GREEN + ";PenSize 1;";
        d += "MoveTo 480,14;FillEllipse 16,16;";
        d += "PenColour ffffffff;FontSize 8;";
        d += "MoveTo 476,32;Text LIVE;";
    }

    // Column headers
    d += "PenColour " + COL_MUTED + ";FontSize 9;";
    d += "MoveTo 16,82;Text #;";
    d += "MoveTo 46,82;Text ANGLER;";
    if (gTournMetric == "weight") {
        d += "MoveTo 310,82;Text WEIGHT;";
        d += "MoveTo 410,82;Text SPECIES;";
    } else {
        d += "MoveTo 310,82;Text TOTAL;";
        d += "MoveTo 410,82;Text BEST;";
    }

    // Header divider
    d += "PenColour " + COL_ACCENT + ";PenSize 1;MoveTo 10,96;LineTo 502,96;";

    // Rows
    integer count = llGetListLength(gEntries);
    integer i;
    integer rowY = 100;
    integer rowH = 38;

    for (i = 0; i < 10; i++) {
        integer y = rowY + i * rowH;

        if (i % 2 == 1) {
            d += "PenColour " + COL_BG2 + ";MoveTo 0," + (string)y + ";FillRectangle 512," + (string)rowH + ";";
        }

        if (i >= count) {
            d += "PenColour " + COL_MUTED + ";FontSize 11;MoveTo 46," + (string)(y + 10) + ";Text —;";
        } else {
            string entry = llList2String(gEntries, i);
            list parts = llParseString2List(entry, ["|"], []);
            string pName   = llList2String(parts, 0);
            string pValue  = llList2String(parts, 1);
            string pDetail = llList2String(parts, 2);

            string rc = COL_TEXT;
            if (i == 0) rc = COL_GOLD;
            else if (i == 1) rc = COL_SILVER;
            else if (i == 2) rc = COL_BRONZE;

            d += "PenColour " + rc + ";FontSize 13;FontName Arial;";
            d += "MoveTo 18," + (string)(y + 9) + ";Text " + (string)(i + 1) + ";";

            if (llStringLength(pName) > 20) pName = llGetSubString(pName, 0, 19) + "..";
            d += "PenColour " + COL_TEXT + ";FontSize 12;";
            d += "MoveTo 46," + (string)(y + 10) + ";Text " + pName + ";";

            d += "PenColour " + COL_ACCENT + ";FontSize 12;";
            d += "MoveTo 310," + (string)(y + 10) + ";Text " + pValue + ";";

            if (pDetail != "") {
                if (llStringLength(pDetail) > 12) pDetail = llGetSubString(pDetail, 0, 11) + "..";
                d += "PenColour " + COL_MUTED + ";FontSize 9;";
                d += "MoveTo 410," + (string)(y + 12) + ";Text " + pDetail + ";";
            }
        }
    }

    // Bottom area
    integer bY = rowY + 10 * rowH;
    d += "PenColour " + COL_ACCENT + ";PenSize 1;MoveTo 10," + (string)bY + ";LineTo 502," + (string)bY + ";";

    // Bottom accent stripe
    d += "PenColour " + COL_ACCENT + ";MoveTo 0,508;FillRectangle 512,4;";

    // Border
    d += "PenColour " + COL_TITLE_BG + ";PenSize 2;MoveTo 1,1;Rectangle 510,510;";

    osSetDynamicTextureDataBlendFace("", "vector", d, "width:512,height:512", 0, 0, 0, 255, gRenderFace);
}

default {
    state_entry() {
        parseConfig();
        if (gTournamentId <= 0) {
            llSetText("Set description to tournament ID", <1,0.3,0.3>, 1.0);
            return;
        }
        llSetText("", ZERO_VECTOR, 0.0);
        llListen(CH_TOURNAMENT, "", NULL_KEY, "");
        gEntries = [];
        drawBoard();
        fetchData();
        llSetTimerEvent(300.0);  // 5 min refresh
    }

    listen(integer ch, string name, key id, string msg) {
        if (ch != CH_TOURNAMENT) return;
        // Format: CATCH|spot_id
        list parts = llParseString2List(msg, ["|"], []);
        if (llList2String(parts, 0) != "CATCH") return;

        // If we're filtering by spot, only refresh if it matches
        if (gSpotFilter > 0) {
            integer catchSpot = (integer)llList2String(parts, 1);
            if (catchSpot != gSpotFilter) return;
        }

        // Someone caught a fish — refresh the board
        if (gTournStatus == "active") {
            fetchData();
        }
    }

    http_response(key req, integer status, list meta, string body) {
        if (req != gHttpReq) return;
        gEntries = [];

        if (status != 200) { drawBoard(); return; }

        // Parse tournament info
        string tJson = llJsonGetValue(body, ["tournament"]);
        if (tJson != JSON_INVALID) {
            gTournName    = llJsonGetValue(tJson, ["name"]);
            gTournMetric  = llJsonGetValue(tJson, ["metric"]);
            gTournStatus  = llJsonGetValue(tJson, ["status"]);
            gSecsRemain   = (integer)llJsonGetValue(tJson, ["seconds_remaining"]);
            gParticipants = (integer)llJsonGetValue(tJson, ["participants"]);
            string sn     = llJsonGetValue(tJson, ["spot_name"]);
            gSpotName     = (sn != JSON_INVALID && sn != JSON_NULL) ? sn : "";
        }

        // Parse leaderboard
        string lbJson = llJsonGetValue(body, ["leaderboard"]);
        if (lbJson != JSON_INVALID && lbJson != "[]") {
            integer i = 0;
            while (i < 10) {
                string entry = llJsonGetValue(lbJson, [i]);
                if (entry == JSON_INVALID) jump pdone;

                string pn = llJsonGetValue(entry, ["display_name"]);
                if (pn == JSON_INVALID) pn = llJsonGetValue(entry, ["username"]);
                if (pn == JSON_INVALID) pn = "???";

                string val; string det;
                if (gTournMetric == "weight") {
                    float w = (float)llJsonGetValue(entry, ["weight"]);
                    val = (string)w + " lb";
                    det = llJsonGetValue(entry, ["fish_name"]);
                    if (det == JSON_INVALID) det = "";
                } else {
                    val = llJsonGetValue(entry, ["total_catches"]);
                    if (val == JSON_INVALID) val = "0";
                    val += " fish";
                    float bw = (float)llJsonGetValue(entry, ["best_weight"]);
                    det = (string)bw + "lb";
                }
                gEntries += [pn + "|" + val + "|" + det];
                i++;
            }
            @pdone;
        }

        drawBoard();

        // Adjust timer based on status
        if (gTournStatus == "active") {
            llSetTimerEvent(300.0);  // Keep refreshing
        } else if (gTournStatus == "ended") {
            llSetTimerEvent(0.0);    // Stop refreshing when ended
            drawBoard();             // Final render
        }
    }

    timer() {
        // Decrement timer display
        if (gTournStatus == "active") {
            gSecsRemain -= 300;
            if (gSecsRemain < 0) gSecsRemain = 0;
        }
        // Skip refresh if region is empty
        if (llGetListLength(llGetAgentList(AGENT_LIST_REGION, [])) == 0) return;
        fetchData();
    }

    on_rez(integer param) { llResetScript(); }
    changed(integer change) { if (change & CHANGED_OWNER) llResetScript(); }
}
