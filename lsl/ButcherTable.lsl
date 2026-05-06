// ============================================================
// BUTCHERING TABLE - Sit to chop fish into bait
// ============================================================
// Flow: Sit → Rarity menu → Fish list (filtered) → Butcher
//       Back from fish list → Rarity menu
//       Close from rarity menu → Stand up
//       After butcher → auto-refresh same rarity list
// ============================================================

string  gApiUrl = "https://sp.wa.darkheartsos.net/fishing/api/";

key     gSitter       = NULL_KEY;
key     gHttpReq      = NULL_KEY;
string  gHttpAction   = "";

integer gDialogCh     = 0;
integer gDialogHandle = 0;
string  gMenuMode     = "";    // "rarity" or "fish"

// All fish from server
list    gAllFishIds     = [];  // player_fish_id strings
list    gAllFishLabels  = [];  // display labels
list    gAllFishRarity  = [];  // rarity name per fish

// Filtered fish for current rarity
list    gFishIds    = [];
list    gFishLabels = [];
integer gPage       = 0;
string  gRarityFilter = "";

// Rarity counts for menu display
integer gCountCommon    = 0;
integer gCountUncommon  = 0;
integer gCountRare      = 0;
integer gCountEpic      = 0;
integer gCountLegendary = 0;

integer CH_GATHER_HUD = -7710006;

// ── Helpers ──

hudMsg(key who, string msg) {
    llRegionSayTo(who, CH_GATHER_HUD, "GATHER_MSG|" + msg);
}

showStartMenu() {
    if (gSitter == NULL_KEY) return;
    gMenuMode = "start";
    if (gDialogHandle) llListenRemove(gDialogHandle);
    gDialogCh = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", gSitter, "");
    llDialog(gSitter, "🔪 Butchering Table\n\nWhat would you like to do?", ["Butcher Fish", "Craft Item", "Close"], gDialogCh);
}

fetchCraftRecipes() {
    gHttpAction = "craft_recipes";
    gHttpReq = llHTTPRequest(gApiUrl, [
        HTTP_METHOD, "POST",
        HTTP_MIMETYPE, "application/x-www-form-urlencoded",
        HTTP_BODY_MAXLENGTH, 4096
    ], "action=craft_recipes&uuid=" + llEscapeURL((string)gSitter));
}

requestCraft(string buffType) {
    gHttpAction = "craft";
    gHttpReq = llHTTPRequest(gApiUrl, [
        HTTP_METHOD, "POST",
        HTTP_MIMETYPE, "application/x-www-form-urlencoded",
        HTTP_BODY_MAXLENGTH, 4096
    ], "action=craft_item&uuid=" + llEscapeURL((string)gSitter) + "&buff_type=" + buffType);
}

fetchFishList() {
    if (gSitter == NULL_KEY) return;
    gHttpAction = "list";
    gHttpReq = llHTTPRequest(gApiUrl, [
        HTTP_METHOD, "POST",
        HTTP_MIMETYPE, "application/x-www-form-urlencoded",
        HTTP_BODY_MAXLENGTH, 16384
    ], "action=butcher_list&uuid=" + llEscapeURL((string)gSitter));
}

requestButcher(string playerFishId) {
    if (gSitter == NULL_KEY) return;
    gHttpAction = "butcher";
    gHttpReq = llHTTPRequest(gApiUrl, [
        HTTP_METHOD, "POST",
        HTTP_MIMETYPE, "application/x-www-form-urlencoded",
        HTTP_BODY_MAXLENGTH, 4096
    ], "action=butcher_fish&uuid=" + llEscapeURL((string)gSitter) +
       "&player_fish_id=" + playerFishId);
}

// ── Filter fish by rarity and populate gFishIds/gFishLabels ──
filterByRarity(string rarity) {
    gRarityFilter = rarity;
    gFishIds = [];
    gFishLabels = [];
    integer count = llGetListLength(gAllFishIds);
    integer i;
    for (i = 0; i < count; i++) {
        if (llList2String(gAllFishRarity, i) == rarity) {
            gFishIds += [llList2String(gAllFishIds, i)];
            gFishLabels += [llList2String(gAllFishLabels, i)];
        }
    }
    gPage = 0;
}

