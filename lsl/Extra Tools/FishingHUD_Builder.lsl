// ============================================================
// FISHING HUD BUILDER (one-shot)
// ============================================================
// Lays out the minimal bar HUD described in lsl/Textures/HUD/BUILD.md.
//
// 1. Rez 15 cubes and link them into one object (root + 14 children).
//    Prims already named after a row below keep that job wherever they sit
//    in the link order; unnamed cubes get the remaining rows.
// 2. Put every hud_*.png texture and FishingHUD.lsl in the ROOT prim.
// 3. Drop this script in. It names, sizes, places and textures every prim,
//    resets the HUD script, then removes itself so it can never rebuild over
//    a layout you adjusted by hand. To rebuild, drop in a fresh copy.
//
// Every prim is built visible so you can check the whole layout; the HUD
// script hides the panel and fight strip when it starts.
// ============================================================

// ---- Tweak these if something comes out wrong in-world -------------------

// Depth between layers along X. If the bar covers the buttons instead of
// sitting behind them, flip the sign.
float   LAYER_STEP = -0.012;

// Face that gets the texture. ALL_SIDES works whichever face points at you,
// because the prims are so thin you never see the edges.
integer FACE = ALL_SIDES;

// Texture rotation in degrees. If every texture shows sideways, try 90 or -90.
float   TEX_ROT_DEG = 0.0;

// Prim thickness (X). 0.01 is the minimum.
float   DEPTH = 0.01;

// Overall size. 1.0 = 1 layout pixel is 1/720 m, which is one screen pixel
// on a 720px-tall window at default HUD zoom. Bigger screens show it smaller.
float   SCALE = 1.0;

// ---- Layout (pixels; + Y = screen left, + Z = up, from the bar's centre) ----
// name, width, height, offset Y, offset Z, layer, texture, cell
// texture: a texture name, or "blank" / "clear"
// cell:    -1 whole texture; otherwise the sheet cell (see BUILD.md)
// Row 1 is the root (link 1). Its name is the object name and is never written.
list PRIMS = [
    "Bar",              380,  64,     0,    0, 0, "hud_bar",               -1,
    "HUD_Logo",         156,  78,     0,   10, 1, "hud_logo_sheet",         0,
    "HUD_Bait",         104,  52,   134,    0, 1, "hud_buttons",            3,
    "HUD_Cast",         104,  52,  -134,    0, 1, "hud_buttons",            0,
    "HUD_Track",        300,  26,     0,   72, 0, "hud_track",             -1,
    "HUD_Meter",        292,  14,     0,   72, 1, "blank",                 -1,
    "HUD_Fish",          40,  40,     0,   76, 2, "hud_icons",              0,
    "HUD_Text",          10,  10,     0,   96, 1, "clear",                 -1,
    "PanelBack",        440, 540,     0,  326, 0, "hud_panel_back",        -1,
    "PanelMedia",       424, 480,     0,  304, 1, "hud_media_loading",     -1,
    "PanelClose",        32,  32,  -198,  574, 2, "hud_icons",              8,
    "PanelOpen",         32,  32,  -160,  574, 2, "hud_icons",              9,
    "PanelSwap",         72,  36,  -100,  574, 2, "hud_buttons",            6,
    "TutorialText",      10,  10,  -300,   10, 1, "clear",                 -1,
    "TutorialPointer",   60,  60,     0,  -60, 3, "hud_icons",             10
];
integer STRIDE = 8;

// Textures not placed above that the HUD script swaps to later.
list EXTRA_TEXTURES = ["hud_media_unavailable"];

// Names the HUD script stores built sizes/positions under (cleared on rebuild).
list REMEMBERED = ["HUD_Track", "HUD_Meter", "HUD_Fish", "HUD_Text", "PanelBack", "PanelMedia",
                   "PanelClose", "PanelOpen", "PanelSwap", "TutorialPointer"];

// ---------------------------------------------------------------------------

float PX = 0.0013888889;   // 1 / 720

