/* ==========================================================================
   vm-motion.js — VouchMorph departure-board motion layer
   --------------------------------------------------------------------------
   Self-contained. Exposes window.VM and, at the bottom, wraps the dashboard's
   existing global functions so no call sites need editing.
   Load AFTER the dashboard's own <script> block.
   ========================================================================== */
(function () {
'use strict';

var REDUCED = matchMedia('(prefers-reduced-motion:reduce)').matches;
var DIGITS  = '0123456789';
var LETTERS = ' ABCDEFGHIJKLMNOPQRSTUVWXYZ';

/* ------------------------------------------------------------------ audio */
var AC = null, master = null, soundOn = false, lastClack = 0;

function audioInit() {
  if (!AC) {
    var Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    AC = new Ctx();
    master = AC.createGain();
    master.gain.value = 0.5;
    master.connect(AC.destination);
  }
  if (AC.state === 'suspended') AC.resume();
}
function noiseBuf(sec) {
  var n = Math.floor(AC.sampleRate * sec),
      b = AC.createBuffer(1, n, AC.sampleRate),
      d = b.getChannelData(0);
  for (var i = 0; i < n; i++) d[i] = Math.random() * 2 - 1;
  return b;
}
function clack() {
  if (!soundOn || !AC) return;
  var now = AC.currentTime;
  if (now - lastClack < 0.018) return;
  lastClack = now;
  var s = AC.createBufferSource(); s.buffer = noiseBuf(0.05);
  var f = AC.createBiquadFilter();
  f.type = 'bandpass'; f.frequency.value = 2100 + Math.random() * 900; f.Q.value = 1.6;
  var g = AC.createGain();
  g.gain.setValueAtTime(0.16, now);
  g.gain.exponentialRampToValueAtTime(0.001, now + 0.045);
  s.connect(f); f.connect(g); g.connect(master);
  s.start(now); s.stop(now + 0.06);
}
function tone(freq, dur, type, vol, slideTo) {
  if (!soundOn || !AC) return;
  var now = AC.currentTime, o = AC.createOscillator(), g = AC.createGain();
  o.type = type || 'sine';
  o.frequency.setValueAtTime(freq, now);
  if (slideTo) o.frequency.exponentialRampToValueAtTime(slideTo, now + dur);
  g.gain.setValueAtTime(vol || 0.14, now);
  g.gain.exponentialRampToValueAtTime(0.001, now + dur);
  o.connect(g); g.connect(master);
  o.start(now); o.stop(now + dur + 0.02);
}
function coin()  { tone(760, 0.16, 'triangle', 0.10, 1500); }
function thud()  { tone(96, 0.34, 'sine', 0.30, 44); }
function chime() { tone(659, 0.5, 'sine', 0.11); setTimeout(function(){ tone(988, 0.6, 'sine', 0.09); }, 90); }
function whoosh() {
  if (!soundOn || !AC) return;
  var now = AC.currentTime, s = AC.createBufferSource(); s.buffer = noiseBuf(0.7);
  var f = AC.createBiquadFilter(); f.type = 'bandpass'; f.Q.value = 3;
  f.frequency.setValueAtTime(300, now);
  f.frequency.exponentialRampToValueAtTime(2600, now + 0.55);
  var g = AC.createGain();
  g.gain.setValueAtTime(0.001, now);
  g.gain.exponentialRampToValueAtTime(0.10, now + 0.2);
  g.gain.exponentialRampToValueAtTime(0.001, now + 0.68);
  s.connect(f); f.connect(g); g.connect(master);
  s.start(now); s.stop(now + 0.7);
}
function setSound(on) {
  soundOn = !!on;
  if (soundOn) { audioInit(); coin(); }
  try { localStorage.setItem('vm_sound', soundOn ? '1' : '0'); } catch (e) {}
}
try { soundOn = localStorage.getItem('vm_sound') === '1'; } catch (e) {}

/* ------------------------------------------------------------- split-flap */
function Flap(host, charset, size) {
  this.set = charset; this.i = 0; this.busy = false; this.queue = null;
  var el = document.createElement('div');
  el.className = 'vm-flap';
  el.style.width    = Math.round(size * 0.66) + 'px';
  el.style.height   = size + 'px';
  el.style.fontSize = Math.round(size * 0.72) + 'px';
  el.innerHTML =
    '<div class="vm-h t"><span></span></div><div class="vm-h b"><span></span></div>' +
    '<div class="vm-seam"></div>' +
    '<div class="vm-fx t" style="display:none"><span></span></div>' +
    '<div class="vm-fx b" style="display:none"><span></span></div>';
  host.appendChild(el);
  this.el  = el;
  this.ht  = el.querySelector('.vm-h.t span');
  this.hb  = el.querySelector('.vm-h.b span');
  this.ft  = el.querySelector('.vm-fx.t');
  this.fb  = el.querySelector('.vm-fx.b');
  this.fts = this.ft.querySelector('span');
  this.fbs = this.fb.querySelector('span');
  this.paint(this.set[0]);
}
Flap.prototype.paint = function (c) { this.ht.textContent = c; this.hb.textContent = c; };
Flap.prototype.to = function (ch) {
  var self = this, target = this.set.indexOf(ch);
  if (target < 0 || target === this.i) return Promise.resolve();
  if (this.busy) { this.queue = ch; return Promise.resolve(); }
  this.busy = true;
  var guard = 0;
  function step() {
    if (self.i === target || guard++ > 60) {
      self.busy = false;
      if (self.queue) { var q = self.queue; self.queue = null; self.to(q); }
      return Promise.resolve();
    }
    var from = self.set[self.i];
    self.i = (self.i + 1) % self.set.length;
    return self.flip(from, self.set[self.i]).then(step);
  }
  return step();
};
Flap.prototype.flip = function (from, to) {
  var self = this;
  return new Promise(function (res) {
    if (REDUCED) { self.paint(to); res(); return; }
    var D = 52;
    self.ht.textContent  = to;
    self.fts.textContent = from;
    self.fbs.textContent = to;
    self.ft.style.display = 'flex'; self.fb.style.display = 'flex';
    self.ft.style.transition = 'none'; self.fb.style.transition = 'none';
    self.ft.style.transform = 'rotateX(0deg)';
    self.fb.style.transform = 'rotateX(90deg)';
    void self.ft.offsetHeight;
    self.ft.style.transition = 'transform ' + (D / 2) + 'ms linear';
    self.ft.style.transform  = 'rotateX(-90deg)';
    clack();
    setTimeout(function () {
      self.ft.style.display = 'none';
      self.fb.style.transition = 'transform ' + (D / 2) + 'ms cubic-bezier(.4,1.6,.6,1)';
      self.fb.style.transform  = 'rotateX(0deg)';
    }, D / 2);
    setTimeout(function () {
      self.hb.textContent = to;
      self.fb.style.display = 'none';
      res();
    }, D + 6);
  });
};

function staticCell(host, ch, size) {
  var el = document.createElement('div');
  el.className = 'vm-flap vm-static';
  el.style.width  = Math.round(size * 0.34) + 'px';
  el.style.height = size + 'px';
  el.style.fontSize = Math.round(size * 0.7) + 'px';
  el.innerHTML = '<div class="vm-sep">' + ch + '</div>';
  host.appendChild(el);
}

/** Money board. Digit count adapts to the magnitude, so 950.00 doesn't
 *  render as 00,950.00 — it renders as 950.00. */
function moneyBoard(host, size, maxValue) {
  host.classList.add('vm-board');
  host.innerHTML = '';
  var whole = Math.max(1, String(Math.floor(Math.max(1, maxValue || 0))).length);
  var flaps = [], i;
  for (i = 0; i < whole; i++) {
    if (i > 0 && (whole - i) % 3 === 0) staticCell(host, ',', size);
    flaps.push(new Flap(host, DIGITS, size));
  }
  staticCell(host, '.', size);
  flaps.push(new Flap(host, DIGITS, size));
  flaps.push(new Flap(host, DIGITS, size));
  return {
    flaps: flaps,
    set: function (v) {
      var s = Math.max(0, v).toFixed(2).replace('.', '');
      s = s.slice(-flaps.length).padStart(flaps.length, '0');
      for (var k = 0; k < flaps.length; k++) flaps[k].to(s[k]);
    }
  };
}
function wordBoard(host, size, width) {
  host.classList.add('vm-board');
  host.innerHTML = '';
  var f = [], i;
  for (i = 0; i < width; i++) f.push(new Flap(host, LETTERS, size));
  return {
    set: function (t) {
      var s = String(t).toUpperCase().replace(/[^A-Z ]/g, ' ').padEnd(width, ' ').slice(0, width);
      for (var k = 0; k < width; k++) f[k].to(s[k]);
    }
  };
}

/* ---------------------------------------------------------------- canvas */
function Flow(canvas) {
  var ctx = canvas.getContext('2d'), parts = [], raf = null,
      DPR = Math.min(devicePixelRatio || 1, 2), W = 0, H = 0, self = this;

  function size() {
    var r = canvas.parentElement.getBoundingClientRect();
    W = r.width; H = r.height;
    canvas.width = W * DPR; canvas.height = H * DPR;
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
  }
  size();
  addEventListener('resize', size);

  function rectOf(el) {
    var a = el.getBoundingClientRect(), b = canvas.getBoundingClientRect();
    return { y: a.top - b.top + a.height / 2, l: a.left - b.left, r: a.right - b.left };
  }

  this.emit = function (fromEl, toEl, colour, count, spread, delay) {
    if (REDUCED) return;
    var A = rectOf(fromEl), B = rectOf(toEl);
    var ax = A.r, ay = A.y, bx = B.l, by = B.y;
    if (bx < ax) { ax = A.l; bx = B.r; }
    for (var i = 0; i < count; i++) {
      parts.push({
        x0: ax, y0: ay + (Math.random() - 0.5) * 16,
        x1: bx, y1: by + (Math.random() - 0.5) * 16,
        cx: (ax + bx) / 2, cy: (ay + by) / 2 + (Math.random() - 0.5) * 90,
        t0: performance.now() + (delay || 0) + i * (spread / count),
        dur: 620 + Math.random() * 380,
        c: colour, r: 1.1 + Math.random() * 1.9
      });
    }
  };
  this.clear = function () { parts.length = 0; };

  function frame() {
    ctx.fillStyle = 'rgba(4,18,14,0.30)';
    ctx.fillRect(0, 0, W, H);
    var now = performance.now();
    for (var i = parts.length - 1; i >= 0; i--) {
      var p = parts[i], t = (now - p.t0) / p.dur;
      if (t < 0) continue;
      if (t >= 1) { parts.splice(i, 1); continue; }
      var u = 1 - t,
          x = u * u * p.x0 + 2 * u * t * p.cx + t * t * p.x1,
          y = u * u * p.y0 + 2 * u * t * p.cy + t * t * p.y1,
          fade = Math.sin(Math.PI * t);
      ctx.globalAlpha = Math.min(1, fade * 1.5);
      ctx.fillStyle = p.c;
      ctx.beginPath(); ctx.arc(x, y, p.r, 0, 6.283); ctx.fill();
      ctx.globalAlpha = Math.min(0.32, fade * 0.4);
      ctx.beginPath(); ctx.arc(x, y, p.r * 3.4, 0, 6.283); ctx.fill();
    }
    ctx.globalAlpha = 1;
    raf = requestAnimationFrame(frame);
  }
  raf = requestAnimationFrame(frame);
  this.stop = function () { cancelAnimationFrame(raf); removeEventListener('resize', size); };
}

/* -------------------------------------------------------------- theatre */
var COLOURS = ['#00A878', '#5AC8FA', '#FFB400', '#FF7A59', '#B98FA0', '#8ee6a3'];
function initials(n) {
  var p = String(n || '?').trim().split(/\s+/);
  return ((p[0][0] || '') + (p[1] ? p[1][0] : (p[0][1] || ''))).toUpperCase();
}
var wait = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };

function Theatre(opts) {
  var self = this;
  this.done = false;
  var mob = innerWidth < 620;

  var root = document.createElement('div');
  root.className = 'vm-theatre';
  root.setAttribute('role', 'dialog');
  root.setAttribute('aria-label', opts.label || 'Transaction in progress');
  root.innerHTML =
    '<canvas class="vm-flow"></canvas>' +
    '<button class="vm-snd' + (soundOn ? ' vm-on' : '') + '">' + (soundOn ? 'Sound on' : 'Sound off') + '</button>' +
    '<button class="vm-skip">Skip</button>' +
    '<div class="vm-th-in">' +
      '<div class="vm-head">' +
        '<div class="vm-word"></div>' +
        '<div class="vm-amt-row"><div class="vm-amt"></div>' +
          '<span class="vm-ccy">' + (opts.currency || 'BWP') + '</span></div>' +
        '<div class="vm-sub"></div>' +
      '</div>' +
      '<div class="vm-grid">' +
        '<div><div class="vm-colh">SOURCES</div><div class="vm-srccol"></div></div>' +
        '<div class="vm-core">' +
          '<i class="vm-iris"></i><i class="vm-iris i2"></i><i class="vm-iris i3"></i>' +
          '<i class="vm-pulse"></i>' +
          '<div class="vm-core-c"><div class="g">SWITCH</div><div class="v">IDLE</div></div>' +
        '</div>' +
        '<div><div class="vm-colh">DESTINATION</div>' +
          '<div class="vm-dest">' +
            '<div class="vm-dest-k">' + (opts.destKicker || 'DESTINATION') + '</div>' +
            '<div class="vm-dest-n">Pending</div>' +
            '<div class="vm-dest-i"></div>' +
            '<div class="vm-dest-s"></div>' +
          '</div></div>' +
      '</div>' +
      '<div class="vm-tape"></div>' +
    '</div>';
  document.body.appendChild(root);
  document.body.style.overflow = 'hidden';

  this.root   = root;
  this.flow   = new Flow(root.querySelector('.vm-flow'));
  this.core   = root.querySelector('.vm-core');
  this.coreV  = root.querySelector('.vm-core-c .v');
  this.pulse  = root.querySelector('.vm-pulse');
  this.dest   = root.querySelector('.vm-dest');
  this.tapeEl = root.querySelector('.vm-tape');
  this.srcCol = root.querySelector('.vm-srccol');
  this.subEl  = root.querySelector('.vm-sub');
  this.word   = wordBoard(root.querySelector('.vm-word'), mob ? 17 : 23, 10);
  this.amt    = moneyBoard(root.querySelector('.vm-amt'), mob ? 40 : 60, opts.peak || 9999);

  root.querySelector('.vm-snd').onclick = function () {
    setSound(!soundOn);
    this.textContent = soundOn ? 'Sound on' : 'Sound off';
    this.classList.toggle('vm-on', soundOn);
  };
  this._resolve = null;
  root.querySelector('.vm-skip').onclick = function () { self.close(); };
  this._esc = function (e) { if (e.key === 'Escape') self.close(); };
  document.addEventListener('keydown', this._esc);

  requestAnimationFrame(function () { root.classList.add('vm-in'); });
}
Theatre.prototype.say  = function (w, s) { this.word.set(w); this.subEl.textContent = s || ''; };
Theatre.prototype.money = function (v) { this.amt.set(v); };
Theatre.prototype.log = function (a, b) {
  var d = document.createElement('div');
  d.innerHTML = new Date().toLocaleTimeString('en-GB', { hour12: false }) +
                ' <b>' + a + '</b> <i>' + b + '</i>';
  this.tapeEl.appendChild(d);
  while (this.tapeEl.children.length > 5) this.tapeEl.firstElementChild.remove();
};
Theatre.prototype.addSource = function (s, k) {
  var el = document.createElement('div');
  el.className = 'vm-node';
  el.innerHTML =
    '<i class="vm-clamp l"></i><i class="vm-clamp r"></i>' +
    '<span class="vm-node-t">' + initials(s.name) + '</span>' +
    '<span class="vm-node-m"><span class="vm-node-n">' + s.name + '</span>' +
    '<span class="vm-node-i">' + (s.id || '') + '</span></span>' +
    '<span class="vm-node-a">' + Number(s.amount || 0).toFixed(2) + '</span>';
  el.dataset.colour = COLOURS[k % COLOURS.length];
  this.srcCol.appendChild(el);
  return el;
};
Theatre.prototype.setDest = function (name, id, status, live) {
  this.dest.querySelector('.vm-dest-n').textContent = name;
  this.dest.querySelector('.vm-dest-i').textContent = id || '';
  this.dest.querySelector('.vm-dest-s').textContent = status || '';
  this.dest.classList.toggle('vm-live', live !== false);
};
Theatre.prototype.openCore = function (label) {
  this.core.classList.add('vm-open');
  this.coreV.textContent = label || 'OPEN';
};
Theatre.prototype.beat = function () {
  this.pulse.classList.remove('vm-go');
  void this.pulse.offsetWidth;
  this.pulse.classList.add('vm-go');
};
Theatre.prototype.close = function () {
  if (this.done) return;
  this.done = true;
  document.removeEventListener('keydown', this._esc);
  this.root.classList.remove('vm-in');
  var self = this;
  setTimeout(function () {
    self.flow.stop();
    self.root.remove();
    document.body.style.overflow = '';
    if (self._resolve) self._resolve();
  }, 340);
};
Theatre.prototype.wait = function (ms) {
  var self = this;
  return new Promise(function (r) {
    var t = setTimeout(r, ms);
    var iv = setInterval(function () {
      if (self.done) { clearTimeout(t); clearInterval(iv); r(); }
    }, 60);
    setTimeout(function () { clearInterval(iv); }, ms + 20);
  });
};
Theatre.prototype.finish = function () {
  var self = this;
  return new Promise(function (res) { self._resolve = res; if (self.done) res(); });
};

/* ------------------------------------------------------------ ceremonies */
function normalise(list) {
  return (list || []).map(function (c) {
    return {
      name: (window.PARTICIPANTS && PARTICIPANTS[c.institution] && PARTICIPANTS[c.institution].name) ||
            c.institution || c.name || 'Source',
      id: c.identifier || c.source_identifier || c.id || '',
      amount: Number(c.available_balance != null ? c.available_balance :
              (c.authorized_amount != null ? c.authorized_amount :
              (c.held_amount != null ? c.held_amount : (c.amount || 0))))
    };
  });
}

var Ceremony = {};

Ceremony.hook = async function (sources, currency) {
  var list = normalise(sources);
  if (!list.length) return;
  var total = list.reduce(function (s, x) { return s + x.amount; }, 0);
  var t = new Theatre({ currency: currency || 'BWP', peak: total, destKicker: 'HELD ON CARD', label: 'Hooking sources' });
  t.say('HOOKING', 'CLAMPING SOURCES TO YOUR CARD');
  t.setDest('Your VouchMorph Card', 'held for 24 hours', 'NOT SPENT YET', true);
  var running = 0;
  for (var i = 0; i < list.length && !t.done; i++) {
    var el = t.addSource(list[i], i);
    await t.wait(60);
    el.classList.add('vm-live');
    thud();
    running += list[i].amount;
    t.money(running);
    t.log(list[i].name, 'clamped · ' + list[i].amount.toFixed(2));
    Rail.push('hook', list[i].name, list[i].amount);
    await t.wait(680);
  }
  if (!t.done) {
    t.say('HELD', list.length + (list.length === 1 ? ' SOURCE' : ' SOURCES') + ' · RELEASES IN 24H');
    t.openCore('LOADED');
    chime();
    await t.wait(1400);
  }
  t.close();
  return t.finish();
};

Ceremony.swap = async function (opts) {
  var list = normalise(opts.sources);
  var amount = Number(opts.amount || 0);
  var t = new Theatre({
    currency: opts.currency || 'BWP',
    peak: Math.max(amount, 999),
    destKicker: opts.kind === 'IDENTITY' ? 'HELD FOR IDENTITY' :
                opts.kind === 'CASHOUT'  ? 'CASH COLLECTION'   : 'DESTINATION',
    label: 'Swap in progress'
  });

  t.say('ROUTING', 'DRAWING FROM YOUR SOURCES');
  var els = list.map(function (s, i) { return t.addSource(s, i); });
  await t.wait(220);
  els.forEach(function (e) { e.classList.add('vm-live'); });
  t.money(amount);
  await t.wait(560);
  if (t.done) { t.close(); return t.finish(); }

  t.openCore('OPEN');
  whoosh();
  els.forEach(function (e, i) {
    t.flow.emit(e, t.core, e.dataset.colour, 48, 900, i * 130);
    setTimeout(function () { e.classList.remove('vm-live'); e.classList.add('vm-spent'); coin(); }, 880 + i * 130);
  });
  await t.wait(1250);
  if (t.done) { t.close(); return t.finish(); }
  t.beat();
  t.coreV.textContent = 'FULL';
  t.log('SWITCH', amount.toFixed(2) + ' consolidated');

  await t.wait(420);
  if (opts.kind === 'IDENTITY') {
    t.say('IN FLIGHT', 'BINDING VALUE TO AN IDENTITY');
    t.setDest(opts.destName || 'Recipient', opts.destId || '', 'AWAITING THEIR CHOICE OF ACCOUNT');
  } else if (opts.kind === 'CASHOUT') {
    t.say('IN FLIGHT', 'ISSUING A COLLECTION CODE');
    t.setDest(opts.destName || 'Cash collection', opts.destId || '', 'CODE SENT BY SMS');
  } else {
    t.say('IN FLIGHT', 'CROSSING TO THE DESTINATION');
    t.setDest(opts.destName || 'Destination', opts.destId || '', 'SETTLING');
  }
  t.flow.emit(t.core, t.dest, '#FF7A59', 140, 1300, 0);
  whoosh();
  await t.wait(1250);
  if (!t.done) {
    t.core.classList.remove('vm-open');
    t.coreV.textContent = 'CLEAR';
    chime();
    t.say(opts.kind === 'IDENTITY' ? 'DELIVERED' : 'SETTLED',
          opts.kind === 'IDENTITY' ? 'BOUND TO IDENTITY — DESTINATION NOT YET SET'
                                   : 'FUNDS ON THEIR WAY');
    t.log('COMPLETE', opts.reference || '');
    Rail.push('swap', opts.destName || 'Swap', -amount);
    await t.wait(1500);
  }
  t.close();
  return t.finish();
};

Ceremony.unhook = async function (sources, currency) {
  var list = normalise(sources);
  if (!list.length) return;
  var total = list.reduce(function (s, x) { return s + x.amount; }, 0);
  var t = new Theatre({ currency: currency || 'BWP', peak: total, destKicker: 'RETURNING TO', label: 'Releasing holds' });
  t.say('RELEASE', 'RETURNING THE UNSPENT HOLDS');
  t.money(total);
  t.openCore('OPEN');
  var els = list.map(function (s, i) { var e = t.addSource(s, i); e.classList.add('vm-live'); return e; });
  await t.wait(420);
  var running = total;
  for (var i = els.length - 1; i >= 0 && !t.done; i--) {
    t.flow.emit(t.core, els[i], '#8A968F', 26, 520, 0);
    els[i].classList.remove('vm-live');
    els[i].classList.add('vm-spent');
    running = Math.max(0, running - list[i].amount);
    t.money(running);
    thud();
    t.log(list[i].name, 'released');
    Rail.push('unhook', list[i].name, -list[i].amount);
    await t.wait(560);
  }
  if (!t.done) {
    t.core.classList.remove('vm-open');
    t.coreV.textContent = 'IDLE';
    t.say('SETTLED', 'NOTHING HELD');
    await t.wait(1200);
  }
  t.close();
  return t.finish();
};

/* ------------------------------------------------------------------ rail */
var Rail = {
  el: null, total: 0,
  mount: function (host, startingTotal, currency) {
    if (!host) return;
    this.total = Number(startingTotal || 0);
    host.classList.add('vm-rail');
    host.innerHTML =
      '<div class="vm-rail-head">' +
        '<div class="vm-rail-k">TOTAL HELD</div>' +
        '<div class="vm-rail-total"><div class="vm-rail-amt-board"></div>' +
          '<span class="vm-ccy">' + (currency || 'BWP') + '</span></div>' +
        '<div class="vm-rail-delta"></div>' +
      '</div>' +
      '<div class="vm-rail-list"><div class="vm-rail-empty">Nothing hooked yet.</div></div>' +
      '<div class="vm-rail-foot">Each source releases automatically after 24 hours.</div>';
    this.el = host;
    this.board = moneyBoard(host.querySelector('.vm-rail-amt-board'), 26, Math.max(this.total, 999));
    this.board.set(this.total);
    return this;
  },
  push: function (kind, label, amount) {
    if (!this.el) return;
    var list = this.el.querySelector('.vm-rail-list');
    var empty = list.querySelector('.vm-rail-empty');
    if (empty) empty.remove();
    var mark = { hook: '+', unhook: '−', swap: '→', gift: '✦' }[kind] || '·';
    var item = document.createElement('div');
    item.className = 'vm-rail-item';
    item.innerHTML =
      '<span class="vm-rail-mark ' + kind + '">' + mark + '</span>' +
      '<span class="vm-rail-txt"><span class="vm-rail-inst">' + label + '</span>' +
      '<span class="vm-rail-when">just now</span></span>' +
      '<span class="vm-rail-amt ' + (amount < 0 ? 'down' : 'up') + '">' +
      (amount < 0 ? '−' : '+') + Math.abs(amount).toFixed(2) + '</span>';
    list.prepend(item);
    while (list.children.length > 8) list.lastElementChild.remove();

    this.total = Math.max(0, this.total + amount);
    this.board.set(this.total);
    var d = this.el.querySelector('.vm-rail-delta');
    d.textContent = (amount < 0 ? '−' : '+') + Math.abs(amount).toFixed(2);
    d.className = 'vm-rail-delta vm-show ' + (amount < 0 ? 'down' : 'up');
    setTimeout(function () { d.classList.remove('vm-show'); }, 1400);
  }
};

/* -------------------------------------------------------------- occasions */
var OCCASIONS = [
  { id: 'gift',      e: '🎁', n: 'Gift',         seal: '#00A878', when: 'Straight away' },
  { id: 'birthday',  e: '🎂', n: 'Birthday',     seal: '#7A3B57', when: 'Their birthday' },
  { id: 'valentine', e: '❤️', n: "Valentine's",  seal: '#C62828', when: '14 February' },
  { id: 'festive',   e: '🎄', n: 'Festive',      seal: '#1F8A54', when: '25 December' },
  { id: 'grad',      e: '🎓', n: 'Graduation',   seal: '#1E3A8A', when: 'A date you pick' },
  { id: 'mystery',   e: '✦',  n: 'Mystery',      seal: '#10201C', when: 'A date you pick' }
];
function occasionPicker(host, onPick, selectedId) {
  host.classList.add('vm-occs');
  host.innerHTML = '';
  OCCASIONS.forEach(function (o) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'vm-occ' + (o.id === selectedId ? ' vm-on' : '');
    b.innerHTML = '<span class="e">' + o.e + '</span>' + o.n;
    b.onclick = function () {
      [].forEach.call(host.children, function (c) { c.classList.remove('vm-on'); });
      b.classList.add('vm-on');
      onPick(o);
    };
    host.appendChild(b);
  });
}

