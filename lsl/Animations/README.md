# Avatar animations

Every animation the in-world scripts call, as SL/OpenSim BVH files. They are
made by `make_animations.py` (pure Python, no installs):

```
python lsl/Animations/make_animations.py
```

Edit the poses in that script and re-run it. Never hand-edit the `.bvh` files.

## Format

- Standard SL BVH skeleton, inches, 30 fps.
- Frame 0 is the T-pose **reference frame**. The uploader compares every joint
  against it, and a joint that never moves away from it is left out of the
  animation. That's how `fishing_reel` moves only the left arm and plays on top
  of the fight animations.
- The rod is held in the **right hand** (FishingRod attaches to Right Hand).
  The left hand rests on the reel. If the rod points the wrong way in the
  `fishing_wait` pose, fix the rod's attachment rotation, not the animation.
- Feet are grounded automatically: bending the knees lowers the hips.

## Upload settings

Upload each file under the exact name in the table (file name without `.bvh`).
Then drop the uploaded animation into the inventory of the object in the
**Goes in** column. Each script checks its inventory and silently skips any
animation that's missing.

| Animation | Goes in | Priority | Loop | Ease in / out | What it shows |
|---|---|---|---|---|---|
| `fishing_wait` | FishingHUD | 3 | yes, 0–100% | 0.5 / 0.5 | Idle with the rod held at the waist, breathing sway |
| `fishing_charge` | FishingHUD | 4 | yes, in 25%, out 100% | 0.3 / 0.2 | Rod wound back over the right shoulder, held (slight tremble) |
| `fishing_cast` | FishingHUD | 4 | no | 0.1 / 0.4 | Forward snap to release, then settle back to the hold |
| `fishing_nibble` | FishingHUD | 4 | no | 0.1 / 0.2 | Small rod twitch and a lean in to look at the bobber |
| `fishing_yank` | FishingHUD | 5 | no | 0.1 / 0.3 | Hook set: rod jerked up and back, lean back |
| `fishing_reel` | FishingHUD | 5 | yes, 0–100% | 0.2 / 0.2 | Left hand cranking the reel. **Left arm only**; it plays over the fight pose |
| `fishing_fight_steady` | FishingHUD | 4 | yes, 0–100% | 0.3 / 0.3 | Rod up, leaning back, staggered stance, rhythmic tugs |
| `fishing_fight_lean_left` | FishingHUD | 4 | yes, 0–100% | 0.3 / 0.3 | Fight pose turned and leaning to the avatar's left |
| `fishing_fight_lean_right` | FishingHUD | 4 | yes, 0–100% | 0.3 / 0.3 | Mirror of lean left |
| `fishing_fight_strain` | FishingHUD | 4 | yes, 0–100% | 0.3 / 0.3 | Heavy lean back, deep knees, shaking |
| `fishing_landed` | FishingHUD | 5 | yes, in 55%, out 100% | 0.3 / 0.5 | Rod lowered, fish raised up in the left hand to admire |
| `fishing_disappointed` | FishingHUD | 4 | no | 0.3 / 0.5 | Shoulders slump, head shake |
| `cheer` | FishingHUD | 5 | no | 0.2 / 0.4 | Left-hand fist pump twice. The HUD only *stops* this one today; nothing plays it yet |
| `gather_search` | BaitGatherPoint | 4 | yes, 0–100% | 0.5 / 0.5 | Squatting, digging in the ground with both hands |
| `gather_found` | BaitGatherPoint | 4 | no | 0.2 / 0.3 | Still squatting, holds the find up to look at it |
| `prep_chop` | PrepTable | 4 | no | 0.2 / 0.2 | Standing at the table, left hand on the fish, right hand chopping |
| `prep_done` | PrepTable | 4 | no | 0.2 / 0.4 | Holds the result up and nods |

Priority 3 for the idle means the fight animations (4) and the reel and hook set
(5) always win over it while it keeps playing underneath.

The loop-in percentages make an animation hold its last pose: the wind-up or
the raised fish plays once, then the pose holds until the HUD stops it.
(Firestorm upload: Loop on, In % / Out %.)

## Sit-based objects

`gather_search` / `gather_found` squat (hips about 17 in lower) and the prep
animations stand. Both objects seat the avatar with `llSitTarget`, so after
uploading, check the avatar's height in-world. If it floats or sinks, adjust
the sit target Z in `BaitGatherPoint.lsl` (0.4) or `PrepTable.lsl` (0.6).

PrepTable used to borrow `gather_search` / `gather_found`, which would have put
the avatar in a squat at the table. It now uses `prep_chop` / `prep_done`.
