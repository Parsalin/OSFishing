// ============================================================
// FISHING ROD - Wearable Attachment (v2 - no rod manipulation)
// ============================================================
// Attach to Right Hand.
// Receives commands from HUD, rezzes bobber, manages particle
// fishing line. The rod NEVER rotates or repositions itself —
// it stays exactly where the avatar's hand positions it.
//
// Contains a "FishingBobber" object in inventory for rezzing.
//
// CHANNELS:
//   -7710001 : HUD -> Rod (commands, pipe-delimited)
//   -7710002 : Rod -> HUD (responses)
// ============================================================

integer CH_HUD_TO_ROD = -7710001;
integer CH_ROD_TO_HUD = -7710002;

key gOwner     = NULL_KEY;
key gBobberKey = NULL_KEY;
vector gBobberTargetPos = ZERO_VECTOR;
float  gBobberCastPower = 0.0;

// ── Particle fishing line (rod tip → bobber) ──
startLine(key target) {
    gBobberKey = target;
    llParticleSystem([
        PSYS_PART_FLAGS,
            PSYS_PART_INTERP_COLOR_MASK |
            PSYS_PART_FOLLOW_VELOCITY_MASK |
            PSYS_PART_TARGET_POS_MASK,
        PSYS_SRC_PATTERN, PSYS_SRC_PATTERN_DROP,
        PSYS_PART_START_COLOR, <0.85, 0.85, 0.85>,
        PSYS_PART_END_COLOR, <0.85, 0.85, 0.85>,
        PSYS_PART_START_ALPHA, 0.9,
        PSYS_PART_END_ALPHA, 0.9,
        PSYS_PART_START_SCALE, <0.02, 0.02, 0.0>,
        PSYS_PART_END_SCALE, <0.02, 0.02, 0.0>,
        PSYS_PART_MAX_AGE, 1.5,
        PSYS_SRC_BURST_RATE, 0.01,
        PSYS_SRC_BURST_PART_COUNT, 1,
        PSYS_SRC_BURST_SPEED_MIN, 0.0,
        PSYS_SRC_BURST_SPEED_MAX, 0.0,
        PSYS_SRC_TARGET_KEY, target,
        PSYS_SRC_MAX_AGE, 0.0
    ]);
}

updateLineColor(float tensionPct) {
    vector color;
    if (tensionPct < 0.4)      color = <0.85, 0.85, 0.85>;
    else if (tensionPct < 0.7) color = <1.0, 1.0, 0.3>;
    else                        color = <1.0, 0.2, 0.1>;

    if (gBobberKey != NULL_KEY) {
        llParticleSystem([
            PSYS_PART_FLAGS,
                PSYS_PART_INTERP_COLOR_MASK |
                PSYS_PART_FOLLOW_VELOCITY_MASK |
                PSYS_PART_TARGET_POS_MASK,
            PSYS_SRC_PATTERN, PSYS_SRC_PATTERN_DROP,
            PSYS_PART_START_COLOR, color,
            PSYS_PART_END_COLOR, color,
            PSYS_PART_START_ALPHA, 0.9,
            PSYS_PART_END_ALPHA, 0.9,
            PSYS_PART_START_SCALE, <0.02, 0.02, 0.0>,
            PSYS_PART_END_SCALE, <0.02, 0.02, 0.0>,
            PSYS_PART_MAX_AGE, 1.5,
            PSYS_SRC_BURST_RATE, 0.01,
            PSYS_SRC_BURST_PART_COUNT, 1,
            PSYS_SRC_BURST_SPEED_MIN, 0.0,
            PSYS_SRC_BURST_SPEED_MAX, 0.0,
            PSYS_SRC_TARGET_KEY, gBobberKey,
            PSYS_SRC_MAX_AGE, 0.0
        ]);
    }
}

stopLine() {
    llParticleSystem([]);
    gBobberKey = NULL_KEY;
}

