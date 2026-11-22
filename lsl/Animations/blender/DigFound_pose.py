# DigFound — the "found one" for DigForWorms (gather_found).
# Kneeling like DigForWorms: the right hand pinches at the dirt, the avatar
# sits up, holds the worm up in front of the face and turns it to look, then
# goes back down into the dig pose (DigForWorms frame 2), so it hands back
# to the dig loop without a jump. Plays over it at priority 5.
#
# Reuses DigForWorms_pose.py's rig helpers (rest, legs, aim_rel, arm_hinge); run in the
# DigForWorms.blend with the Avatar rig. Builds the action "DigFound" and
# leaves DigForWorms untouched.
import bpy, math, os
from mathutils import Vector

HERE = os.path.dirname(bpy.data.filepath)
_src = open(os.path.join(HERE, 'DigForWorms_pose.py')).read().split('worst = build()')[0]
G = {}
exec(_src, G)
a, P, sc = G['a'], G['P'], G['sc']
aim, arm, KEY, LOCS, upd = G['aim'], G['arm'], G['KEY'], G['LOCS'], G['upd']

_limits, _excess, aim_rel, arm_hinge = G['_limits'], G['_excess'], G['aim_rel'], G['arm_hinge']

def lerp(p, q, t):
    return Vector(p).lerp(Vector(q), t)

def look_dir(f):
    # head bone Y for a face looking along f (face looks down -Y at rest)
    f = Vector(f).normalized()
    return Vector((f.x * 0.6, f.z, -f.y))

def found(lift, reach=0.0, turn=0.0):
    """lift 0 = dig pose (DigForWorms t=0), 1 = sat up holding the worm to
    the face. reach = right hand pinching at the dirt (only at lift 0).
    turn = turning the worm to look at it (-1..1)."""
    G['pose'](0.0)                       # legs, pelvis, dig arms at t=0
    if lift == 0 and reach == 0:
        return                           # exactly the dig pose: hands back cleanly
    # dig pose at t=0 (from DigForWorms_pose.pose)
    dig_r = Vector((-0.20, G['HAND_Y'] - 0.11, G['HAND_Z']))
    dig_r_wrist = (0, -0.7, -0.85)
    dig_l = Vector((0.20, G['HAND_Y'] + 0.11, G['HAND_Z']))
    dig_l_wrist = (0, -0.1, -0.85)
    l = lift * lift * (3 - 2 * lift)     # smoothstep
    aim('Torso', lerp((0, G['TORSO'][0], G['TORSO'][1]), (0, -0.32, 0.95), l))
    aim('Chest', lerp((0, G['CHEST'][0], G['CHEST'][1]), (0, -0.25, 0.97), l))   # continues the torso, no backward kink
    aim('Neck',  lerp(G['neck_dir'](), (0, -0.50, 0.87), l))   # neck leans forward, sharing the look-down with the head
    H = P['Head'].head.copy()
    # right hand: dig spot → pinch in the middle → up in front of the face
    pinch = Vector((-0.07, -0.66, 0.03))
    face = H + Vector((-0.08 + 0.03 * turn, -0.30, -0.14))
    r = dig_r.lerp(pinch, reach).lerp(face, l)
    arm_hinge('Right', r, lerp((-1.0, 0.6, 0.3), (-1.0, 0.1, -0.7), l))
    aim_rel('WristRight', lerp(lerp(dig_r_wrist, (0, -0.8, -0.6), reach),
                           (0.35 * turn, -0.45, 0.9), l))
    # left hand: stays in the dirt, then rests on the left thigh
    lh = dig_l.lerp(Vector((0.17, -0.44, 0.42)), l)
    arm_hinge('Left', lh, lerp((1.0, 0.6, 0.3), (1.0, 0.4, 0.0), l))
    aim_rel('WristLeft', lerp(dig_l_wrist, (0.1, -0.6, -0.4), l))   # inside the wrist's limits
    # head: looks at the right hand (the worm), a tilt while turning it
    eye = H + Vector((0, -0.08, 0.10))
    d = look_dir(r - eye)
    d.x += 0.12 * turn
    aim('Head', lerp((0.08 * math.sin(0.6), -0.75, 0.55), d, max(l, 0.6 * reach)))

# frame, lift, reach, turn
KEYS = [(1, 0, 0, 0), (8, 0, 1, 0), (13, 0.25, 0.6, 0), (22, 1, 0, 0),
        (29, 1, 0, 1), (35, 1, 0, -0.6), (40, 1, 0, 0), (52, 0, 0, 0)]
END = KEYS[-1][0]

def build():
    keep = bpy.data.actions.get('DigForWorms')
    old = bpy.data.actions.get('DigFound')
    if old: bpy.data.actions.remove(old)
    act = bpy.data.actions.new('DigFound')
    a.animation_data_create(); a.animation_data.action = act
    for f, lift, reach, turn in KEYS:
        sc.frame_set(f); found(lift, reach, turn)
        for n in KEY:
            P[n].keyframe_insert('rotation_quaternion', frame=f)
            if n in LOCS: P[n].keyframe_insert('location', frame=f)
    for fc in act.fcurves:
        for k in fc.keyframe_points: k.interpolation = 'BEZIER'
    ap = act.AnimProp
    ap.frame_start = 1; ap.frame_end = END; ap.fps = 30; ap.Loop = False
    ap.Priority = 5; ap.Ease_In = 0.3; ap.Ease_Out = 0.4
    ap.with_reference_frame = False; ap.Mode = 'anim'
    act.use_fake_user = True
    if keep: keep.use_fake_user = True
    return act

build()
