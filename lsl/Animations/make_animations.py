"""Generate the OSFishing avatar animations as SL/OpenSim BVH files.

    python make_animations.py          # writes every *.bvh next to this file

Pure Python (no numpy). Uses the standard SL BVH skeleton (inches, Y up,
avatar faces +Z, +X is the avatar's LEFT). Frame 0 is the T-pose reference
frame the uploader compares against; a joint left at T-pose for the whole
file is not animated, so layered animations (fishing_reel) only move the
joints they mean to.

Upload settings for each file are in README.md.
"""
import math
import os

FPS = 30
HERE = os.path.dirname(os.path.abspath(__file__))

# ── Skeleton ────────────────────────────────────────────────────────────────
# name, parent, offset, channel order (rotation part)
SKEL = [
    ("hip",      None,       (0.0, 0.0, 0.0),                "XZY"),
    ("abdomen",  "hip",      (0.0, 3.422050, 0.0),           "XZY"),
    ("chest",    "abdomen",  (0.0, 8.486693, -0.684411),     "XZY"),
    ("neck",     "chest",    (0.0, 10.266162, -0.273764),    "XZY"),
    ("head",     "neck",     (0.0, 3.148285, 0.0),           "XZY"),
    ("lCollar",  "chest",    (3.422053, 6.707223, -0.821293), "YZX"),
    ("lShldr",   "lCollar",  (3.285171, 0.0, 0.0),           "ZYX"),
    ("lForeArm", "lShldr",   (10.129278, 0.0, 0.0),          "YZX"),
    ("lHand",    "lForeArm", (8.486692, 0.0, 0.0),           "ZYX"),
    ("rCollar",  "chest",    (-3.558935, 6.707223, -0.821293), "YZX"),
    ("rShldr",   "rCollar",  (-3.148289, 0.0, 0.0),          "ZYX"),
    ("rForeArm", "rShldr",   (-10.266159, 0.0, 0.0),         "YZX"),
    ("rHand",    "rForeArm", (-8.349810, 0.0, 0.0),          "ZYX"),
    ("lThigh",   "hip",      (5.338403, -1.642589, 1.368821), "XZY"),
    ("lShin",    "lThigh",   (-2.053232, -20.121670, 0.0),   "XZY"),
    ("lFoot",    "lShin",    (0.0, -19.300380, -1.231939),   "XYZ"),
    ("rThigh",   "hip",      (-5.338403, -1.642589, 1.368821), "XZY"),
    ("rShin",    "rThigh",   (2.053232, -20.121670, 0.0),    "XZY"),
    ("rFoot",    "rShin",    (0.0, -19.300380, -1.231939),   "XYZ"),
]
END_SITES = {
    "head": (0.0, 3.148289, 0.0),
    "lHand": (4.106464, 0.0, 0.0),
    "rHand": (-4.106464, 0.0, 0.0),
    "lFoot": (0.0, -2.463878, 4.653993),
    "rFoot": (0.0, -2.463878, 4.653993),
}
HIP_Y = 43.528   # hip height that puts the feet on the ground
PARENT = {n: p for n, p, _, _ in SKEL}
OFFSET = {n: o for n, _, o, _ in SKEL}
ORDER = {n: c for n, _, _, c in SKEL}
REST_ANKLE_Y = HIP_Y + OFFSET["lThigh"][1] + OFFSET["lShin"][1] + OFFSET["lFoot"][1]

# ── Quaternion math (w, x, y, z) ────────────────────────────────────────────
QI = (1.0, 0.0, 0.0, 0.0)
AX = {"X": (1, 0, 0), "Y": (0, 1, 0), "Z": (0, 0, 1)}


def qaxis(axis, deg):
    if isinstance(axis, str):
        axis = AX[axis]
    a = math.radians(deg) / 2
    n = math.sqrt(sum(c * c for c in axis)) or 1.0
    s = math.sin(a) / n
    return (math.cos(a), axis[0] * s, axis[1] * s, axis[2] * s)


def qmul(a, b):
    aw, ax, ay, az = a
    bw, bx, by, bz = b
    return (aw * bw - ax * bx - ay * by - az * bz,
            aw * bx + ax * bw + ay * bz - az * by,
            aw * by - ax * bz + ay * bw + az * bx,
            aw * bz + ax * by - ay * bx + az * bw)


