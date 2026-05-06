// ============================================================
// BAIT GATHER POINT - Sit to gather bait
// ============================================================
// Place at locations where players can gather bait.
// Player sits → search animation → periodic gather ticks.
// Server controls stock — spot depletes and respawns.
//
// CONFIGURATION: Set in object description:
//   name,bait_id
// Example: Worm Patch,1
//
// ANIMATIONS: Place these in the object inventory (optional):
//   "gather_search" — looping search animation
//   "gather_found"  — brief found animation (plays on each gather)
// If missing, animations are silently skipped.
//
// The object self-registers with the server on rez.
// ============================================================

string  gApiUrl = "https://sp.wa.darkheartsos.net/fishing/api/";

integer gPointId    = 0;
string  gPointName  = "";
integer gBaitId     = 0;
string  gBaitName   = "";
integer gRegistered = FALSE;

key     gSitter     = NULL_KEY;     // Who is sitting
key     gHttpReq    = NULL_KEY;
string  gHttpAction = "";
float   gNextGather = 0.0;         // When to fire next gather tick
integer gDepleted   = FALSE;
integer gStockPct   = 100;
float   gNextRegenCheck = 0.0;  // When to ask server for regen update

// ── Config parsing ──
parseConfig() {
    string desc = llGetObjectDesc();
    list parts = llParseString2List(desc, [","], []);
    if (llGetListLength(parts) >= 2) {
        gPointName = llStringTrim(llList2String(parts, 0), STRING_TRIM);
        gBaitId    = (integer)llStringTrim(llList2String(parts, 1), STRING_TRIM);
    } else {
        llOwnerSay("ERROR: Set description to: name,bait_id");
        llOwnerSay("Example: Worm Patch,1");
    }
}

// ── Simple JSON value extractor ──
string jsonGet(string json, string akey) {
    string search = "\"" + akey + "\"";
    integer idx = llSubStringIndex(json, search);
    if (idx == -1) return "";
    idx += llStringLength(search);
    integer colonIdx = llSubStringIndex(llGetSubString(json, idx, idx + 5), ":");
    idx += colonIdx + 1;
    string rest = llStringTrim(llGetSubString(json, idx, idx + 256), STRING_TRIM_HEAD);
    if (llGetSubString(rest, 0, 0) == "\"") {
        integer endQuote = llSubStringIndex(llGetSubString(rest, 1, -1), "\"");
        return llGetSubString(rest, 1, endQuote);
    }
    integer i;
    string val = "";
    for (i = 0; i < llStringLength(rest) && i < 32; i++) {
        string c = llGetSubString(rest, i, i);
        if (c == "," || c == "}" || c == "]" || c == "\n") jump done;
        val += c;
    }
    @done;
    return llStringTrim(val, STRING_TRIM);
}

// ── Safe animation helpers ──
safeStartAnim(key av, string animName) {
    if (animName == "") return;
    if (llGetInventoryType(animName) != INVENTORY_ANIMATION) return;
    llStartAnimation(animName);
}

safeStopAnim(key av, string animName) {
    if (animName == "") return;
    if (llGetInventoryType(animName) != INVENTORY_ANIMATION) return;
    llStopAnimation(animName);
}

// ── Register with server ──
registerWithServer() {
    vector pos = llGetPos();
    string region = llGetRegionName();

    string body = "action=gather_register" +
                  "&name=" + llEscapeURL(gPointName) +
                  "&bait_id=" + (string)gBaitId +
                  "&owner_key=" + llEscapeURL((string)llGetOwner()) +
                  "&region=" + llEscapeURL(region) +
                  "&pos_x=" + (string)pos.x +
                  "&pos_y=" + (string)pos.y +
                  "&pos_z=" + (string)pos.z;

    gHttpAction = "register";
    gHttpReq = llHTTPRequest(gApiUrl, [
        HTTP_METHOD, "POST",
        HTTP_MIMETYPE, "application/x-www-form-urlencoded",
        HTTP_BODY_MAXLENGTH, 4096
    ], body);
}