// ── Count fish per rarity ──
countRarities() {
    gCountCommon = 0;
    gCountUncommon = 0;
    gCountRare = 0;
    gCountEpic = 0;
    gCountLegendary = 0;
    integer count = llGetListLength(gAllFishRarity);
    integer i;
    for (i = 0; i < count; i++) {
        string r = llList2String(gAllFishRarity, i);
        if (r == "common") gCountCommon++;
        else if (r == "uncommon") gCountUncommon++;
        else if (r == "rare") gCountRare++;
        else if (r == "epic") gCountEpic++;
        else if (r == "legendary") gCountLegendary++;
    }
}

// ── Show rarity selection menu ──
showRarityMenu() {
    if (gSitter == NULL_KEY) return;
    gMenuMode = "rarity";

    list buttons = [];
    integer total = llGetListLength(gAllFishIds);

    // Only show rarities that have fish
    if (gCountCommon > 0) buttons += ["Common (" + (string)gCountCommon + ")"];
    if (gCountUncommon > 0) buttons += ["Uncommon (" + (string)gCountUncommon + ")"];
    if (gCountRare > 0) buttons += ["Rare (" + (string)gCountRare + ")"];
    if (gCountEpic > 0) buttons += ["Epic (" + (string)gCountEpic + ")"];
    if (gCountLegendary > 0) buttons += ["Legendary (" + (string)gCountLegendary + ")"];
    buttons += ["Close"];

    if (gDialogHandle) llListenRemove(gDialogHandle);
    gDialogCh = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", gSitter, "");

    llDialog(gSitter,
        "🔪 Butchering Table\n" +
        (string)total + " fish held\n\nSelect rarity to butcher:",
        buttons, gDialogCh);
}

// ── Show paginated fish menu (12 per page) ──
showFishMenu() {
    if (gSitter == NULL_KEY) return;
    gMenuMode = "fish";

    integer count = llGetListLength(gFishIds);
    if (count == 0) {
        hudMsg(gSitter, "No " + gRarityFilter + " fish left.");
        showRarityMenu();
        return;
    }

    integer perPage = 10;  // 10 fish + Back + nav
    integer maxPage = (count - 1) / perPage;
    if (gPage > maxPage) gPage = maxPage;
    if (gPage < 0) gPage = 0;

    integer startIdx = gPage * perPage;
    integer endIdx = startIdx + perPage - 1;
    if (endIdx >= count) endIdx = count - 1;

    list buttons = [];
    integer i;
    for (i = startIdx; i <= endIdx; i++) {
        buttons += [llList2String(gFishLabels, i)];
    }

    // Navigation
    if (gPage < maxPage) buttons += ["Next >>"];
    if (gPage > 0) buttons += ["<< Prev"];
    buttons += ["Back"];

    if (gDialogHandle) llListenRemove(gDialogHandle);
    gDialogCh = -1 - (integer)llFrand(999999.0);
    gDialogHandle = llListen(gDialogCh, "", gSitter, "");

    string title = gRarityFilter;
    // Capitalize
    if (title == "common") title = "Common";
    else if (title == "uncommon") title = "Uncommon";
    else if (title == "rare") title = "Rare";
    else if (title == "epic") title = "Epic";
    else if (title == "legendary") title = "Legendary";

    llDialog(gSitter,
        "🔪 " + title + " Fish (" + (string)count + ")\n" +
        "Page " + (string)(gPage + 1) + "/" + (string)(maxPage + 1),
        buttons, gDialogCh);
}

clearSitter() {
    if (gDialogHandle) {
        llListenRemove(gDialogHandle);
        gDialogHandle = 0;
    }
    if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
        llStopAnimation("gather_search");
        llStopAnimation("gather_found");
    }
    gSitter = NULL_KEY;
    gAllFishIds = [];
    gAllFishLabels = [];
    gAllFishRarity = [];
    gFishIds = [];
    gFishLabels = [];
    gPage = 0;
    gMenuMode = "";
    llSetText("🔪 Butchering Table\nSit to chop fish", <0.85, 0.55, 0.3>, 1.0);
}

