# Avatar animations

Every animation the in-world scripts call, as SL/OpenSim binary `.anim` files
(KeyframeMotion 1.0). Priority, loop, ease and hand pose are **baked into each
file**, so uploading needs no settings: Build → Upload → Animation, pick the
file, upload. Made by `make_animations.py` (pure Python, no installs), except
the two gather animations (see below):

```
python lsl/Animations/make_animations.py          # .anim files, then the twist check
python lsl/Animations/check_twist.py              # twist check alone (any .anim)
```

Edit the poses or the `SETTINGS` table in that script and re-run it. Never
hand-edit the `.anim` files. Ship `.anim` only: it carries the priority,
loop, ease and hand pose, where a `.bvh` takes them from the upload window.
`--bvh` still writes `.bvh` files, but only when Matthew asks for them;
they're never committed.

`check_twist.py` measures each joint's twist (rotation about its own bone)
on every keyframe of every `.anim` here and compares it with the Avastar
rig's twist range for that joint, the joint's natural range. `OVER` stops
the generator: fix it, or if it's meant, say so to Matthew (BEST_PRACTICES
60e).

## Format

- Built on the standard SL skeleton. Each animation is a few hand-set poses
  (keyframes) joined by a smooth curve that keeps moving through each pose, so
  motion doesn't stop dead at every keyframe. The `.anim` gets a key every
  0.1 s plus one at every pose; the viewer fills in between.
- **Loops join cleanly:** a loop restarts at a keyframe whose pose is the same
  as the last one, and the curve's speed carries across the seam. The script
  refuses to build a loop whose ends differ. `fishing_charge` and
  `fishing_landed` play their start once, then loop the held pose.
- **Not too fast:** no joint turns faster than 400°/s. The script prints each
  animation's fastest joint turn.
- **The script enforces these rules** (and BEST_PRACTICES 60a–60d): it stops
  with an error if an animation doesn't hold every bone it owns, has a loop
  with no matching restart keyframe, turns a joint faster than 400°/s, or has
  keyframes out of order or closer than one frame.
- **A bone you don't move is free.** A bone left at the T-pose is left out of
  the animation, so other animations (AO, stands) can still move it. Every
  full-body animation here therefore holds all 19 bones: any bone that would
  rest at T-pose is set 1° off it (`NUDGE`). Only layered animations leave
  bones out, on purpose: `fishing_reel` holds just the left arm (collar,
  shoulder, elbow, wrist) so it plays on top of the fight poses. (This is about animations and
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
| `gather_search` | BaitGatherPoint | 4 | yes | Kneeling, digging in the dirt with alternating hands (Blender: DigForWorms) |
| `prep_chop` | PrepTable | 4 | no | Standing at the table, left hand on the fish, right hand chopping |
| `prep_done` | PrepTable | 4 | no | Holds the result up and nods (follows `prep_chop`, which is stopped first) |
| `fishing_cast` | FishingHUD | 5 | no | Forward snap to release, then settle into the hold (over `fishing_wait`) |
| `fishing_nibble` | FishingHUD | 5 | no | Small rod twitch and a lean in to look (over `fishing_wait`) |
| `fishing_yank` | FishingHUD | 5 | no | Hook set: rod jerked up and back (over the fight poses) |
| `fishing_reel` | FishingHUD | 5 | yes | Left hand cranking the reel. **Left arm only** (all 4 bones); it plays over the fight poses |
| `fishing_landed` | FishingHUD | 5 | raise once, then loops the hold | Rod lowered, fish raised up in the left hand (over the fight poses) |
| `fishing_disappointed` | FishingHUD | 5 | no | Shoulders slump, head shake (over `fishing_wait`) |
| `cheer` | FishingHUD | 5 | no | Left-hand fist pump twice. The HUD only *stops* this one today; nothing plays it yet |
| `gather_found` | BaitGatherPoint | 5 | no | Pinches at the dirt, sits up, holds the worm up and turns it to look, back to digging (over `gather_search`; Blender: DigFound) |

`fishing_wait` and the fight poses share priority 4, so the HUD stops
`fishing_wait` at hook set and the fight poses take over cleanly.

## Sit-based objects

`gather_search` / `gather_found` kneel, knees on the ground, and the prep
animations stand. BaitGatherPoint stops the default sit and places the
sitter's feet on the object's bottom for their height (the third description
field fine-tunes it); PrepTable uses a fixed sit target Z (0.6).

## Gather animations (Blender)

`gather_search` and `gather_found` are made in Blender with Avastar, on the
Avatar rig in `C:/Users/Matthew/Blender/animations/DigForWorms.blend`
(not in the repo, 12 MB). Pose them on a rig with standard SL proportions
(no joint offsets): the hand reach and kneel are solved by position, so a
rig with long bones puts the hands above the dirt on a normal avatar. Leg
IK must be on (`IKSwitchesProp.legs_ik_enabled`): the legs are posed with
the heel controls. Their scripts are in `blender/`:

- `DigForWorms_pose.py` builds the action **DigForWorms** (the dig loop).
- `DigFound_pose.py` builds **DigFound** from the same kneel, reusing those
  helpers.

Both keep every bone inside the rig's Limit Rotation constraints, the
natural range of each joint (the export doesn't clamp; going outside a limit
is a deliberate choice, flagged to Matthew; BEST_PRACTICES 60e). The only
excess today is the chest, 2-3° in the dig. `aim_rel` turns a bone from where
its parent leaves it (plain `aim` turns it from rest and loses the parent's
roll, which twists collars and elbows past their limits), and `arm_hinge`
rolls the upper arm so the elbow bends on its hinge. The neck sits on the
bisector of chest and head so the look-up isn't folded into the neck base.

Export each action with Avastar (`bpy.ops.avastar.export_single_anim`).
DigFound exports as `gather_found.anim` (priority 5). DigForWorms is exported
at priority 5; `gather_search.anim` is that file with the priority set to 4
in the header and every joint, so `gather_found` plays over it.

PrepTable used to borrow `gather_search` / `gather_found`, which would have put
the avatar in a squat at the table. It now uses `prep_chop` / `prep_done`.
