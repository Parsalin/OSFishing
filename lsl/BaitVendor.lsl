// ============================================================
// BAIT VENDOR v2
// ============================================================
// First click (owner): setup wizard → find nearby shop → pick bait
// Customer click: Buy / Sell (pops qty dialog)
// Owner click elsewhere: Edit / Set Price / Deposit / Withdraw
// System shop owner: Restock button available
// Display on face 4 as rendered HTML texture
// ============================================================

string gApiUrl = "https://sp.wa.darkheartsos.net/fishing/api/";

integer gShopId     = 0;
integer gBaitId     = 0;
integer gListingId  = 0;
string  gShopName   = "";
string  gBaitName   = "";
integer gStock      = 0;
integer gMaxStock   = 25;
integer gBuyPrice   = 0;
integer gSellPrice  = 0;
integer gIsSystem   = FALSE;
integer gSetupDone  = FALSE;

key     gHttpReq    = NULL_KEY;
string  gHttpAction = "";
key     gCustomer   = NULL_KEY;
integer gDialogCh   = 0;
integer gDialogHandle = 0;
integer gTextCh     = 0;
integer gTextHandle = 0;
string  gMenuStep   = "";

list    gNearbyShops = [];
list    gAvailBait   = [];

string LD_SHOP_ID = "bv_shop_id";
string LD_BAIT_ID = "bv_bait_id";
string LD_LISTING = "bv_listing_id";
string LD_OWNER   = "bv_owner";
string LD_DONE    = "bv_done";
string LD_SHOPNAME = "bv_shopname";

saveData() {
    llLinksetDataWrite(LD_SHOP_ID, (string)gShopId);
    llLinksetDataWrite(LD_BAIT_ID, (string)gBaitId);
    llLinksetDataWrite(LD_LISTING, (string)gListingId);
    llLinksetDataWrite(LD_OWNER, (string)llGetOwner());
    llLinksetDataWrite(LD_SHOPNAME, gShopName);
    llLinksetDataWrite(LD_DONE, "1");
}

integer loadData() {
    if (llLinksetDataRead(LD_OWNER) != (string)llGetOwner()) { clearData(); return FALSE; }
    if (llLinksetDataRead(LD_DONE) != "1") return FALSE;
    gShopId    = (integer)llLinksetDataRead(LD_SHOP_ID);
    gBaitId    = (integer)llLinksetDataRead(LD_BAIT_ID);
    gListingId = (integer)llLinksetDataRead(LD_LISTING);
    gShopName  = llLinksetDataRead(LD_SHOPNAME);
    return (gShopId > 0 && gBaitId > 0);
}

clearData() {
    llLinksetDataDelete(LD_SHOP_ID); llLinksetDataDelete(LD_BAIT_ID);
    llLinksetDataDelete(LD_LISTING); llLinksetDataDelete(LD_OWNER);
    llLinksetDataDelete(LD_SHOPNAME); llLinksetDataDelete(LD_DONE);
}

cleanupDialog() {
    if (gDialogHandle) { llListenRemove(gDialogHandle); gDialogHandle = 0; }
    if (gTextHandle)   { llListenRemove(gTextHandle);   gTextHandle = 0; }
}

// ── Colors matching leaderboard theme ──
string COL_BG     = "ff1a1e1a";
string COL_BG2    = "ff222822";
string COL_ACCENT = "ffd4884e";
string COL_TEXT   = "ffe0ddd5";
string COL_MUTED  = "ff908880";
string COL_BORDER = "ff3a423a";
string COL_GREEN  = "ff66bb6a";
string COL_RED    = "ffef5350";
string COL_BLUE   = "ff7ec8e3";

string gLastRenderKey = "";  // Prevent redundant redraws