// ── Check stock status ──
checkStatus() {
    if (gPointId == 0) return;
    string body = "action=gather_status&point_id=" + (string)gPointId;
    gHttpAction = "status";
    gHttpReq = llHTTPRequest(gApiUrl, [
        HTTP_METHOD, "POST",
        HTTP_MIMETYPE, "application/x-www-form-urlencoded",
        HTTP_BODY_MAXLENGTH, 1024
    ], body);
}

// ── Do a gather tick ──
// This needs auth — we need the sitter's HUD to make the call.
// Instead, we send a channel message to the sitter's HUD asking it to call gather_tick.
doGatherTick() {
    if (gSitter == NULL_KEY || gPointId == 0) return;
    // Tell the sitter's HUD to call gather_tick for us
    llRegionSayTo(gSitter, -7710006, "GATHER_TICK|" + (string)gPointId);
}

// ── Update floating text ──
updateText() {
    if (gSitter != NULL_KEY) {
        // Someone is gathering — show bait name and stock
        if (gDepleted) {
            llSetText(gPointName + "\n" + gBaitName + "\nDepleted — come back later",
                      <0.8, 0.3, 0.3>, 1.0);
        } else {
            llSetText(gPointName + "\n" + gBaitName + "\n" + (string)gStockPct + "% remaining",
                      <0.4, 0.8, 0.4>, 1.0);
        }
    } else if (gDepleted) {
        // Empty, nobody sitting
        llSetText(gPointName + "\n(depleted)", <0.5, 0.5, 0.5>, 0.7);
    } else {
        // Available, nobody sitting — hide text
        llSetText("", ZERO_VECTOR, 0.0);
    }
}

// ── Schedule next gather with random delay ──
scheduleNextGather() {
    gNextGather = llGetTime() + 10.0 + llFrand(20.0);  // 10-30 seconds
}