def qinv(q):
    return (q[0], -q[1], -q[2], -q[3])


def qrot(q, v):
    p = qmul(qmul(q, (0.0, v[0], v[1], v[2])), qinv(q))
    return (p[1], p[2], p[3])


def norm(v):
    n = math.sqrt(sum(c * c for c in v))
    return tuple(c / n for c in v)


def qbetween(u, v):
    u, v = norm(u), norm(v)
    d = sum(a * b for a, b in zip(u, v))
    c = (u[1] * v[2] - u[2] * v[1], u[2] * v[0] - u[0] * v[2], u[0] * v[1] - u[1] * v[0])
    if d < -0.9999:
        perp = (0, 1, 0) if abs(u[1]) < 0.9 else (1, 0, 0)
        return qaxis(perp, 180)
    q = (1 + d, c[0], c[1], c[2])
    n = math.sqrt(sum(x * x for x in q))
    return tuple(x / n for x in q)


def qslerp(a, b, t):
    d = sum(x * y for x, y in zip(a, b))
    if d < 0:
        b = tuple(-x for x in b)
        d = -d
    if d > 0.9995:
        r = tuple(x + (y - x) * t for x, y in zip(a, b))
    else:
        th = math.acos(d)
        s = math.sin(th)
        wa, wb = math.sin((1 - t) * th) / s, math.sin(t * th) / s
        r = tuple(wa * x + wb * y for x, y in zip(a, b))
    n = math.sqrt(sum(x * x for x in r))
    return tuple(x / n for x in r)


def ypr(yaw=0.0, pitch=0.0, roll=0.0):
    """yaw: + turns to the avatar's left; pitch: + bends forward; roll: + tips to the avatar's right."""
    return qmul(qmul(qaxis("Y", yaw), qaxis("X", pitch)), qaxis("Z", roll))


def qmat(q):
    w, x, y, z = q
    return [[1 - 2 * (y * y + z * z), 2 * (x * y - w * z), 2 * (x * z + w * y)],
            [2 * (x * y + w * z), 1 - 2 * (x * x + z * z), 2 * (y * z - w * x)],
            [2 * (x * z - w * y), 2 * (y * z + w * x), 1 - 2 * (x * x + y * y)]]


def to_euler(q, order):
    """Angles (deg) so that R = R_order[0] * R_order[1] * R_order[2] (BVH channel order)."""
    i, j, k = ("XYZ".index(c) for c in order)
    s = 1.0 if (i, j, k) in ((0, 1, 2), (1, 2, 0), (2, 0, 1)) else -1.0
    m = qmat(q)
    b = math.asin(max(-1.0, min(1.0, s * m[i][k])))
    if abs(m[i][k]) < 0.9999:
        a = math.atan2(-s * m[j][k], m[k][k])
        c = math.atan2(-s * m[i][j], m[i][i])
    else:  # gimbal lock
        a = math.atan2(s * m[k][j], m[j][j])
        c = 0.0
    return tuple(math.degrees(x) for x in (a, b, c))


def from_euler(angles, order):
    q = QI
    for ax, ang in zip(order, angles):
        q = qmul(q, qaxis(ax, ang))
    return q


def _selftest():
    import random
    rnd = random.Random(1)
    for order in ("XZY", "YZX", "ZYX", "XYZ"):
        for _ in range(200):
            ang = (rnd.uniform(-170, 170), rnd.uniform(-80, 80), rnd.uniform(-170, 170))
            q = from_euler(ang, order)
            q2 = from_euler(to_euler(q, order), order)
            assert abs(abs(sum(a * b for a, b in zip(q, q2))) - 1) < 1e-9, order


