// ============================================================
// SHOP REGISTER - Marks a shop location on the grid map
// ============================================================
// Owner touches to set up. Registers with server.
// Uses LinksetData for persistence.
// ============================================================

string gApiUrl = "https://osfishing.flamesfall.net/api/";

integer gShopId     = 0;
string  gShopName   = "";
integer gIsSystem   = FALSE;
integer gSetupDone  = FALSE;

key     gHttpReq    = NULL_KEY;
string  gHttpAction = "";
key     gSetupPlayer = NULL_KEY;
integer gDialogCh   = 0;
integer gDialogHandle = 0;
string  gSetupStep  = "";
integer gTextCh     = 0;
integer gTextHandle = 0;

string LD_SHOP_ID    = "shop_id";
string LD_SHOP_NAME  = "shop_name";
string LD_SHOP_OWNER = "shop_owner";
string LD_SHOP_DONE  = "shop_done";

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

saveShopData() {
    llLinksetDataWrite(LD_SHOP_ID, (string)gShopId);
    llLinksetDataWrite(LD_SHOP_NAME, gShopName);
    llLinksetDataWrite(LD_SHOP_OWNER, (string)llGetOwner());
    llLinksetDataWrite(LD_SHOP_DONE, "1");
}

integer loadShopData() {
    string savedOwner = llLinksetDataRead(LD_SHOP_OWNER);
    if (savedOwner == "" || savedOwner != (string)llGetOwner()) {
        llLinksetDataDelete(LD_SHOP_ID);
        llLinksetDataDelete(LD_SHOP_NAME);
        llLinksetDataDelete(LD_SHOP_OWNER);
        llLinksetDataDelete(LD_SHOP_DONE);
        return FALSE;
    }
    string sid = llLinksetDataRead(LD_SHOP_ID);
    if (sid == "" || (integer)sid <= 0) return FALSE;
    if (llLinksetDataRead(LD_SHOP_DONE) != "1") return FALSE;
    gShopId = (integer)sid;
    gShopName = llLinksetDataRead(LD_SHOP_NAME);
    return TRUE;
}

registerShop() {
    vector pos = llGetPos();
    vector regionCorner = llGetRegionCorner();
    string body = "action=shop_register" +
        "&name=" + llEscapeURL(gShopName) +
        "&region=" + llEscapeURL(llGetRegionName()) +
        "&grid_name=" + llEscapeURL(detectGrid()) +
        "&pos_x=" + (string)pos.x + "&pos_y=" + (string)pos.y + "&pos_z=" + (string)pos.z +
        "&owner_key=" + llEscapeURL((string)llGetOwner()) +
        "&is_system=" + (string)gIsSystem +
        "&region_x=" + (string)((integer)(regionCorner.x / 256.0)) +
        "&region_y=" + (string)((integer)(regionCorner.y / 256.0));

    gHttpAction = "register";
    gHttpReq = llHTTPRequest(gApiUrl, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096], body);
}

default {
    state_entry() {
        if (loadShopData()) {
            gSetupDone = TRUE;
            llSetText("🏪 " + gShopName + "\nShop #" + (string)gShopId, <0.3, 0.8, 0.9>, 1.0);
            registerShop();  // Re-sync
        } else {
            llSetText("🏪 Bait Shop\n(Touch to set up)", <1.0, 0.8, 0.3>, 1.0);
        }
    }

    touch_start(integer n) {
        key who = llDetectedKey(0);
        if (!gSetupDone) {
            if (who != llGetOwner()) { llRegionSayTo(who, 0, "Shop not set up yet."); return; }
            gSetupPlayer = who;
            gSetupStep = "name";
            gTextCh = -1 - (integer)llFrand(999999.0);
            gTextHandle = llListen(gTextCh, "", who, "");
            llTextBox(who, "🏪 Name your shop:", gTextCh);
        } else {
            llRegionSayTo(who, 0, "🏪 " + gShopName + " — Shop #" + (string)gShopId);
        }
    }

    listen(integer ch, string name, key id, string msg) {
        if (gSetupStep == "name") {
            if (gTextHandle) { llListenRemove(gTextHandle); gTextHandle = 0; }
            gShopName = msg;
            gSetupStep = "system";
            gDialogCh = -1 - (integer)llFrand(999999.0);
            gDialogHandle = llListen(gDialogCh, "", gSetupPlayer, "");
            llDialog(gSetupPlayer, "🏪 Is this a system shop?\n(System shops auto-restock)", ["Yes", "No"], gDialogCh);
            return;
        }
        if (gSetupStep == "system") {
            if (gDialogHandle) { llListenRemove(gDialogHandle); gDialogHandle = 0; }
            gIsSystem = (msg == "Yes");
            registerShop();
            return;
        }
    }

    http_response(key req, integer status, list meta, string body) {
        if (req != gHttpReq) return;
        string ok = llJsonGetValue(body, ["success"]);
        if (ok != "true" && ok != "1" && ok != JSON_TRUE) {
            string err = llJsonGetValue(body, ["error"]);
            llOwnerSay("Shop error: " + err);
            return;
        }
        gShopId = (integer)llJsonGetValue(body, ["shop_id"]);
        gSetupDone = TRUE;
        saveShopData();
        llSetText("🏪 " + gShopName + "\nShop #" + (string)gShopId, <0.3, 0.8, 0.9>, 1.0);
        llOwnerSay("✅ Shop registered: " + gShopName + " (ID " + (string)gShopId + ")");
    }

    on_rez(integer p) {
        string savedOwner = llLinksetDataRead(LD_SHOP_OWNER);
        if (savedOwner != "" && savedOwner != (string)llGetOwner()) {
            llLinksetDataDelete(LD_SHOP_ID); llLinksetDataDelete(LD_SHOP_NAME);
            llLinksetDataDelete(LD_SHOP_OWNER); llLinksetDataDelete(LD_SHOP_DONE);
        }
        llResetScript();
    }

    changed(integer change) {
        if (change & CHANGED_OWNER) {
            llLinksetDataDelete(LD_SHOP_ID); llLinksetDataDelete(LD_SHOP_NAME);
            llLinksetDataDelete(LD_SHOP_OWNER); llLinksetDataDelete(LD_SHOP_DONE);
            llResetScript();
        }
    }
}
