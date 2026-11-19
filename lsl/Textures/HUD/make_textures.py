"""Generate the OSFishing HUD textures as transparent PNGs.

Each texture is written as a small HTML page under src/ and screenshotted
with headless Chrome (or Edge), so the art stays editable as plain markup.

    python make_textures.py              # writes every PNG next to this script
    python make_textures.py hud_bar      # just the named ones

See BUILD.md for where each texture goes and the sheet cell maps.
"""
import os
import shutil
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, 'src')

BROWSERS = [
    r'C:\Program Files\Google\Chrome\Application\chrome.exe',
    r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
    shutil.which('chrome') or '', shutil.which('google-chrome') or '', shutil.which('chromium') or '',
]

BLUE = '#1F6FE0'
DEEP = '#0B3D91'
NAVY = '#082A66'
SKY = '#7CC4FF'
WHITE = '#FFFFFF'
CORAL = '#F0524F'
AMBER = '#FFB547'
MINT = '#6EF0A0'
FONT_LINK = '<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&display=swap" rel="stylesheet">'


def page(w, h, body):
    return f'''<!doctype html><html><head><meta charset="utf-8">{FONT_LINK}
<style>html,body{{margin:0;padding:0;background:transparent;overflow:hidden}}
body{{width:{w}px;height:{h}px;position:relative;font-family:Fredoka,system-ui,sans-serif}}</style>
</head><body>{body}</body></html>'''


# ---- shapes (24x24 viewBox unless noted) ---------------------------------------

def fish_svg(body, outline, eye=NAVY, size=100, flip=False, extra=''):
    """Side-view fish facing LEFT (mirrored with flip)."""
    t = ' transform="translate(24,0) scale(-1,1)"' if flip else ''
    return f'''<svg width="{size}" height="{size}" viewBox="0 0 24 24"><g{t}>{extra}
<path d="M2.5 12c2.6-4.4 6.4-6 10-6 2.6 0 4.6 1.4 6 3.2L22 6.5v11l-3.5-2.7C17.1 16.6 15.1 18 12.5 18c-3.6 0-7.4-1.6-10-6z"
 fill="{body}" stroke="{outline}" stroke-width="1.4" stroke-linejoin="round"/>
<path d="M11.5 8.6c.9 1 .9 5.8 0 6.8" fill="none" stroke="{outline}" stroke-width="1.1" stroke-linecap="round" opacity=".7"/>
<circle cx="6.6" cy="10.8" r="1.15" fill="{eye}"/></g></svg>'''


HOOK = '<path d="M15 2.5v9.5a4 4 0 0 1-8 0v-2.2l-2 2" fill="none" stroke="{c}" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="15" cy="2.6" r="1.4" fill="none" stroke="{c}" stroke-width="1.6"/>'
WORM = '<path d="M3 15c2-4 4-4 5.5-1.5S12 17 13.5 13s3.5-6 7.5-3" fill="none" stroke="{c}" stroke-width="3" stroke-linecap="round"/><circle cx="20.6" cy="9.6" r="0.9" fill="{n}"/>'
PLAY = '<path d="M7 4.5l12 7.5-12 7.5z" fill="{c}" stroke="{c}" stroke-width="1.5" stroke-linejoin="round"/>'
STOP = '<rect x="5.5" y="5.5" width="13" height="13" rx="2.5" fill="{c}"/>'
LIST = '<g stroke="{c}" stroke-width="2.6" stroke-linecap="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></g>'
SLASH = '<line x1="4" y1="20" x2="20" y2="4" stroke="{c}" stroke-width="2.6" stroke-linecap="round"/>'


def g24(inner, size, **colors):
    return f'<svg width="{size}" height="{size}" viewBox="0 0 24 24">{inner.format(**colors)}</svg>'


def logo_mark(size, fish, hook):
    """Fish with a hook over it: the OSFishing mark."""
    return f'''<svg width="{size}" height="{size}" viewBox="0 0 24 24">
<g transform="translate(0,3) scale(0.82)">{fish_svg(fish, fish, eye=WHITE, size=24)[len('<svg width="24" height="24" viewBox="0 0 24 24">'):-6]}</g>
<g transform="translate(5.5,-1.2) scale(0.62)">{HOOK.format(c=hook)}</g></svg>'''


# ---- the bar and the logo ---------------------------------------------------------

def bar():
    return page(1024, 172, f'''<div style="position:absolute;inset:4px;box-sizing:border-box;border-radius:82px;
background:linear-gradient(180deg,#3A90F2 0%,{BLUE} 45%,#1452B8 100%);border:6px solid rgba(255,255,255,0.92);
box-shadow:inset 0 10px 0 rgba(255,255,255,0.14)"></div>''')