# ── Pose → joint rotations ──────────────────────────────────────────────────
# A pose is a dict. Arms are given as directions in the chest frame
# (x = avatar's left, y = up, z = forward): (upper-arm dir, forearm dir[, twist]).
BASE = {
    "hip_pos": (0.0, 0.0, 0.0),   # x/z shift in inches (y is auto-grounded)
    "hip": (0, 0, 0), "abdomen": (0, 0, 0), "chest": (0, 0, 0),
    "neck": (0, 0, 0), "head": (0, 0, 0),
    "lCollar": (0, 0), "rCollar": (0, 0),   # (forward, shrug) degrees
    "lArm": ((0.15, -1, 0.05), (0.1, -1, 0.25)),
    "rArm": ((-0.15, -1, 0.05), (-0.1, -1, 0.25)),
    "lHand": (0, 0, 0), "rHand": (0, 0, 0),
    "lLeg": ((0, 0, 0), 0), "rLeg": ((0, 0, 0), 0),   # (thigh yaw/pitch/roll, knee bend)
    "ground": True,
}


def pose(base=None, **kw):
    p = dict(BASE if base is None else base)
    p.update(kw)
    return p


def arm_quats(side, spec):
    rest = (1, 0, 0) if side == "l" else (-1, 0, 0)
    up, fore = spec[0], spec[1]
    twist = spec[2] if len(spec) > 2 else 0.0
    sh = qmul(qbetween(rest, up), qaxis(rest, twist))
    el = qmul(qmul(qinv(sh), qbetween(up, fore)), sh)
    return sh, el


def leg_quats(spec, hip_q):
    (ty, tp, tr), knee = spec
    th = ypr(ty, tp, tr)
    sh = qaxis("X", knee)
    # foot: undo the leg's pitch so the sole stays level with the ground
    world = qmul(qmul(hip_q, th), sh)
    fwd = qrot(world, (0, 0, 1))
    pitch = math.degrees(math.atan2(-fwd[1], math.hypot(fwd[0], fwd[2])))
    ft = qaxis("X", -pitch)
    return th, sh, ft


def pose_quats(p):
    q = {}
    q["hip"] = ypr(*p["hip"])
    for j in ("abdomen", "chest", "neck", "head"):
        q[j] = ypr(*p[j])
    lf, ls = p["lCollar"]
    rf, rs = p["rCollar"]
    q["lCollar"] = qmul(qaxis("Y", -lf), qaxis("Z", ls))
    q["rCollar"] = qmul(qaxis("Y", rf), qaxis("Z", -rs))
    q["lShldr"], q["lForeArm"] = arm_quats("l", p["lArm"])
    q["rShldr"], q["rForeArm"] = arm_quats("r", p["rArm"])
    q["lHand"] = ypr(*p["lHand"])
    q["rHand"] = ypr(*p["rHand"])
    q["lThigh"], q["lShin"], q["lFoot"] = leg_quats(p["lLeg"], q["hip"])
    q["rThigh"], q["rShin"], q["rFoot"] = leg_quats(p["rLeg"], q["hip"])
    return q


def world_pos(q, hip_pos):
    """Forward kinematics: world position of every joint."""
    wr, wp = {}, {}
    for n, par, off, _ in SKEL:
        if par is None:
            wr[n], wp[n] = q[n], hip_pos
        else:
            o = qrot(wr[par], off)
            wp[n] = tuple(a + b for a, b in zip(wp[par], o))
            wr[n] = qmul(wr[par], q[n])
    return wp


def ground_hip(q, p):
    hx, _, hz = p["hip_pos"]
    if not p.get("ground", True):
        return (hx, HIP_Y + p["hip_pos"][1], hz)
    wp = world_pos(q, (hx, HIP_Y, hz))
    low = min(wp["lFoot"][1], wp["rFoot"][1])
    return (hx, HIP_Y + (REST_ANKLE_Y - low), hz)


# ── Keyframes → frames ─────────────────────────────────────────────────────
def smooth(t):
    return t * t * (3 - 2 * t)


NUDGE = qaxis("X", 1.0)