renderDisplay() {
    // Build a key from current state — only redraw if something changed
    string renderKey = (string)gSetupDone + gBaitName + gShopName +
                       (string)gBuyPrice + (string)gSellPrice +
                       (string)gStock + (string)gMaxStock;
    if (renderKey == gLastRenderKey) return;
    gLastRenderKey = renderKey;
    string d = "";

    // Background
    d += "PenColour " + COL_BG + ";MoveTo 0,0;FillRectangle 512,512;";

    if (!gSetupDone) {
        // Header
        d += "PenColour " + COL_ACCENT + ";MoveTo 0,0;FillRectangle 512,70;";
        d += "PenColour ffffffff;FontName Arial;FontSize 20;MoveTo 16,16;Text BAIT VENDOR;";
        // Hint
        d += "PenColour " + COL_MUTED + ";FontSize 18;MoveTo 80,240;Text Touch to set up;";
    } else {
        // Header bar
        d += "PenColour " + COL_ACCENT + ";MoveTo 0,0;FillRectangle 512,70;";
        d += "PenColour ffffffff;FontName Arial;FontSize 18;MoveTo 16,10;Text BAIT VENDOR;";
        string shopDisplay = gShopName;
        if (llStringLength(shopDisplay) > 24) shopDisplay = llGetSubString(shopDisplay, 0, 23) + "..";
        d += "FontSize 12;MoveTo 16,42;Text " + shopDisplay + ";";

        // Bait name
        string baitDisplay = gBaitName;
        if (llStringLength(baitDisplay) > 18) baitDisplay = llGetSubString(baitDisplay, 0, 17) + "..";
        d += "PenColour " + COL_BLUE + ";FontName Arial;FontSize 30;MoveTo 16,90;Text " + baitDisplay + ";";

        // Divider
        d += "PenColour " + COL_BORDER + ";PenSize 1;MoveTo 10,138;LineTo 502,138;";

        // Buy row
        d += "PenColour " + COL_MUTED + ";FontSize 16;MoveTo 16,154;Text BUY;";
        d += "PenColour " + COL_BLUE + ";FontSize 26;MoveTo 180,148;Text " + (string)gBuyPrice + " pts;";

        // Divider
        d += "PenColour " + COL_BORDER + ";PenSize 1;MoveTo 10,200;LineTo 502,200;";

        // Sell row
        d += "PenColour " + COL_MUTED + ";FontSize 16;MoveTo 16,216;Text SELL;";
        d += "PenColour " + COL_GREEN + ";FontSize 26;MoveTo 180,210;Text " + (string)gSellPrice + " pts;";

        // Divider
        d += "PenColour " + COL_BORDER + ";PenSize 1;MoveTo 10,262;LineTo 502,262;";

        // Stock row
        string stockCol = COL_GREEN;
        string stockLabel = (string)gStock + " / " + (string)gMaxStock;
        if (gStock <= 0)  { stockCol = COL_RED;    stockLabel = "SOLD OUT"; }
        else if (gStock < 6) stockCol = COL_ACCENT;

        d += "PenColour " + COL_MUTED + ";FontSize 16;MoveTo 16,278;Text STOCK;";
        d += "PenColour " + stockCol + ";FontSize 26;MoveTo 180,272;Text " + stockLabel + ";";

        // Fake buttons at bottom
        d += "PenColour " + COL_BLUE + ";MoveTo 20,400;FillRectangle 210,56;";
        d += "PenColour " + COL_GREEN + ";MoveTo 280,400;FillRectangle 210,56;";
        d += "PenColour ff000000;FontName Arial;FontSize 20;";
        d += "MoveTo 60,416;Text BUY;";
        d += "MoveTo 340,416;Text SELL;";
    }

    osSetDynamicTextureData("", "vector", d,
        "width:512,height:512,distrib:0,alpha:255", 4);
}

fetchStatus() {
    if (gListingId <= 0) return;
    gHttpAction = "status";
    gHttpReq = llHTTPRequest(gApiUrl, [
        HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
    ], "action=shop_listing_status&listing_id=" + (string)gListingId);
}

showQtyDialog(key who, string action) {
    cleanupDialog();
    gCustomer = who;
    gMenuStep = action + "_qty";
    gDialogCh = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", who, "");
    string label = "Buy @ " + (string)gBuyPrice + " pts each";
    if (action == "sell") label = "Sell @ " + (string)gSellPrice + " pts each";
    llDialog(who, "🎣 " + gBaitName + "\n" + label + "\n\nHow many?",
             ["1", "5", "10", "25", "Cancel"], gDialogCh);
}

