import bpy, math
from mathutils import Vector, Matrix

# ---- tunables -------------------------------------------------------------
HIP_Y, HIP_Z = -0.20, 0.50        # hip joints (world)
KNEE_Z = 0.069                    # knee joint height (kneecap resting on the ground)
ANKLE_Z = 0.09                    # ankles behind, just off the ground
HIP_TILT = 40.0                   # pelvis rolled forward (deg) to line up with the torso
ROCK = 1.5                        # pelvis rock amplitude (deg)
PRIORITY = 5
KNEE_POLE_Y = -0.6                # knee target in front so knees bend forward
FOOT_DIR = Vector((0, 0.93, -0.36))   # toes pointing back, tips on the ground
TORSO = (-0.95, 0.3); CHEST = (-0.8, -0.6)   # tuned on a standard-size rig 2026-11-21
HAND_Y, HAND_Z, HAND_X = -0.50, 0.05, 0.20
N = 60
# ---------------------------------------------------------------------------

a = bpy.data.objects['Avatar']; P = a.pose.bones; sc = bpy.context.scene
bpy.context.view_layer.objects.active = a
if a.mode != 'POSE': bpy.ops.object.mode_set(mode='POSE')
def upd(): bpy.context.view_layer.update()

def aim(n, d):
    pb = P[n]; d = Vector(d).normalized(); rest = pb.bone.matrix_local
    rdir = (rest.to_3x3() @ Vector((0, 1, 0))).normalized()
    m = (rdir.rotation_difference(d).to_matrix() @ rest.to_3x3()).to_4x4()
    m.translation = pb.head.copy(); pb.matrix = m; upd()

def arm(s, T, pole):
    S = P['Shoulder'+s].head.copy(); L1 = P['Shoulder'+s].bone.length
    L2 = P['Elbow'+s].bone.length + P['Wrist'+s].bone.length*0.5
    D = T - S; d = min(D.length, (L1+L2)*0.98); u = D.normalized()
    x = (L1*L1 - L2*L2 + d*d)/(2*d); h = math.sqrt(max(0, L1*L1 - x*x))
    p = Vector(pole); p = (p - p.dot(u)*u).normalized(); E = S + u*x + p*h
    aim('Shoulder'+s, E - S); aim('Elbow'+s, (S + u*d) - E)
    return (D.length - (L1+L2))   # >0 means hand can't reach


def _limits(n):
    for c in P[n].constraints:
        if c.type == 'LIMIT_ROTATION' and c.influence > 0:
            return [(getattr(c, 'min_' + ax), getattr(c, 'max_' + ax)) for ax in 'xyz']
    return None

def _excess(n):
    # how far (radians) a bone's local rotation is outside its Limit Rotation
    lim = _limits(n)
    if not lim: return 0.0
    e = P[n].rotation_quaternion.to_euler('XYZ')
    return sum(max(0, lo - v) + max(0, v - hi) for v, (lo, hi) in zip(e, lim))

def aim_rel(n, d):
    # aim() turns a bone from its REST frame, which throws away its parent's
    # roll; this turns it from where its parent leaves it, so a bone that
    # only bends on one axis (the elbow) gets a clean bend
    pb = P[n]; pb.rotation_quaternion = (1, 0, 0, 0); upd()
    m = pb.matrix.copy(); cur = (m.to_3x3() @ Vector((0, 1, 0))).normalized()
    r = (cur.rotation_difference(Vector(d).normalized()).to_matrix() @ m.to_3x3()).to_4x4()
    r.translation = pb.head.copy(); pb.matrix = r; upd()

def arm_hinge(s, T, pole):
    """arm(), plus a roll of the upper arm about its
    own axis so the elbow bends on its hinge. The rig's Limit Rotation
    (elbow: bend on Z only, twist +-10) is the joint's natural range; aimed
    from rest, a raised arm twists far outside it."""
    arm(s, T, pole)
    S = P['Shoulder' + s].head.copy(); E = P['Elbow' + s].head.copy()
    goal = Vector(T)                     # the hand target (aim is direction only)
    ax = (E - S).normalized()
    base = P['Shoulder' + s].matrix.copy()
    def place(deg):
        R = Matrix.Translation(S) @ Matrix.Rotation(math.radians(deg), 4, ax) @ Matrix.Translation(-S)
        P['Shoulder' + s].matrix = R @ base; upd()
        aim_rel('Elbow' + s, goal - P['Elbow' + s].head)
        return _excess('Shoulder' + s) + _excess('Elbow' + s)
    best = min(range(0, 360, 10), key=place)
    best = min(range(best - 9, best + 10), key=place)
    place(best)

KEY = ['COG','Pelvis','ikKneeTargetLeft','ikKneeTargetRight','ikHeelLeft','ikHeelRight',
       'Torso','Chest','Neck','Head','CollarLeft','CollarRight',
       'ShoulderLeft','ShoulderRight','ElbowLeft','ElbowRight','WristLeft','WristRight']
LOCS = ('COG','Pelvis','ikKneeTargetLeft','ikKneeTargetRight','ikHeelLeft','ikHeelRight')

def rest():
    for n in KEY:
        P[n].rotation_quaternion = (1,0,0,0); P[n].location = (0,0,0)
    upd()