def logo_cell(x, opened):
    if opened:
        bg = f'linear-gradient(180deg,{BLUE},{DEEP})'
        border, os_c, word_c, cap_c, cap = WHITE, SKY, WHITE, SKY, '&#9660; CLOSE'
        mark = logo_mark(104, WHITE, SKY)
    else:
        bg = WHITE
        border, os_c, word_c, cap_c, cap = BLUE, BLUE, DEEP, BLUE, '&#9650; MENU'
        mark = logo_mark(104, BLUE, DEEP)
    return f'''<div style="position:absolute;left:{x + 10}px;top:10px;width:492px;height:236px;box-sizing:border-box;border-radius:118px;
background:{bg};border:9px solid {border};display:flex;align-items:center;justify-content:center;gap:6px;
box-shadow:0 0 0 4px rgba(8,42,102,0.35)">
<div style="margin-left:-8px">{mark}</div>
<div style="display:flex;flex-direction:column;align-items:center;line-height:1">
<div style="font-size:66px;font-weight:700;letter-spacing:-1px"><span style="color:{os_c}">OS</span><span style="color:{word_c}">Fishing</span></div>
<div style="margin-top:8px;font-size:31px;font-weight:600;letter-spacing:4px;color:{cap_c}">{cap}</div></div></div>'''


# ---- button sheet: 512x512, 2 columns x 4 rows of 256x128 -----------------------------

def chip(i, fill, border, icon, label, color, size=54, gap=12):
    col, row = i % 2, i // 2
    return f'''<div style="position:absolute;left:{col * 256 + 8}px;top:{row * 128 + 8}px;width:240px;height:112px;box-sizing:border-box;
border-radius:56px;background:{fill};border:6px solid {border};display:flex;align-items:center;justify-content:center;gap:{gap}px">
{icon}<span style="font-size:{size}px;font-weight:700;color:{color};letter-spacing:1px;line-height:1">{label}</span></div>'''


def buttons():
    dim = 'rgba(11,61,145,0.5)'
    cells = [
        chip(0, WHITE, WHITE, g24(PLAY, 46, c=BLUE), 'FISH', DEEP),
        chip(1, CORAL, WHITE, g24(STOP, 46, c=WHITE), 'STOP', WHITE),
        chip(2, 'rgba(255,255,255,0.4)', 'rgba(255,255,255,0.55)', g24(PLAY, 46, c=dim), 'FISH', dim),
        chip(3, 'rgba(8,42,102,0.45)', WHITE, g24(WORM, 56, c=WHITE, n=NAVY), 'BAIT', WHITE),
        chip(4, 'rgba(8,42,102,0.45)', AMBER, g24(WORM, 56, c=AMBER, n=NAVY), 'BAIT', AMBER),
        chip(5, 'rgba(8,42,102,0.45)', CORAL, g24(WORM + SLASH, 56, c=CORAL, n=NAVY), 'BAIT', CORAL),
        chip(6, WHITE, WHITE, g24(LIST, 40, c=BLUE), 'MENU', DEEP),
        chip(7, WHITE, WHITE, fish_svg(BLUE, BLUE, eye=WHITE, size=52), 'FISHING', DEEP, size=40, gap=6),
    ]
    return page(512, 512, ''.join(cells))


# ---- icon sheet: 512x256, 8 columns x 4 rows of 64px -------------------------------------

def icon(i, svg):
    col, row = i % 8, i // 8
    return f'<div style="position:absolute;left:{col * 64}px;top:{row * 64}px;width:64px;height:64px;display:flex;align-items:center;justify-content:center">{svg}</div>'


SPEED = '<g stroke="{c}" stroke-width="1.3" stroke-linecap="round" opacity=".9"><line x1="19" y1="9" x2="23.5" y2="9"/><line x1="20" y1="12" x2="24" y2="12"/><line x1="19" y1="15" x2="23.5" y2="15"/></g>'
DROPS = '<g fill="{c}"><path d="M3 22c0-1.5 1-2.6 1-2.6s1 1.1 1 2.6a1 1 0 0 1-2 0z"/><path d="M19 22c0-1.5 1-2.6 1-2.6s1 1.1 1 2.6a1 1 0 0 1-2 0z"/><path d="M11 23.4c0-1.5 1-2.6 1-2.6s1 1.1 1 2.6a1 1 0 0 1-2 0z"/></g>'
BOOT = '<path d="M7 3h7v9l6 2.5c1.5.6 2 1.7 2 3V20H3v-3.5l1.5-1V3z" fill="#B7C3D6" stroke="{c}" stroke-width="1.4" stroke-linejoin="round"/><line x1="3" y1="17.5" x2="22" y2="17.5" stroke="{c}" stroke-width="1.2"/>'
CLOSE = '<g stroke="{c}" stroke-width="2.8" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></g>'
OPEN = '<g fill="none" stroke="{c}" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4h6v6"/><line x1="20" y1="4" x2="11" y2="13"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></g>'