showCustomerMenu(key who) {
    cleanupDialog();
    gCustomer = who;
    gMenuStep = "customer";
    gDialogCh = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", who, "");
    list buttons = [];
    if (gStock > 0) buttons += ["Buy"];
    buttons += ["Sell", "Close"];
    string stockLine = "Stock: " + (string)gStock + "/" + (string)gMaxStock;
    if (gStock <= 0) stockLine = "⚠️ OUT OF STOCK — check grid map for other shops";
    llDialog(who, "🎣 " + gBaitName + "\n" + stockLine +
             "\nBuy: " + (string)gBuyPrice + " pts | Sell: " + (string)gSellPrice + " pts",
             buttons, gDialogCh);
}

showOwnerMenu(key who) {
    cleanupDialog();
    gCustomer = who;
    gMenuStep = "owner";
    gDialogCh = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", who, "");
    list buttons;
    if (gIsSystem) buttons = ["Edit Bait", "Set Price", "Restock", "Close"];
    else buttons = ["Edit Bait", "Set Price", "Deposit", "Withdraw", "Close"];
    llDialog(who, "🔧 " + gBaitName + " vendor\nShop: " + gShopName +
             "\nStock: " + (string)gStock + "/" + (string)gMaxStock,
             buttons, gDialogCh);
}