def legs():
    # knee on the ground in front of the hip, ankle a shin-length behind the knee
    thigh = (P['KneeLeft'].bone.head_local - P['HipLeft'].bone.head_local).length
    shin = (P['AnkleLeft'].bone.head_local - P['KneeLeft'].bone.head_local).length
    knee_y = HIP_Y - math.sqrt(max(0, thigh**2 - (HIP_Z - KNEE_Z)**2))
    ANKLE_Y = knee_y + math.sqrt(max(0, shin**2 - (ANKLE_Z - KNEE_Z)**2)) * 0.995
    # hips: move COG so hip midpoint lands at HIP_Y/HIP_Z
    hm = (P['HipLeft'].bone.head_local + P['HipRight'].bone.head_local)*0.5
    cog = P['COG']
    cog.location = cog.bone.matrix_local.to_3x3().inverted() @ Vector((0, HIP_Y - hm.y, HIP_Z - hm.z))
    for s, sx in (('Left',1),('Right',-1)):
        # heel control: rotate so the foot points back, and place the ankle behind
        hb = P['ikHeel'+s]; rest_m = hb.bone.matrix_local
        ank = P['ikAnkle'+s].bone.head_local; ball = P['ikFootBall'+s].bone.head_local
        q = (ball - ank).normalized().rotation_difference(FOOT_DIR)
        new_ank = Vector((ank.x, ANKLE_Y, ANKLE_Z))
        R = q.to_matrix().to_4x4()
        # rotate rest heel about the ankle, then move ankle to new spot
        m = Matrix.Translation(new_ank) @ R @ Matrix.Translation(-ank) @ rest_m
        hb.matrix = m
    upd()
    # knee targets are children of the heel: place them in world space after the heel moved
    for s, sx in (('Left',1),('Right',-1)):
        kt = P['ikKneeTarget'+s]; m = kt.matrix.copy()
        m.translation = Vector((0.13*sx, KNEE_POLE_Y, 0.2)); kt.matrix = m
    upd()

def neck_dir():
    return (Vector((0, CHEST[0], CHEST[1])).normalized() + Vector((0, -0.75, 0.55)).normalized()).normalized()

def pose(t):
    rest(); legs()
    w = 2*math.pi*t
    pv = (P['HipLeft'].head + P['HipRight'].head)*0.5
    ang = math.radians(HIP_TILT + ROCK*math.sin(2*w))
    P['Pelvis'].matrix = Matrix.Translation(pv) @ Matrix.Rotation(ang,4,'X') @ Matrix.Translation(-pv) @ P['Pelvis'].matrix; upd()
    sway = 0.04*math.sin(w); bob = 0.06*math.sin(2*w)
    aim('Torso',(sway*0.5, TORSO[0]-bob, TORSO[1])); aim('Chest',(sway, CHEST[0], CHEST[1]))
    # neck on the bisector of chest and head: the look-up is shared, not folded into the neck base
    aim('Neck', neck_dir()); aim('Head',(0.08*math.sin(w+0.6),-0.75,0.55))
    miss = 0
    for s, sx, ph in (('Left',1,0.0),('Right',-1,math.pi)):
        aim_rel('Collar'+s,(sx*0.85,-0.5,-0.15)); th = w + ph
        y = HAND_Y + 0.11*math.cos(th); z = HAND_Z + 0.06*max(0, math.sin(th)); x = sx*(HAND_X + 0.03*math.sin(th))
        miss = max(miss, arm(s, Vector((x,y,z)), (sx*1.0, 0.6, 0.3)))
        arm_hinge(s, Vector((x,y,z)), (sx*1.0, 0.6, 0.3))   # same arm, elbow on its hinge
        aim_rel('Wrist'+s,(0,-0.4+0.3*math.cos(th),-0.85))
    return miss

def key(f):
    for n in KEY:
        P[n].keyframe_insert('rotation_quaternion', frame=f)
        if n in LOCS: P[n].keyframe_insert('location', frame=f)

def build():
    if a.animation_data and a.animation_data.action:
        old = a.animation_data.action; a.animation_data.action = None; bpy.data.actions.remove(old)
    sc.render.fps = 30; sc.frame_start = 1; sc.frame_end = N + 2
    sc.frame_set(1); rest(); key(1)
    worst = 0
    for f in range(2, N + 3, 3):
        sc.frame_set(f); worst = max(worst, pose((f - 2)/N)); key(f)
    act = a.animation_data.action; act.name = 'DigForWorms'
    for fc in act.fcurves:
        for k in fc.keyframe_points:
            k.interpolation = 'CONSTANT' if k.co.x == 1 else 'BEZIER'
    ap = act.AnimProp
    ap.frame_start = 1; ap.frame_end = N + 2; ap.fps = 30; ap.Loop = True; ap.Loop_In = 2; ap.Loop_Out = N + 2
    ap.Priority = PRIORITY; ap.Ease_In = 0.8; ap.Ease_Out = 0.8; ap.with_reference_frame = False; ap.Mode = 'anim'
    sc.frame_set(17)
    return worst

worst = build()
W = a.matrix_world
for n in ('HipLeft','KneeLeft','AnkleLeft','FootLeft'):
    print(n, [round(v,3) for v in W @ P[n].head], 'tail', [round(v,3) for v in W @ P[n].tail])
print('hand reach shortfall (m):', round(worst,3))