/** Sealed parcel. opts: {occasion, amount, givers:[{name,amount,when}], usable, onOpen} */
function sealedGift(host, opts) {
  var occ = typeof opts.occasion === 'string'
    ? (OCCASIONS.filter(function (o) { return o.id === opts.occasion; })[0] || OCCASIONS[0])
    : (opts.occasion || OCCASIONS[0]);
  host.classList.add('vm-seal-stage');
  host.style.setProperty('--vm-seal', occ.seal);
  var givers = opts.givers || [];

  function renderSealed() {
    host.innerHTML =
      '<div class="vm-env" tabindex="0" role="button" aria-label="Break the seal on this ' + occ.n + ' swap">' +
        '<div class="vm-env-body">' +
          '<div class="vm-env-flap"></div>' +
          '<div class="vm-wax"><div class="vm-wax-p">' + occ.e + '</div></div>' +
          '<div class="vm-env-occ">' + occ.n + ' swap</div>' +
          '<div class="vm-env-from">Sealed by ' + givers.length +
            (givers.length === 1 ? ' person' : ' people') + '</div>' +
          '<div class="vm-env-q">? ? ? ?</div>' +
        '</div>' +
        '<div class="vm-env-lock"><span>Opens</span><b>' +
          String(opts.opensOn || occ.when).toUpperCase() + '</b></div>' +
      '</div>';
    var env = host.querySelector('.vm-env');
    env.onclick = crack;
    env.onkeydown = function (k) {
      if (k.key === 'Enter' || k.key === ' ') { k.preventDefault(); crack(); }
    };
  }

  function crack() {
    audioInit();
    var env = host.querySelector('.vm-env');
    if (!env || env.classList.contains('vm-cracking')) return;
    env.classList.add('vm-cracking');
    thud();
    var body = env.querySelector('.vm-env-body');
    for (var i = 0; i < 11; i++) {
      var s = document.createElement('i');
      s.className = 'vm-shard';
      var a = (i / 11) * 6.283 + Math.random();
      s.style.setProperty('--dx', (Math.cos(a) * (70 + Math.random() * 90)).toFixed(0) + 'px');
      s.style.setProperty('--dy', (Math.sin(a) * (70 + Math.random() * 90) + 40).toFixed(0) + 'px');
      s.style.setProperty('--r', (Math.random() * 700 - 350) + 'deg');
      s.style.animation = 'vmShard ' + (0.6 + Math.random() * 0.35).toFixed(2) +
                          's cubic-bezier(.2,.7,.4,1) forwards';
      body.appendChild(s);
    }
    setTimeout(function () { env.classList.add('vm-opened'); whoosh(); }, 260);
    setTimeout(reveal, 900);
    if (opts.onOpen) opts.onOpen(occ);
  }

  function reveal() {
    host.innerHTML =
      '<div class="vm-reveal">' +
        '<div class="vm-rev-top" style="background:' + occ.seal + '">' +
          '<div class="e">' + occ.e + '</div>' +
          '<div class="t">' + occ.n + ' swap — opened</div>' +
        '</div>' +
        '<div class="vm-rev-amt">' +
          '<div class="vm-rev-cap">THEY SENT YOU</div>' +
          '<div class="vm-amt-row"><div class="vm-gift-board"></div>' +
            '<span class="vm-ccy" style="color:#8A968F">' + (opts.currency || 'BWP') + '</span></div>' +
        '</div>' +
        '<div class="vm-givers">' +
          '<div class="vm-giv-h">WHO SEALED IT IN</div>' +
          givers.map(function (g, i) {
            return '<div class="vm-giv" style="animation-delay:' + (900 + i * 160) + 'ms">' +
              '<span class="vm-giv-a">' + initials(g.name) + '</span>' +
              '<span class="vm-giv-n">' + g.name +
                (g.when ? '<br><span class="vm-giv-w">' + g.when + '</span>' : '') + '</span>' +
              '<span class="vm-giv-v">' + Number(g.amount).toFixed(2) + '</span></div>';
          }).join('') +
        '</div>' +
        '<div class="vm-rev-use">' + (opts.usable ||
          'Yours to spend now. Send it on, collect it as cash from any agent, or hook it to your card.') +
        '</div>' +
      '</div>';
    var b = moneyBoard(host.querySelector('.vm-gift-board'), innerWidth < 620 ? 30 : 40, opts.amount);
    setTimeout(function () { b.set(opts.amount); chime(); }, 220);
    givers.forEach(function (_, i) { setTimeout(coin, 950 + i * 160); });
  }

  renderSealed();
  return { open: crack };
}