def build(keys, only=None):
    """keys: [(time_s, pose), ...]. only: joint names to animate (others stay T-pose)."""
    solved = []
    for t, p in keys:
        q = pose_quats(p)
        solved.append((t, q, ground_hip(q, p)))
    total = keys[-1][0]
    n = int(round(total * FPS)) + 1
    frames = []
    for f in range(n):
        t = f / FPS
        k = 0
        while k < len(solved) - 2 and solved[k + 1][0] <= t:
            k += 1
        t0, q0, h0 = solved[k]
        t1, q1, h1 = solved[min(k + 1, len(solved) - 1)]
        u = 0.0 if t1 <= t0 else smooth(max(0.0, min(1.0, (t - t0) / (t1 - t0))))
        qs = {j: qslerp(q0[j], q1[j], u) for j in q0}
        hp = tuple(a + (b - a) * u for a, b in zip(h0, h1))
        if only is not None:
            qs = {j: (qs[j] if j in only else QI) for j in qs}
            if "hip" not in only:
                hp = (0.0, HIP_Y, 0.0)
        else:
            # A joint left at T-pose is dropped from the animation and stays free
            # for any other animation (AO, stands) to move. Full-body poses must
            # hold every bone, so nudge resting joints 1 degree off T-pose.
            qs = {j: (NUDGE if abs(q[0]) > 0.99996 else q) for j, q in qs.items()}
        frames.append((hp, qs))
    return frames


def write_bvh(name, frames):
    lines = ["HIERARCHY"]
    children = {}
    for n, par, _, _ in SKEL:
        children.setdefault(par, []).append(n)

    def emit(n, depth):
        ind = "  " * depth
        o = OFFSET[n]
        lines.append(f"{ind}{'ROOT' if PARENT[n] is None else 'JOINT'} {n}")
        lines.append(f"{ind}{{")
        lines.append(f"{ind}  OFFSET {o[0]:.6f} {o[1]:.6f} {o[2]:.6f}")
        rot = " ".join(f"{c}rotation" for c in ORDER[n])
        if PARENT[n] is None:
            lines.append(f"{ind}  CHANNELS 6 Xposition Yposition Zposition {rot}")
        else:
            lines.append(f"{ind}  CHANNELS 3 {rot}")
        for c in children.get(n, []):
            emit(c, depth + 1)
        if n in END_SITES:
            e = END_SITES[n]
            lines.extend([f"{ind}  End Site", f"{ind}  {{",
                          f"{ind}    OFFSET {e[0]:.6f} {e[1]:.6f} {e[2]:.6f}", f"{ind}  }}"])
        lines.append(f"{ind}}}")

    emit("hip", 0)
    ref = ((0.0, HIP_Y, 0.0), {n: QI for n in PARENT})
    allf = [ref] + frames
    lines += ["MOTION", f"Frames: {len(allf)}", f"Frame Time: {1.0 / FPS:.6f}"]
    for hp, qs in allf:
        vals = list(hp)
        for n, _, _, order in SKEL:
            vals += to_euler(qs[n], order)
        lines.append(" ".join(f"{v:.4f}" for v in vals))
    path = os.path.join(HERE, name + ".bvh")
    with open(path, "w", newline="\n") as fh:
        fh.write("\n".join(lines) + "\n")
    return path, len(frames) / FPS


# ── Poses ───────────────────────────────────────────────────────────────────
STAND = pose(lLeg=((0, -3, 4), 4), rLeg=((0, 2, -4), 3))

# Rod in the right hand at the waist, left hand on the reel just below it.
HOLD_ARMS = dict(
    rArm=((-0.3, -0.85, 0.45), (0.4, 0.25, 0.9)),
    lArm=((0.0, -0.85, 0.55), (-0.8, -0.05, 0.6)),
    rHand=(0, 0, -10), lHand=(0, 0, 0),
)
WAIT = pose(STAND, head=(0, 8, 0), neck=(0, 4, 0), **HOLD_ARMS)

# Fight: rod raised in front of the chest, left hand down on the reel.
FIGHT_ARMS = dict(
    rArm=((-0.35, -0.7, 0.4), (0.35, 0.75, 0.55)),
    lArm=((0.05, -0.85, 0.5), (-0.75, 0.1, 0.65)),
    rHand=(0, 0, -15), lHand=(0, 0, 0),
)
FIGHT_LEGS = dict(lLeg=((0, -14, 8), 14), rLeg=((0, 10, -8), 8))
FIGHT = pose(STAND, hip=(0, -4, 0), hip_pos=(0, 0, -1.0), abdomen=(0, -4, 0), chest=(0, -2, 0),
             head=(0, 10, 0), **FIGHT_ARMS, **FIGHT_LEGS)

