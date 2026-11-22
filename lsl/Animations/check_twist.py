"""Twist check for every .anim here (BEST_PRACTICES 60e).

Twist is a joint's rotation about its own bone (the forearm rolling, the
neck turning). Each joint's twist, taken from its keyframes by swing-twist
decomposition about the bone's rest direction, is compared with the twist
range of the matching bone on the Avastar rig: the joint's natural range.

Going past it is allowed only on purpose, and always pointed out to
Matthew. The sign of a rig range doesn't map one-to-one onto SL axes, so a
joint is flagged when its twist is past the LARGER end of the range
(OVER), and noted when it is past the smaller end only (check).

    python lsl/Animations/check_twist.py            # every .anim here
    python lsl/Animations/check_twist.py a.anim     # just these
"""
import glob, math, os, struct, sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from make_animations import SKEL, END_SITES, SL_JOINT  # noqa: E402

# Avastar rig twist (bone Y) range in degrees, per SL joint
TWIST = {
    "mTorso": (-60, 60), "mChest": (-60, 60), "mNeck": (-60, 60), "mHead": (-80, 80),
    "mCollarLeft": (-80, 80), "mShoulderLeft": (-90, 90), "mElbowLeft": (-10, 10), "mWristLeft": (-45, 45),
    "mCollarRight": (-80, 80), "mShoulderRight": (-90, 90), "mElbowRight": (-10, 10), "mWristRight": (-45, 45),
    "mHipLeft": (-60, 40), "mKneeLeft": (-40, 40), "mAnkleLeft": (-50, 20),
    "mHipRight": (-40, 60), "mKneeRight": (-40, 40), "mAnkleRight": (-20, 50),
}


def bone_axes():
    """SL joint name -> unit rest direction (SL axes: x fwd, y left, z up)."""
    child = {}
    for n, p, off, _ in SKEL:
        if p and p not in child:
            child[p] = off
    axes = {}
    for n, _, _, _ in SKEL:
        x, y, z = child.get(n) or END_SITES.get(n, (0, 1, 0))
        sl = (z, x, y)                        # BVH (x left, y up, z fwd) -> SL
        L = math.sqrt(sum(c * c for c in sl))
        axes[SL_JOINT[n]] = tuple(c / L for c in sl)
    return axes


def read_anim(path):
    d = open(path, 'rb').read(); o = [0]
    def r(f, n):
        v = struct.unpack_from(f, d, o[0])[0]; o[0] += n; return v
    def s():
        e = d.index(b'\0', o[0]); x = d[o[0]:e].decode(); o[0] = e + 1; return x
    r('<H', 2); r('<H', 2); prio = r('<i', 4); dur = r('<f', 4); s()
    r('<f', 4); r('<f', 4); r('<i', 4); r('<f', 4); r('<f', 4); r('<I', 4)
    joints = {}
    for _ in range(r('<I', 4)):
        name = s(); r('<i', 4)
        keys = []
        for _ in range(r('<i', 4)):
            t, x, y, z = struct.unpack_from('<4H', d, o[0]); o[0] += 8
            x, y, z = (v / 65535 * 2 - 1 for v in (x, y, z))
            w = math.sqrt(max(0.0, 1 - x * x - y * y - z * z))
            keys.append((t / 65535 * dur, w, x, y, z))
        npos = r('<i', 4); o[0] += 8 * npos  # position keys (read first: r() moves o)
        joints[name] = keys
    return prio, dur, joints


def twist_deg(q, axis):
    _, w, x, y, z = q
    p = x * axis[0] + y * axis[1] + z * axis[2]
    return math.degrees(2 * math.atan2(p, w))


def main(paths):
    axes = bone_axes()
    any_over = False
    for path in paths:
        prio, dur, joints = read_anim(path)
        rows = []
        for name, keys in joints.items():
            if name not in TWIST or not keys:
                continue
            tw = [(twist_deg(k, axes[name]), k[0]) for k in keys]
            lo, hi = TWIST[name]
            big = max(abs(lo), abs(hi)); small = min(abs(lo), abs(hi))
            worst, at = max(tw, key=lambda v: abs(v[0]))
            if abs(worst) > big + 0.5:
                rows.append(("OVER", name, worst, at, lo, hi)); any_over = True
            elif abs(worst) > small + 0.5:
                rows.append(("check", name, worst, at, lo, hi))
        label = os.path.basename(path)
        if not rows:
            print(f"{label:32s} ok")
        for kind, name, worst, at, lo, hi in rows:
            print(f"{label:32s} {kind:5s} {name:15s} twist {worst:7.1f} deg at {at:4.2f}s (rig {lo}..{hi})")
    return 1 if any_over else 0


if __name__ == "__main__":
    files = sys.argv[1:] or sorted(glob.glob(os.path.join(HERE, "*.anim")))
    sys.exit(main(files))
