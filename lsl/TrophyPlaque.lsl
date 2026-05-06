// ============================================================
// TROPHY PLAQUE - WORKING / CALIBRATED VERSION
// ============================================================
// Owner clicks → picks from their saved fish collection
// Plaque rezzes the appropriate fish mesh (fish_[species] or fish_default)
// Positions fish in front of plaque, scales by weight
// Draws display panel on Trophy_Text child prim face 4 via link message
// Remembers last displayed fish via LinksetData
//
// CALIBRATION NOTES:
//  Position offset: localOffset = <-0.10, 0.0, 0.039>
//  Fish rotation: <0, 0, -PI/2> in plaque local space
//  Fish scale: lerps between min <0.26011, 0.03789, 0.09269>
//                          and max <1.32147, 0.19250, 0.47089>
// ============================================================

string gApiUrl = "https://sp.wa.darkheartsos.net/fishing/api/";

// ── Display state ──
string  gFishName    = "";
float   gFishWeight  = 0.0;
string  gRarity      = "";
string  gCaughtAt    = "";
string  gCaughtDate  = "";
string  gBaitName    = "";
string  gNote        = "";
float   gMinWeight   = 0.0;
float   gMaxWeight   = 10.0;
string  gLastScaleStr = "";

// ── Rezzed fish ──
key     gFishKey     = NULL_KEY;
integer gFishHandle  = 0;

// ── Dialog ──
key     gHttpReq     = NULL_KEY;
string  gHttpAction  = "";
integer gDialogCh    = 0;
integer gDialogHandle = 0;
string  gMenuStep    = "";
key     gMenuPlayer  = NULL_KEY;
list    gFishIds     = [];
list    gFishLabels  = [];
integer gPageOffset  = 0;
list    gFishData    = [];

// ── LinksetData ──
string LD_FISH_NAME  = "tp_name";
string LD_FISH_WT    = "tp_weight";
string LD_RARITY     = "tp_rarity";
string LD_CAUGHT_AT  = "tp_caught_at";
string LD_DATE       = "tp_date";
string LD_BAIT       = "tp_bait";
string LD_NOTE       = "tp_note";
string LD_MIN_W      = "tp_min_w";
string LD_MAX_W      = "tp_max_w";

integer gTextLink = LINK_THIS;

findTextPrim() {
    integer n = llGetNumberOfPrims();
    integer i;
    for (i = 1; i <= n; i++) {
        if (llGetLinkName(i) == "Trophy_Text") {
            gTextLink = i;
            return;
        }
    }
    llOwnerSay("⚠️ No child prim named 'Trophy_Text' found. Add one and reset.");
}

string COL_BG     = "ff1a1e1a";
string COL_ACCENT = "ffd4884e";
string COL_TEXT   = "ffe0ddd5";
string COL_MUTED  = "ff908880";
string COL_BORDER = "ff3a423a";
string COL_COMMON = "ff9a9a9a";
string COL_UNCOMMON = "ff1eff00";
string COL_RARE   = "ff0070ff";
string COL_EPIC   = "ffa335ee";
string COL_LEGEND = "ffff8000";

savePlaque() {
    llLinksetDataWrite(LD_FISH_NAME, gFishName);
    llLinksetDataWrite(LD_FISH_WT, (string)gFishWeight);
    llLinksetDataWrite(LD_RARITY, gRarity);
    llLinksetDataWrite(LD_CAUGHT_AT, gCaughtAt);
    llLinksetDataWrite(LD_DATE, gCaughtDate);
    llLinksetDataWrite(LD_BAIT, gBaitName);
    llLinksetDataWrite(LD_NOTE, gNote);
    llLinksetDataWrite(LD_MIN_W, (string)gMinWeight);
    llLinksetDataWrite(LD_MAX_W, (string)gMaxWeight);
}