# Squat for gathering bait.
SQUAT_LEGS = dict(lLeg=((0, -95, 10), 125), rLeg=((0, -80, -8), 115))


def wobble(base, t0, t1, step, fn):
    """Keyframes from t0 to t1 every `step` s; fn(i, base) returns a pose."""
    out, i, t = [], 0, t0
    while t <= t1 + 1e-9:
        out.append((round(t, 4), fn(i, base)))
        i += 1
        t += step
    return out


def anim_wait():
    def f(i, b):
        s = math.sin(i * math.pi / 2)
        return pose(b, chest=(0, 1.5 * s, 0), head=(3 * s, 8 + s, 0),
                    rArm=((-0.3, -0.85, 0.45), (0.4, 0.25 + 0.03 * s, 0.9)))
    return wobble(WAIT, 0, 4.0, 1.0, f)          # 4 s loop, frames 1 and last match


def anim_charge():
    back = pose(STAND, hip=(-15, 0, 0), abdomen=(-10, -6, 0), chest=(-10, -4, 0), head=(20, 0, 0),
                rArm=((-0.55, 0.2, 0.35), (-0.1, 0.9, -0.45), -20),
                lArm=((-0.1, -0.4, 0.9), (-0.65, 0.5, 0.55)),
                rCollar=(0, 8), rHand=(0, 0, 10),
                lLeg=((0, -8, 6), 10), rLeg=((0, 10, -6), 6))
    hold = [(0.5, back)]
    def f(i, b):
        s = 1 if i % 2 else -1
        return pose(b, chest=(-10, -4 + 0.8 * s, 0))
    return [(0.0, WAIT)] + hold + wobble(back, 0.7, 1.9, 0.3, f)


def anim_cast():
    back = anim_charge()[1][1]
    snap = pose(STAND, hip=(10, 4, 0), abdomen=(6, 8, 0), chest=(4, 6, 0), head=(-8, 4, 0),
                rArm=((-0.2, 0.05, 1.0), (0.05, 0.1, 1.0)),
                lArm=((0.05, -0.5, 0.85), (-0.5, -0.1, 0.85)),
                lLeg=((0, -14, 6), 12), rLeg=((0, 12, -6), 4))
    return [(0.0, back), (0.25, snap), (0.6, snap), (1.4, WAIT)]


def anim_nibble():
    look = pose(WAIT, abdomen=(0, 4, 0), head=(0, 18, 0),
                rArm=((-0.3, -0.8, 0.5), (0.4, 0.4, 0.85)))
    tw = pose(WAIT, rArm=((-0.3, -0.82, 0.48), (0.4, 0.38, 0.85)))
    return [(0.0, WAIT), (0.12, look), (0.25, tw), (0.4, look), (0.9, WAIT)]


def anim_yank():
    up = pose(STAND, hip=(0, -8, 0), hip_pos=(0, 0, -2.0), abdomen=(0, -10, 0), chest=(0, -6, 0),
              head=(0, 0, 0),
              rArm=((-0.35, -0.3, 0.3), (0.2, 0.95, -0.1)),
              lArm=((0.05, -0.6, 0.6), (-0.7, 0.5, 0.4)),
              **FIGHT_LEGS)
    return [(0.0, WAIT), (0.15, up), (0.45, up), (1.0, FIGHT)]


def _fight_loop(b, amp_pitch=3.0, step=0.5, length=2.0, shake=0.0):
    def f(i, base):
        s = math.sin(i * math.pi / 2)
        jit = shake * (1 if i % 2 else -1)
        a = base["abdomen"]
        c = base["chest"]
        return pose(base, abdomen=(a[0], a[1] + amp_pitch * s, a[2] + jit),
                    chest=(c[0] + jit, c[1] - amp_pitch * 0.5 * s, c[2]))
    return wobble(b, 0, length, step, f)


def anim_fight_steady():
    return _fight_loop(FIGHT)


def anim_fight_lean(side):
    sgn = 1 if side == "left" else -1
    b = pose(FIGHT, hip=(12 * sgn, -4, -4 * sgn), hip_pos=(-1.5 * sgn, 0, -1.0),
             abdomen=(10 * sgn, -4, -6 * sgn), chest=(8 * sgn, -2, -4 * sgn),
             head=(-10 * sgn, 10, 4 * sgn),
             lLeg=((0, -10, 12 if sgn > 0 else 6), 10 if sgn > 0 else 18),
             rLeg=((0, 8, -6 if sgn > 0 else -12), 18 if sgn > 0 else 10))
    return _fight_loop(b)


