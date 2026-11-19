# Building the OSFishing HUD

A minimal bar, worn on **HUD Bottom**: the bait chip on the left, the OSFishing logo in the middle, FISH / STOP on the right. Everything else (fish, tackle, quests, shop, ranks, map, settings) lives in a media panel that opens above the bar when you tap the logo.

The textures in this folder are made by `make_textures.py` (HTML/SVG screenshotted with headless Chrome or Edge). Edit that and re-run it; don't edit the PNGs by hand.

## Quick build

1. Rez **15 cubes** and link them (root + 14). The root becomes the bar.
2. Put every `hud_*.png` texture and `FishingHUD.lsl` in the **root** prim.
3. Drop [`lsl/Extra Tools/FishingHUD_Builder.lsl`](../../Extra%20Tools/FishingHUD_Builder.lsl) into the root. It names, sizes, places and textures every prim, resets the HUD script and removes itself (drop in a fresh copy to rebuild).
4. Wear it on **HUD Bottom**. On the first wear after a build (or for a new owner) the script puts the bar at bottom centre, ~32px above the screen edge. After that you can move it.

The builder's `SCALE` (default 1.0) multiplies every size and offset. At 1.0, 1 layout pixel = 1/720 m, which is one screen pixel on a 720px-tall window at default HUD zoom.

## Textures

| File | Size | Goes on |
|---|---|---|
| `hud_bar.png` | 1024×172 | Root (the bar) |
| `hud_logo_sheet.png` | 1024×256 | `HUD_Logo`. Left half = normal ("▲ MENU"), right half = panel open ("▼ CLOSE"). Repeats ⟨0.5, 1⟩, offset X ∓0.25 |
| `hud_buttons.png` | 512×512 | `HUD_Cast`, `HUD_Bait`, `PanelSwap`. 2 × 4 sheet, see below |
| `hud_icons.png` | 512×256 | `HUD_Fish`, `PanelClose`, `PanelOpen`, `TutorialPointer`. 8 × 4 sheet |
| `hud_track.png` | 512×64 | `HUD_Track` (fight strip background) |
| `hud_panel_back.png` | 880×1080 | `PanelBack` (frame + OSFishing header) |
| `hud_media_loading.png` | 640×724 | `PanelMedia` before the page loads |
| `hud_media_unavailable.png` | 640×724 | `PanelMedia` if the viewer/region won't show media |

Everything except the two media placeholders has a transparent background. The builder sets **full bright** on every face. Sizes that aren't powers of two get resampled on upload; that's fine.

## Layout (pixels; + Y = screen left, + Z = up, from the bar's centre)

Every prim is a box, flat on X (0.01 m). Layer 0 is at the back; each layer is 0.012 m nearer the camera (`LAYER_STEP` in the builder; flip its sign if the bar covers the buttons).

| Link name | W × H | Offset Y, Z | Layer | Texture | Notes |
|---|---|---|---|---|---|
| root | 380 × 64 | — | 0 | `hud_bar` | Any name (it's the object name) |
| `HUD_Logo` | 156 × 78 | 0, 10 | 1 | logo sheet | Tap: open / close the panel |
| `HUD_Bait` | 104 × 52 | 134, 0 | 1 | buttons cell 3 | Bait name and count float above it. Tap: Tackle page |
| `HUD_Cast` | 104 × 52 | −134, 0 | 1 | buttons cell 0 | FISH / STOP |
| `HUD_Track` | 300 × 26 | 0, 72 | 0 | `hud_track` | Fight strip; hidden unless charging or fighting |
| `HUD_Meter` | 292 × 14 | 0, 72 | 1 | blank (tinted) | Cast power, then line tension. Fills from the left |
| `HUD_Fish` | 40 × 40 | 0, 76 | 2 | icons cell 0 | Slides toward the side the fish runs to; picture = what to do |
| `HUD_Text` | 10 × 10 | 0, 96 | 1 | transparent | Hover text: latest message + status. Moves beside the bar while the panel is open |
| `PanelBack` | 440 × 540 | 0, 326 | 0 | `hud_panel_back` | Hidden until the panel opens |
| `PanelMedia` | 424 × 480 | 0, 304 | 1 | `hud_media_loading` | The page, 640×724 px, face 4 (`MEDIA_FACE` in the script) |
| `PanelClose` | 32 × 32 | −198, 574 | 2 | icons cell 8 | Close the panel |
| `PanelOpen` | 32 × 32 | −160, 574 | 2 | icons cell 9 | Open the current page in the web browser |
| `PanelSwap` | 72 × 36 | −100, 574 | 2 | buttons cell 6/7 | Switch fishing view ↔ website. Only shown when the fishing view is available |
| `TutorialText` | 10 × 10 | −300, 10 | 1 | transparent | Tutorial hover text, right of the bar |
| `TutorialPointer` | 60 × 60 | moves | 3 | icons cell 10 | Yellow ring the script sizes and puts over the button to press |

"Hidden" means tiny **and** transparent: a transparent prim still catches clicks. The script records each hidden prim's built size and position in linkset data (`hud_size_<name>`, `hud_pos_<name>`) on its first run; the builder clears those, so rebuild with the builder rather than resizing by hand.

## Sheets

`hud_buttons.png`: 2 columns × 4 rows of 256×128, repeats ⟨0.5, 0.25⟩, offset `<(col + 0.5) * 0.5 - 0.5, 0.5 - (row + 0.5) * 0.25>`.

| cell | | cell | |
|---|---|---|---|
| 0 | ▶ FISH | 1 | ■ STOP |
| 2 | FISH, dimmed (no spot / no bait) | 3 | BAIT |
| 4 | BAIT, amber (5 or fewer left) | 5 | BAIT, red (none) |
| 6 | MENU (PanelSwap in fishing view) | 7 | FISHING (PanelSwap on the website) |

`hud_icons.png`: 8 × 4 of 64px, repeats ⟨0.125, 0.25⟩, offset `<(col + 0.5) * 0.125 - 0.5, 0.5 - (row + 0.5) * 0.25>`, cell = row × 8 + col.

| cell | icon | cell | icon |
|---|---|---|---|
| 0 | fish, calm | 5 | junk (boot) |
| 1 | fish running left (steer right) | 8 | close × |
| 2 | fish running right (steer left) | 9 | open in browser ↗ |
| 3 | fish thrashing (stop reeling) | 10 | tutorial ring |
| 4 | fish tired, green (reel now) | | |

## The fishing view

When the panel is open during a cast it shows a live view of the water, bobber, fish, tension, fish energy and line out, with a big "what to do now" call. It's optional: the bar's strip and hover text carry the same fight.

- The HUD serves it from its own HTTP-in URL: `GET <url>/fish` returns a small HTML shell that loads `https://osfishing.flamesfall.net/hud/fishview.js`, and the page polls `GET <url>/s` (JSON state, read-only) four times a second. Same origin as the page, so there's no CORS or mixed-content problem, and no load on the server.
- A website page in the panel would cover the strip, so on cast start or a bite the HUD switches the panel to the fishing view, or closes it on a grid that denies HTTP-in (no URL, no fishing view, no PanelSwap).
- Every media change pauses the script about a second, so the HUD only changes the URL on those moments, never per fight tick.

## Linkset data

`fishing_token*` (pairing), `hud_cache_*` (display cache), `hud_size_<name>` / `hud_pos_<name>` (built layout), `hud_placed` (on-screen placement done), `hud_media_on` (a page may still be on the media face; cleared on the next start).