// Line snap visual — quick burst of particles
lineSnapEffect() {
    llParticleSystem([
        PSYS_PART_FLAGS,
            PSYS_PART_INTERP_COLOR_MASK |
            PSYS_PART_EMISSIVE_MASK,
        PSYS_SRC_PATTERN, PSYS_SRC_PATTERN_EXPLODE,
        PSYS_PART_START_COLOR, <1.0, 0.3, 0.1>,
        PSYS_PART_END_COLOR, <0.5, 0.1, 0.0>,
        PSYS_PART_START_ALPHA, 1.0,
        PSYS_PART_END_ALPHA, 0.0,
        PSYS_PART_START_SCALE, <0.05, 0.3, 0.0>,
        PSYS_PART_END_SCALE, <0.01, 0.01, 0.0>,
        PSYS_PART_MAX_AGE, 1.0,
        PSYS_SRC_BURST_RATE, 0.0,
        PSYS_SRC_BURST_PART_COUNT, 15,
        PSYS_SRC_BURST_SPEED_MIN, 1.0,
        PSYS_SRC_BURST_SPEED_MAX, 3.0,
        PSYS_SRC_MAX_AGE, 0.5
    ]);
    llSleep(1.0);
    llParticleSystem([]);
}

default {
    state_entry() {
        gOwner = llGetOwner();
        llListen(CH_HUD_TO_ROD, "", NULL_KEY, "");
    }

    on_rez(integer param) {
        llResetScript();
    }

    attach(key id) {
        if (id) {
            gOwner = id;
            llResetScript();
        }
    }

    listen(integer ch, string name, key id, string msg) {
        // Only accept commands from owner's objects
        if (llGetOwnerKey(id) != gOwner) return;

        list parts = llParseString2List(msg, ["|"], []);
        string cmd = llList2String(parts, 0);

        if (cmd == "ROD_CAST") {
            // Do nothing visually on the rod itself — HUD plays avatar cast anim.
        }
        else if (cmd == "ROD_REZ_BOBBER") {
            // Format: ROD_REZ_BOBBER|<x,y,z>|power
            string posStr = llList2String(parts, 1);
            float  power  = (float)llList2String(parts, 2);

            vector spotPos = (vector)posStr;
            vector myPos   = llGetPos();

            // Rez AT the player, slightly in front at waist height
            vector rezPos = myPos + <0.0, 0.0, 0.3>;

            // Calculate where the bobber should LAND (proportional to power)
            // At 100% power, it lands at the spot. At lower power, it lands short.
            vector landPos = myPos + (spotPos - myPos) * (0.5 + power * 0.5);

            if (llGetInventoryType("FishingBobber") == INVENTORY_OBJECT) {
                llRezObject("FishingBobber", rezPos, ZERO_VECTOR, ZERO_ROTATION, 42);

                // Store the target so object_rez can pass it to the bobber
                gBobberTargetPos = landPos;
                gBobberCastPower = power;
            } else {
                llOwnerSay("ERROR: no 'FishingBobber' in rod inventory!");
                llRegionSayTo(gOwner, CH_ROD_TO_HUD, "ERROR|No bobber in rod inventory");
            }
        }
        else if (cmd == "ROD_HOOKSET") {
            // Start particle line to bobber
            key bobber = (key)llList2String(parts, 1);
            startLine(bobber);
        }
        else if (cmd == "ROD_TENSION") {
            float tPct = (float)llList2String(parts, 1);
            updateLineColor(tPct);
        }
        else if (cmd == "ROD_LINEBREAK") {
            lineSnapEffect();
            gBobberKey = NULL_KEY;
        }
        else if (cmd == "ROD_IDLE") {
            stopLine();
        }
    }

    object_rez(key id) {
        gBobberKey = id;
        // Use channel say instead of osMessageObject for reliable timing.
        // Bobber listens on CH_HUD_TO_BOBBER and accepts INIT/CAST_TO commands.
        // Small delay gives the bobber's script time to start its listener.
        llSleep(0.3);
        llRegionSayTo(id, -7710003, "INIT|" + (string)gOwner);
        if (gBobberTargetPos != ZERO_VECTOR) {
            llRegionSayTo(id, -7710003,
                "CAST_TO|" + (string)gBobberTargetPos + "|" + (string)gBobberCastPower);
            gBobberTargetPos = ZERO_VECTOR;
        }
    }
}