def anim_fight_strain():
    b = pose(FIGHT, hip=(0, -12, 0), hip_pos=(0, 0, -3.5), abdomen=(0, -10, 0), chest=(0, -6, 0),
             head=(0, 14, 0), lCollar=(0, 6), rCollar=(0, 6),
             rArm=((-0.3, -0.75, 0.35), (0.4, 0.8, 0.35)),
             lArm=((0.05, -0.85, 0.45), (-0.8, 0.15, 0.5)),
             lLeg=((0, -28, 10), 32), rLeg=((0, 4, -10), 22))
    return _fight_loop(b, amp_pitch=1.5, step=0.1, length=1.6, shake=1.2)


def anim_landed():
    show = pose(STAND, head=(18, -12, 0), neck=(6, -4, 0), chest=(6, -3, 0),
                rArm=((-0.3, -0.95, 0.1), (-0.05, -0.35, 1.0)),
                lArm=((0.25, 0.35, 0.9), (0.05, 0.8, 0.6)), lHand=(0, 0, 10))
    up = pose(show, lArm=((0.25, 0.45, 0.85), (0.05, 0.85, 0.5)))
    return [(0.0, FIGHT), (0.6, show), (1.1, up), (1.6, show), (3.0, show)]


def anim_disappointed():
    slump = pose(WAIT, abdomen=(0, 8, 0), chest=(0, 10, 0), neck=(0, 10, 0), head=(0, 22, 0),
                 lCollar=(4, -6), rCollar=(4, -6))
    def shake(y):
        return pose(slump, head=(y, 22, 0))
    return [(0.0, WAIT), (0.5, slump), (0.8, shake(-15)), (1.1, shake(15)),
            (1.4, shake(-12)), (1.7, shake(0)), (2.3, slump), (2.8, WAIT)]


def anim_cheer():
    down = pose(STAND, head=(0, -10, 0), chest=(0, -4, 0),
                rArm=((-0.3, -0.95, 0.1), (-0.05, -0.35, 1.0)),
                lArm=((0.45, -0.2, 0.4), (0.0, 1.0, 0.1)), lHand=(0, 0, 0))
    up = pose(down, chest=(0, -8, 0), lArm=((0.3, 0.95, 0.05), (0.05, 1.0, 0.0)), lCollar=(0, 8))
    return [(0.0, WAIT), (0.3, down), (0.55, up), (0.8, down), (1.05, up), (1.3, down), (2.0, WAIT)]


def anim_reel():
    # Left arm only — layers on top of whatever fight pose is playing.
    pts = []
    for i in range(5):
        a = i * math.pi / 2
        pts.append((i * 0.15, pose(FIGHT, lArm=((0.05 + 0.05 * math.cos(a), -0.85 + 0.12 * math.sin(a), 0.5),
                                               (-0.75, 0.1 + 0.35 * math.sin(a), 0.65 + 0.3 * math.cos(a))),
                                    lHand=(0, 0, 0))))
    return pts


def anim_gather_search():
    b = pose(BASE, hip=(0, 10, 0), abdomen=(0, 15, 0), chest=(0, 15, 0), head=(0, 15, 0),
             rArm=((-0.2, -0.55, 0.8), (0.1, -0.85, 0.5)),
             lArm=((0.2, -0.55, 0.8), (-0.1, -0.85, 0.5)), **SQUAT_LEGS)
    def dig(i, base):
        s = 1 if i % 2 else -1
        return pose(base, head=(6 * s, 16, 0),
                    rArm=((-0.2, -0.55 + 0.15 * s, 0.8), (0.1, -0.85 + 0.25 * s, 0.5)),
                    lArm=((0.2, -0.55 - 0.15 * s, 0.8), (-0.1, -0.85 - 0.25 * s, 0.5)))
    return wobble(b, 0, 3.0, 0.375, dig)