default {
    state_entry() {
        parseConfig();

        if (gPointName == "" || gBaitId == 0) {
            llSetText("Configure me!\nSet description:\nname,bait_id",
                      <1.0, 0.3, 0.3>, 1.0);
            return;
        }

        // Set sit target so players can sit
        llSitTarget(<0.0, 0.0, 0.4>, ZERO_ROTATION);
        llSetClickAction(CLICK_ACTION_SIT);

        // Listen for gather results from HUD
        llListen(-7710006, "", NULL_KEY, "");

        updateText();
        registerWithServer();
    }

    http_response(key req, integer status, list meta, string body) {
        if (req != gHttpReq) return;

        if (status != 200) {
            llOwnerSay("Gather point API error (HTTP " + (string)status + ")");
            return;
        }

        string success = jsonGet(body, "success");
        if (success != "true" && success != "1") {
            string err = jsonGet(body, "error");
            llOwnerSay("Gather point error: " + err);
            return;
        }

        if (gHttpAction == "register") {
            gPointId = (integer)jsonGet(body, "point_id");
            gBaitName = jsonGet(body, "bait_name");
            gRegistered = TRUE;

            string created = jsonGet(body, "created");
            if (created == "true" || created == "1") {
                llOwnerSay("Gather point registered: " + gPointName + " (ID " + (string)gPointId + ")");
            } else {
                llOwnerSay("Gather point updated: " + gPointName + " (ID " + (string)gPointId + ")");
            }

            // Check initial stock status
            checkStatus();
        }
        else if (gHttpAction == "status") {
            string depleted = jsonGet(body, "depleted");
            gDepleted = (depleted == "true" || depleted == "1");
            gStockPct = (integer)jsonGet(body, "stock_pct");
            gBaitName = jsonGet(body, "bait_name");
            updateText();

            // Schedule next regen check based on server response
            integer nextCheck = (integer)jsonGet(body, "next_check");
            if (nextCheck < 30) nextCheck = 600;
            if (gSitter == NULL_KEY) {
                // Nobody sitting — use regen timer
                gNextRegenCheck = llGetTime() + (float)nextCheck;
                llSetTimerEvent((float)nextCheck);
            }
        }
    }

    changed(integer change) {
        if (change & CHANGED_LINK) {
            key sitter = llAvatarOnSitTarget();

            if (sitter != NULL_KEY && gSitter == NULL_KEY) {
                // Someone just sat down
                gSitter = sitter;

                if (!gRegistered || gPointId == 0) {
                    llRegionSayTo(gSitter, -7710006, "GATHER_MSG|Gather point not ready.");
                    llUnSit(gSitter);
                    gSitter = NULL_KEY;
                    return;
                }

                // Check stock before starting
                if (gDepleted) {
                    llRegionSayTo(gSitter, -7710006, "GATHER_MSG|Spot depleted. Come back later.");
                    llUnSit(gSitter);
                    gSitter = NULL_KEY;
                    return;
                }

                // Request animation permission
                llRequestPermissions(gSitter, PERMISSION_TRIGGER_ANIMATION);

                llRegionSayTo(gSitter, -7710006, "GATHER_MSG|Searching for " + gBaitName + "...");
                updateText();

                // Schedule first gather
                llResetTime();
                scheduleNextGather();
                llSetTimerEvent(1.0);  // Check every second
            }
            else if (sitter == NULL_KEY && gSitter != NULL_KEY) {
                // Player stood up
                if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                    safeStopAnim(gSitter, "gather_search");
                    safeStopAnim(gSitter, "gather_found");
                }

                llRegionSayTo(gSitter, -7710006, "GATHER_MSG|Stopped gathering.");
                gSitter = NULL_KEY;
                llSetTimerEvent(0.0);

                updateText();

                // Recheck stock status
                checkStatus();
            }
        }

        if (change & (CHANGED_REGION | CHANGED_OWNER)) {
            llResetScript();
        }
    }

    run_time_permissions(integer perm) {
        if (perm & PERMISSION_TRIGGER_ANIMATION) {
            safeStartAnim(gSitter, "gather_search");
        }
    }

    timer() {
        // ── Nobody sitting: this is a regen check timer ──
        if (gSitter == NULL_KEY) {
            checkStatus();  // Ask server for regen, server returns next_check
            return;
        }

        // ── Someone sitting: check they're still here ──
        if (llAvatarOnSitTarget() == NULL_KEY) {
            if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                safeStopAnim(gSitter, "gather_search");
            }
            gSitter = NULL_KEY;
            updateText();
            checkStatus();  // Recheck stock and start regen timer
            return;
        }

        float now = llGetTime();

        if (now >= gNextGather) {
            if (gDepleted) {
                llRegionSayTo(gSitter, -7710006, "GATHER_MSG|Nothing left. Come back later.");
                if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                    safeStopAnim(gSitter, "gather_search");
                }
                llUnSit(gSitter);
                return;
            }

            // Play found animation briefly
            if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                safeStopAnim(gSitter, "gather_search");
                safeStartAnim(gSitter, "gather_found");
            }

            // Fire the gather tick — tell the sitter's HUD to call the server
            doGatherTick();

            // After a brief pause, go back to search animation
            llSleep(2.0);
            if (gSitter != NULL_KEY && llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                safeStopAnim(gSitter, "gather_found");
                safeStartAnim(gSitter, "gather_search");
            }

            scheduleNextGather();
        }
    }

    // Listen for gather results from the HUD
    listen(integer ch, string name, key id, string msg) {
        if (ch == -7710006) {
            list parts = llParseString2List(msg, ["|"], []);
            string cmd = llList2String(parts, 0);

            if (cmd == "GATHER_RESULT") {
                integer gathered = (integer)llList2String(parts, 1);
                string depleted  = llList2String(parts, 4);
                integer stockPct = (integer)llList2String(parts, 5);

                gStockPct = stockPct;
                gDepleted = (depleted == "true" || depleted == "1");
                updateText();

                if (gDepleted && gSitter != NULL_KEY) {
                    if (llGetPermissions() & PERMISSION_TRIGGER_ANIMATION) {
                        safeStopAnim(gSitter, "gather_search");
                        safeStopAnim(gSitter, "gather_found");
                    }
                    llUnSit(gSitter);
                }
            }
        }
    }

    on_rez(integer param) {
        llResetScript();
    }

    touch_start(integer n) {
        key who = llDetectedKey(0);
        if (who == gSitter) return;

        if (gDepleted) {
            llRegionSayTo(who, -7710006, "GATHER_MSG|" + gPointName + " — " + gBaitName + " (depleted)");
        } else {
            llRegionSayTo(who, -7710006, "GATHER_MSG|" + gPointName + " — Sit to gather " + gBaitName);
        }
    }
}