/* ------------------------------------------------------------------ API */
window.VM = {
  Flap: Flap,
  moneyBoard: moneyBoard,
  wordBoard: wordBoard,
  ceremony: Ceremony,
  rail: Rail,
  sealedGift: sealedGift,
  occasionPicker: occasionPicker,
  occasions: OCCASIONS,
  sound: setSound,
  isSoundOn: function () { return soundOn; }
};

/* ======================================================================
   AUTO-WIRING — wraps the dashboard's existing globals.
   Each wrap is guarded, so if you rename or remove a function nothing
   here throws; the motion layer simply doesn't attach to that one.
   ====================================================================== */
function wrap(name, factory) {
  if (typeof window[name] === 'function') window[name] = factory(window[name]);
}

// 1. Hook success -> clamp ceremony, then the existing success screen.
wrap('showHookSuccess', function (orig) {
  return function (count, freshSources) {
    var cur = (window.myCard && (myCard.hook && myCard.hook.currency || myCard.currency)) || 'BWP';
    var args = arguments, self = this;
    Ceremony.hook(freshSources || [], cur).then(function () {
      orig.apply(self, args);
    });
    return undefined;
  };
});

// 2. Unhook everything -> release ceremony after the API confirms.
wrap('executeUnhook', function (orig) {
  return async function () {
    var held = (window.myCard && myCard.hook && myCard.hook.contributors) || [];
    var cur  = (window.myCard && myCard.hook && myCard.hook.currency) || 'BWP';
    var snapshot = held.slice();
    var r = await orig.apply(this, arguments);
    if (snapshot.length) await Ceremony.unhook(snapshot, cur);
    return r;
  };
});