def anim_gather_found():
    base = anim_gather_search()[0][1]
    look = pose(base, hip=(0, 4, 0), abdomen=(0, 4, 0), chest=(0, 2, 0), head=(12, -6, 0),
                lArm=((0.15, -0.2, 0.95), (-0.1, 0.65, 0.75)), lHand=(0, 0, 15),
                rArm=((-0.2, -0.8, 0.5), (0.05, -0.6, 0.8)))
    turn = pose(look, lHand=(25, 0, 15), head=(16, -4, 0))
    return [(0.0, base), (0.35, look), (0.8, turn), (1.1, look), (1.5, base)]


def anim_prep_chop():
    b = pose(STAND, abdomen=(0, 10, 0), chest=(0, 8, 0), head=(0, 20, 0),
             lArm=((0.1, -0.7, 0.7), (-0.3, -0.3, 0.9)),
             rArm=((-0.25, -0.6, 0.6), (0.1, 0.5, 0.85)), rHand=(0, 0, -20))
    down = pose(b, rArm=((-0.25, -0.75, 0.55), (0.1, -0.45, 0.9)), rHand=(0, 0, 10))
    return [(0.0, b), (0.3, down), (0.5, b), (0.8, down), (1.0, b)]


def anim_prep_done():
    b = anim_prep_chop()[0][1]
    show = pose(STAND, head=(-12, -6, 0), chest=(0, -2, 0),
                lArm=((0.15, -0.95, 0.1), (0.05, -0.4, 1.0)),
                rArm=((-0.25, 0.2, 0.9), (-0.05, 0.8, 0.6)), rHand=(0, 0, -10))
    return [(0.0, b), (0.4, show), (0.8, pose(show, head=(-12, 4, 0))),
            (1.1, show), (1.6, b)]


LEFT_ARM = {"lCollar", "lShldr", "lForeArm", "lHand"}

ANIMS = {
    "fishing_wait":             (anim_wait, None),
    "fishing_charge":           (anim_charge, None),
    "fishing_cast":             (anim_cast, None),
    "fishing_nibble":           (anim_nibble, None),
    "fishing_yank":             (anim_yank, None),
    "fishing_reel":             (anim_reel, LEFT_ARM),
    "fishing_fight_steady":     (anim_fight_steady, None),
    "fishing_fight_lean_left":  (lambda: anim_fight_lean("left"), None),
    "fishing_fight_lean_right": (lambda: anim_fight_lean("right"), None),
    "fishing_fight_strain":     (anim_fight_strain, None),
    "fishing_landed":           (anim_landed, None),
    "fishing_disappointed":     (anim_disappointed, None),
    "cheer":                    (anim_cheer, None),
    "gather_search":            (anim_gather_search, None),
    "gather_found":             (anim_gather_found, None),
    "prep_chop":                (anim_prep_chop, None),
    "prep_done":                (anim_prep_done, None),
}

# ── SL binary .anim (KeyframeMotion v1.0) ──────────────────────────────────
import struct

SL_JOINT = {
    "hip": "mPelvis", "abdomen": "mTorso", "chest": "mChest", "neck": "mNeck", "head": "mHead",
    "lCollar": "mCollarLeft", "lShldr": "mShoulderLeft", "lForeArm": "mElbowLeft", "lHand": "mWristLeft",
    "rCollar": "mCollarRight", "rShldr": "mShoulderRight", "rForeArm": "mElbowRight", "rHand": "mWristRight",
    "lThigh": "mHipLeft", "lShin": "mKneeLeft", "lFoot": "mAnkleLeft",
    "rThigh": "mHipRight", "rShin": "mKneeRight", "rFoot": "mAnkleRight",
}
MAX_PELVIS_OFFSET = 5.0      # metres, LL_MAX_PELVIS_OFFSET
INCH = 0.0254
HAND_RELAXED, HAND_FIST = 1, 3