list textureParams(string tex, integer cell)
{
    if (tex == "blank")
        return [PRIM_TEXTURE, FACE, TEXTURE_BLANK, <1, 1, 0>, ZERO_VECTOR, 0.0];
    if (tex == "clear")
        return [PRIM_TEXTURE, FACE, TEXTURE_TRANSPARENT, <1, 1, 0>, ZERO_VECTOR, 0.0,
                PRIM_COLOR, ALL_SIDES, <1, 1, 1>, 0.0];
    vector repeats = <1, 1, 0>;
    vector offsets = ZERO_VECTOR;
    if (tex == "hud_logo_sheet")
    {
        repeats = <0.5, 1, 0>;
        offsets = <-0.25 + cell * 0.5, 0, 0>;
    }
    else if (tex == "hud_buttons" && cell >= 0)
    {
        repeats = <0.5, 0.25, 0>;
        offsets = <(cell % 2 + 0.5) * 0.5 - 0.5, 0.5 - (cell / 2 + 0.5) * 0.25, 0>;
    }
    else if (tex == "hud_icons" && cell >= 0)
    {
        repeats = <0.125, 0.25, 0>;
        offsets = <(cell % 8 + 0.5) * 0.125 - 0.5, 0.5 - (cell / 8 + 0.5) * 0.25, 0>;
    }
    if (llGetInventoryType(tex) != INVENTORY_TEXTURE) return [];
    return [PRIM_TEXTURE, FACE, tex, repeats, offsets, TEX_ROT_DEG * DEG_TO_RAD];
}

checkTextures()
{
    list missing = [];
    integer i;
    integer count = llGetListLength(PRIMS) / STRIDE;
    for (i = 0; i < count; ++i)
    {
        string tex = llList2String(PRIMS, i * STRIDE + 6);
        if (tex != "blank" && tex != "clear" && llGetInventoryType(tex) != INVENTORY_TEXTURE
            && llListFindList(missing, [tex]) == -1)
            missing += tex;
    }
    for (i = 0; i < llGetListLength(EXTRA_TEXTURES); ++i)
    {
        string tex = llList2String(EXTRA_TEXTURES, i);
        if (llGetInventoryType(tex) != INVENTORY_TEXTURE) missing += tex;
    }
    if (missing != [])
        llOwnerSay("Missing textures in the root prim: " + llDumpList2String(missing, ", ")
            + ". Prims using them are built untextured.");
}

vector wantSize(integer i)
{
    return <DEPTH, llList2Float(PRIMS, i * STRIDE + 1) * PX * SCALE,
                   llList2Float(PRIMS, i * STRIDE + 2) * PX * SCALE>;
}

vector wantPos(integer i)
{
    return <llList2Integer(PRIMS, i * STRIDE + 5) * LAYER_STEP,
            llList2Float(PRIMS, i * STRIDE + 3) * PX * SCALE,
            llList2Float(PRIMS, i * STRIDE + 4) * PX * SCALE>;
}