// 3. Single-source unhook -> same ceremony, one node.
wrap('executeUnhookSource', function (orig) {
  return async function () {
    var id = window.pendingExecution && pendingExecution.payload && pendingExecution.payload.hookSourceId;
    var held = (window.myCard && myCard.hook && myCard.hook.contributors) || [];
    var one = held.filter(function (c) { return c.hook_source_id === id; });
    var cur = (window.myCard && myCard.hook && myCard.hook.currency) || 'BWP';
    var r = await orig.apply(this, arguments);
    if (one.length) await Ceremony.unhook(one, cur);
    return r;
  };
});

// 4. Wizard result -> routing ceremony, then the existing result modal.
wrap('showWizardResultModal', function (orig) {
  return function (response, journeyData) {
    var args = arguments, self = this;
    var ws = window.wizardState || {};
    var data = (response && response.data) || {};
    var sources;
    if (ws.source === 'VMCARD' && window.vmCardSources) sources = vmCardSources;
    else if (ws.source === 'COMBINE') sources = (ws.multiSources || []).filter(function (r) { return !r._draft; });
    else sources = [{ institution: ws.fromInst, identifier: '', amount: ws.amount }];

    var destName, destId;
    if (ws.destType === 'IDENTITY') {
      destName = ws.identityValue || 'Recipient';
      destId = (ws.identityType || '').replace(/_/g, ' ');
    } else if (ws.destType === 'CASHOUT') {
      destName = 'Cash — ' + (ws.deliveryMethod || 'ATM');
      destId = ws.beneficiaryPhone || '';
    } else {
      destName = (window.PARTICIPANTS && PARTICIPANTS[ws.toInst] && PARTICIPANTS[ws.toInst].name) || ws.toInst || 'Destination';
      destId = '';
    }

    Ceremony.swap({
      amount: Number(data.amount != null ? data.amount : ws.amount) || 0,
      currency: ws.currency || 'BWP',
      kind: ws.destType || 'DEPOSIT',
      sources: sources,
      destName: destName,
      destId: destId,
      reference: response && (response.swap_reference || data.reference)
    }).then(function () { orig.apply(self, args); });
    return undefined;
  };
});

