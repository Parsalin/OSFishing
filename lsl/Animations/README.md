# Avatar animations

Every animation the in-world scripts call, as SL/OpenSim binary `.anim` files
(KeyframeMotion 1.0). Priority, loop, ease and hand pose are **baked into each
file**, so uploading needs no settings: Build → Upload → Animation, pick the
file, upload. Made by `make_animations.py` (pure Python, no installs):

```
python lsl/Animations/make_animations.py          # .anim files
python lsl/Animations/make_animations.py --bvh    # also .bvh, to preview in Blender
```

Edit the poses or the `SETTINGS` table in that script and re-run it. Never
hand-edit the `.anim` files. The `.bvh` files are only for previewing and are
not committed.

## Format

- Built on the standard SL skeleton. Each animation is a few hand-set poses
  (keyframes) joined by a smooth curve that keeps moving through each pose, so
  motion doesn't stop dead at every keyframe. The `.anim` gets a key every
  0.1 s plus one at every pose; the viewer fills in between.
- **Loops join cleanly:** a loop restarts at a keyframe whose pose is the same
  as the last one, and the curve's speed carries across the seam. The script
  refuses to build a loop whose ends differ. `fishing_charge` and
  `fishing_landed` play their start once, then loop the held pose.
- **Not too fast:** the script prints each animation's fastest joint turn
  (deg/s). Keep everyday motion under about 400. The hook set (`fishing_yank`,
  about 530) and the fist pump (`cheer`, about 480) are meant to be snappy.
- **A bone you don't move is free.** A bone left at the T-pose is left out of
  the animation, so other animations (AO, stands) can still move it. Every
  full-body animation here therefore holds all 19 bones: any bone that would
  rest at T-pose is set 1° off it (`NUDGE`). Only layered animations leave
  bones out, on purpose: `fishing_reel` moves just the left shoulder and
  elbow so it plays on top of the fight poses. (This is about animations and
  poses, not morphs.)
- The rod is held in the **right hand** (FishingRod attaches to Right Hand).
  The left hand rests on the reel. If the rod points the wrong way in the
  `fishing_wait` pose, fix the rod's attachment rotation, not the animation.
- Feet are grounded automatically: bending the knees lowers the hips.

## Priorities

**4 = base animation**: the avatar's whole pose for that moment.
**5 = plays over a base animation** that is still running underneath.

Upload each file under its own name (the file name without `.anim`) and put it
in the inventory of the object in the **Goes in** column. Each script skips any
animation that's missing.

| Animation | Goes in | Priority | Loop | What it shows |
|---|---|---|---|---|
| `fishing_wait` | FishingHUD | 4 | yes | Idle with the rod held at the waist, breathing sway |
| `fishing_charge` | FishingHUD | 4 | wind-up once, then loops the hold | Rod wound back over the right shoulder, held (slight tremble) |
| `fishing_fight_steady` | FishingHUD | 4 | yes | Rod up, leaning back, staggered stance, rhythmic tugs |
| `fishing_fight_lean_left` | FishingHUD | 4 | yes | Fight pose turned and leaning to the avatar's left |
| `fishing_fight_lean_right` | FishingHUD | 4 | yes | Mirror of lean left |
| `fishing_fight_strain` | FishingHUD | 4 | yes | Heavy lean back, deep knees, shaking |
| `gather_search` | BaitGatherPoint | 4 | yes | Squatting, digging in the ground with both hands |
| `prep_chop` | PrepTable | 4 | no | Standing at the table, left hand on the fish, right hand chopping |
| `prep_done` | PrepTable | 4 | no | Holds the result up and nods (follows `prep_chop`, which is stopped first) |
| `fishing_cast` | FishingHUD | 5 | no | Forward snap to release, then settle into the hold (over `fishing_wait`) |
| `fishing_nibble` | FishingHUD | 5 | no | Small rod twitch and a lean in to look (over `fishing_wait`) |
| `fishing_yank` | FishingHUD | 5 | no | Hook set: rod jerked up and back (over the fight poses) |
| `fishing_reel` | FishingHUD | 5 | yes | Left hand cranking the reel. **Left arm only**; it plays over the fight poses |
| `fishing_landed` | FishingHUD | 5 | raise once, then loops the hold | Rod lowered, fish raised up in the left hand (over the fight poses) |
| `fishing_disappointed` | FishingHUD | 5 | no | Shoulders slump, head shake (over `fishing_wait`) |
| `cheer` | FishingHUD | 5 | no | Left-hand fist pump twice. The HUD only *stops* this one today; nothing plays it yet |
| `gather_found` | BaitGatherPoint | 5 | no | Still squatting, holds the find up to look at it (over `gather_search`) |

`fishing_wait` and the fight poses share priority 4, so the HUD stops
`fishing_wait` at hook set and the fight poses take over cleanly.

## Sit-based objects

`gather_search` / `gather_found` squat (hips about 17 in lower) and the prep
animations stand. Both objects seat the avatar with `llSitTarget`, so after
uploading, check the avatar's height in-world. If it floats or sinks, adjust
the sit target Z in `BaitGatherPoint.lsl` (0.4) or `PrepTable.lsl` (0.6).

PrepTable used to borrow `gather_search` / `gather_found`, which would have put
the avatar in a squat at the table. It now uses `prep_chop` / `prep_done`.