# priority 4 = base animation, 5 = plays over a base animation.
# loop_in is a fraction of the length (the pose from there to the end repeats).
#                            priority loop  loop_in ease_in ease_out hand
SETTINGS = {
    "fishing_wait":             (4, True,  0.0,  0.5, 0.5, HAND_FIST),
    "fishing_charge":           (4, True,  0.25, 0.3, 0.2, HAND_FIST),
    "fishing_fight_steady":     (4, True,  0.0,  0.3, 0.3, HAND_FIST),
    "fishing_fight_lean_left":  (4, True,  0.0,  0.3, 0.3, HAND_FIST),
    "fishing_fight_lean_right": (4, True,  0.0,  0.3, 0.3, HAND_FIST),
    "fishing_fight_strain":     (4, True,  0.0,  0.3, 0.3, HAND_FIST),
    "gather_search":            (4, True,  0.0,  0.5, 0.5, HAND_RELAXED),
    "prep_chop":                (4, False, 0.0,  0.2, 0.2, HAND_FIST),
    "prep_done":                (4, False, 0.0,  0.2, 0.4, HAND_RELAXED),
    "fishing_cast":             (5, False, 0.0,  0.1, 0.4, HAND_FIST),
    "fishing_nibble":           (5, False, 0.0,  0.1, 0.2, HAND_FIST),
    "fishing_yank":             (5, False, 0.0,  0.1, 0.3, HAND_FIST),
    "fishing_reel":             (5, True,  0.0,  0.2, 0.2, HAND_FIST),
    "fishing_landed":           (5, True,  0.55, 0.3, 0.5, HAND_FIST),
    "fishing_disappointed":     (5, False, 0.0,  0.3, 0.5, HAND_FIST),
    "cheer":                    (5, False, 0.0,  0.2, 0.4, HAND_FIST),
    "gather_found":             (5, False, 0.0,  0.2, 0.3, HAND_RELAXED),
}


def _u16(v, lo, hi):
    v = max(lo, min(hi, v))
    return int(round((v - lo) / (hi - lo) * 65535))


def write_anim(name, frames):
    prio, loop, loop_in, ease_in, ease_out, hand = SETTINGS[name]
    dur = (len(frames) - 1) / FPS
    times = [_u16(i / FPS, 0.0, dur) for i in range(len(frames))]
    joints = []
    for n, _, _, _ in SKEL:
        rots = [qs[n] for _, qs in frames]
        moves = any(abs(r[0]) < 0.99999 for r in rots)
        pos = []
        if n == "hip":
            pos = [tuple((a - b) * INCH for a, b in zip(hp, (0.0, HIP_Y, 0.0))) for hp, _ in frames]
            if not any(max(abs(c) for c in p) > 0.0005 for p in pos):
                pos = []
        if not moves and not pos:
            continue   # never leaves the reference pose: not animated (layers cleanly)
        rk = []
        if moves:
            for t, (w, x, y, z) in zip(times, rots):
                if w < 0:
                    w, x, y, z = -w, -x, -y, -z
                # BVH (x left, y up, z fwd) → SL (x fwd, y left, z up)
                rk.append(struct.pack("<4H", t, _u16(z, -1, 1), _u16(x, -1, 1), _u16(y, -1, 1)))
        pk = [struct.pack("<4H", t, *(_u16(c, -MAX_PELVIS_OFFSET, MAX_PELVIS_OFFSET) for c in (pz, px, py)))
              for t, (px, py, pz) in zip(times, pos)]
        joints.append(SL_JOINT[n].encode() + b"\0" + struct.pack("<ii", prio, len(rk)) + b"".join(rk)
                      + struct.pack("<i", len(pk)) + b"".join(pk))
    data = struct.pack("<HHif", 1, 0, prio, dur) + b"\0"
    data += struct.pack("<ffiffII", loop_in * dur, dur, 1 if loop else 0, ease_in, ease_out, hand, len(joints))
    data += b"".join(joints) + struct.pack("<i", 0)
    path = os.path.join(HERE, name + ".anim")
    with open(path, "wb") as fh:
        fh.write(data)
    return path, dur, len(joints), len(data)


if __name__ == "__main__":
    import sys
    _selftest()
    for name, (fn, only) in ANIMS.items():
        frames = build(fn(), only)
        path, secs, nj, size = write_anim(name, frames)
        if "--bvh" in sys.argv:   # for previewing in Blender
            write_bvh(name, frames)
        print(f"{name:26s} prio {SETTINGS[name][0]}  {secs:4.2f}s  {nj:2d} joints  {size:6d} B")