integer loadPlaque() {
    gFishName   = llLinksetDataRead(LD_FISH_NAME);
    if (gFishName == "") return FALSE;
    gFishWeight = (float)llLinksetDataRead(LD_FISH_WT);
    gRarity     = llLinksetDataRead(LD_RARITY);
    gCaughtAt   = llLinksetDataRead(LD_CAUGHT_AT);
    gCaughtDate = llLinksetDataRead(LD_DATE);
    gBaitName   = llLinksetDataRead(LD_BAIT);
    gNote       = llLinksetDataRead(LD_NOTE);
    gMinWeight  = (float)llLinksetDataRead(LD_MIN_W);
    gMaxWeight  = (float)llLinksetDataRead(LD_MAX_W);
    return TRUE;
}

string rarityColor() {
    string r = llToLower(gRarity);
    if (r == "legendary") return COL_LEGEND;
    if (r == "epic")      return COL_EPIC;
    if (r == "rare")      return COL_RARE;
    if (r == "uncommon")  return COL_UNCOMMON;
    return COL_COMMON;
}

renderPlaque() {
    string d = "";
    d += "PenColour " + COL_BG + ";MoveTo 0,0;FillRectangle 512,512;";

    if (gFishName == "") {
        d += "PenColour " + COL_ACCENT + ";MoveTo 0,0;FillRectangle 512,60;";
        d += "PenColour ffffffff;FontName Arial;FontSize 18;MoveTo 16,14;Text TROPHY PLAQUE;";
        d += "PenColour " + COL_MUTED + ";FontSize 14;MoveTo 80,260;Text Touch to display a fish;";
    } else {
        d += "PenColour " + COL_ACCENT + ";MoveTo 0,0;FillRectangle 512,60;";
        d += "PenColour ffffffff;FontName Arial;FontSize 14;MoveTo 16,10;Text TROPHY;";
        d += "PenColour " + rarityColor() + ";FontSize 13;MoveTo 180,12;Text " + llToUpper(gRarity) + ";";

        d += "PenColour " + COL_TEXT + ";FontName Arial;FontSize 26;MoveTo 16,72;Text " + gFishName + ";";

        integer cents = (integer)(gFishWeight * 100.0 + 0.5);
        integer whole = cents / 100;
        integer dec = cents % 100;
        string ds = (string)dec;
        if (dec < 10) ds = "0" + ds;
        string wtStr = (string)whole + "." + ds + " lb";
        d += "PenColour " + COL_ACCENT + ";FontSize 30;MoveTo 16,108;Text " + wtStr + ";";

        d += "PenColour " + COL_BORDER + ";PenSize 1;MoveTo 10,152;LineTo 502,152;";

        d += "PenColour " + COL_MUTED + ";FontSize 13;MoveTo 16,164;Text Caught at:;";
        string region = gCaughtAt;
        if (llStringLength(region) > 26) region = llGetSubString(region, 0, 25) + "..";
        d += "PenColour " + COL_TEXT + ";FontSize 13;MoveTo 130,164;Text " + region + ";";

        d += "PenColour " + COL_MUTED + ";FontSize 13;MoveTo 16,192;Text Date:;";
        d += "PenColour " + COL_TEXT + ";FontSize 13;MoveTo 130,192;Text " + gCaughtDate + ";";

        if (gBaitName != "" && gBaitName != JSON_INVALID) {
            d += "PenColour " + COL_MUTED + ";FontSize 13;MoveTo 16,220;Text Bait used:;";
            string bn = gBaitName;
            if (llStringLength(bn) > 22) bn = llGetSubString(bn, 0, 21) + "..";
            d += "PenColour " + COL_TEXT + ";FontSize 13;MoveTo 130,220;Text " + bn + ";";
        }

        if (gNote != "" && gNote != JSON_INVALID) {
            d += "PenColour " + COL_BORDER + ";PenSize 1;MoveTo 10,254;LineTo 502,254;";
            d += "PenColour " + COL_MUTED + ";FontSize 12;MoveTo 16,264;Text " + llGetSubString(gNote, 0, 55) + ";";
        }
    }

    llMessageLinked(gTextLink, 4, d, NULL_KEY);
}

