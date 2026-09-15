// ============================================================
// TROPHY TEXT PRIM
// ============================================================
// Place this script in the child prim named "Trophy_Text"
// Receives draw commands from TrophyPlaque.lsl via link message
// and renders them on face 4 of this prim.
// ============================================================

default {
    state_entry() {
        // Do NOT call osSetDynamicTextureData here. If the grid denies the
        // function the OSSL Permission Error halts the script, which would
        // also kill the link_message handler below and leave the plaque
        // permanently unable to draw. Defer the cosmetic clear to a timer so
        // a denial costs us the blank face, not the whole script.
        llSetTimerEvent(0.5);
    }

    timer() {
        llSetTimerEvent(0.0);
        string d = "PenColour ff1a1e1a;MoveTo 0,0;FillRectangle 512,512;";
        osSetDynamicTextureData("", "vector", d, "width:512,height:512,distrib:0,alpha:255", 4);
    }

    link_message(integer sender, integer face, string drawCmd, key id) {
        // Only handle messages intended for this prim (face = 4)
        if (face != 4) return;
        osSetDynamicTextureData("", "vector", drawCmd, "width:512,height:512,distrib:0,alpha:255", 4);
    }
}