// 5. Card-session swipe report -> same ceremony with the real contributors.
wrap('showTransactionReport', function (orig) {
  return function (response, session) {
    var args = arguments, self = this;
    var preview = (session && session.preview) || {};
    Ceremony.swap({
      amount: Number(preview.total_target || (response && response.data && response.data.amount) || 0),
      currency: (session && session.currency) || 'BWP',
      kind: 'DEPOSIT',
      sources: preview.contributors || [],
      destName: 'Destination',
      reference: response && response.data && response.data.swap_reference
    }).then(function () { orig.apply(self, args); });
    return undefined;
  };
});

// 6. Card view -> mount the rail once the card body renders.
wrap('renderCardViewBody', function (orig) {
  return function () {
    var html = orig.apply(this, arguments);
    if (window.myCard && myCard.is_active) {
      setTimeout(function () {
        var area = document.getElementById('sessionStatusArea');
        if (!area || document.getElementById('vmRail')) return;
        var holder = document.createElement('div');
        holder.id = 'vmRail';
        holder.style.marginBottom = '14px';
        area.parentNode.insertBefore(holder, area);
        var hook = myCard.hook;
        Rail.mount(holder, (hook && hook.total_held) || 0, (hook && hook.currency) || myCard.currency || 'BWP');
        if (hook && hook.contributors) {
          hook.contributors.slice().reverse().forEach(function (c) {
            var nm = (window.PARTICIPANTS && PARTICIPANTS[c.institution] && PARTICIPANTS[c.institution].name) || c.institution;
            Rail.push('hook', nm, 0);
          });
        }
      }, 30);
    }
    return html;
  };
});

})();