rezFish() {
    if (gFishName == "") return;

    if (gFishKey != NULL_KEY) {
        llRegionSayTo(gFishKey, 0, "PLAQUE_REMOVE");
        gFishKey = NULL_KEY;
    }

    // Scale based on weight in species range
    float range = gMaxWeight - gMinWeight;
    float pct = 0.5;
    if (range > 0) pct = (gFishWeight - gMinWeight) / range;
    if (pct < 0.0) pct = 0.0;
    if (pct > 1.0) pct = 1.0;

    vector minScale = <0.26011, 0.03789, 0.09269>;
    vector maxScale = <1.32147, 0.19250, 0.47089>;
    vector fishScale = minScale + (maxScale - minScale) * pct;

    string scaleStr = (string)fishScale.x + "," + (string)fishScale.y + "," + (string)fishScale.z;

    string objName = "fish_" + gFishName;
    if (llGetInventoryType(objName) != INVENTORY_OBJECT) {
        objName = "fish_default";
    }
    if (llGetInventoryType(objName) != INVENTORY_OBJECT) {
        llOwnerSay("No fish mesh found. Add 'fish_default' or 'fish_" + gFishName + "' to this prim's inventory.");
        return;
    }

    vector myPos = llGetPos();
    rotation myRot = llGetRot();

    // Calibrated rotation: -90 degrees on Z in plaque local space
    rotation localFishRot = llEuler2Rot(<0.0, 0.0, -PI_BY_TWO>);
    rotation fishRot = localFishRot * myRot;

    // Calibrated position offset in plaque LOCAL space
    vector localOffset = <-0.10, 0.0, 0.039>;
    vector rezPos = myPos + (localOffset * myRot);

    if (gFishHandle) { llListenRemove(gFishHandle); gFishHandle = 0; }
    gFishHandle = llListen(-7720001, "", NULL_KEY, "");

    integer startParam = (integer)(pct * 1000);

    llRezObject(objName, rezPos, ZERO_VECTOR, fishRot, startParam);

    gLastScaleStr = scaleStr;
}

showFishMenu(key who) {
    cleanupDialog();
    gMenuStep = "pick_fish";
    gMenuPlayer = who;

    integer total = llGetListLength(gFishIds);
    integer end = gPageOffset + 8;
    if (end > total) end = total;

    list buttons = [];
    integer i;
    for (i = gPageOffset; i < end; i++) {
        buttons += [llList2String(gFishLabels, i)];
    }
    if (gPageOffset > 0) buttons += ["< Prev"];
    if (end < total) buttons += ["Next >"];
    buttons += ["Clear", "Cancel"];

    gDialogCh = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", who, "");
    llDialog(who, "🏆 Select a trophy fish to display:\n(" + (string)total + " saved)", buttons, gDialogCh);
}

cleanupDialog() {
    if (gDialogHandle) { llListenRemove(gDialogHandle); gDialogHandle = 0; }
}

