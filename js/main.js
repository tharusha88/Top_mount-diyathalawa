/* Top Mountain Resort · interactions and motion */
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const html = document.documentElement;
  const body = document.body;
  const C = window.TMR_CONTENT;
  const T = window.TMR;
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const hasGsap = !!(window.gsap && window.ScrollTrigger) && !reduce;
  const pad = n => String(n).padStart(2, '0');
  const desktop = () => matchMedia('(min-width: 900px)').matches;

  /* ---------------- focus trap helper ---------------- */
  const focusables = root => $$('a[href], button:not([disabled]), input, select, video[controls], [tabindex]:not([tabindex="-1"])', root)
    .filter(el => !el.hidden && !el.closest('[hidden]') && el.offsetParent !== null);
  const trap = (root, e) => {
    if (e.key !== 'Tab') return;
    const f = focusables(root);
    if (!f.length) return;
    const first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  };
  let scrollLocks = 0;
  const lockScroll = on => {
    scrollLocks = Math.max(0, scrollLocks + (on ? 1 : -1));
    html.style.overflow = scrollLocks ? 'hidden' : '';
  };

  /* ================= NAV ================= */
  const nav = $('#nav');
  const hero = $('.hero');
  let lastY = scrollY;
  const onScroll = () => {
    const y = scrollY;
    nav.classList.toggle('is-solid', y > 40);
    const modal = body.classList.contains('menu-open') || !$('#lightbox').hidden || !$('#film').hidden;
    if (!modal) {
      if (y > innerHeight * 0.9 && y > lastY + 6) nav.classList.add('is-hidden');
      else if (y < lastY - 6 || y < innerHeight * 0.9) nav.classList.remove('is-hidden');
    }
    lastY = y;
  };
  addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  nav.addEventListener('focusin', () => nav.classList.remove('is-hidden'));

  // current section in the nav
  const navLinks = $$('.nav__links a');
  const spy = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (!e.isIntersecting) return;
      navLinks.forEach(a => a.classList.toggle('is-current', a.getAttribute('href') === '#' + e.target.id));
    });
  }, { rootMargin: '-45% 0px -50% 0px' });
  ['stay', 'experiences', 'craft', 'dining', 'gallery', 'location', 'book', 'intro', 'discover'].forEach(id => { const el = document.getElementById(id); if (el) spy.observe(el); });

  /* ================= MOBILE MENU ================= */
  const toggle = $('.nav__toggle'), menu = $('#menu');
  const setMenu = open => {
    if (open === body.classList.contains('menu-open')) return;
    body.classList.toggle('menu-open', open);
    toggle.setAttribute('aria-expanded', open);
    $('.nav__toggle-label', toggle).textContent = open ? 'Close' : 'Menu';
    if (open) { menu.removeAttribute('inert'); nav.classList.remove('is-hidden'); setTimeout(() => $('a', menu).focus({ preventScroll: true }), 350); }
    else menu.setAttribute('inert', '');
    lockScroll(open);
  };
  toggle.addEventListener('click', () => setMenu(!body.classList.contains('menu-open')));
  // close first (which releases the scroll lock), then travel to the section
  $$('a[href^="#"]', menu).forEach(a => a.addEventListener('click', e => {
    const target = document.querySelector(a.getAttribute('href'));
    if (!target) return;
    e.preventDefault();
    setMenu(false);
    history.pushState(null, '', a.getAttribute('href'));
    requestAnimationFrame(() => target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth' }));
  }));
  addEventListener('keydown', e => {
    if (!body.classList.contains('menu-open')) return;
    if (e.key === 'Escape') { setMenu(false); toggle.focus(); }
    if (e.key === 'Tab') { const pool = [toggle, ...focusables(menu)]; const i = pool.indexOf(document.activeElement); if (e.shiftKey && i <= 0) { e.preventDefault(); pool[pool.length - 1].focus(); } else if (!e.shiftKey && i === pool.length - 1) { e.preventDefault(); pool[0].focus(); } }
  });
  matchMedia('(min-width: 1100px)').addEventListener('change', e => { if (e.matches) setMenu(false); });

  /* ================= VIDEO TOGGLES ================= */
  $$('[data-vtoggle]').forEach(btn => {
    const media = $(btn.dataset.vtoggle);
    if (!media || !media.tmrVideo) { btn.hidden = true; return; }
    const video = media.tmrVideo, label = $('.vtoggle__label', btn);
    const sync = () => {
      const paused = !!media.dataset.paused || media.classList.contains('is-static') && video.paused;
      btn.setAttribute('aria-pressed', paused);
      label.textContent = paused ? 'Play video' : 'Pause video';
      btn.setAttribute('aria-label', paused ? 'Play background video' : 'Pause background video');
    };
    btn.addEventListener('click', () => {
      const paused = btn.getAttribute('aria-pressed') === 'true';
      if (paused) {
        delete media.dataset.paused;
        media.classList.remove('is-static');
        media.tmrLoad && media.tmrLoad();
        video.play().catch(() => {});
      } else {
        media.dataset.paused = '1';
        video.pause();
      }
      sync();
    });
    sync();
  });

  /* ================= FILM ================= */
  const film = $('#film'), filmVideo = $('.film__video', film);
  let filmReturn = null;
  const openFilm = () => {
    const src = T.videoSource('film', { getBoundingClientRect: () => ({ width: innerWidth, height: innerWidth * 9 / 16 }) });
    if (!src) return;
    filmReturn = document.activeElement;
    if (filmVideo.dataset.src !== src.src) {
      filmVideo.src = src.src; filmVideo.dataset.src = src.src;
      const poster = T.media.videos.film && T.media.videos.film.poster;
      if (poster) filmVideo.poster = T.best(poster);
    }
    film.hidden = false; lockScroll(true);
    const focusClose = () => $('.film__close', film).focus();
    if (hasGsap) gsap.fromTo(film, { autoAlpha: 0 }, { autoAlpha: 1, duration: .6, ease: 'power2.out', onComplete: focusClose });
    else focusClose();
    filmVideo.play().catch(() => {});
  };
  const closeFilm = () => {
    filmVideo.pause();
    const done = () => { film.hidden = true; lockScroll(false); filmReturn && filmReturn.focus(); };
    hasGsap ? gsap.to(film, { autoAlpha: 0, duration: .4, onComplete: done }) : done();
  };
  $$('[data-film]').forEach(b => b.addEventListener('click', openFilm));
  $('.film__close', film).addEventListener('click', closeFilm);
  $('.film__bg', film).addEventListener('click', closeFilm);
  film.addEventListener('keydown', e => { if (e.key === 'Escape') closeFilm(); trap(film, e); });

  /* ================= BOOKING ================= */
  const form = $('.bform'), result = $('.bresult'), err = $('.bform__error');
  const iso = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const parse = s => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
  const today = iso(new Date());
  form.checkin.min = today;
  form.checkout.min = today;
  form.checkin.addEventListener('change', () => {
    if (!form.checkin.value) return;
    const next = parse(form.checkin.value); next.setDate(next.getDate() + 1);
    form.checkout.min = iso(next);
    if (form.checkout.value && form.checkout.value <= form.checkin.value) form.checkout.value = iso(next);
  });
  $$('.stepper', form).forEach(st => {
    const input = $('input', st), min = +st.dataset.min, max = +st.dataset.max;
    const clamp = v => Math.min(max, Math.max(min, isNaN(v) ? min : v));
    $$('[data-step]', st).forEach(b => b.addEventListener('click', () => { input.value = clamp(+input.value + +b.dataset.step); }));
    input.addEventListener('change', () => { input.value = clamp(+input.value); });
  });
  // "Enquire" links pre-select the stay
  document.addEventListener('click', e => {
    const a = e.target.closest('[data-stay]');
    if (!a) return;
    const v = a.dataset.stay;
    if ([...form.stay.options].some(o => o.value === v)) form.stay.value = v;
  });
  const fmt = s => parse(s).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
  form.addEventListener('submit', e => {
    e.preventDefault();
    const a = form.checkin.value, l = form.checkout.value;
    err.textContent = '';
    if (!a || !l) { err.textContent = 'Please choose your check-in and check-out dates.'; (a ? form.checkout : form.checkin).focus(); return; }
    if (a < today) { err.textContent = 'Your check-in date can’t be in the past.'; form.checkin.focus(); return; }
    if (l <= a) { err.textContent = 'Your check-out date needs to be after your check-in date.'; form.checkout.focus(); return; }
    const nights = Math.round((parse(l) - parse(a)) / 864e5);
    const ad = +form.adults.value, ch = +form.children.value;
    const guests = `${ad} adult${ad > 1 ? 's' : ''}${ch ? `, ${ch} child${ch > 1 ? 'ren' : ''}` : ''}`;
    const stay = form.stay.value || 'Any stay';
    $('.bresult__summary', result).textContent = `${stay} · ${fmt(a)} to ${fmt(l)} · ${nights} night${nights > 1 ? 's' : ''} · ${guests}`;
    const wa = $('.bresult__wa', result);
    if (wa && C.contact.whatsapp) {
      const text = `Hello Top Mountain Resort, I'd like to check availability.\nStay: ${stay}\nCheck-in: ${fmt(a)}\nCheck-out: ${fmt(l)} (${nights} night${nights > 1 ? 's' : ''})\nGuests: ${guests}`;
      wa.href = `https://wa.me/${C.contact.whatsapp}?text=${encodeURIComponent(text)}`;
    }
    form.hidden = true; result.hidden = false;
    if (hasGsap) gsap.fromTo(result, { autoAlpha: 0, y: 20 }, { autoAlpha: 1, y: 0, duration: .7, ease: 'power3.out' });
    result.focus();
  });
  $('.bresult__edit', result).addEventListener('click', () => { result.hidden = true; form.hidden = false; form.checkin.focus(); });

  /* ================= EXPERIENCES ================= */
  const expItems = $$('.exp-item'), expFigs = $$('.exp-stage__fig'), expCount = $('.exp__count b');
  let expActive = 0;
  const setExp = i => {
    if (i === expActive) return;
    expFigs.forEach(f => f.classList.remove('was-active'));
    expFigs[expActive] && expFigs[expActive].classList.add('was-active');
    expActive = i;
    expItems.forEach((li, k) => li.classList.toggle('is-active', k === i));
    expFigs.forEach((f, k) => f.classList.toggle('is-active', k === i));
    if (expCount) expCount.textContent = pad(i + 1);
  };
  expItems.forEach((li, i) => li.addEventListener('mouseenter', () => desktop() && setExp(i)));
  const expSpy = new IntersectionObserver(entries => {
    entries.forEach(e => { if (e.isIntersecting && desktop()) setExp(+e.target.dataset.i); });
  }, { rootMargin: '-48% 0px -48% 0px' });
  expItems.forEach(li => expSpy.observe(li));

  /* ================= REVIEWS (only if verified reviews exist) ================= */
  const reviews = $$('.review');
  if (reviews.length > 1) {
    let ri = 0;
    const show = i => {
      ri = (i + reviews.length) % reviews.length;
      reviews.forEach((r, k) => { r.classList.toggle('is-active', k === ri); r.setAttribute('aria-hidden', k !== ri); });
    };
    $$('[data-review]').forEach(b => b.addEventListener('click', () => show(ri + +b.dataset.review)));
  }

  /* ================= LIGHTBOX ================= */
  const lb = $('#lightbox');
  const lbImg = $('.lb__img', lb), lbVideo = $('.lb__video', lb), lbStage = $('.lb__stage', lb);
  const lbCount = $('.lb__count', lb), lbTitle = $('.lb__title', lb), lbMeta = $('.lb__meta', lb), lbDesc = $('.lb__desc', lb), lbLink = $('.lb__link', lb);
  let lbItems = [], lbIndex = 0, lbOrigins = [], lbReturn = null, lbBusy = false;

  const galleryList = () => (T.galleryItems || []).map(g => ({ key: g.image, video: g.video, title: g.alt, meta: g.video ? 'Video' : '' }));
  const nearbyList = () => (T.nearbyItems || []).map(n => ({
    key: n.image, title: n.name, meta: `${n.type} · ${n.km} km from the resort`, desc: n.desc,
    link: `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(n.map)}`
  }));

  const containRect = key => {
    const m = T.image(key) || { w: 16, h: 10 };
    const s = lbStage.getBoundingClientRect();
    const scale = Math.min(s.width / m.w, s.height / m.h);
    const w = m.w * scale, h = m.h * scale;
    return { left: s.left + (s.width - w) / 2, top: s.top + (s.height - h) / 2, width: w, height: h };
  };

  const fill = () => {
    const it = lbItems[lbIndex];
    lbCount.innerHTML = `<b>${pad(lbIndex + 1)}</b> / <span>${pad(lbItems.length)}</span>`;
    lbTitle.textContent = it.title || '';
    lbMeta.textContent = it.meta || '';
    lbDesc.textContent = it.desc || '';
    lbLink.hidden = !it.link;
    if (it.link) { lbLink.href = it.link; lbLink.setAttribute('aria-label', `Directions to ${it.title} (opens Google Maps)`); }
    const m = T.image(it.key) || {};
    if (it.video) {
      const src = T.videoSource(it.video, { getBoundingClientRect: () => lbStage.getBoundingClientRect() });
      lbImg.hidden = true; lbVideo.hidden = false;
      lbVideo.poster = T.best(it.key, 1200);
      if (src && lbVideo.dataset.src !== src.src) { lbVideo.src = src.src; lbVideo.dataset.src = src.src; }
      lbVideo.setAttribute('aria-label', it.title || '');
      lbVideo.play().catch(() => {});
    } else {
      lbVideo.pause(); lbVideo.hidden = true; lbImg.hidden = false;
      const s = lbStage.getBoundingClientRect();
      lbImg.src = T.best(it.key, Math.max(s.width, s.height * (m.w / m.h || 1.5)) * Math.min(devicePixelRatio || 1, 2));
      lbImg.alt = it.title || m.alt || '';
      lbImg.width = m.w || 0; lbImg.height = m.h || 0;
    }
    const n = lbItems.length > 1;
    $('.lb__prev', lb).hidden = !n; $('.lb__next', lb).hidden = !n;
  };

  const openLb = (items, index, origins) => {
    if (lbBusy) return;
    lbItems = items; lbIndex = index; lbOrigins = origins || [];
    lbReturn = document.activeElement;
    lb.hidden = false; lockScroll(true);
    fill();
    const origin = lbOrigins[index] && $('img', lbOrigins[index]);
    if (hasGsap && origin) {
      lbBusy = true;
      const o = origin.getBoundingClientRect(), t = containRect(lbItems[index].key);
      const fly = document.createElement('img');
      fly.className = 'lb__fly'; fly.src = origin.currentSrc || origin.src; fly.alt = '';
      Object.assign(fly.style, { left: o.left + 'px', top: o.top + 'px', width: o.width + 'px', height: o.height + 'px', objectPosition: getComputedStyle(origin).objectPosition });
      body.appendChild(fly);
      const media = lbImg.hidden ? lbVideo : lbImg;
      gsap.set(media, { autoAlpha: 0 });
      gsap.set([$('.lb__top', lb), $('.lb__bottom', lb)], { autoAlpha: 0, y: 12 });
      // the dialog itself holds focus (and keyboard control) while the controls are still fading in
      lb.focus({ preventScroll: true });
      gsap.timeline({ onComplete: () => { fly.remove(); lbBusy = false; $('.lb__close', lb).focus({ preventScroll: true }); } })
        .fromTo($('.lb__bg', lb), { autoAlpha: 0 }, { autoAlpha: 1, duration: .5, ease: 'power2.out' }, 0)
        .to(fly, { left: t.left, top: t.top, width: t.width, height: t.height, duration: .85, ease: 'expo.inOut' }, 0)
        .to(media, { autoAlpha: 1, duration: .25 }, .75)
        .to([$('.lb__top', lb), $('.lb__bottom', lb)], { autoAlpha: 1, y: 0, duration: .5, stagger: .06, ease: 'power3.out' }, .55);
    } else {
      $('.lb__close', lb).focus({ preventScroll: true });
    }
  };

  const closeLb = () => {
    if (lbBusy) return;
    lbVideo.pause();
    const finish = () => {
      lb.hidden = true; lockScroll(false);
      gsap && gsap.set && hasGsap && gsap.set([lbImg, lbVideo, $('.lb__bg', lb), $('.lb__top', lb), $('.lb__bottom', lb)], { clearProps: 'all' });
      const back = lbOrigins[lbIndex] || lbReturn;
      back && back.focus({ preventScroll: true });
    };
    const originEl = lbOrigins[lbIndex], origin = originEl && $('img', originEl);
    const r = origin && origin.getBoundingClientRect();
    const visible = r && r.bottom > 0 && r.top < innerHeight && r.width > 0;
    if (!hasGsap) return finish();
    lbBusy = true;
    const done = () => { lbBusy = false; finish(); };
    if (visible && !lbImg.hidden) {
      const s = lbImg.getBoundingClientRect();
      const fly = document.createElement('img');
      fly.className = 'lb__fly'; fly.src = lbImg.currentSrc || lbImg.src; fly.alt = '';
      Object.assign(fly.style, { left: s.left + 'px', top: s.top + 'px', width: s.width + 'px', height: s.height + 'px', objectPosition: getComputedStyle(origin).objectPosition });
      body.appendChild(fly);
      gsap.set(lbImg, { autoAlpha: 0 });
      gsap.timeline({ onComplete: () => { fly.remove(); done(); } })
        .to([$('.lb__top', lb), $('.lb__bottom', lb)], { autoAlpha: 0, duration: .25 }, 0)
        .to($('.lb__bg', lb), { autoAlpha: 0, duration: .55, ease: 'power2.inOut' }, .1)
        .to(fly, { left: r.left, top: r.top, width: r.width, height: r.height, duration: .75, ease: 'expo.inOut' }, 0);
    } else {
      gsap.to(lb, { autoAlpha: 0, duration: .4, onComplete: () => { gsap.set(lb, { clearProps: 'all' }); done(); } });
    }
  };

  const stepLb = dir => {
    if (lbBusy || lbItems.length < 2) return;
    lbIndex = (lbIndex + dir + lbItems.length) % lbItems.length;
    if (!hasGsap) return fill();
    lbBusy = true;
    const out = lbImg.hidden ? lbVideo : lbImg;
    gsap.to(out, {
      autoAlpha: 0, x: -dir * 40, duration: .3, ease: 'power2.in', onComplete: () => {
        fill();
        const inn = lbImg.hidden ? lbVideo : lbImg;
        gsap.fromTo(inn, { autoAlpha: 0, x: dir * 40 }, { autoAlpha: 1, x: 0, duration: .55, ease: 'power3.out', onComplete: () => { lbBusy = false; } });
        gsap.fromTo($('.lb__cap', lb), { autoAlpha: 0, y: 10 }, { autoAlpha: 1, y: 0, duration: .5, ease: 'power3.out' });
      }
    });
  };

  $('.lb__close', lb).addEventListener('click', closeLb);
  $('.lb__prev', lb).addEventListener('click', () => stepLb(-1));
  $('.lb__next', lb).addEventListener('click', () => stepLb(1));
  $('.lb__bg', lb).addEventListener('click', closeLb);
  lbStage.addEventListener('click', e => { if (e.target === lbStage) closeLb(); });
  lb.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeLb();
    else if (e.key === 'ArrowRight') stepLb(1);
    else if (e.key === 'ArrowLeft') stepLb(-1);
    else trap(lb, e);
  });
  // swipe
  let sx = null, sy = null;
  lbStage.addEventListener('pointerdown', e => { sx = e.clientX; sy = e.clientY; });
  lbStage.addEventListener('pointerup', e => {
    if (sx === null) return;
    const dx = e.clientX - sx, dy = e.clientY - sy;
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) stepLb(dx < 0 ? 1 : -1);
    else if (dy > 90 && Math.abs(dy) > Math.abs(dx)) closeLb();
    sx = sy = null;
  });

  const gBtns = $$('[data-gallery]');
  gBtns.forEach(b => b.addEventListener('click', () => openLb(galleryList(), +b.dataset.gallery, gBtns)));
  const pBtns = $$('[data-place]');
  pBtns.forEach(b => b.addEventListener('click', () => {
    if (rail && rail.dataset.dragged) return;
    openLb(nearbyList(), +b.dataset.place, pBtns);
  }));

  /* ================= NEARBY RAIL ================= */
  const rail = $('.rail');
  if (rail) {
    const places = $$('.place', rail);
    const fillBar = $('.scale__fill'), dot = $('.scale__dot'), kmLbl = $('.scale__km');
    const [prevB, nextB] = [$('[data-rail="-1"]'), $('[data-rail="1"]')];
    const update = () => {
      const max = rail.scrollWidth - rail.clientWidth;
      const p = max > 0 ? rail.scrollLeft / max : 0;
      if (fillBar) fillBar.style.transform = `scaleX(${p})`;
      if (dot) dot.style.left = (p * 100) + '%';
      const left = rail.getBoundingClientRect().left + parseFloat(getComputedStyle(rail).paddingLeft) - 2;
      const first = places.find(pl => pl.getBoundingClientRect().right > left + 40) || places[0];
      if (kmLbl && first) kmLbl.textContent = first.dataset.km + ' km';
      if (prevB) prevB.disabled = rail.scrollLeft < 4;
      if (nextB) nextB.disabled = rail.scrollLeft > max - 4;
    };
    rail.addEventListener('scroll', () => requestAnimationFrame(update), { passive: true });
    addEventListener('resize', update);
    update();
    const stepW = () => (places[0] ? places[0].getBoundingClientRect().width : 300) + parseFloat(getComputedStyle(rail).columnGap || 20);
    $$('[data-rail]').forEach(b => b.addEventListener('click', () => {
      rail.scrollBy({ left: +b.dataset.rail * stepW() * (desktop() ? 2 : 1), behavior: reduce ? 'auto' : 'smooth' });
    }));
    rail.addEventListener('keydown', e => {
      if (e.target !== rail) return;
      if (e.key === 'ArrowRight') { e.preventDefault(); rail.scrollBy({ left: stepW(), behavior: 'smooth' }); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); rail.scrollBy({ left: -stepW(), behavior: 'smooth' }); }
    });
    // mouse drag (touch uses native scrolling)
    let down = false, startX = 0, startL = 0, moved = 0;
    rail.addEventListener('pointerdown', e => {
      if (e.pointerType !== 'mouse' || e.button !== 0) return;
      down = true; moved = 0; startX = e.clientX; startL = rail.scrollLeft;
    });
    addEventListener('pointermove', e => {
      if (!down) return;
      const dx = e.clientX - startX; moved = Math.max(moved, Math.abs(dx));
      if (moved > 6) { rail.classList.add('is-dragging'); rail.scrollLeft = startL - dx; }
    });
    addEventListener('pointerup', () => {
      if (!down) return;
      down = false;
      if (moved > 6) {
        rail.dataset.dragged = '1';
        setTimeout(() => { delete rail.dataset.dragged; }, 60);
        const target = places.reduce((best, pl) => {
          const d = Math.abs(pl.offsetLeft - parseFloat(getComputedStyle(rail).paddingLeft) - rail.scrollLeft);
          return d < best.d ? { d, pl } : best;
        }, { d: Infinity }).pl;
        rail.classList.remove('is-dragging');
        target && rail.scrollTo({ left: target.offsetLeft - parseFloat(getComputedStyle(rail).paddingLeft), behavior: 'smooth' });
      } else rail.classList.remove('is-dragging');
    });
    rail.addEventListener('dragstart', e => e.preventDefault());
  }

  /* ================= MOBILE BOOKING BAR ================= */
  const mbar = $('.mbar');
  const vis = { hero: true, book: false, foot: false };
  const syncBar = () => mbar.classList.toggle('is-on', !vis.hero && !vis.book && !vis.foot);
  new IntersectionObserver(es => es.forEach(e => {
    const k = e.target === hero ? 'hero' : e.target.id === 'book' ? 'book' : 'foot';
    vis[k] = e.isIntersecting; syncBar();
  }), { rootMargin: '0px 0px -10% 0px' }).observe(hero);
  const io2 = new IntersectionObserver(es => es.forEach(e => {
    vis[e.target.id === 'book' ? 'book' : 'foot'] = e.isIntersecting; syncBar();
  }));
  io2.observe($('#book')); io2.observe($('.foot'));

  /* ================= MOTION ================= */
  if (!hasGsap) { html.classList.remove('anim'); return; }
  window.__tmrAnim = true;
  gsap.registerPlugin(ScrollTrigger);
  ScrollTrigger.config({ ignoreMobileResize: true });
  gsap.defaults({ ease: 'power3.out' });

  /* hero entrance */
  const heroLines = $$('.hero__title .ln > span');
  const heroFades = $$('[data-hero-fade]');
  const heroMedia = $('.hero__media');
  // hand the start states over from CSS to GSAP in the same task (no paint in between),
  // so GSAP never reads the CSS offsets as its own base values
  html.classList.remove('anim');
  gsap.set(heroLines, { yPercent: 110 });
  gsap.set(heroFades, { autoAlpha: 0, y: 16 });
  gsap.set(heroMedia, { clipPath: 'inset(5% 4% 5% 4% round 14px)', scale: 1.04 });
  gsap.set('.nav__inner', { autoAlpha: 0, y: -10 });

  gsap.timeline({ delay: .15 })
    .to(heroMedia, { clipPath: 'inset(0% 0% 0% 0% round 0px)', scale: 1, duration: 1.9, ease: 'expo.inOut' }, 0)
    .to(heroLines, { yPercent: 0, duration: 1.4, ease: 'expo.out', stagger: .12 }, .9)
    .to(heroFades, { autoAlpha: 1, y: 0, duration: 1.1, stagger: .1 }, 1.25)
    .to('.nav__inner', { autoAlpha: 1, y: 0, duration: 1, clearProps: 'transform,opacity,visibility' }, 1.2)
    .add(() => gsap.set(heroMedia, { clearProps: 'clipPath,transform' }));

  /* hero scroll-out */
  gsap.to('.hero__inner', { yPercent: -18, autoAlpha: 0, ease: 'none', scrollTrigger: { trigger: hero, start: '30% top', end: 'bottom top', scrub: true } });
  gsap.to('.hero__foot', { autoAlpha: 0, ease: 'none', scrollTrigger: { trigger: hero, start: '10% top', end: '35% top', scrub: true } });
  gsap.fromTo('.hero__media .pic, .hero__media .bgvideo', { yPercent: 0 }, { yPercent: 12, ease: 'none', scrollTrigger: { trigger: hero, start: 'top top', end: 'bottom top', scrub: true } });

  /* headline line reveals */
  $$('[data-split]').forEach(el => {
    gsap.from($$('.ln > span', el), { yPercent: 110, duration: 1.3, ease: 'expo.out', stagger: .1, scrollTrigger: { trigger: el, start: 'top 86%' } });
  });

  /* fade-ups (batched) */
  ScrollTrigger.batch('[data-fade]', {
    start: 'top 90%', once: true,
    onEnter: els => gsap.fromTo(els, { autoAlpha: 0, y: 28 }, { autoAlpha: 1, y: 0, duration: 1.1, stagger: .08, overwrite: true })
  });
  gsap.set('[data-fade]', { autoAlpha: 0 });
  ScrollTrigger.addEventListener('refreshInit', () => {});

  /* clip-path image reveals */
  $$('[data-reveal="clip"]:not(.g-item)').forEach(el => {
    const pic = $('.pic', el);
    const tl = gsap.timeline({ scrollTrigger: { trigger: el, start: 'top 85%' } });
    tl.fromTo(el, { clipPath: 'inset(100% 0% 0% 0%)' }, { clipPath: 'inset(0% 0% 0% 0%)', duration: 1.5, ease: 'expo.inOut' }, 0);
    if (pic) tl.fromTo(pic, { scale: 1.28 }, { scale: 1, duration: 2, ease: 'expo.out' }, .1);
  });
  ScrollTrigger.batch('.g-item', {
    start: 'top 92%', once: true,
    onEnter: els => {
      gsap.fromTo(els, { clipPath: 'inset(100% 0% 0% 0%)' }, { clipPath: 'inset(0% 0% 0% 0%)', duration: 1.3, ease: 'expo.inOut', stagger: .09 });
      gsap.fromTo(els.map(e => $('.pic', e)), { scale: 1.25 }, { scale: 1, duration: 1.8, ease: 'expo.out', stagger: .09 });
    }
  });
  gsap.set('.g-item', { clipPath: 'inset(100% 0% 0% 0%)' });

  /* parallax inside frames */
  $$('[data-parallax]').forEach(el => {
    const amt = parseFloat(el.dataset.parallax) || .1;
    gsap.fromTo(el, { yPercent: -amt * 50 }, { yPercent: amt * 50, ease: 'none', scrollTrigger: { trigger: el.parentElement, start: 'top bottom', end: 'bottom top', scrub: true } });
  });
  // figures marked data-drift float at their own speed, giving the collage depth (desktop only)
  gsap.matchMedia().add('(min-width: 900px)', () => {
    $$('[data-drift]').forEach(el => {
      const d = parseFloat(el.dataset.drift) || 40;
      gsap.fromTo(el, { y: d }, { y: -d, ease: 'none', scrollTrigger: { trigger: el, start: 'top bottom', end: 'bottom top', scrub: true } });
    });
  });
  // floating rooms rise gently into place; the photo inside settles from a slight zoom
  gsap.set('[data-float-in]', { autoAlpha: 0, y: 70 });
  ScrollTrigger.batch('[data-float-in]', {
    start: 'top 88%', once: true,
    onEnter: els => {
      gsap.to(els, { autoAlpha: 1, y: 0, duration: 1.5, ease: 'expo.out', stagger: .15 });
      gsap.fromTo(els.map(e => $('.room__frame .pic', e)).filter(Boolean), { scale: 1.12 }, { scale: 1, duration: 2, ease: 'expo.out', stagger: .15 });
    }
  });

  /* section transitions: pine panels open out as they arrive */
  ['.dining', '.book'].forEach(sel => {
    const el = $(sel);
    if (!el) return;
    gsap.fromTo(el, { clipPath: 'inset(0% 3.5% 0% 3.5% round 28px)' }, { clipPath: 'inset(0% 0% 0% 0% round 0px)', ease: 'none', scrollTrigger: { trigger: el, start: 'top bottom', end: 'top 25%', scrub: true } });
  });

  /* footer wordmark */
  gsap.fromTo('.foot__mark', { yPercent: 45, autoAlpha: 0 }, { yPercent: 0, autoAlpha: 1, ease: 'none', scrollTrigger: { trigger: '.foot__mark', start: 'top bottom', end: 'bottom bottom', scrub: true } });

  /* discover: framed window opens into a full-screen aerial, then three chapters */
  const mm = gsap.matchMedia();
  mm.add('(min-width: 900px) and (min-height: 600px)', () => {
    const sec = $('.discover');
    sec.classList.add('is-pinned');
    const chaps = $$('.dchap', sec);
    const tl = gsap.timeline({
      scrollTrigger: { trigger: sec, start: 'top top', end: '+=320%', pin: '.discover__pin', scrub: .8, anticipatePin: 1, invalidateOnRefresh: true }
    });
    tl.fromTo('.discover__frame', { clipPath: 'inset(35% 23% 8% 23% round 6px)' }, { clipPath: 'inset(0% 0% 0% 0% round 0px)', duration: 1, ease: 'power2.inOut' }, 0)
      .fromTo('.discover__media', { scale: 1.25 }, { scale: 1, duration: 1.2, ease: 'none' }, 0)
      .to('.discover__head', { autoAlpha: 0, yPercent: -40, duration: .45, ease: 'power2.in' }, 0.05)
      .to('.discover__ui', { autoAlpha: 1, duration: .3 }, .8);
    chaps.forEach((c, i) => {
      const at = .9 + i * .75;
      tl.fromTo(c, { autoAlpha: 0, y: 40 }, { autoAlpha: 1, y: 0, duration: .3, ease: 'power3.out' }, at);
      if (i < chaps.length - 1) tl.to(c, { autoAlpha: 0, y: -30, duration: .25, ease: 'power2.in' }, at + .5);
    });
    tl.to({}, { duration: .4 });
    return () => { sec.classList.remove('is-pinned'); };
  });

  /* settle */
  const refresh = () => ScrollTrigger.refresh();
  addEventListener('load', refresh);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(refresh);
})();
