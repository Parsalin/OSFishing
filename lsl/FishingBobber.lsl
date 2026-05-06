// ============================================================
// FISHING BOBBER - Rezzed by FishingRod.lsl at the water surface
// ============================================================
// Receives messages from the HUD to animate different states.
// Uses keyframed motion only — no physics.
//
// CHANNELS:
//   -7710003 : HUD -> Bobber (NIBBLE, BITE, PULL_*, DESPAWN)
//   -7710004 : Bobber -> HUD (BOBBER_READY)
//
// MESSAGE PROTOCOL (received via osMessageObject from HUD):
//   NIBBLE         - small twitch
//   BITE           - sharp downward jerk (hook moment)
//   PULL_LEFT      - fish running left during fight
//   PULL_RIGHT     - fish running right during fight
//   PULL_CENTER    - fish holding still
//   DESPAWN        - clean up and die
// ============================================================

integer CH_HUD_TO_BOBBER = -7710003;
integer CH_BOBBER_TO_HUD = -7710004;

key     gOwner       = NULL_KEY;   // The player who cast us
vector  gHomePos     = ZERO_VECTOR; // Where we were rezzed
integer gState       = 0;           // 0=idle/bobbing, 1=fighting
integer gTwitching   = FALSE;       // TRUE during quick twitch/nibble
integer gStartedBobbing = FALSE;    // TRUE once we've begun the idle bob
float   gBobHeight   = 0.04;        // How far up/down to bob
float   gBobTime     = 1.2;         // Seconds for one direction of bob

// ── Start the idle floating bob loop ──
startBobbing() {
    // KFM_LOOP repeats the sequence forever
    llSetKeyframedMotion([
        <0, 0,  gBobHeight>, gBobTime,
        <0, 0, -gBobHeight>, gBobTime
    ], [KFM_DATA, KFM_TRANSLATION, KFM_MODE, KFM_LOOP]);
}

// ── Stop any active keyframe animation ──
stopAnimation() {
    llSetKeyframedMotion([], []);
}

// ── Short twitch (nibble) ──
twitch() {
    stopAnimation();
    gTwitching = TRUE;
    llSetKeyframedMotion([
        <0, 0, -0.05>, 0.15,
        <0, 0,  0.05>, 0.15
    ], [KFM_DATA, KFM_TRANSLATION]);
    // After twitch, return to bobbing
    llSetTimerEvent(0.5);
}

// ── Sharp downward jerk (bite moment) ──
biteJerk() {
    stopAnimation();
    // Snap down fast, then smaller recovery
    llSetKeyframedMotion([
        <0, 0, -0.25>, 0.1,
        <0, 0,  0.15>, 0.2,
        <0, 0, -0.10>, 0.15
    ], [KFM_DATA, KFM_TRANSLATION]);
    // Hold position after bite - no bobbing during bite window
}

// ── Fight phase: fish running left ──
pullLeft() {
    stopAnimation();
    llSetKeyframedMotion([
        <0, -0.3, -0.08>, 0.6
    ], [KFM_DATA, KFM_TRANSLATION]);
}

// ── Fight phase: fish running right ──
pullRight() {
    stopAnimation();
    llSetKeyframedMotion([
        <0,  0.3, -0.08>, 0.6
    ], [KFM_DATA, KFM_TRANSLATION]);
}

// ── Fight phase: fish holding ──
pullCenter() {
    stopAnimation();
    // Gentle tugging in place while fish rests
    llSetKeyframedMotion([
        <0, 0, -0.06>, 0.4,
        <0, 0,  0.04>, 0.4
    ], [KFM_DATA, KFM_TRANSLATION, KFM_MODE, KFM_LOOP]);
}

