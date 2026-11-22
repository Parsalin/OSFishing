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
//   "AVhipfix"      — optional, AVsitter's hip-offset fix (played on sit)
// If gather_search is missing, the normal sit is left playing so nobody
// ends up standing in the prim. Sit handling follows AVsitter
// ([AV]sitA.lsl): stop "sit" once when permission comes back, start the
// new animation before stopping the old one, and a sit target with the
// viewer's 0.4 m offset taken off.
//
// The sitter is placed with their feet on the bottom of this object,
// whatever their height. An optional third description field moves them
// up (or down, negative) in metres: Worm Patch,1,0.05
//
// The object self-registers with the server on rez.
// ============================================================

string  gApiUrl = "https://osfishing.flamesfall.net/api/";

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
float   gFloorLocalZ = 0.0;     // Bottom of the object, relative to the root
float   gHeightAdjust = 0.0;    // Optional 3rd description field (metres)
integer gFitLeft = 0;           // Re-fit passes left (the sit can settle late)

float   SIT_HALF_HEIGHT = 0.9;      // typical avatar, until fitSitter measures

// ── Config parsing ──
parseConfig() {
    string desc = llGetObjectDesc();
    list parts = llParseString2List(desc, [","], []);
    if (llGetListLength(parts) >= 2) {
        gPointName = llStringTrim(llList2String(parts, 0), STRING_TRIM);
        gBaitId    = (integer)llStringTrim(llList2String(parts, 1), STRING_TRIM);
        gHeightAdjust = 0.0;
        if (llGetListLength(parts) >= 3)
            gHeightAdjust = (float)llStringTrim(llList2String(parts, 2), STRING_TRIM);
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

// Where the object's bottom is. Measured only while nobody sits, because
// a seated avatar counts toward the bounding box.
measureFloor() {
    list bb = llGetBoundingBox(llGetKey());
    vector lo = llList2Vector(bb, 0);
    gFloorLocalZ = lo.z;
}

// Link number of the seated avatar, 0 if gone
integer sitterLink() {
    integer n = llGetNumberOfPrims();
    for (; n > 1; --n) {
        if (llGetLinkKey(n) == gSitter) return n;
    }
    return 0;
}

// Put the sitter's feet on the object's bottom, whatever their height.
// An avatar's position is the middle of its agent box, so the feet are
// half its height below. Measured and corrected rather than computed
// from the sit target, because grids offset sit targets differently.
fitSitter() {
    if (gSitter == NULL_KEY) return;
    integer link = sitterLink();
    if (!link) return;
    vector size = llGetAgentSize(gSitter);
    if (size == ZERO_VECTOR) return;
    vector now = llList2Vector(llGetObjectDetails(gSitter, [OBJECT_POS]), 0);
    if (now == ZERO_VECTOR) return;
    float want = llGetPos().z + gFloorLocalZ + size.z * 0.5 + gHeightAdjust;
    float dz = want - now.z;
    if (llFabs(dz) < 0.01) return;
    vector local = llList2Vector(llGetLinkPrimitiveParams(link, [PRIM_POS_LOCAL]), 0);
    // dz is in region axes; turn it into the root's axes
    local += <0.0, 0.0, dz> / llGetRootRotation();
    llSetLinkPrimitiveParamsFast(link, [PRIM_POS_LOCAL, local]);
}

// Sit target where a typical avatar stands on the object's bottom, as
// AVsitter sets it: minus the viewer's 0.4 m offset, plus 0.05 up.
// fitSitter then corrects for the sitter's real height.
setSitTarget() {
    vector want = <0.0, 0.0, gFloorLocalZ + SIT_HALF_HEIGHT + gHeightAdjust>;
    llSitTarget(want - <0.0, 0.0, 0.4> + <0.0, 0.0, 0.05>, ZERO_ROTATION);
}

// Swap one animation for another the AVsitter way: start the new one
// first, stop the old one a moment later, so there's never a gap for
// the default sit or stand to show through.
swapAnim(string from, string to) {
    if (!(llGetPermissions() & PERMISSION_TRIGGER_ANIMATION)) return;
    if (llGetInventoryType(to) != INVENTORY_ANIMATION) return;
    llStartAnimation(to);
    if (from != "" && llGetInventoryType(from) == INVENTORY_ANIMATION) {
        llSleep(0.2);
        llStopAnimation(from);
    }
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
        if (llAvatarOnSitTarget() == NULL_KEY) measureFloor();
        setSitTarget();
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
        if (success != "true" && success != "1" && success != JSON_TRUE) {
            string err = jsonGet(body, "error");
            llOwnerSay("Gather point error: " + err);
            return;
        }

        if (gHttpAction == "register") {
            gPointId = (integer)jsonGet(body, "point_id");
            gBaitName = jsonGet(body, "bait_name");
            gRegistered = TRUE;

            string created = jsonGet(body, "created");
            if (created == "true" || created == "1" || created == JSON_TRUE) {
                llOwnerSay("Gather point registered: " + gPointName + " (ID " + (string)gPointId + ")");
            } else {
                llOwnerSay("Gather point updated: " + gPointName + " (ID " + (string)gPointId + ")");
            }

            // Check initial stock status
            checkStatus();
        }
        else if (gHttpAction == "status") {
            string depleted = jsonGet(body, "depleted");
            gDepleted = (depleted == "true" || depleted == "1" || depleted == JSON_TRUE);
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

                gFitLeft = 2;

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
                    safeStopAnim(gSitter, "AVhipfix");
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

        if (change & CHANGED_SCALE) {
            if (gSitter == NULL_KEY) {
                measureFloor();
                setSitTarget();
            }
        }
    }

    run_time_permissions(integer perm) {
        if (perm & PERMISSION_TRIGGER_ANIMATION) {
            // As AVsitter does: with no gather animation, keep the sit
            if (llGetInventoryType("gather_search") == INVENTORY_ANIMATION) {
                llStopAnimation("sit");
                if (llGetInventoryType("AVhipfix") == INVENTORY_ANIMATION)
                    llStartAnimation("AVhipfix");
                llStartAnimation("gather_search");
            }
            // The sit has settled by the time permissions come back
            fitSitter();
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

        if (gFitLeft > 0) {
            --gFitLeft;
            fitSitter();
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
            swapAnim("gather_search", "gather_found");

            // Fire the gather tick — tell the sitter's HUD to call the server
            doGatherTick();

            // After a brief pause, go back to search animation
            llSleep(2.0);
            if (gSitter != NULL_KEY) swapAnim("gather_found", "gather_search");

            scheduleNextGather();
        }
    }

    // Listen for gather results from the HUD
    listen(integer ch, string name, key id, string msg) {
        if (ch == -7710006) {
            list parts = llParseString2List(msg, ["|"], []);
            string cmd = llList2String(parts, 0);

            if (cmd == "GATHER_RESULT") {
                // Only the sitter's HUD, about this point (older HUDs send no
                // point id). Otherwise any object could fake "depleted" and
                // unseat people, or one point's stock would show on another.
                if (gSitter == NULL_KEY || llGetOwnerKey(id) != gSitter) return;
                string forPoint = llList2String(parts, 6);
                if (forPoint != "" && (integer)forPoint != gPointId) return;
                integer gathered = (integer)llList2String(parts, 1);
                string depleted  = llList2String(parts, 4);
                integer stockPct = (integer)llList2String(parts, 5);

                gStockPct = stockPct;
                gDepleted = (depleted == "true" || depleted == "1" || depleted == JSON_TRUE);
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