default {
    state_entry() {
        findTextPrim();
        if (loadPlaque()) {
            renderPlaque();
            rezFish();
        } else {
            renderPlaque();
        }
    }

    touch_start(integer n) {
        key who = llDetectedKey(0);
        if (who != llGetOwner()) {
            llRegionSayTo(who, 0, "🏆 " + gFishName + (gFishName != "" ? " — " + (string)gFishWeight + " lb " + gRarity : " (empty plaque)"));
            return;
        }

        gMenuPlayer = who;
        gPageOffset = 0;
        llRegionSayTo(who, 0, "Loading your trophy fish...");
        gHttpAction = "list";
        gHttpReq = llHTTPRequest(gApiUrl, [
            HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 8192
        ], "action=trophy_for_plaque&uuid=" + llEscapeURL((string)llGetOwner()));
    }

    listen(integer ch, string name, key id, string msg) {
        if (ch == -7720001 && msg == "FISH_READY") {
            gFishKey = id;
            if (gFishHandle) { llListenRemove(gFishHandle); gFishHandle = 0; }
            if (gLastScaleStr != "") {
                llRegionSayTo(gFishKey, -7720002, "FISH_SCALE|" + gLastScaleStr);
                gLastScaleStr = "";
            }
            return;
        }

        if (id != gMenuPlayer) return;

        if (gMenuStep == "pick_fish") {
            cleanupDialog();
            if (msg == "Cancel") return;
            if (msg == "Clear") {
                if (gFishKey != NULL_KEY) {
                    llRegionSayTo(gFishKey, 0, "PLAQUE_REMOVE");
                    gFishKey = NULL_KEY;
                }
                gFishName = ""; gFishWeight = 0.0;
                llLinksetDataDelete(LD_FISH_NAME);
                renderPlaque();
                return;
            }
            if (msg == "< Prev") { gPageOffset -= 8; if (gPageOffset < 0) gPageOffset = 0; showFishMenu(id); return; }
            if (msg == "Next >") { gPageOffset += 8; showFishMenu(id); return; }

            integer idx = llListFindList(gFishLabels, [msg]);
            if (idx == -1) return;

            integer base = idx * 10;
            gFishName   = llList2String(gFishData, base + 1);
            gFishWeight = (float)llList2String(gFishData, base + 2);
            gRarity     = llList2String(gFishData, base + 3);
            gCaughtAt   = llList2String(gFishData, base + 4);
            gCaughtDate = llList2String(gFishData, base + 5);
            gBaitName   = llList2String(gFishData, base + 6);
            gNote       = llList2String(gFishData, base + 7);
            gMinWeight  = (float)llList2String(gFishData, base + 8);
            gMaxWeight  = (float)llList2String(gFishData, base + 9);

            savePlaque();
            renderPlaque();
            rezFish();
        }
    }

    http_response(key req, integer status, list meta, string body) {
        if (req != gHttpReq) return;

        string ok = llJsonGetValue(body, ["success"]);
        if (ok != "true" && ok != "1" && ok != JSON_TRUE) {
            string err = llJsonGetValue(body, ["error"]);
            llOwnerSay("Plaque error: " + err);
            return;
        }

        if (gHttpAction == "list") {
            string fishJson = llJsonGetValue(body, ["saved_fish"]);
            gFishIds = [];
            gFishLabels = [];
            gFishData = [];

            integer i = 0;
            while (i < 50) {
                string entry = llJsonGetValue(fishJson, [i]);
                if (entry == JSON_INVALID) jump fish_done;

                string savedId   = llJsonGetValue(entry, ["saved_id"]);
                string species   = llJsonGetValue(entry, ["species_name"]);
                string weight    = llJsonGetValue(entry, ["weight"]);
                string rarity    = llJsonGetValue(entry, ["rarity_name"]);
                string region    = llJsonGetValue(entry, ["caught_region"]);
                string date      = llJsonGetValue(entry, ["caught_at"]);
                string bait      = llJsonGetValue(entry, ["bait_name"]);
                string note      = llJsonGetValue(entry, ["note"]);
                string minW      = llJsonGetValue(entry, ["min_weight"]);
                string maxW      = llJsonGetValue(entry, ["max_weight"]);

                if (date != JSON_INVALID && llStringLength(date) >= 10) date = llGetSubString(date, 0, 9);

                float w = (float)weight;
                integer wc = (integer)(w * 100.0 + 0.5);
                string wStr = (string)(wc/100) + "." + (wc%100<10?"0":"") + (string)(wc%100);
                string label = species;
                if (llStringLength(label) > 16) label = llGetSubString(label, 0, 15);
                label += " " + wStr;

                gFishIds    += [savedId];
                gFishLabels += [label];
                gFishData   += [savedId, species, weight, rarity, region, date, bait, note, minW, maxW];
                i++;
            }
            @fish_done;

            if (llGetListLength(gFishIds) == 0) {
                llRegionSayTo(gMenuPlayer, 0, "No trophy fish saved. Visit the web portal → Trophy page to save fish.");
                return;
            }

            showFishMenu(gMenuPlayer);
        }
    }

    object_rez(key id) {
        llSleep(0.5);
    }

    on_rez(integer p) {
        if (gFishKey != NULL_KEY) {
            llRegionSayTo(gFishKey, 0, "PLAQUE_REMOVE");
            gFishKey = NULL_KEY;
        }
        llResetScript();
    }

    changed(integer change) {
        if (change & CHANGED_OWNER) {
            gFishName = "";
            llLinksetDataDelete(LD_FISH_NAME);
            if (gFishKey != NULL_KEY) {
                llRegionSayTo(gFishKey, 0, "PLAQUE_REMOVE");
                gFishKey = NULL_KEY;
            }
            llResetScript();
        }
    }
}