default {
    state_entry() {
        if (loadData()) {
            gSetupDone = TRUE;
            renderDisplay();
            fetchStatus();
        } else {
            gSetupDone = FALSE;
            renderDisplay();
        }
        llSetTimerEvent(300.0); // Redraw every 5 min // Refresh every 10 min
    }

    touch_start(integer n) {
        key who = llDetectedKey(0);
        integer isOwner = (who == llGetOwner());

        if (!gSetupDone) {
            if (!isOwner) { llRegionSayTo(who, 0, "This vendor isn't set up yet."); return; }
            gCustomer = who;
            gMenuStep = "";
            llRegionSayTo(who, 0, "Finding shops in this region...");
            gHttpAction = "find_shops";
            gHttpReq = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
            ], "action=shops_in_region&region=" + llEscapeURL(llGetRegionName()) +
               "&grid_name=" + llEscapeURL(osGetGridName()));
            return;
        }

        if (isOwner) { showOwnerMenu(who); return; }
        showCustomerMenu(who);
    }

    listen(integer ch, string name, key id, string msg) {
        if (id != gCustomer) return;

        if (gMenuStep == "setup_shop") {
            cleanupDialog();
            if (msg == "Cancel") return;
            integer i;
            for (i = 0; i < llGetListLength(gNearbyShops) - 1; i += 2) {
                if (llList2String(gNearbyShops, i + 1) == msg) {
                    gShopId   = (integer)llList2String(gNearbyShops, i);
                    gShopName = msg;
                    gHttpAction = "find_bait";
                    gHttpReq = llHTTPRequest(gApiUrl, [
                        HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
                    ], "action=bait_list_shop&shop_id=" + (string)gShopId);
                    return;
                }
            }
        }

        else if (gMenuStep == "setup_bait") {
            cleanupDialog();
            if (msg == "Cancel") return;
            integer i;
            for (i = 0; i < llGetListLength(gAvailBait) - 1; i += 2) {
                if (llList2String(gAvailBait, i + 1) == msg) {
                    gBaitId   = (integer)llList2String(gAvailBait, i);
                    gBaitName = msg;
                    gHttpAction = "register";
                    gHttpReq = llHTTPRequest(gApiUrl, [
                        HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
                    ], "action=shop_register_listing&shop_id=" + (string)gShopId +
                       "&item_type=bait&item_id=" + (string)gBaitId + "&max_stock=25");
                    return;
                }
            }
        }

        else if (gMenuStep == "customer") {
            cleanupDialog();
            if (msg == "Buy")  { showQtyDialog(id, "buy");  return; }
            if (msg == "Sell") { showQtyDialog(id, "sell"); return; }
        }

        else if (gMenuStep == "buy_qty") {
            cleanupDialog();
            if (msg == "Cancel") return;
            integer qty = (integer)msg;
            if (qty <= 0) return;
            gHttpAction = "shop_buy";
            gHttpReq = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
            ], "action=shop_buy&uuid=" + llEscapeURL((string)gCustomer) +
               "&listing_id=" + (string)gListingId + "&quantity=" + (string)qty);
        }

        else if (gMenuStep == "sell_qty") {
            cleanupDialog();
            if (msg == "Cancel") return;
            integer qty = (integer)msg;
            if (qty <= 0) return;
            gHttpAction = "shop_sell";
            gHttpReq = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
            ], "action=shop_sell&uuid=" + llEscapeURL((string)gCustomer) +
               "&listing_id=" + (string)gListingId + "&quantity=" + (string)qty);
        }

        else if (gMenuStep == "owner") {
            cleanupDialog();
            if (msg == "Close") return;

            if (msg == "Edit Bait") {
                gHttpAction = "find_bait";
                gMenuStep = "";
                gHttpReq = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
                ], "action=bait_list_shop&shop_id=" + (string)gShopId);
            }
            else if (msg == "Set Price") {
                gMenuStep = "set_price";
                gTextCh = -1 - (integer)llFrand(999999.0);
                gTextHandle = llListen(gTextCh, "", gCustomer, "");
                llTextBox(gCustomer, "Price modifier:\n0.9 = 10% discount\n1.0 = default price\n1.1 = 10% markup", gTextCh);
            }
            else if (msg == "Deposit") {
                gMenuStep = "deposit_qty";
                gDialogCh = -1 - (integer)llFrand(999999.0);
                gDialogHandle = llListen(gDialogCh, "", gCustomer, "");
                llDialog(gCustomer, "Deposit how much stock?", ["5", "10", "25", "Cancel"], gDialogCh);
            }
            else if (msg == "Withdraw") {
                gMenuStep = "withdraw_qty";
                gDialogCh = -1 - (integer)llFrand(999999.0);
                gDialogHandle = llListen(gDialogCh, "", gCustomer, "");
                llDialog(gCustomer, "Withdraw how much?", ["5", "10", "25", "Cancel"], gDialogCh);
            }
            else if (msg == "Restock") {
                gHttpAction = "restock";
                gHttpReq = llHTTPRequest(gApiUrl, [
                    HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
                ], "action=admin_shop_restock&listing_id=" + (string)gListingId +
                   "&uuid=" + llEscapeURL((string)gCustomer));
            }
        }

        else if (gMenuStep == "set_price") {
            if (gTextHandle) { llListenRemove(gTextHandle); gTextHandle = 0; }
            float mod = (float)msg;
            if (mod < 0.9 || mod > 1.1) { llRegionSayTo(gCustomer, 0, "Must be 0.9 – 1.1"); return; }
            gHttpAction = "set_modifier";
            gHttpReq = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
            ], "action=shop_set_modifier&uuid=" + llEscapeURL((string)gCustomer) +
               "&listing_id=" + (string)gListingId + "&modifier=" + msg);
        }

        else if (gMenuStep == "deposit_qty") {
            cleanupDialog();
            if (msg == "Cancel") return;
            gHttpAction = "deposit";
            gHttpReq = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
            ], "action=shop_deposit&uuid=" + llEscapeURL((string)gCustomer) +
               "&listing_id=" + (string)gListingId + "&quantity=" + msg);
        }

        else if (gMenuStep == "withdraw_qty") {
            cleanupDialog();
            if (msg == "Cancel") return;
            gHttpAction = "withdraw";
            gHttpReq = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded", HTTP_BODY_MAXLENGTH, 4096
            ], "action=shop_withdraw&uuid=" + llEscapeURL((string)gCustomer) +
               "&listing_id=" + (string)gListingId + "&quantity=" + msg);
        }
    }

    http_response(key req, integer status, list meta, string body) {
        if (req != gHttpReq) return;

        string ok = llJsonGetValue(body, ["success"]);
        integer success = (ok == "true" || ok == "1" || ok == JSON_TRUE);

        if (!success) {
            string err = llJsonGetValue(body, ["error"]);
            if (err == JSON_INVALID) err = "Error";
            if (gCustomer != NULL_KEY) llRegionSayTo(gCustomer, 0, "❌ " + err);
            else llOwnerSay("❌ " + err);
            return;
        }

        if (gHttpAction == "find_shops") {
            string shopsJson = llJsonGetValue(body, ["shops"]);
            gNearbyShops = [];
            list buttons = [];
            integer i = 0;
            while (i < 9) {
                string entry = llJsonGetValue(shopsJson, [i]);
                if (entry == JSON_INVALID) jump shops_done;
                string sid = llJsonGetValue(entry, ["id"]);
                string sname = llJsonGetValue(entry, ["name"]);
                if (llStringLength(sname) > 19) sname = llGetSubString(sname, 0, 18);
                gNearbyShops += [(integer)sid, sname];
                buttons += [sname];
                i++;
            }
            @shops_done;
            if (llGetListLength(buttons) == 0) {
                llRegionSayTo(gCustomer, 0, "No shops in this region. Place a ShopRegister prim first.");
                return;
            }
            buttons += ["Cancel"];
            gMenuStep = "setup_shop";
            gDialogCh = -1 - (integer)llFrand(999999.0);
            gDialogHandle = llListen(gDialogCh, "", gCustomer, "");
            llDialog(gCustomer, "🏪 Which shop does this vendor belong to?", buttons, gDialogCh);
        }

        else if (gHttpAction == "find_bait") {
            string baitJson = llJsonGetValue(body, ["bait"]);
            gAvailBait = [];
            list buttons = [];
            integer i = 0;
            while (i < 12) {
                string entry = llJsonGetValue(baitJson, [i]);
                if (entry == JSON_INVALID) jump bait_done;
                string bid = llJsonGetValue(entry, ["id"]);
                string bname = llJsonGetValue(entry, ["name"]);
                if (llStringLength(bname) > 19) bname = llGetSubString(bname, 0, 18);
                gAvailBait += [(integer)bid, bname];
                buttons += [bname];
                i++;
            }
            @bait_done;
            if (llGetListLength(buttons) == 0) {
                llRegionSayTo(gCustomer, 0, "No bait types found.");
                return;
            }
            buttons += ["Cancel"];
            gMenuStep = "setup_bait";
            gDialogCh = -1 - (integer)llFrand(999999.0);
            gDialogHandle = llListen(gDialogCh, "", gCustomer, "");
            llDialog(gCustomer, "🎣 What bait will this vendor sell?", buttons, gDialogCh);
        }

        else if (gHttpAction == "register") {
            gListingId = (integer)llJsonGetValue(body, ["listing_id"]);
            gSetupDone = TRUE;
            saveData();
            renderDisplay();
            llRegionSayTo(gCustomer, 0, "✅ Vendor set up: " + gBaitName + " at " + gShopName);
            fetchStatus();
        }

        else if (gHttpAction == "status") {
            gStock     = (integer)llJsonGetValue(body, ["stock"]);
            gMaxStock  = (integer)llJsonGetValue(body, ["max_stock"]);
            gBuyPrice  = (integer)llJsonGetValue(body, ["buy_price"]);
            gSellPrice = (integer)llJsonGetValue(body, ["sell_price"]);
            gIsSystem  = (integer)llJsonGetValue(body, ["is_system"]);
            string iname = llJsonGetValue(body, ["item_name"]);
            if (iname != JSON_INVALID && iname != "") gBaitName = iname;
            renderDisplay();
        }

        else if (gHttpAction == "shop_buy" || gHttpAction == "shop_sell") {
            string rmsg = llJsonGetValue(body, ["message"]);
            if (rmsg == JSON_INVALID) rmsg = "Done!";
            if (gCustomer != NULL_KEY) llRegionSayTo(gCustomer, 0, "✅ " + rmsg);
            gLastRenderKey = "";  // Force redraw on next status fetch
            fetchStatus();
        }

        else {
            string rmsg = llJsonGetValue(body, ["message"]);
            if (rmsg == JSON_INVALID) rmsg = "Done.";
            if (gCustomer != NULL_KEY) llRegionSayTo(gCustomer, 0, "✅ " + rmsg);
            fetchStatus();
        }
    }

    timer() {
        // Skip if region is empty
        if (llGetListLength(llGetAgentList(AGENT_LIST_REGION, [])) == 0) return;
        // Only refresh if no dialog is open (avoid interrupting customer)
        if (gDialogHandle == 0 && gTextHandle == 0) fetchStatus();
    }

    on_rez(integer p) {
        if (llLinksetDataRead(LD_OWNER) != (string)llGetOwner()) clearData();
        llResetScript();
    }

    changed(integer change) {
        if (change & CHANGED_OWNER) { clearData(); llResetScript(); }
    }
}
