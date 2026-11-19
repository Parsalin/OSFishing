/*
 * OSFishing — live fishing view for the HUD's media panel.
 *
 * The HUD serves a tiny HTML shell from its own HTTP-in URL
 * (<hud url>/fish) that loads this file. Same origin as the HUD, so this
 * page polls "s" (<hud url>/s) for the live cast / fight state. It only
 * shows; every control stays on the keyboard and the HUD, and the fight
 * runs in the HUD whether or not this page is open.
 *
 * State fields (FishingHUD.lsl fishViewState):
 *   s state, fs fish state, jk junk fight, t tension 0-1, d distance m,
 *   cap max line m, a angle rad (+ = left), sta fish energy 0-1, pw cast
 *   power 0-1, bl/bw bite time left / window s, f fish, w weight lb,
 *   r rarity id, rn rarity name (last catch), m message, mq message seq,
 *   ns nibble seq, rl reeling, lo line out, dir steer -1/0/1, bn/bq bait,
 *   sp spot, wt water type, lv level
 */
(function () {
  'use strict';

  var S = { IDLE: 0, CHARGING: 1, CAST: 2, NIBBLE: 3, BITE: 4, HOOKSET: 5, FIGHT: 6, LANDING: 7, MENU: 8, VICTORY: 9, REELING: 10 };
  var F = { STRUGGLING: 0, LEFT: 1, RIGHT: 2, TIRED: 3 };
  var RARITY = { 1: ['Common', '#C9D6E8'], 2: ['Uncommon', '#6EF0A0'], 3: ['Rare', '#7CC4FF'], 4: ['Epic', '#C59BFF'], 5: ['Legendary', '#FFC24D'] };
  var POLL_MS = 250;

  var css = [
    "@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&display=swap');",
    '*{box-sizing:border-box}',
    'html,body{margin:0;height:100%;overflow:hidden;background:#082A66;color:#fff;font-family:Fredoka,system-ui,sans-serif;user-select:none}',
    '#fv{position:relative;width:100vw;height:100vh;display:flex;flex-direction:column}',
    '.top{display:flex;align-items:center;gap:10px;padding:14px 18px 10px;font-size:22px}',
    '.chip{background:rgba(255,255,255,.12);border:2px solid rgba(255,255,255,.35);border-radius:999px;padding:4px 14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.chip.low{border-color:#FFB547;color:#FFB547}.chip.out{border-color:#F0524F;color:#F0524F}',
    '.spot{flex:1;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.scene{position:relative;flex:0 0 auto;height:52vh;margin:0 14px;border-radius:22px;overflow:hidden;border:3px solid rgba(255,255,255,.85);background:linear-gradient(180deg,#9FD6FF 0,#CFEAFF 26%,#2F86E8 27%,#1452B8 70%,#0B3D91 100%)}',
    '.scene svg{position:absolute;inset:0;width:100%;height:100%}',
    '.waves path{animation:drift 6s linear infinite}',
    '@keyframes drift{from{transform:translateX(0)}to{transform:translateX(-80px)}}',
    '.float,.ripple,#fishin{transform-box:fill-box;transform-origin:center}',
    '.bob .float{animation:bob 2.4s ease-in-out infinite}',
    '@keyframes bob{0%,100%{transform:translateY(0)}50%{transform:translateY(5px)}}',
    '.bite .float{animation:dunk .35s ease-in-out infinite}',
    '@keyframes dunk{0%,100%{transform:translateY(0)}50%{transform:translateY(16px)}}',
    '.ripple{fill:none;stroke:#fff;stroke-width:2;opacity:0}',
    '.ripple.go{animation:ripple 1.1s ease-out}',
    '@keyframes ripple{from{opacity:.9;transform:scale(.15)}to{opacity:0;transform:scale(1)}}',
    '.fish{transition:opacity .3s}',
    '.jump{animation:jump .5s ease-in-out infinite alternate}',
    '@keyframes jump{from{transform:translateY(0) rotate(-20deg)}to{transform:translateY(-26px) rotate(15deg)}}',
    '.call{margin:14px 14px 0;border-radius:18px;padding:12px 16px;text-align:center;font-size:34px;font-weight:700;line-height:1.1;background:rgba(255,255,255,.1);border:3px solid rgba(255,255,255,.25);min-height:66px;display:flex;flex-direction:column;align-items:center;justify-content:center;transition:background .2s,border-color .2s}',
    '.call.go{background:#1E9E5A;border-color:#6EF0A0}.call.stop{background:#B33A37;border-color:#FF8A86}.call.steer{background:#1F6FE0;border-color:#7CC4FF}.call.bite{background:#FFB547;color:#082A66;border-color:#fff;animation:pulse .5s ease-in-out infinite alternate}',
    '@keyframes pulse{from{transform:scale(1)}to{transform:scale(1.04)}}',
    '.call small{display:block;font-size:20px;font-weight:500;opacity:.85;margin-top:4px}',
    '.gauges{display:flex;flex-direction:column;gap:10px;padding:14px 18px 0}',
    '.g{display:flex;align-items:center;gap:12px;font-size:20px}',
    '.g label{width:112px;opacity:.85}',
    '.bar{position:relative;flex:1;height:22px;border-radius:999px;background:rgba(255,255,255,.14);border:2px solid rgba(255,255,255,.4);overflow:hidden}',
    '.bar i{position:absolute;left:0;top:0;bottom:0;border-radius:999px;transition:width .45s linear,background .3s}',
    '.bar b{position:absolute;top:-2px;bottom:-2px;width:3px;background:#fff;opacity:.7}',
    '.g span{width:70px;text-align:right;font-weight:600}',
    '.msg{padding:10px 18px 0;font-size:20px;opacity:.9;min-height:34px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.keys{margin-top:auto;padding:10px 14px 14px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap;font-size:17px;opacity:.85}',
    '.keys kbd{font-family:inherit;background:#fff;color:#0B3D91;border-radius:7px;padding:1px 8px;font-weight:700;margin-right:4px}',
    '.card{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(8,42,102,.72);opacity:0;pointer-events:none;transition:opacity .3s}',
    '.card.on{opacity:1}',
    '.card div{background:#fff;color:#0B3D91;border-radius:26px;padding:26px 34px;text-align:center;border:5px solid #7CC4FF;transform:scale(.9);transition:transform .3s}',
    '.card.on div{transform:scale(1)}',
    '.card h1{margin:0;font-size:30px;color:#1F6FE0}.card h2{margin:6px 0;font-size:44px}.card p{margin:0;font-size:26px;font-weight:600}',
    '.lost{position:absolute;left:14px;right:14px;bottom:14px;padding:10px;border-radius:14px;background:#B33A37;text-align:center;font-size:20px;display:none}',
    '.lost.on{display:block}'
  ].join('\n');

  var FISH_PATH = 'M2.5 12c2.6-4.4 6.4-6 10-6 2.6 0 4.6 1.4 6 3.2L22 6.5v11l-3.5-2.7C17.1 16.6 15.1 18 12.5 18c-3.6 0-7.4-1.6-10-6z';

  var root = document.getElementById('fv') || document.body.appendChild(document.createElement('div'));
  root.id = 'fv';
  var st = document.createElement('style');
  st.textContent = css;
  document.head.appendChild(st);

  root.innerHTML =
    '<div class="top"><div class="spot" id="spot">OSFishing</div><div class="chip" id="bait"></div></div>' +
    '<div class="scene" id="scene"><svg viewBox="0 0 640 380" preserveAspectRatio="none">' +
      '<g class="waves" opacity=".35" stroke="#fff" stroke-width="3" fill="none">' +
        wave(150) + wave(215) + wave(290) + wave(350) +
      '</g>' +
      '<line id="line" x1="320" y1="392" x2="320" y2="200" stroke="#fff" stroke-width="2.5" opacity=".85"/>' +
      '<g id="fishg" class="fish" opacity="0"><g id="fishin"><g id="fishflip">' +
        '<path d="' + FISH_PATH + '" transform="translate(-36,-26) scale(3)" fill="#0B3D91" opacity=".55"/>' +
      '</g></g></g>' +
      '<g id="bob" class="bob" transform="translate(320,200)"><circle id="rip" class="ripple" r="46"/>' +
        '<g class="float"><ellipse cx="0" cy="6" rx="15" ry="5" fill="#0B3D91" opacity=".35"/>' +
        '<path d="M-11 0a11 11 0 0 1 22 0z" fill="#F0524F"/><path d="M-11 0a11 11 0 0 0 22 0z" fill="#fff"/>' +
        '<rect x="-2" y="-20" width="4" height="10" rx="2" fill="#fff"/></g></g>' +
    '</svg>' +
    '<div class="card" id="card"><div><h1>CAUGHT IT!</h1><h2 id="cf"></h2><p id="cw"></p></div></div></div>' +
    '<div class="call" id="call">Connecting to your HUD…</div>' +
    '<div class="gauges" id="gauges"></div>' +
    '<div class="msg" id="msg"></div>' +
    '<div class="keys" id="keys"></div>' +
    '<div class="lost" id="lost">Lost contact with your HUD — retrying…</div>';

  function wave(y) {
    var d = 'M0 ' + y;
    for (var x = 0; x <= 720; x += 80) d += ' q20 -8 40 0 t40 0';
    return '<path d="' + d + '"/>';
  }

  var $ = function (id) { return document.getElementById(id); };
  var last = null, lastNs = -1, fails = 0, lastMq = -1;

  // The HUD reports positions every half second or so; glide between them.
  var target = { x: 320, y: 200 }, pos = { x: 320, y: 200 };
  function frame() {
    pos.x += (target.x - pos.x) * 0.12;
    pos.y += (target.y - pos.y) * 0.12;
    var x = pos.x.toFixed(1), y = pos.y.toFixed(1);
    $('bob').setAttribute('transform', 'translate(' + x + ',' + y + ')');
    $('line').setAttribute('x2', x);
    $('line').setAttribute('y2', y);
    $('fishg').setAttribute('transform', 'translate(' + x + ',' + (pos.y + 30).toFixed(1) + ')');
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);

  function gauge(label, pct, color, text, tick) {
    pct = Math.max(0, Math.min(1, pct || 0));
    return '<div class="g"><label>' + label + '</label><div class="bar"><i style="width:' + (pct * 100).toFixed(1) +
      '%;background:' + color + '"></i>' + (tick ? '<b style="left:' + tick * 100 + '%"></b>' : '') +
      '</div><span>' + text + '</span></div>';
  }

  function tensionColor(t) {
    if (t < 0.5) return 'hsl(' + (130 - t * 120) + ',75%,52%)';
    return 'hsl(' + Math.max(0, 70 - (t - 0.5) * 140) + ',85%,55%)';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
  }

  function keys(list) {
    return list.map(function (k) { return '<span><kbd>' + k[0] + '</kbd>' + k[1] + '</span>'; }).join('');
  }

  function render(d) {
    var s = +d.s;
    var fighting = (s === S.FIGHT || s === S.HOOKSET || s === S.LANDING);

    // Top: spot + bait
    $('spot').textContent = d.sp ? '📍 ' + d.sp + (d.wt ? '  ·  ' + d.wt.charAt(0).toUpperCase() + d.wt.slice(1) : '') : 'Not at a fishing spot';
    var bait = $('bait');
    var bq = +d.bq;
    bait.textContent = d.bn && d.bn !== 'None' ? d.bn + ' ×' + bq : 'No bait';
    bait.className = 'chip' + (bq <= 0 ? ' out' : bq <= 5 ? ' low' : '');

    // Scene: bobber position. Distance → height on the water (far = higher),
    // angle → sideways (+ = left).
    var cap = +d.cap || 50;
    var dist = Math.max(0, +d.d || 0);
    var ang = Math.max(-1, Math.min(1, (+d.a || 0) / 0.524));
    var inWater = (s === S.CAST || s === S.NIBBLE || s === S.BITE || s === S.REELING || fighting);
    target.x = 320 - ang * 230;
    target.y = 372 - Math.min(1, dist / cap) * 250;
    if (!inWater) { pos.x = target.x; pos.y = target.y; }   // next cast starts where it lands
    $('bob').style.opacity = inWater ? 1 : 0;
    $('bob').setAttribute('class', 'bob' + (s === S.BITE ? ' bite' : ''));
    $('line').style.opacity = inWater ? 0.85 : 0;

    // Fish shadow under the bobber during a fight
    var fs = +d.fs;
    var fg = $('fishg');
    fg.style.opacity = fighting && !+d.jk ? 1 : 0;
    $('fishin').setAttribute('class', fs === F.STRUGGLING && fighting ? 'jump' : '');
    // The fish shape faces left; mirror it when it runs right.
    $('fishflip').setAttribute('transform', fs === F.RIGHT ? 'scale(-1,1)' : '');

    // Nibble ripple
    if (lastNs !== -1 && +d.ns !== lastNs) {
      var rip = $('rip');
      rip.setAttribute('class', 'ripple');
      void rip.getBoundingClientRect();
      rip.setAttribute('class', 'ripple go');
    }
    lastNs = +d.ns;

    // The big call: what to do now
    var call = '', cls = '', sub = '';
    if (s === S.IDLE || s === S.MENU) { call = 'Tap FISH on your HUD to start'; sub = d.sp ? '' : 'Walk up to a fishing spot first'; }
    else if (s === S.CHARGING) { call = 'Hold PgUp to charge — release to cast'; cls = 'steer'; }
    else if (s === S.CAST) { call = 'Watch the bobber…'; sub = 'Small twitches are nibbles. Wait for the bite!'; }
    else if (s === S.REELING) { call = 'Reeling in…'; }
    else if (s === S.BITE) { call = 'BITE! Press PgDn!'; cls = 'bite'; sub = (+d.bl > 0 ? Math.ceil(+d.bl) + 's to set the hook' : ''); }
    else if (fighting && +d.jk) { call = 'Heavy! Reel in short bursts'; cls = 'steer'; sub = 'Ease off with PgUp before the line snaps'; }
    else if (fighting) {
      if (fs === F.TIRED) { call = 'Tired — REEL NOW!'; cls = 'go'; sub = 'Hold PgDn'; }
      else if (fs === F.STRUGGLING) { call = 'Thrashing — let go!'; cls = 'stop'; sub = 'Stop reeling until it calms down'; }
      else if (fs === F.LEFT) { call = 'Running left — steer RIGHT →'; cls = 'steer'; sub = 'Hold → or D'; }
      else if (fs === F.RIGHT) { call = '← Steer LEFT — running right'; cls = 'steer'; sub = 'Hold ← or A'; }
      if (+d.t > 0.8) sub = 'Line about to snap! Ease off with PgUp';
    }
    else if (s === S.VICTORY) { call = 'Nice catch!'; cls = 'go'; }
    var callEl = $('call');
    callEl.className = 'call ' + cls;
    callEl.innerHTML = esc(call) + (sub ? '<small>' + esc(sub) + '</small>' : '');

    // Gauges
    var g = '';
    if (s === S.CHARGING) {
      g += gauge('Power', +d.pw, '#7CC4FF', Math.round(+d.pw * 100) + '%');
    } else if (fighting) {
      var t = +d.t;
      g += gauge('Tension', t, tensionColor(t), Math.round(t * 100) + '%', 0.8);
      if (!+d.jk) g += gauge('Fish energy', +d.sta, '#FFB547', Math.round(+d.sta * 100) + '%');
      g += gauge('Line out', dist / cap, '#7CC4FF', Math.round(dist) + 'm');
    } else if (s === S.BITE) {
      var bw = +d.bw || 10;
      g += gauge('Hook time', (+d.bl || 0) / bw, '#FFB547', Math.ceil(+d.bl || 0) + 's');
    } else if (s === S.CAST || s === S.REELING) {
      g += gauge('Line out', dist / cap, '#7CC4FF', Math.round(dist) + 'm');
    }
    $('gauges').innerHTML = g;

    // Latest HUD message (the fight call is already shown above)
    var quiet = fighting || s === S.BITE || s === S.CHARGING;
    if (+d.mq !== lastMq || quiet || !d.m) {
      lastMq = +d.mq;
      $('msg').textContent = quiet ? '' : (d.m || '');
    }

    // Keys for this moment
    var k = [];
    if (s === S.CHARGING) k = [['PgUp', 'charge & cast']];
    else if (s === S.CAST) k = [['PgDn', 'reel in']];
    else if (s === S.BITE) k = [['PgDn', 'set the hook']];
    else if (fighting) k = [['PgDn', 'reel'], ['PgUp', 'let line out'], ['← →', 'steer']];
    $('keys').innerHTML = keys(k);

    // Catch card
    var card = $('card');
    if (s === S.VICTORY) {
      var rn = d.rn || (RARITY[+d.r] || [''])[0];
      var rc = (RARITY[+d.r] || ['', '#7CC4FF'])[1];
      $('cf').textContent = d.f || '';
      $('cw').textContent = (+d.w).toFixed(2) + ' lb' + (rn ? ' · ' + rn : '');
      card.firstChild.style.borderColor = rc;
      card.className = 'card on';
    } else {
      card.className = 'card';
    }
  }

  function poll() {
    fetch('s', { cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(function (txt) {
        fails = 0;
        $('lost').className = 'lost';
        var d = JSON.parse(txt);
        last = d;
        render(d);
      })
      .catch(function () {
        fails++;
        if (fails > 8) $('lost').className = 'lost on';
      })
      .then(function () { setTimeout(poll, fails > 8 ? 2000 : POLL_MS); });
  }

  poll();
})();