build()
{
    integer count = llGetListLength(PRIMS) / STRIDE;
    integer links = llGetNumberOfPrims();
    if (links != count)
    {
        llOwnerSay("This needs exactly " + (string)count + " linked prims (root + "
            + (string)(count - 1) + "). The object has " + (string)links + ". Nothing changed.");
        return;
    }
    checkTextures();

    // Assign rows BY NAME first; unnamed prims take the rows left over.
    list rowLink = [1];
    list used = [1];
    integer i;
    for (i = 1; i < count; ++i)
    {
        string want = llList2String(PRIMS, i * STRIDE);
        integer found = 0;
        integer l;
        for (l = 2; l <= links && !found; ++l)
            if (llGetLinkName(l) == want && llListFindList(used, [l]) == -1) found = l;
        rowLink += [found];
        if (found) used += [found];
    }
    for (i = 1; i < count; ++i)
    {
        if (llList2Integer(rowLink, i) == 0)
        {
            integer free = 0;
            integer l;
            for (l = 2; l <= links && !free; ++l)
            {
                if (llListFindList(used, [l]) == -1)
                {
                    integer named = FALSE;
                    integer r;
                    for (r = 1; r < count; ++r)
                        if (llGetLinkName(l) == llList2String(PRIMS, r * STRIDE)) named = TRUE;
                    if (!named) free = l;
                }
            }
            if (!free)
            {
                llOwnerSay("Could not find a prim for \"" + llList2String(PRIMS, i * STRIDE)
                    + "\": two prims share a name. Rename the duplicate and try again. Nothing changed.");
                return;
            }
            rowLink = llListReplaceList(rowLink, [free], i, i);
            used += [free];
        }
    }

    for (i = 0; i < count; ++i)
    {
        string  name = llList2String(PRIMS, i * STRIDE);
        string  tex  = llList2String(PRIMS, i * STRIDE + 6);
        integer cell = llList2Integer(PRIMS, i * STRIDE + 7);
        integer link = llList2Integer(rowLink, i);

        list p = [];
        if (link != 1) p += [PRIM_NAME, name, PRIM_DESC, ""];
        else           p += [PRIM_DESC, "scale=" + (string)SCALE];
        p += [
            PRIM_TYPE, PRIM_TYPE_BOX, PRIM_HOLE_DEFAULT, <0, 1, 0>, 0.0,
                ZERO_VECTOR, <1, 1, 0>, ZERO_VECTOR,
            PRIM_SIZE, wantSize(i),
            PRIM_ROT_LOCAL, ZERO_ROTATION,
            PRIM_COLOR, ALL_SIDES, <1, 1, 1>, 1.0,
            PRIM_FULLBRIGHT, ALL_SIDES, TRUE,
            PRIM_GLOW, ALL_SIDES, 0.0,
            PRIM_TEXT, "", ZERO_VECTOR, 0.0
        ];
        if (link != 1) p += [PRIM_POS_LOCAL, wantPos(i)];
        p += textureParams(tex, cell);
        llSetLinkPrimitiveParamsFast(link, p);

        // Media survives everything else; clear it from the panel prims only
        // (each media call pauses the script about a second).
        if (name == "PanelBack" || name == "PanelMedia")
        {
            integer sides = llGetLinkNumberOfSides(link);
            integer f;
            for (f = 0; f < sides; f++) llClearLinkMedia(link, f);
        }
    }

    // Verify every prim took its layout; report which prim got which job.
    string report = "";
    for (i = 1; i < count; ++i)
    {
        integer link = llList2Integer(rowLink, i);
        list got = llGetLinkPrimitiveParams(link, [PRIM_SIZE, PRIM_POS_LOCAL]);
        report += llList2String(PRIMS, i * STRIDE) + "=" + (string)link + " ";
        if (llVecDist(llList2Vector(got, 0), wantSize(i)) > 0.002 || llVecDist(llList2Vector(got, 1), wantPos(i)) > 0.002)
        {
            llSetLinkPrimitiveParams(link, [PRIM_SIZE, wantSize(i), PRIM_POS_LOCAL, wantPos(i)]);
            llOwnerSay("Builder: \"" + llList2String(PRIMS, i * STRIDE) + "\" needed a second try.");
        }
    }
    llOwnerSay("Builder: prim -> link: " + report);

    // Forget sizes/positions an earlier build recorded, and the on-screen
    // placement, so the HUD script records this layout now while it's visible.
    for (i = 0; i < llGetListLength(REMEMBERED); ++i)
    {
        llLinksetDataDelete("hud_size_" + llList2String(REMEMBERED, i));
        llLinksetDataDelete("hud_pos_" + llList2String(REMEMBERED, i));
    }
    llLinksetDataDelete("hud_placed");

    integer s;
    for (s = 0; s < llGetInventoryNumber(INVENTORY_SCRIPT); ++s)
    {
        string script = llGetInventoryName(INVENTORY_SCRIPT, s);
        if (script != llGetScriptName()) llResetOtherScript(script);
    }

    llOwnerSay("Built " + (string)count + " prims at " + (string)llRound(SCALE * 100)
        + "% size. The builder has removed itself; drop a fresh copy in to rebuild.");
    llRemoveInventory(llGetScriptName());
}

default
{
    state_entry()
    {
        build();
    }
}