default {
    state_entry() {
        gOwner = llGetOwner();
        gHomePos = llGetPos();
        gState = 0;

        // Make sure physics is OFF - we're using keyframed motion
        llSetStatus(STATUS_PHYSICS, FALSE);
        llSetStatus(STATUS_PHANTOM, TRUE);

        // Listen for messages from the HUD
        llListen(CH_HUD_TO_BOBBER, "", NULL_KEY, "");

        // Announce ourselves to the HUD so it knows our key
        llRegionSayTo(gOwner, CH_BOBBER_TO_HUD, "BOBBER_READY");

        // Wait briefly for CAST_TO from rod; if none arrives in 0.5s,
        // assume we were rezzed standalone and start bobbing.
        llSetTimerEvent(0.5);

        // Auto-despawn safety net is re-armed below
    }

    on_rez(integer param) {
        llResetScript();
    }

    listen(integer ch, string name, key id, string msg) {
        if (ch != CH_HUD_TO_BOBBER) return;

        // Only respond to messages from our owner's HUD or rod
        if (llGetOwnerKey(id) != gOwner) return;

        // CAST_TO|<vector>|power - fly to target
        if (llSubStringIndex(msg, "CAST_TO|") == 0) {
            list parts = llParseString2List(msg, ["|"], []);
            vector target = (vector)llList2String(parts, 1);
            float power   = (float)llList2String(parts, 2);
            float flyTime = 0.8 + (1.0 - power) * 0.6;
            vector delta = target - llGetPos();
            stopAnimation();
            llSetKeyframedMotion([delta, flyTime], [KFM_DATA, KFM_TRANSLATION]);
            llSetTimerEvent(flyTime + 0.1);
            return;
        }

        // INIT|<owner_uuid> - just acknowledge, owner already known via llGetOwner
        if (llSubStringIndex(msg, "INIT|") == 0) {
            return;
        }

        // MOVE_TO|<vector> - smoothly reposition during fight
        if (llSubStringIndex(msg, "MOVE_TO|") == 0) {
            list parts = llParseString2List(msg, ["|"], []);
            vector target = (vector)llList2String(parts, 1);
            vector delta = target - llGetPos();
            float dist = llVecMag(delta);
            if (dist > 0.1) {
                stopAnimation();
                float moveTime = 0.4;
                if (dist > 10.0) moveTime = 0.6;
                llSetKeyframedMotion([delta, moveTime], [KFM_DATA, KFM_TRANSLATION]);
            }
            return;
        }

        if (msg == "NIBBLE") twitch();
        else if (msg == "BITE") biteJerk();
        else if (msg == "PULL_LEFT") { gState = 1; pullLeft(); }
        else if (msg == "PULL_RIGHT") { gState = 1; pullRight(); }
        else if (msg == "PULL_CENTER") { gState = 1; pullCenter(); }
        else if (msg == "DESPAWN") { stopAnimation(); llDie(); }
    }

    // Also listen for direct osMessageObject calls (used by HUD script)
    link_message(integer sender, integer num, string msg, key id) {
        // Parse CAST_TO which takes params
        if (llSubStringIndex(msg, "CAST_TO|") == 0) {
            list parts = llParseString2List(msg, ["|"], []);
            vector target = (vector)llList2String(parts, 1);
            float power   = (float)llList2String(parts, 2);
            // Fly from rez point to target over time proportional to power
            // Stronger casts arc faster
            float flyTime = 0.8 + (1.0 - power) * 0.6;
            vector delta = target - llGetPos();
            stopAnimation();
            llSetKeyframedMotion([delta, flyTime], [KFM_DATA, KFM_TRANSLATION]);
            // Once arrived, start bobbing
            llSetTimerEvent(flyTime + 0.1);
            return;
        }
        if (msg == "NIBBLE") twitch();
        else if (msg == "BITE") biteJerk();
        else if (msg == "PULL_LEFT") { gState = 1; pullLeft(); }
        else if (msg == "PULL_RIGHT") { gState = 1; pullRight(); }
        else if (msg == "PULL_CENTER") { gState = 1; pullCenter(); }
        else if (msg == "DESPAWN") { stopAnimation(); llDie(); }
    }

    timer() {
        // Twitch recovery - quick return to bobbing
        if (gState == 0 && gTwitching) {
            gTwitching = FALSE;
            startBobbing();
            llSetTimerEvent(300.0);
            return;
        }
        // First startup / CAST_TO completion: begin bobbing
        if (gState == 0 && !gStartedBobbing) {
            gStartedBobbing = TRUE;
            startBobbing();
            llSetTimerEvent(300.0);  // Re-arm as the 5-minute safety net
            return;
        }
        // Safety net fired - clean up
        
        llDie();
    }

    // Clean up if somehow un-rezzed
    changed(integer c) {
        if (c & CHANGED_OWNER) llResetScript();
    }
}
