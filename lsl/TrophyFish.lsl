// ============================================================
// TROPHY FISH MESH SCRIPT
// ============================================================
// Attach to each fish mesh object.
// Receives scale from TrophyPlaque via channel -7720002.
// Listens on channel 0 for PLAQUE_REMOVE to self-delete.
// ============================================================

default {
    state_entry() {
        llSetStatus(STATUS_PHANTOM, TRUE);
        llSetStatus(STATUS_PHYSICS, FALSE);
    }

    on_rez(integer startParam) {
        llSetStatus(STATUS_PHANTOM, TRUE);
        llSetStatus(STATUS_PHYSICS, FALSE);

        // Listen for scale from plaque
        llListen(-7720002, "", NULL_KEY, "");

        // Also listen for removal
        llListen(0, "", NULL_KEY, "PLAQUE_REMOVE");

        // Report ready to plaque
        llSay(-7720001, "FISH_READY");

        // Auto-delete after 6 hours
        llSetTimerEvent(21600.0);
    }

    listen(integer ch, string name, key id, string msg) {
        if (ch == -7720002) {
            list parts = llParseString2List(msg, ["|"], []);
            if (llList2String(parts, 0) == "FISH_SCALE") {
                list xyz = llParseString2List(llList2String(parts, 1), [","], []);
                float sx = (float)llList2String(xyz, 0);
                float sy = (float)llList2String(xyz, 1);
                float sz = (float)llList2String(xyz, 2);
                if (sx > 0.0 && sy > 0.0 && sz > 0.0) {
                    llSetScale(<sx, sy, sz>);
                }
            }
            return;
        }
        if (msg == "PLAQUE_REMOVE") llDie();
    }

    timer() { llDie(); }
}
