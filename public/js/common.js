/* Shared helpers for the public site (home + booking pages). */
(function () {
  'use strict';
  const BOOT = window.BOOT || {};
  const media = BOOT.media || {};
  const site = BOOT.site || {};
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // ---------- images from the processed media manifest ----------
  // uploads live in /media; the website's own photos say where they are (base)
  const fileBase = (key, m) => m.base || `/media/${key}`;
  function srcset(key) {
    const m = media[key];
    if (!m) return '';
    return m.sizes.map((w) => `${fileBase(key, m)}-${w}.webp ${w}w`).join(', ');
  }
  function src(key, prefer = 1280) {
    const m = media[key];
    if (!m) return '';
    const w = m.sizes.reduce((best, s) => (Math.abs(s - prefer) < Math.abs(best - prefer) ? s : best), m.sizes[0]);
    return `${fileBase(key, m)}-${w}.webp`;
  }
  function imgTag(key, { sizes = '100vw', alt = '', cls = '', eager = false } = {}) {
    const m = media[key] || {};
    return `<img src="${src(key, 640)}" srcset="${srcset(key)}" sizes="${esc(sizes)}" width="${m.width || ''}" height="${m.height || ''}" alt="${esc(alt)}" ${cls ? `class="${cls}"` : ''} loading="${eager ? 'eager' : 'lazy'}" decoding="async">`;
  }
  /** Hydrate <img data-src="key"> and [data-bg="key"] placeholders. */
  function hydrateImages(root = document) {
    $$('img[data-src]', root).forEach((img) => {
      const key = img.dataset.src;
      const m = media[key];
      if (!m) return;
      img.src = src(key, 640);
      img.srcset = srcset(key);
      img.sizes = img.dataset.sizes || '100vw';
      img.width = m.width; img.height = m.height;
      if (!img.loading) img.loading = 'lazy';
      img.decoding = 'async';
      img.removeAttribute('data-src');
    });
    $$('[data-bg]', root).forEach((el) => {
      el.style.backgroundImage = `url(${src(el.dataset.bg, 1280)})`;
    });
  }

  // ---------- formatting ----------
  const money = (n) => `${site.currency || 'LKR'} ${Number(n || 0).toLocaleString('en-US')}`;
  const parseDate = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(Date.UTC(y, m - 1, d)); };
  const fmtDate = (s, opts = { day: 'numeric', month: 'short' }) => parseDate(s).toLocaleDateString('en-GB', { timeZone: 'UTC', ...opts });
  const nights = (a, b) => Math.round((parseDate(b) - parseDate(a)) / 86400000);
  const guestsLabel = (a, c) => `${a} adult${a === 1 ? '' : 's'}${c ? `, ${c} child${c === 1 ? '' : 'ren'}` : ''}`;

  // ---------- nav ----------
  function initNav() {
    const nav = $('[data-nav]');
    if (!nav) return;
    let lastY = window.scrollY;
    const solidAlways = nav.classList.contains('is-scrolled');
    const onScroll = () => {
      const y = window.scrollY;
      if (!solidAlways) nav.classList.toggle('is-scrolled', y > 40);
      nav.classList.toggle('is-hidden', y > 600 && y > lastY && !document.body.classList.contains('menu-open'));
      lastY = y;
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    const burger = $('[data-burger]');
    const menu = $('[data-menu]');
    if (burger && menu) {
      const set = (open) => {
        document.body.classList.toggle('menu-open', open);
        burger.setAttribute('aria-expanded', String(open));
        burger.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        menu.setAttribute('aria-hidden', String(!open));
      };
      burger.addEventListener('click', () => set(!document.body.classList.contains('menu-open')));
      $$('a', menu).forEach((a) => a.addEventListener('click', () => set(false)));
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') set(false); });
    }

    // highlight the section in view
    const links = $$('.nav__links a[href^="#"]');
    if (links.length && 'IntersectionObserver' in window) {
      const map = new Map(links.map((a) => [a.getAttribute('href').slice(1), a]));
      const io = new IntersectionObserver((entries) => {
        entries.forEach((e) => {
          if (e.isIntersecting) {
            links.forEach((l) => l.classList.remove('is-active'));
            const a = map.get(e.target.id);
            if (a) a.classList.add('is-active');
          }
        });
      }, { rootMargin: '-45% 0px -50% 0px' });
      map.forEach((_, id) => { const s = document.getElementById(id); if (s) io.observe(s); });
    }
  }

  // ---------- reveal on scroll ----------
  function initReveal() {
    const els = $$('.reveal, .img-reveal');
    if (reduceMotion || !('IntersectionObserver' in window)) { els.forEach((e) => e.classList.add('is-in')); return; }
    const io = new IntersectionObserver((entries) => {
      entries.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    els.forEach((e) => io.observe(e));
  }

  // ---------- parallax ----------
  function initParallax() {
    if (reduceMotion) return;
    const els = $$('[data-parallax]');
    if (!els.length) return;
    let ticking = false;
    const update = () => {
      const vh = window.innerHeight;
      els.forEach((el) => {
        const r = el.getBoundingClientRect();
        if (r.bottom < -200 || r.top > vh + 200) return;
        const speed = parseFloat(el.dataset.parallax) || 0;
        const offset = (r.top + r.height / 2 - vh / 2) * speed;
        el.style.translate = `0 ${offset.toFixed(1)}px`;
      });
      ticking = false;
    };
    window.addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
    window.addEventListener('resize', update);
    update();
  }

  // ---------- button hover glow follows the pointer ----------
  function initButtonGlow() {
    document.addEventListener('pointermove', (e) => {
      const b = e.target.closest && e.target.closest('.btn');
      if (!b) return;
      const r = b.getBoundingClientRect();
      b.style.setProperty('--mx', `${e.clientX - r.left}px`);
      b.style.setProperty('--my', `${e.clientY - r.top}px`);
    }, { passive: true });
  }

  // ---------- fireflies ----------
  function fireflies(canvas) {
    const count = parseInt(canvas.dataset.fireflies, 10) || 30;
    const ctx = canvas.getContext('2d');
    let w = 0, h = 0, dpr = 1, raf = 0, running = false;
    const flies = [];
    const resize = () => {
      dpr = Math.min(window.devicePixelRatio || 1, 2);
      w = canvas.clientWidth; h = canvas.clientHeight;
      canvas.width = w * dpr; canvas.height = h * dpr;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    };
    const spawn = () => ({
      x: Math.random() * w, y: h * (0.35 + Math.random() * 0.65),
      r: 0.8 + Math.random() * 1.8,
      a: Math.random() * Math.PI * 2, s: 0.15 + Math.random() * 0.35,
      phase: Math.random() * Math.PI * 2, blink: 0.6 + Math.random() * 1.4,
      hue: Math.random() < 0.8 ? '246, 200, 115' : '200, 236, 150',
    });
    resize();
    for (let i = 0; i < count; i++) flies.push(spawn());
    const tick = (t) => {
      ctx.clearRect(0, 0, w, h);
      for (const f of flies) {
        f.a += (Math.random() - 0.5) * 0.25;
        f.x += Math.cos(f.a) * f.s;
        f.y += Math.sin(f.a) * f.s - 0.05;
        if (f.x < -20) f.x = w + 20; if (f.x > w + 20) f.x = -20;
        if (f.y < h * 0.15 || f.y > h + 20) Object.assign(f, spawn(), { y: h + 10 });
        const glow = Math.max(0, Math.sin(t / 1000 * f.blink + f.phase));
        const alpha = 0.15 + glow * 0.85;
        const rad = f.r * 7;
        const g = ctx.createRadialGradient(f.x, f.y, 0, f.x, f.y, rad);
        g.addColorStop(0, `rgba(${f.hue}, ${alpha})`);
        g.addColorStop(0.25, `rgba(${f.hue}, ${alpha * 0.35})`);
        g.addColorStop(1, `rgba(${f.hue}, 0)`);
        ctx.fillStyle = g;
        ctx.beginPath(); ctx.arc(f.x, f.y, rad, 0, Math.PI * 2); ctx.fill();
      }
      if (running) raf = requestAnimationFrame(tick);
    };
    const start = () => { if (!running) { running = true; raf = requestAnimationFrame(tick); } };
    const stop = () => { running = false; cancelAnimationFrame(raf); };
    window.addEventListener('resize', resize);
    if (reduceMotion) { tick(4000); return; }
    new IntersectionObserver(([e]) => (e.isIntersecting ? start() : stop())).observe(canvas);
  }
  const initFireflies = () => $$('canvas[data-fireflies]').forEach(fireflies);

  // ---------- "sound of the falls" — synthesised, nothing to download ----------
  function initSound() {
    const btn = $('[data-sound]');
    if (!btn) return;
    let ctx = null, master = null;
    function build() {
      ctx = new (window.AudioContext || window.webkitAudioContext)();
      master = ctx.createGain();
      master.gain.value = 0;
      master.connect(ctx.destination);
      // brown noise = deep rushing water
      const len = ctx.sampleRate * 4;
      const buf = ctx.createBuffer(2, len, ctx.sampleRate);
      for (let ch = 0; ch < 2; ch++) {
        const d = buf.getChannelData(ch);
        let last = 0;
        for (let i = 0; i < len; i++) {
          const white = Math.random() * 2 - 1;
          last = (last + 0.02 * white) / 1.02;
          d[i] = last * 3.2;
        }
      }
      const layer = (freq, q, gain, rate) => {
        const src = ctx.createBufferSource();
        src.buffer = buf; src.loop = true; src.playbackRate.value = rate;
        const f = ctx.createBiquadFilter(); f.type = 'bandpass'; f.frequency.value = freq; f.Q.value = q;
        const g = ctx.createGain(); g.gain.value = gain;
        // slow swell, like spray gusting
        const lfo = ctx.createOscillator(); lfo.frequency.value = 0.05 + Math.random() * 0.1;
        const lfoGain = ctx.createGain(); lfoGain.gain.value = gain * 0.35;
        lfo.connect(lfoGain).connect(g.gain); lfo.start();
        src.connect(f).connect(g).connect(master); src.start(0, Math.random() * 3);
      };
      layer(420, 0.6, 0.9, 1);
      layer(1400, 0.8, 0.35, 1.3);
      layer(160, 0.7, 0.8, 0.8);
    }
    btn.addEventListener('click', () => {
      const on = btn.getAttribute('aria-pressed') !== 'true';
      if (on && !ctx) build();
      if (ctx.state === 'suspended') ctx.resume();
      const now = ctx.currentTime;
      master.gain.cancelScheduledValues(now);
      master.gain.setValueAtTime(master.gain.value, now);
      master.gain.linearRampToValueAtTime(on ? 0.5 : 0, now + (on ? 2.5 : 0.8));
      btn.setAttribute('aria-pressed', String(on));
      btn.title = on ? 'Mute the falls' : 'Play the sound of the falls';
    });
  }

  // ---------- floating WhatsApp appears after the hero ----------
  function initWhatsApp() {
    const wa = $('[data-wa]');
    if (!wa) return;
    const on = () => wa.classList.toggle('is-shown', window.scrollY > window.innerHeight * 0.6);
    window.addEventListener('scroll', on, { passive: true });
    on();
  }

  // ---------- generic stepper ----------
  function stepper(el, onChange) {
    const out = $('output', el);
    const min = Number(el.dataset.min || 0);
    let max = Number(el.dataset.max || 10);
    const dec = $('[data-dec]', el), inc = $('[data-inc]', el);
    const api = {
      get value() { return Number(out.textContent); },
      set(v) {
        v = Math.max(min, Math.min(max, v));
        out.textContent = v;
        dec.disabled = v <= min; inc.disabled = v >= max;
        onChange && onChange(v);
      },
      setMax(m) { max = m; el.dataset.max = m; api.set(api.value); },
    };
    dec.addEventListener('click', () => api.set(api.value - 1));
    inc.addEventListener('click', () => api.set(api.value + 1));
    api.set(api.value);
    return api;
  }

  function hideEmptyLinks() {
    $$('[data-optional-link]').forEach((a) => { if (!a.getAttribute('href')) a.remove(); });
  }

  function boot() {
    hydrateImages();
    initNav();
    initReveal();
    initParallax();
    initButtonGlow();
    initFireflies();
    initSound();
    initWhatsApp();
    hideEmptyLinks();
    const done = () => document.body.classList.add('is-loaded');
    if (document.readyState === 'complete') setTimeout(done, 350);
    else window.addEventListener('load', () => setTimeout(done, 350));
    setTimeout(done, 2500); // never hold the page hostage to a slow image
  }

  window.YD = { $, $$, esc, BOOT, site, media, src, srcset, imgTag, hydrateImages, money, fmtDate, parseDate, nights, guestsLabel, stepper, reduceMotion, boot };
})();