def icons():
    cells = [
        icon(0, fish_svg(WHITE, BLUE, size=60)),                                          # calm
        icon(1, fish_svg(WHITE, BLUE, size=60, extra=SPEED.format(c=SKY))),               # rushing left
        icon(2, fish_svg(WHITE, BLUE, size=60, flip=True, extra=SPEED.format(c=SKY))),    # rushing right
        icon(3, f'<svg width="60" height="60" viewBox="0 0 24 24"><g transform="rotate(-35 12 12)">{fish_svg(WHITE, CORAL, size=24)[48:-6]}</g>{DROPS.format(c=SKY)}</svg>'),  # struggling
        icon(4, fish_svg(MINT, '#11885A', size=60)),                                       # tired: reel now
        icon(5, g24(BOOT, 54, c=NAVY)),                                                    # junk
        icon(8, g24(CLOSE, 40, c=WHITE)),
        icon(9, g24(OPEN, 38, c=WHITE)),
        icon(10, '<svg width="64" height="64" viewBox="0 0 64 64"><circle cx="32" cy="32" r="27" fill="none" stroke="#FFE14D" stroke-width="6"/><circle cx="32" cy="32" r="30.5" fill="none" stroke="rgba(255,225,77,0.35)" stroke-width="3"/></svg>'),
    ]
    return page(512, 256, ''.join(cells))


# ---- fight strip track -------------------------------------------------------------------

def track():
    return page(512, 64, f'''<div style="position:absolute;inset:3px;box-sizing:border-box;border-radius:29px;background:rgba(8,42,102,0.88);
border:3px solid rgba(255,255,255,0.9);overflow:hidden">
<svg width="506" height="58" style="position:absolute;left:-3px;top:-3px" viewBox="0 0 506 58">
<path d="M0 40 Q 31 32 63 40 T 126 40 T 189 40 T 252 40 T 315 40 T 378 40 T 441 40 T 504 40" fill="none" stroke="{SKY}" stroke-opacity=".25" stroke-width="3"/>
<line x1="253" y1="10" x2="253" y2="48" stroke="#fff" stroke-opacity=".35" stroke-width="2" stroke-dasharray="4 4"/></svg></div>''')


# ---- media panel ---------------------------------------------------------------------------

def panel_back():
    return page(880, 1080, f'''<div style="position:absolute;inset:4px;box-sizing:border-box;border-radius:40px;
background:linear-gradient(180deg,{BLUE} 0,{DEEP} 88px,{NAVY} 100%);border:5px solid rgba(255,255,255,0.92);overflow:hidden">
<div style="height:84px;box-sizing:border-box;padding-left:26px;display:flex;align-items:center;gap:10px;border-bottom:3px solid rgba(255,255,255,0.35)">
{logo_mark(60, WHITE, SKY)}<span style="font-size:42px;font-weight:700;letter-spacing:-0.5px"><span style="color:{SKY}">OS</span><span style="color:{WHITE}">Fishing</span></span></div></div>''')


def media_page(title, lines):
    sub = ''.join(f'<p style="margin:0;font-size:30px;line-height:1.35;color:rgba(255,255,255,0.82);text-align:center">{t}</p>' for t in lines)
    return page(640, 724, f'''<div style="position:absolute;inset:0;background:linear-gradient(180deg,{BLUE},{NAVY});display:flex;flex-direction:column;
align-items:center;justify-content:center;gap:22px;padding:0 56px;box-sizing:border-box">
{logo_mark(170, WHITE, SKY)}
<h1 style="margin:0;font-size:46px;font-weight:700;color:{WHITE};text-align:center">{title}</h1>{sub}</div>''')


TEXTURES = {
    # name: (width, height, html)
    'hud_bar': (1024, 172, bar()),
    'hud_logo_sheet': (1024, 256, page(1024, 256, logo_cell(0, False) + logo_cell(512, True))),
    'hud_buttons': (512, 512, buttons()),
    'hud_icons': (512, 256, icons()),
    'hud_track': (512, 64, track()),
    'hud_panel_back': (880, 1080, panel_back()),
    'hud_media_loading': (640, 724, media_page('Loading…', ['One moment while the panel connects.'])),
    'hud_media_unavailable': (640, 724, media_page('Panel unavailable', [
        'Media is turned off in your viewer, or blocked on this region.',
        'Fishing still works from the bar. Tap &#8599; to open OSFishing in your browser.'])),
}


def main():
    browser = next((b for b in BROWSERS if b and os.path.exists(b)), None)
    if not browser:
        sys.exit('Chrome or Edge not found; edit BROWSERS at the top of this script.')
    os.makedirs(SRC, exist_ok=True)
    only = set(sys.argv[1:])
    for name, (w, h, html) in TEXTURES.items():
        if only and name not in only:
            continue
        src = os.path.join(SRC, name + '.html')
        out = os.path.join(HERE, name + '.png')
        with open(src, 'w', encoding='utf-8') as f:
            f.write(html)
        subprocess.run([
            browser, '--headless=new', '--disable-gpu', '--hide-scrollbars',
            '--force-device-scale-factor=1', '--default-background-color=00000000',
            '--virtual-time-budget=8000', f'--window-size={w},{h}',
            f'--screenshot={out}', 'file:///' + src.replace('\\', '/'),
        ], check=True, capture_output=True)
        print(f'{name}.png  {w}x{h}')


if __name__ == '__main__':
    main()