default {
    state_entry() {
        llSitTarget(<0.0, 0.0, 0.6>, ZERO_ROTATION);
        llSetText("🔪 Butchering Table\nSit to chop fish", <0.85, 0.55, 0.3>, 1.0);
    }

    changed(integer change) {
        if (change & CHANGED_OWNER) llResetScript();
        if (!(change & CHANGED_LINK)) return;

        key sitter = llAvatarOnSitTarget();

        if (sitter != NULL_KEY && gSitter == NULL_KEY) {
            gSitter = sitter;
            llSetText("🔪 Butchering Table\n(In use)", <0.85, 0.55, 0.3>, 0.7);
            llRequestPermissions(gSitter, PERMISSION_TRIGGER_ANIMATION);
            hudMsg(gSitter, "What would you like to do?");
            showStartMenu();
            return;
        }

        if (sitter == NULL_KEY && gSitter != NULL_KEY) {
            clearSitter();
            return;
        }
    }

    run_time_permissions(integer perm) { }

    http_response(key req, integer status, list meta, string body) {
        if (req != gHttpReq) return;
        if (gSitter == NULL_KEY) return;

        if (status != 200) {
            hudMsg(gSitter, "Server error (" + (string)status + ")");
            return;
        }

        string ok = llJsonGetValue(body, ["success"]);
        if (ok != "true" && ok != "1" && ok != JSON_TRUE) {
            string err = llJsonGetValue(body, ["error"]);
            if (err == JSON_INVALID || err == "") err = "Something went wrong.";
            hudMsg(gSitter, err);
            return;
        }

        // ── Fish list ──
        if (gHttpAction == "list") {
            gAllFishIds = [];
            gAllFishLabels = [];
            gAllFishRarity = [];

            string fishArr = llJsonGetValue(body, ["fish"]);
            if (fishArr == JSON_INVALID || fishArr == "[]" || fishArr == "null") {
                hudMsg(gSitter, "No fish to butcher. Go catch some!");
                return;
            }

            integer i = 0;
            while (i < 45) {
                string entry = llJsonGetValue(fishArr, [i]);
                if (entry == JSON_INVALID) jump listDone;

                string fid     = llJsonGetValue(entry, ["player_fish_id"]);
                string fname   = llJsonGetValue(entry, ["fish_name"]);
                string fweight = llJsonGetValue(entry, ["weight"]);
                string frarity = llJsonGetValue(entry, ["rarity_name"]);

                if (fid == JSON_INVALID || fname == JSON_INVALID) {
                    i++;
                    jump skip;
                }

                // Build label (fit in dialog button)
                if (llStringLength(fname) > 12) fname = llGetSubString(fname, 0, 11);
                float w = (float)fweight;
                string wStr;
                if (w >= 10.0) wStr = (string)((integer)w);
                else wStr = llGetSubString((string)w, 0, 3);

                gAllFishIds += [fid];
                gAllFishLabels += [fname + " " + wStr + "lb"];
                gAllFishRarity += [frarity];

                @skip;
                i++;
            }
            @listDone;

            countRarities();

            integer total = llGetListLength(gAllFishIds);
            if (total == 0) {
                hudMsg(gSitter, "No fish to butcher.");
                return;
            }

            hudMsg(gSitter, (string)total + " fish ready to butcher.");

            // If we had a rarity filter active, go back to that rarity's fish list
            if (gRarityFilter != "") {
                filterByRarity(gRarityFilter);
                showFishMenu();
            } else {
                showRarityMenu();
            }
        }

        // ── Butcher result ──
        else if (gHttpAction == "butcher") {
            string msg = llJsonGetValue(body, ["message"]);
            if (msg == JSON_INVALID) msg = "Fish butchered!";

            string special = llJsonGetValue(body, ["special_bait"]);

            // Chopping animation
            if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                llStartAnimation("gather_search");
            }
            llSleep(2.0);
            if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                llStopAnimation("gather_search");
                llStartAnimation("gather_found");
            }
            llSleep(1.0);
            if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                llStopAnimation("gather_found");
            }

            hudMsg(gSitter, msg);

            if (special != JSON_INVALID && special != JSON_NULL && special != "" && special != "null") {
                llSleep(1.5);
                hudMsg(gSitter, "✨ RARE FIND: " + special + "!");
            }

            // Refresh — stay on same rarity
            llSleep(1.0);
            if (gSitter != NULL_KEY) {
                fetchFishList();
            }
        }

        // ── Craft recipes response ──
        else if (gHttpAction == "craft_recipes") {
            string recipesJson = llJsonGetValue(body, ["recipes"]);
            if (recipesJson == JSON_INVALID || recipesJson == "[]") {
                hudMsg(gSitter, "No recipes available at your level. Reach level 5 to unlock crafting.");
                showStartMenu();
                return;
            }

            // Show craft menu
            gMenuMode = "craft";
            if (gDialogHandle) llListenRemove(gDialogHandle);
            gDialogCh = -1 - (integer)llFrand(999999.0);
            gDialogHandle = llListen(gDialogCh, "", gSitter, "");

            llDialog(gSitter,
                "🔧 Crafting\n\n" +
                "Chum Bucket — 2 rare fish → +25% bite chance\n" +
                "Lure Oil — 1 legendary fish → +15% rarity boost",
                ["Chum Bucket", "Lure Oil", "Back"], gDialogCh);
        }

        // ── Craft result ──
        else if (gHttpAction == "craft") {
            string msg = llJsonGetValue(body, ["message"]);
            if (msg == JSON_INVALID) msg = "Item crafted!";

            if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                llStartAnimation("gather_search");
            }
            llSleep(2.0);
            if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                llStopAnimation("gather_search");
                llStartAnimation("gather_found");
            }
            llSleep(1.0);
            if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                llStopAnimation("gather_found");
            }

            hudMsg(gSitter, msg);
            llSleep(1.5);
            showStartMenu();
        }
    }

    listen(integer ch, string name, key id, string msg) {
        if (ch != gDialogCh || id != gSitter) return;

        // ── Start menu ──
        if (gMenuMode == "start") {
            if (gDialogHandle) { llListenRemove(gDialogHandle); gDialogHandle = 0; }
            if (msg == "Butcher Fish") {
                hudMsg(gSitter, "Loading fish inventory...");
                fetchFishList();
            } else if (msg == "Craft Item") {
                hudMsg(gSitter, "Checking recipes...");
                fetchCraftRecipes();
            } else {
                hudMsg(gSitter, "Done.");
                llUnSit(gSitter);
            }
            return;
        }

        // ── Craft menu ──
        if (gMenuMode == "craft") {
            if (gDialogHandle) { llListenRemove(gDialogHandle); gDialogHandle = 0; }
            if (msg == "Back") { showStartMenu(); return; }
            if (msg == "Chum Bucket") {
                hudMsg(gSitter, "Crafting Chum Bucket...");
                requestCraft("chum");
            } else if (msg == "Lure Oil") {
                hudMsg(gSitter, "Crafting Lucky Lure Oil...");
                requestCraft("lure_oil");
            }
            return;
        }

        // ── Rarity menu ──
        if (gMenuMode == "rarity") {
            if (msg == "Close") {
                llListenRemove(gDialogHandle);
                gDialogHandle = 0;
                hudMsg(gSitter, "Done butchering.");
                llUnSit(gSitter);
                return;
            }

            // Parse rarity from button "Common (5)" → "common"
            string rarity = "";
            if (llSubStringIndex(msg, "Common") == 0) rarity = "common";
            else if (llSubStringIndex(msg, "Uncommon") == 0) rarity = "uncommon";
            else if (llSubStringIndex(msg, "Rare") == 0) rarity = "rare";
            else if (llSubStringIndex(msg, "Epic") == 0) rarity = "epic";
            else if (llSubStringIndex(msg, "Legendary") == 0) rarity = "legendary";

            if (rarity != "") {
                filterByRarity(rarity);
                showFishMenu();
            }
            return;
        }

        // ── Fish menu ──
        if (gMenuMode == "fish") {
            if (msg == "Back") {
                gRarityFilter = "";
                showRarityMenu();
                return;
            }

            if (msg == "Next >>") { gPage++; showFishMenu(); return; }
            if (msg == "<< Prev") { gPage--; if (gPage < 0) gPage = 0; showFishMenu(); return; }

            // Find selected fish
            integer count = llGetListLength(gFishLabels);
            integer i;
            for (i = 0; i < count; i++) {
                if (llList2String(gFishLabels, i) == msg) {
                    string fishId = llList2String(gFishIds, i);
                    llListenRemove(gDialogHandle);
                    gDialogHandle = 0;
                    hudMsg(gSitter, "Butchering...");
                    requestButcher(fishId);
                    return;
                }
            }

            hudMsg(gSitter, "Selection not found.");
            showFishMenu();
            return;
        }
    }

    touch_start(integer n) {
        key who = llDetectedKey(0);
        if (who == gSitter) {
            if (gMenuMode == "fish" && llGetListLength(gFishIds) > 0) showFishMenu();
            else if (llGetListLength(gAllFishIds) > 0) showRarityMenu();
            else fetchFishList();
        } else if (gSitter != NULL_KEY) {
            hudMsg(who, "Table is in use.");
        } else {
            hudMsg(who, "Sit down to butcher fish.");
        }
    }

    on_rez(integer p) { llResetScript(); }
}
