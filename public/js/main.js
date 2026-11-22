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
  ['stay', 'experiences', 'location', 'book', 'intro', 'discover'].forEach(id => { const el = document.getElementById(id); if (el) spy.observe(el); });

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
  // The thumbnail grows into the player on open and shrinks back into place on close.
  const film = $('#film'), filmVideo = $('.film__video', film), filmBg = $('.film__bg', film), filmTop = $('.film__top', film);
  let filmReturn = null, filmBusy = false, heldVideos = [];
  const onScreen = r => !!r && r.width > 0 && r.bottom > 0 && r.top < innerHeight;
  const flyer = (src, r, radius) => {
    const fly = document.createElement('img');
    fly.className = 'lb__fly'; fly.src = src; fly.alt = '';
    Object.assign(fly.style, { left: r.left + 'px', top: r.top + 'px', width: r.width + 'px', height: r.height + 'px', borderRadius: radius });
    body.appendChild(fly);
    return fly;
  };
  const openFilm = e => {
    if (filmBusy || !film.hidden) return;
    const src = T.videoSource('film', { getBoundingClientRect: () => ({ width: innerWidth, height: innerWidth * 9 / 16 }) });
    if (!src) return;
    const trigger = e.currentTarget;
    filmReturn = trigger;
    if (filmVideo.dataset.src !== src.src) {
      filmVideo.src = src.src; filmVideo.dataset.src = src.src;
      const poster = T.media.videos.film && T.media.videos.film.poster;
      if (poster) filmVideo.poster = T.best(poster);
    }
    const thumb = $('img', trigger);
    const thumbSrc = thumb && (thumb.currentSrc || thumb.src);
    if (thumbSrc) filmBg.style.setProperty('--film-bg', `url("${thumbSrc}")`);
    // the background loops rest while the film plays
    heldVideos = $$('video.bgvideo').filter(v => !v.paused);
    heldVideos.forEach(v => v.pause());
    film.hidden = false; lockScroll(true);
    filmVideo.play().catch(() => {}); // started inside the tap, so phones allow sound
    const focusClose = () => $('.film__close', film).focus({ preventScroll: true });
    const o = thumb && thumb.getBoundingClientRect();
    if (!hasGsap || !onScreen(o)) {
      if (hasGsap) gsap.fromTo(film, { autoAlpha: 0 }, { autoAlpha: 1, duration: .6, ease: 'power2.out', onComplete: focusClose });
      else focusClose();
      return;
    }
    filmBusy = true;
    film.focus({ preventScroll: true });
    const t = filmVideo.getBoundingClientRect();
    const fly = flyer(thumbSrc, o, getComputedStyle(trigger).borderRadius);
    // swap in the full-size poster once it's decoded, so the picture sharpens as it grows
    if (filmVideo.poster && 'decode' in Image.prototype) {
      const big = new Image();
      big.src = filmVideo.poster;
      big.decode().then(() => { if (fly.isConnected) fly.src = big.src; }).catch(() => {});
    }
    // if the film starts before the thumbnail lands, hold it on its first frame
    const hold = () => { if (filmBusy) filmVideo.pause(); };
    filmVideo.addEventListener('playing', hold, { once: true });
    gsap.set(filmVideo, { autoAlpha: 0 });
    gsap.set(filmTop, { autoAlpha: 0, y: -12 });
    gsap.timeline({
      onComplete: () => {
        fly.remove(); filmBusy = false;
        filmVideo.removeEventListener('playing', hold);
        if (filmVideo.paused) filmVideo.play().catch(() => {});
        focusClose();
      }
    })
      .fromTo(filmBg, { autoAlpha: 0 }, { autoAlpha: 1, duration: .6, ease: 'power2.out' }, 0)
      .to(fly, { left: t.left, top: t.top, width: t.width, height: t.height, borderRadius: 0, duration: .95, ease: 'expo.inOut' }, 0)
      .to(filmVideo, { autoAlpha: 1, duration: .25 }, .85)
      .to(filmTop, { autoAlpha: 1, y: 0, duration: .5 }, .6);
  };
  const closeFilm = () => {
    if (filmBusy || film.hidden) return;
    filmVideo.pause();
    const back = filmReturn;
    const finish = () => {
      film.hidden = true; lockScroll(false);
      if (hasGsap) gsap.set([film, filmVideo, filmTop], { clearProps: 'opacity,visibility,transform' });
      if (hasGsap) gsap.set(filmBg, { clearProps: 'opacity,visibility' });
      heldVideos.forEach(v => v.play().catch(() => {}));
      heldVideos = [];
      back && back.focus({ preventScroll: true });
    };
    if (!hasGsap) return finish();
    filmBusy = true;
    const done = () => { filmBusy = false; finish(); };
    const thumb = back && $('img', back), r = thumb && thumb.getBoundingClientRect();
    if (onScreen(r)) {
      const s = filmVideo.getBoundingClientRect();
      const fly = flyer(filmVideo.poster || thumb.currentSrc || thumb.src, s, '0px');
      gsap.timeline({ onComplete: () => { fly.remove(); done(); } })
        .to(filmTop, { autoAlpha: 0, duration: .25 }, 0)
        .to(filmVideo, { autoAlpha: 0, duration: .2 }, 0)
        .to(filmBg, { autoAlpha: 0, duration: .6, ease: 'power2.inOut' }, .15)
        .to(fly, { left: r.left, top: r.top, width: r.width, height: r.height, borderRadius: getComputedStyle(back).borderRadius, duration: .8, ease: 'expo.inOut' }, 0);
    } else {
      gsap.to(film, { autoAlpha: 0, duration: .4, onComplete: done });
    }
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
  const submitBtn = $('.bform__submit', form), submitHtml = submitBtn.innerHTML;
  const BOOT = window.BOOT || {};
  const money = n => `${(BOOT.site && BOOT.site.currency) || 'LKR'} ${Number(n || 0).toLocaleString('en-US')}`;
  const plural = (n, one, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;

  // Asks the booking system which rooms are free. Answers null when it can't be reached (or the page
  // isn't served by index.php), so the guest can still call or message instead.
  const roomsFor = async (a, l) => {
    if (!window.BOOT) return null;
    try {
      const r = await fetch(`/api/availability?${new URLSearchParams({ kind: 'room', checkIn: a, checkOut: l })}`, { headers: { Accept: 'application/json' } });
      const data = await r.json().catch(() => ({}));
      if (r.ok && Array.isArray(data.rooms)) return { rooms: data.rooms, advanceOn: data.advanceOn !== false };
      if (r.status === 400 && data.error) return { error: data.error }; // e.g. a stay longer than allowed
      return null;
    } catch (_) { return null; }
  };

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const a = form.checkin.value, l = form.checkout.value;
    err.textContent = '';
    if (!a || !l) { err.textContent = 'Please choose your check-in and check-out dates.'; (a ? form.checkout : form.checkin).focus(); return; }
    if (a < today) { err.textContent = 'Your check-in date can’t be in the past.'; form.checkin.focus(); return; }
    if (l <= a) { err.textContent = 'Your check-out date needs to be after your check-in date.'; form.checkout.focus(); return; }
    const nights = Math.round((parse(l) - parse(a)) / 864e5);
    const ad = +form.adults.value, ch = +form.children.value;
    const guests = `${plural(ad, 'adult')}${ch ? `, ${ch} child${ch > 1 ? 'ren' : ''}` : ''}`;
    const stay = form.stay.value;

    submitBtn.disabled = true;
    submitBtn.textContent = 'Checking availability…';
    const answer = await roomsFor(a, l);
    submitBtn.disabled = false;
    submitBtn.innerHTML = submitHtml;
    if (answer && answer.error) { err.textContent = answer.error; return; }

    let status, note, canBook = false;
    if (!answer) {
      status = 'Your enquiry';
      note = 'We couldn’t check availability just now. Call or message the resort with these details and we’ll confirm with you directly.';
    } else {
      // a stay picked in the form narrows the rooms once Admin → Rooms gives rooms that type
      const ofStay = stay ? answer.rooms.filter(r => String(r.type).toLowerCase() === stay.toLowerCase()) : [];
      const rooms = ofStay.length ? ofStay : answer.rooms;
      const free = rooms.filter(r => r.available);
      const fits = free.filter(r => r.capacity >= ad);
      canBook = free.length > 0;
      if (!canBook) {
        status = 'Fully booked';
        note = 'Every room is taken for those dates. Try other dates, or call or message us: we may still be able to help.';
      } else {
        const from = Math.min(...(fits.length ? fits : free).map(r => r.total));
        status = `${plural(free.length, 'room')} free`;
        // a group bigger than any one room books several rooms together, on one booking
        const group = ad + ch;
        const beds = free.reduce((sum, r) => sum + r.capacity, 0);
        note = fits.length && group <= Math.max(...fits.map(r => r.capacity))
          ? `From ${money(from)} for ${plural(nights, 'night')}. Choose a room (or several) and send your booking: ${answer.advanceOn ? 'it is confirmed once the advance is paid.' : 'nothing to pay now, you pay at the resort.'}`
          : beds >= group
            ? `No single room sleeps all ${group} of you, so choose two or more rooms on the next page. They’re booked together, on one booking.`
            : `The free rooms sleep ${beds}, fewer than your ${group}. Book the rooms that are free, or call us and we’ll help.`;
      }
      // the button says "rooms" when the group needs more than one
      $('.bresult__book', result).firstChild.textContent = canBook && ad + ch > Math.max(0, ...free.map(r => r.capacity)) ? 'Choose your rooms ' : 'Choose your room ';
      const q = new URLSearchParams({ kind: 'room', checkIn: a, checkOut: l, adults: ad });
      if (ch) q.set('children', ch);
      $('.bresult__book', result).href = `/book?${q}`;
    }
    $('.bresult__status', result).textContent = status;
    $('.bresult__summary', result).textContent = `${stay || 'Any stay'} · ${fmt(a)} to ${fmt(l)} · ${plural(nights, 'night')} · ${guests}`;
    $('.bresult__note', result).textContent = note;
    $('.bresult__book', result).hidden = !canBook;
    // with rooms to book, calling is the quieter option
    $('.bresult__call', result).classList.toggle('btn--solid', !canBook);
    $('.bresult__call', result).classList.toggle('btn--outline', canBook);
    const wa = $('.bresult__wa', result);
    if (wa && C.contact.whatsapp) {
      const text = `Hello Top Mountain Resort, I'd like to check availability.\nStay: ${stay || 'Any stay'}\nCheck-in: ${fmt(a)}\nCheck-out: ${fmt(l)} (${plural(nights, 'night')})\nGuests: ${guests}`;
      wa.href = `https://wa.me/${C.contact.whatsapp}?text=${encodeURIComponent(text)}`;
    }
    form.hidden = true; result.hidden = false;
    if (hasGsap) gsap.fromTo(result, { autoAlpha: 0, y: 20 }, { autoAlpha: 1, y: 0, duration: .7, ease: 'power3.out' });
    result.focus();
  });
  $('.bresult__edit', result).addEventListener('click', () => { result.hidden = true; form.hidden = false; form.checkin.focus(); });

  /* ================= EXPERIENCES & DINING ================= */
  // phones: each tab's cards sit in a swipeable rail, with a counter and hairline that follow along
  const xpRails = $$('.exp__panel').map(panel => ({ rail: $('.xp-grid', panel), prog: $('.xp-progress', panel) })).filter(r => r.rail && r.prog);
  const syncRail = ({ rail, prog }) => {
    const n = $$('.xp', rail).length, max = rail.scrollWidth - rail.clientWidth;
    // only a scrolling rail needs to be reachable by keyboard
    if (max > 2) rail.tabIndex = 0; else rail.removeAttribute('tabindex');
    const p = max > 2 ? Math.min(1, Math.max(0, rail.scrollLeft / max)) : 0;
    $('.xp-progress__bar i', prog).style.transform = `scaleX(${(1 + p * (n - 1)) / n})`;
    $('.xp-progress__count b', prog).textContent = pad(Math.round(p * (n - 1)) + 1);
  };
  const syncRails = () => xpRails.forEach(syncRail);
  xpRails.forEach(r => r.rail.addEventListener('scroll', () => syncRail(r), { passive: true }));
  addEventListener('resize', syncRails);
  syncRails();

  // two tabs share one card grid: switching swaps the heading, the intro and the cards, so the height stays put
  const expSec = $('#experiences'), expTabs = $$('.exp__tab');
  const showTab = (tab, { focus = false, animate = true } = {}) => {
    if (!tab || tab.getAttribute('aria-selected') === 'true') { if (focus && tab) tab.focus(); return; }
    expTabs.forEach(t => {
      const on = t === tab;
      t.setAttribute('aria-selected', String(on));
      t.tabIndex = on ? 0 : -1;
      $('#' + t.getAttribute('aria-controls')).hidden = !on;
    });
    const panel = $('#' + tab.getAttribute('aria-controls')), which = panel.id.replace('xp-panel-', '');
    $$('.exp__copy', expSec).forEach(cp => { cp.hidden = cp.dataset.copy !== which; });
    if (focus) tab.focus();
    const rail = $('.xp-grid', panel);
    if (rail) rail.scrollLeft = 0;
    syncRails();
    if (hasGsap && window.ScrollTrigger) ScrollTrigger.refresh();
    if (!hasGsap || !animate) return;
    const copy = $(`.exp__copy[data-copy="${which}"]`, expSec), cards = $$('.xp', panel);
    gsap.fromTo($$('.ln > span', copy), { yPercent: 110 }, { yPercent: 0, duration: 1, ease: 'expo.out', stagger: .08 });
    gsap.fromTo($('.lede', copy), { opacity: 0, y: 14 }, { opacity: 1, y: 0, duration: .8, ease: 'power3.out', delay: .1, clearProps: 'opacity,transform' });
    // opacity, not autoAlpha: the cards stay reachable while they arrive
    gsap.fromTo(cards, { opacity: 0, y: 30 }, { opacity: 1, y: 0, duration: .9, ease: 'expo.out', stagger: .06, clearProps: 'opacity,transform' });
    gsap.fromTo(cards.map(c => $('.pic', c)).filter(Boolean), { scale: 1.15 }, { scale: 1, duration: 1.4, ease: 'expo.out', stagger: .06, clearProps: 'transform' });
  };
  expTabs.forEach(t => t.addEventListener('click', () => showTab(t)));
  const expTablist = $('.exp__tabs');
  if (expTablist) expTablist.addEventListener('keydown', e => {
    const i = expTabs.indexOf(document.activeElement);
    if (i < 0) return;
    let next = -1;
    if (e.key === 'ArrowRight') next = (i + 1) % expTabs.length;
    else if (e.key === 'ArrowLeft') next = (i - 1 + expTabs.length) % expTabs.length;
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = expTabs.length - 1;
    if (next < 0) return;
    e.preventDefault();
    showTab(expTabs[next], { focus: true });
  });
  // links to #dining (nav, menu, footer) land on this section with the Dining tab open
  // (the browser's own jump would aim at the tab inside the sticky heading, so scroll to the section instead)
  document.addEventListener('click', e => {
    if (!e.target.closest('a[href="#dining"]')) return;
    showTab($('#dining'));
    if (e.defaultPrevented) return; // the phone menu scrolls there itself
    e.preventDefault();
    history.pushState(null, '', '#dining');
    expSec.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth' });
  });
  if (location.hash === '#dining') showTab($('#dining'), { animate: false });

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
  let lbOnIndex = null; // optional: told which photo is showing, so the page behind can follow along
  // optional strip of every photo in the set (room galleries); click one to jump to it
  const lbThumbs = $('.lb__thumbs', lb), lbThumbTrack = $('.lb__thumbs-track', lb);
  let lbHasThumbs = false;
  const lbChrome = () => [$('.lb__top', lb), $('.lb__bottom', lb), lbThumbs];
  // the backdrop takes its tint from the photo on show: a small copy, heavily blurred, fading between layers
  const lbAmbs = $$('.lb__amb', lb);
  let lbAmbOn = -1, lbAmbSrc = '';
  const setLbAmb = src => {
    if (!src || src === lbAmbSrc || lbAmbs.length < 2) return;
    lbAmbSrc = src; lbAmbOn = lbAmbOn === 0 ? 1 : 0;
    lbAmbs[lbAmbOn].style.backgroundImage = `url("${src}")`;
    lbAmbs.forEach((l, k) => l.classList.toggle('is-on', k === lbAmbOn));
  };
  const buildThumbs = on => {
    lbHasThumbs = !!on && lbItems.length > 1;
    lb.classList.toggle('lb--thumbs', lbHasThumbs);
    lbThumbs.hidden = !lbHasThumbs;
    lbThumbTrack.innerHTML = lbHasThumbs ? lbItems.map((it, i) =>
      `<button type="button" class="lb__thumb" data-i="${i}" aria-label="Photo ${i + 1} of ${lbItems.length}"><img src="${T.best(it.key, 160)}" alt="" decoding="async"></button>`).join('') : '';
  };
  const syncThumbs = () => {
    if (!lbHasThumbs) return;
    lbThumbTrack.classList.toggle('is-scroll', lbThumbTrack.scrollWidth > lbThumbTrack.clientWidth + 2);
    [...lbThumbTrack.children].forEach((b, i) => b.setAttribute('aria-current', i === lbIndex ? 'true' : 'false'));
    const cur = lbThumbTrack.children[lbIndex];
    if (cur) lbThumbTrack.scrollTo({ left: cur.offsetLeft - (lbThumbTrack.clientWidth - cur.offsetWidth) / 2, behavior: reduce ? 'auto' : 'smooth' });
  };

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
    if (lbOnIndex) lbOnIndex(lbIndex);
    lbCount.innerHTML = `<b>${pad(lbIndex + 1)}</b> / <span>${pad(lbItems.length)}</span>`;
    lbTitle.textContent = it.title || '';
    lbMeta.textContent = it.meta || '';
    lbDesc.textContent = it.desc || '';
    lbLink.hidden = !it.link;
    if (it.link) { lbLink.href = it.link; lbLink.setAttribute('aria-label', `Directions to ${it.title} (opens Google Maps)`); }
    const m = T.image(it.key) || {};
    setLbAmb(T.best(it.key, 480));
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
      lbImg.alt = it.alt || it.title || m.alt || '';
      lbImg.width = m.w || 0; lbImg.height = m.h || 0;
    }
    const n = lbItems.length > 1;
    $('.lb__prev', lb).hidden = !n; $('.lb__next', lb).hidden = !n;
    syncThumbs();
  };

  const openLb = (items, index, origins, onIndex, opts = {}) => {
    if (lbBusy) return;
    lbItems = items; lbIndex = index; lbOrigins = origins || []; lbOnIndex = onIndex || null;
    lbReturn = document.activeElement;
    lb.hidden = false; lockScroll(true);
    buildThumbs(opts.thumbs);
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
      gsap.set(lbChrome(), { autoAlpha: 0, y: 12 });
      // the dialog itself holds focus (and keyboard control) while the controls are still fading in
      lb.focus({ preventScroll: true });
      gsap.timeline({ onComplete: () => { fly.remove(); lbBusy = false; $('.lb__close', lb).focus({ preventScroll: true }); } })
        .fromTo($('.lb__bg', lb), { autoAlpha: 0 }, { autoAlpha: 1, duration: .5, ease: 'power2.out' }, 0)
        .to(fly, { left: t.left, top: t.top, width: t.width, height: t.height, duration: .85, ease: 'expo.inOut' }, 0)
        .to(media, { autoAlpha: 1, duration: .25 }, .75)
        .to(lbChrome(), { autoAlpha: 1, y: 0, duration: .5, stagger: .06, ease: 'power3.out' }, .55);
    } else {
      $('.lb__close', lb).focus({ preventScroll: true });
    }
  };

  const closeLb = () => {
    if (lbBusy) return;
    lbVideo.pause();
    const finish = () => {
      lb.hidden = true; lockScroll(false);
      gsap && gsap.set && hasGsap && gsap.set([lbImg, lbVideo, $('.lb__bg', lb), ...lbChrome()], { clearProps: 'all' });
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
        .to(lbChrome(), { autoAlpha: 0, duration: .25 }, 0)
        .to($('.lb__bg', lb), { autoAlpha: 0, duration: .55, ease: 'power2.inOut' }, .1)
        .to(fly, { left: r.left, top: r.top, width: r.width, height: r.height, duration: .75, ease: 'expo.inOut' }, 0);
    } else {
      gsap.to(lb, { autoAlpha: 0, duration: .4, onComplete: () => { gsap.set(lb, { clearProps: 'all' }); done(); } });
    }
  };

  const stepLb = dir => {
    if (lbItems.length < 2) return;
    goLb((lbIndex + dir + lbItems.length) % lbItems.length, dir);
  };
  const goLb = (i, dir = i > lbIndex ? 1 : -1) => {
    if (lbBusy || i === lbIndex) return;
    lbIndex = i;
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

  lbThumbTrack.addEventListener('click', e => { const b = e.target.closest('.lb__thumb'); if (b) goLb(+b.dataset.i); });

  // rooms: the button on a room's tile opens all of its photos, starting from the cover
  const roomList = r => r.photos.map(key => {
    const alt = (T.image(key) || {}).alt || '';
    return { key, title: r.name, meta: 'Accommodation', desc: alt, alt: alt ? `${r.name}: ${alt}` : r.name };
  });
  $$('[data-room]').forEach(b => b.addEventListener('click', () => {
    const r = (T.roomItems || [])[+b.dataset.room];
    if (r) openLb(roomList(r), 0, r.photos.map(() => b), null, { thumbs: true });
  }));

  // rooms: five at first; each "Show more rooms" adds five (a new row on desktop, further along the
  // swipeable row on phones), and once all are out "Show fewer rooms" folds them away again
  const rgal = $('.rgal'), rcards = rgal ? $$('.rcard', rgal) : [];
  const moreBtn = $('.stays__morebtn'), shownOut = $('.stays__shown b');
  const rise = (els, delay = 0) => {
    if (!hasGsap || !els.length) return;
    // opacity, not autoAlpha: the rooms stay focusable while they fade in
    gsap.fromTo(els, { opacity: 0, y: 46 }, { opacity: 1, y: 0, duration: 1.2, ease: 'expo.out', stagger: .07, delay, clearProps: 'transform,opacity' });
    gsap.fromTo(els.map(li => $('.rcard__img .pic', li)).filter(Boolean), { scale: 1.25 }, { scale: 1, duration: 1.7, ease: 'expo.out', stagger: .07, delay, clearProps: 'transform' });
  };
  if (rcards.length && moreBtn) {
    const total = rcards.length, step = Math.min(T.roomsFirst || 5, total), moreLabel = $('.stays__morelabel', moreBtn);
    let shown = step;
    const syncMore = () => {
      const all = shown >= total;
      if (shownOut) shownOut.textContent = shown;
      moreLabel.textContent = all ? 'Show fewer rooms' : 'Show more rooms';
      moreBtn.classList.toggle('is-less', all);
      moreBtn.setAttribute('aria-expanded', String(shown > step));
      moreBtn.hidden = total <= step;
    };
    moreBtn.addEventListener('click', () => {
      if (shown >= total) {
        shown = step;
        rcards.forEach((li, i) => { li.hidden = i >= shown; });
        syncMore();
        if (hasGsap && window.ScrollTrigger) ScrollTrigger.refresh();
        rgal.scrollTo({ left: 0, behavior: 'auto' });
        $('#stay').scrollIntoView({ behavior: reduce ? 'auto' : 'smooth' });
        return;
      }
      const fresh = rcards.slice(shown, shown + step);
      shown += fresh.length;
      fresh.forEach(li => { li.hidden = false; });
      syncMore();
      if (hasGsap && window.ScrollTrigger) ScrollTrigger.refresh();
      rise(fresh);
      // on the swipeable row, slide along to the first new room
      if (rgal.scrollWidth > rgal.clientWidth + 2) {
        const pad = parseFloat(getComputedStyle(rgal).paddingLeft) || 0;
        rgal.scrollTo({ left: fresh[0].offsetLeft - pad, behavior: reduce ? 'auto' : 'smooth' });
      }
      // keyboard users land on the first new room
      const firstNew = $('.rcard__btn', fresh[0]);
      if (firstNew) firstNew.focus({ preventScroll: true });
    });
    syncMore();
  }

  // the backdrop is a blurred copy of the room being looked at (the first room until another is hovered)
  const ambs = $$('.stays__amb');
  if (rgal && ambs.length === 2) {
    let ambOn = -1, ambSrc = '', ambTimer = 0;
    const showAmb = img => {
      const src = img && (img.currentSrc || img.src);
      if (!src || src === ambSrc || !img.complete) return;
      ambSrc = src; ambOn = ambOn === 0 ? 1 : 0;
      ambs[ambOn].style.backgroundImage = `url("${src}")`;
      ambs.forEach((l, k) => l.classList.toggle('is-on', k === ambOn));
    };
    const firstImg = $('.rcard img', rgal);
    if (firstImg) {
      if (firstImg.complete && firstImg.currentSrc) showAmb(firstImg);
      else firstImg.addEventListener('load', () => showAmb(firstImg), { once: true });
    }
    const follow = e => {
      const card = e.target.closest('.rcard__btn');
      if (!card) return;
      clearTimeout(ambTimer);
      ambTimer = setTimeout(() => showAmb($('img', card)), 140); // wait for the pointer to settle
    };
    rgal.addEventListener('pointerover', follow);
    rgal.addEventListener('focusin', follow);
  }

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
      // the label reads the place under a point that sweeps across the visible cards as the wall moves,
      // so it says 13 km at the start, 51 km at the end, and the distance in between on the way
      const r = rail.getBoundingClientRect(), cs = getComputedStyle(rail);
      const from = r.left + parseFloat(cs.paddingLeft), to = r.right - parseFloat(cs.paddingRight);
      const readX = from + 30 + p * Math.max(0, to - from - 60);
      const at = places.reduce((best, pl) => {
        const b = pl.getBoundingClientRect(), d = readX < b.left ? b.left - readX : readX > b.right ? readX - b.right : 0;
        return d < best.d ? { d, pl } : best;
      }, { d: Infinity }).pl;
      if (kmLbl && at && kmLbl.textContent !== at.dataset.km + ' km') kmLbl.textContent = at.dataset.km + ' km';
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

    // auto drift: the wall travels towards the farther places, rests at the end, then travels back, and the
    // distance scale above follows it (it reads the scroll position). It pauses on hover and keyboard focus,
    // hands over to the visitor on touch, drag, wheel or the arrows (and carries on a few seconds later),
    // waits while a photo is open or the wall is off screen, and the Pause button stops it altogether.
    const autoBtn = $('.near__auto');
    if (reduce) { if (autoBtn) autoBtn.hidden = true; }
    else {
      const speed = () => desktop() ? 42 : 30; // pixels per second
      const st = { hover: false, focus: false, inView: false, stopped: false, seen: false };
      let dir = 1, pos = rail.scrollLeft, last = 0, raf = 0, restUntil = 0, holdUntil = 0;
      const running = () => !st.stopped && !st.hover && !st.focus && st.inView && lb.hidden && !document.hidden;
      const tick = now => {
        raf = 0;
        if (!running()) { last = 0; return; }
        raf = requestAnimationFrame(tick);
        const dt = last ? Math.min(now - last, 64) / 1000 : 0;
        last = now;
        const held = now < holdUntil;
        // scroll snapping would pull the wall back to a card edge on every step, so it is off while drifting
        rail.classList.toggle('is-auto', !held);
        if (held || now < restUntil) { pos = rail.scrollLeft; return; }
        const max = rail.scrollWidth - rail.clientWidth;
        if (max <= 2) return;
        pos = Math.min(max, Math.max(0, pos + dir * speed() * dt));
        rail.scrollLeft = pos;
        if ((dir > 0 && pos >= max - .5) || (dir < 0 && pos <= .5)) { dir = -dir; restUntil = now + 1800; }
      };
      const sync = () => {
        if (autoBtn) {
          autoBtn.classList.toggle('is-stopped', st.stopped);
          autoBtn.setAttribute('aria-label', st.stopped ? 'Play the moving photos' : 'Pause the moving photos');
        }
        if (st.stopped) rail.classList.remove('is-auto');
        if (running() && !raf) { pos = rail.scrollLeft; raf = requestAnimationFrame(tick); }
      };
      // the visitor takes over; the drift carries on from wherever they leave it
      const hold = ms => { holdUntil = performance.now() + ms; rail.classList.remove('is-auto'); pos = rail.scrollLeft; };
      rail.addEventListener('pointerdown', () => hold(Infinity));
      addEventListener('pointerup', () => { if (holdUntil === Infinity) hold(3000); });
      addEventListener('pointercancel', () => { if (holdUntil === Infinity) hold(3000); });
      rail.addEventListener('wheel', () => hold(2500), { passive: true });
      rail.addEventListener('keydown', () => hold(4000));
      $$('[data-rail]').forEach(b => b.addEventListener('click', () => hold(4000)));
      rail.addEventListener('pointerenter', e => { if (e.pointerType === 'mouse') { st.hover = true; sync(); } });
      rail.addEventListener('pointerleave', e => { if (e.pointerType === 'mouse') { st.hover = false; sync(); } });
      // only keyboard focus pauses it (a mouse click on a photo also focuses it, but the hover covers that)
      rail.addEventListener('focusin', e => { if (e.target.matches(':focus-visible')) { st.focus = true; sync(); } });
      rail.addEventListener('focusout', e => { if (!rail.contains(e.relatedTarget)) { st.focus = false; sync(); } });
      new IntersectionObserver(es => es.forEach(e => {
        st.inView = e.isIntersecting;
        if (st.inView && !st.seen) { st.seen = true; restUntil = performance.now() + 1200; } // a moment to take it in first
        sync();
      }), { threshold: .2 }).observe(rail);
      new MutationObserver(sync).observe(lb, { attributes: true, attributeFilter: ['hidden'] });
      document.addEventListener('visibilitychange', sync);
      if (autoBtn) autoBtn.addEventListener('click', () => { st.stopped = !st.stopped; sync(); });
      sync();
    }
  }

  /* ================= HIGHLIGHTS ================= */
  // Desktop: panels open on hover (after a short pause), click or keyboard focus, and the open one
  // advances by itself while the gallery is on screen. Phones and tablets: a swipe rail, where the
  // card at the start of the rail is the open one. The open photo goes full screen in the lightbox.
  const hl = $('[data-hl]');
  const hlApi = (() => {
    const items = hl ? $$('.hl-item', hl) : [];
    if (!items.length) return null;
    const track = $('.hl__track', hl), btns = $$('.hl-item__btn', hl);
    const live = $('[data-hl-live]', hl), playBtn = $('.hl__play', hl);
    const n = items.length;
    // "01 / 06" in the heading strip follows the open photo
    const two = k => String(k).padStart(2, '0');
    const count = $('.hl__count', hl), countNow = $('[data-hl-now]', hl), countAll = $('[data-hl-total]', hl);
    if (count && countNow && countAll) { countAll.textContent = two(n); count.hidden = false; }
    const wide = matchMedia('(min-width: 900px)');
    const list = (T.highlightItems || []).map(h => ({ key: h.image, title: h.title, desc: h.text, meta: 'Highlights' }));
    const st = { hover: false, focus: false, inView: false, stopped: false, ready: !hasGsap };
    let active = 0;

    const syncPlay = () => {
      hl.classList.toggle('is-auto', wide.matches && !reduce && !st.stopped);
      hl.classList.toggle('is-paused', st.hover || st.focus || !st.inView || !st.ready || !lb.hidden || document.hidden);
      if (playBtn) {
        playBtn.hidden = !wide.matches || reduce;
        playBtn.classList.toggle('is-stopped', st.stopped);
        playBtn.setAttribute('aria-label', st.stopped ? 'Play slideshow' : 'Pause slideshow');
      }
    };

    const padL = () => parseFloat(getComputedStyle(track).paddingLeft) || 0;
    const railTo = (i, smooth = !reduce) => track.scrollTo({ left: items[i].offsetLeft - padL(), behavior: smooth ? 'smooth' : 'auto' });

    const setActive = (i, { announce = false, scroll = true } = {}) => {
      active = (i + n) % n;
      items.forEach((li, k) => li.classList.toggle('is-active', k === active));
      hl.style.setProperty('--hl-i', active);
      if (countNow) countNow.textContent = two(active + 1);
      if (announce && live && list[active]) live.textContent = `${active + 1} of ${n}: ${list[active].title}`;
      if (scroll && !wide.matches) railTo(active);
    };

    // rail: how far each card is from the start of the rail (-1 … 1) drives its scale, shade and drift
    let raf = 0;
    const railSync = () => {
      raf = 0;
      if (wide.matches) return;
      const start = track.scrollLeft + padL();
      const step = items[0].offsetWidth + (parseFloat(getComputedStyle(track).columnGap) || 0);
      let best = active, bestD = Infinity;
      items.forEach((li, k) => {
        const d = li.offsetLeft - start;
        const p = Math.max(-1, Math.min(1, d / step));
        li.style.setProperty('--ps', p.toFixed(3));
        li.style.setProperty('--p', Math.abs(p).toFixed(3));
        if (Math.abs(d) < bestD) { bestD = Math.abs(d); best = k; }
      });
      if (best !== active) setActive(best, { scroll: false });
    };
    const queueSync = () => { if (!raf) raf = requestAnimationFrame(railSync); };
    track.addEventListener('scroll', queueSync, { passive: true });
    addEventListener('resize', queueSync);

    // "View" badge that trails a mouse pointer over the open panel
    let cursorMove = () => {}, cursorOff = () => {};
    if (matchMedia('(hover: hover) and (pointer: fine)').matches && !reduce) {
      const cur = document.createElement('div');
      cur.className = 'hl-cursor'; cur.setAttribute('aria-hidden', 'true'); cur.innerHTML = '<span>View</span>';
      body.appendChild(cur);
      let x = 0, y = 0, tx = 0, ty = 0, on = false, loop = 0;
      const tick = () => {
        x += (tx - x) * .2; y += (ty - y) * .2;
        cur.style.transform = `translate3d(${x.toFixed(1)}px, ${y.toFixed(1)}px, 0)`;
        loop = Math.abs(tx - x) + Math.abs(ty - y) > .2 ? requestAnimationFrame(tick) : 0;
      };
      const show = v => { on = v; cur.classList.toggle('is-on', v); hl.classList.toggle('is-cursor-on', v); };
      cursorMove = e => {
        if (e) { tx = e.clientX; ty = e.clientY; }
        const el = document.elementFromPoint(tx, ty);
        const li = el && el.closest('.hl-item');
        const v = wide.matches && lb.hidden && !!li && li.classList.contains('is-active');
        if (v && !on) { x = tx; y = ty; }
        show(v);
        if (!loop) loop = requestAnimationFrame(tick);
      };
      cursorOff = () => show(false);
      // the page scrolling moves the panels under a still pointer
      addEventListener('scroll', () => { if (on || st.hover) cursorMove(); }, { passive: true });
    }

    // a closed panel (or a card further along the rail) opens; the open one goes full screen
    btns.forEach((b, i) => b.addEventListener('click', () => {
      if (i !== active) { setActive(i, { announce: true }); cursorMove(); return; }
      cursorOff();
      openLb(list, i, btns, k => { if (k !== active) setActive(k); });
    }));
    $$('[data-hl-step]', hl).forEach(b => b.addEventListener('click', () => setActive(active + +b.dataset.hlStep, { announce: true })));
    if (playBtn) playBtn.addEventListener('click', () => { st.stopped = !st.stopped; syncPlay(); });
    // the progress line along the open panel is a CSS animation; when it completes, move on
    track.addEventListener('animationend', e => { if (e.animationName === 'hl-progress') setActive(active + 1); });

    // hover: wait for the pointer to rest on a panel, so sweeping across them doesn't open each one
    let hoverT = 0, hoverI = -1;
    track.addEventListener('pointerenter', e => { if (e.pointerType === 'mouse') { st.hover = true; syncPlay(); } });
    track.addEventListener('pointerleave', () => { st.hover = false; clearTimeout(hoverT); hoverI = -1; cursorOff(); syncPlay(); });
    track.addEventListener('pointermove', e => {
      if (e.pointerType !== 'mouse' || !wide.matches) return;
      cursorMove(e);
      if (!e.movementX && !e.movementY) return; // ignore moves caused by the panels resizing under the pointer
      const li = e.target.closest('.hl-item'), i = li ? +li.dataset.i : -1;
      if (i === hoverI) return;
      clearTimeout(hoverT); hoverI = i;
      if (i >= 0 && i !== active) hoverT = setTimeout(() => { hoverI = -1; setActive(i); cursorMove(); }, 140);
    });

    // keyboard: focusing a panel opens it; arrow keys move between panels
    track.addEventListener('focusin', e => {
      const li = e.target.closest('.hl-item');
      if (!li || !e.target.matches(':focus-visible')) return;
      st.focus = true; syncPlay();
      if (+li.dataset.i !== active) setActive(+li.dataset.i, { announce: true });
    });
    track.addEventListener('focusout', e => { if (!track.contains(e.relatedTarget)) { st.focus = false; syncPlay(); } });
    track.addEventListener('keydown', e => {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      const li = e.target.closest('.hl-item');
      if (!li) return;
      e.preventDefault();
      const k = (+li.dataset.i + (e.key === 'ArrowRight' ? 1 : -1) + n) % n;
      btns[k].focus({ preventScroll: true });
      setActive(k, { announce: true });
    });

    // only advance while the gallery is on screen, the tab is visible and the lightbox is closed
    new IntersectionObserver(([e]) => { st.inView = e.isIntersecting; syncPlay(); }, { threshold: .3 }).observe(track);
    document.addEventListener('visibilitychange', syncPlay);
    new MutationObserver(syncPlay).observe(lb, { attributes: true, attributeFilter: ['hidden'] });
    wide.addEventListener('change', () => {
      items.forEach(li => { li.style.removeProperty('--p'); li.style.removeProperty('--ps'); });
      clearTimeout(hoverT); cursorOff(); syncPlay();
      if (!wide.matches) { railTo(active, false); queueSync(); }
    });

    setActive(0, { scroll: false });
    railSync(); syncPlay();
    return { ready: () => { st.ready = true; syncPlay(); } };
  })();

  /* ================= PHONE CONTACT BAR (call / WhatsApp) =================
     Slides in once the hero has opened and stays on screen, except over the booking
     form and the footer (both have their own call and WhatsApp buttons). On short
     landscape phones it also waits until the hero has scrolled away, as there is no
     room for it beside the hero's buttons. */
  const mbar = $('.mbar');
  const vis = { hero: true, book: false, foot: false };
  const shortScreen = matchMedia('(max-height: 520px) and (orientation: landscape)');
  let barReady = false;
  const syncBar = () => mbar.classList.toggle('is-on',
    barReady && !(vis.hero && shortScreen.matches) && !vis.book && !vis.foot);
  setTimeout(() => { barReady = true; syncBar(); }, 1400);
  if (shortScreen.addEventListener) shortScreen.addEventListener('change', syncBar);
  new IntersectionObserver(es => es.forEach(e => {
    const k = e.target === hero ? 'hero' : e.target.id === 'book' ? 'book' : 'foot';
    vis[k] = e.isIntersecting; syncBar();
  }), { rootMargin: '0px 0px -10% 0px' }).observe(hero);
  const io2 = new IntersectionObserver(es => es.forEach(e => {
    vis[e.target.id === 'book' ? 'book' : 'foot'] = e.isIntersecting; syncBar();
  }));
  io2.observe($('#book')); io2.observe($('.foot'));

  /* ================= FOOTER: the time at the resort right now ================= */
  const localTime = $('[data-localtime]');
  if (localTime && window.Intl) {
    try {
      const fmt = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Colombo', hour: 'numeric', minute: '2-digit', hour12: true });
      const out = $('[data-localtime-value]', localTime);
      const tick = () => { out.textContent = fmt.format(new Date()); };
      tick();
      setInterval(tick, 20000);
      localTime.hidden = false;
    } catch (e) { /* time zone not supported: the line stays hidden */ }
  }

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

  /* word reveals: each word rises out of its own mask and sharpens as it lands,
     while the hairline in the same header draws across */
  $$('[data-words]').forEach(el => {
    const words = [];
    const texts = [];
    const walk = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    while (walk.nextNode()) texts.push(walk.currentNode);
    texts.forEach(t => {
      const frag = document.createDocumentFragment();
      t.textContent.split(/(\s+)/).forEach(part => {
        if (!part) return;
        if (!part.trim()) { frag.appendChild(document.createTextNode(part)); return; }
        const mask = document.createElement('span'), w = document.createElement('span');
        mask.className = 'w'; w.textContent = part;
        mask.appendChild(w); frag.appendChild(mask); words.push(w);
      });
      t.replaceWith(frag);
    });
    const head = el.closest('header'), rule = head && $('.hl__rule', head);
    const tl = gsap.timeline({ scrollTrigger: { trigger: el, start: 'top 90%', once: true } });
    tl.from(words, { yPercent: 115, rotate: 5, filter: 'blur(6px)', duration: 1.25, ease: 'expo.out', stagger: .07, clearProps: 'all' }, 0);
    if (rule) tl.from(rule, { scaleX: 0, duration: 1.7, ease: 'expo.inOut', clearProps: 'transform' }, 0);
  });

  /* fade-ups (batched) */
  ScrollTrigger.batch('[data-fade]', {
    start: 'top 90%', once: true,
    onEnter: els => gsap.fromTo(els, { autoAlpha: 0, y: 28 }, { autoAlpha: 1, y: 0, duration: 1.1, stagger: .08, overwrite: true })
  });
  gsap.set('[data-fade]', { autoAlpha: 0 });
  ScrollTrigger.addEventListener('refreshInit', () => {});

  /* clip-path image reveals */
  $$('[data-reveal="clip"]').forEach(el => {
    const pic = $('.pic', el);
    const tl = gsap.timeline({ scrollTrigger: { trigger: el, start: 'top 85%' } });
    tl.fromTo(el, { clipPath: 'inset(100% 0% 0% 0%)' }, { clipPath: 'inset(0% 0% 0% 0%)', duration: 1.5, ease: 'expo.inOut' }, 0);
    if (pic) tl.fromTo(pic, { scale: 1.28 }, { scale: 1, duration: 2, ease: 'expo.out' }, .1);
  });

  /* parallax inside frames */
  $$('[data-parallax]').forEach(el => {
    const amt = parseFloat(el.dataset.parallax) || .1;
    gsap.fromTo(el, { yPercent: -amt * 50 }, { yPercent: amt * 50, ease: 'none', scrollTrigger: { trigger: el.parentElement, start: 'top bottom', end: 'bottom top', scrub: true } });
  });

  /* highlights: the panels rise into place one after another, each photo settling from a zoom;
     the first caption and the slideshow wait until they have landed */
  if (hlApi) {
    const items = $$('.hl-item', hl), pics = items.map(li => $('.hl-item__img > .pic', li)).filter(Boolean);
    hl.classList.add('is-waiting');
    gsap.set(items, { clipPath: 'inset(100% 0% 0% 0%)' });
    ScrollTrigger.create({
      trigger: '.hl__track', start: 'top 82%', once: true,
      onEnter: () => gsap.timeline({ onComplete: () => { gsap.set(items, { clearProps: 'clipPath' }); hlApi.ready(); } })
        .to(items, { clipPath: 'inset(0% 0% 0% 0%)', duration: 1.5, ease: 'expo.inOut', stagger: .09 }, 0)
        .fromTo(pics, { scale: 1.35 }, { scale: 1, duration: 2.2, ease: 'expo.out', stagger: .09, clearProps: 'transform' }, .2)
        .add(() => hl.classList.remove('is-waiting'), .95)
    });
  }

  /* rooms: the first cards rise into place one after another as the gallery arrives */
  const firstCards = $$('.rcard:not([hidden])');
  if (firstCards.length) {
    gsap.set(firstCards, { opacity: 0, y: 46 });
    ScrollTrigger.create({ trigger: '.rgal', start: 'top 85%', once: true, onEnter: () => rise(firstCards) });
  }

  /* section transitions: the booking panel opens out to full width as it arrives */
  ['.book'].forEach(sel => {
    const el = $(sel);
    if (!el) return;
    gsap.fromTo(el, { clipPath: 'inset(0% 3.5% 0% 3.5% round 28px)' }, { clipPath: 'inset(0% 0% 0% 0% round 0px)', ease: 'none', scrollTrigger: { trigger: el, start: 'top bottom', end: 'top 25%', scrub: true } });
  });

  /* discover: the pine panel opens out as it arrives and the film thumbnail rises into place */
  const disc = $('.discover');
  const mm = gsap.matchMedia();
  if (disc) {
    gsap.fromTo($('.discover__pin', disc), { clipPath: 'inset(0% 3.5% 0% 3.5% round 28px)' }, { clipPath: 'inset(0% 0% 0% 0% round 0px)', ease: 'none', scrollTrigger: { trigger: disc, start: 'top bottom', end: 'top 25%', scrub: true } });
    const card = $('.dfilm', disc), cardPic = card && $('.dfilm__media .pic', card);
    if (card) {
      const reveal = gsap.timeline({ scrollTrigger: { trigger: card, start: 'top 88%', once: true } })
        .fromTo(card, { clipPath: 'inset(100% 0% 0% 0% round 6px)' }, { clipPath: 'inset(0% 0% 0% 0% round 6px)', duration: 1.4, ease: 'expo.inOut', clearProps: 'clipPath' }, 0);
      if (cardPic) reveal.fromTo(cardPic, { scale: 1.25 }, { scale: 1, duration: 1.8, ease: 'expo.out', clearProps: 'transform' }, .1);
    }

    /* desktop: the panel holds while the three chapters change in place, as before */
    mm.add('(min-width: 900px) and (min-height: 600px)', () => {
      disc.classList.add('is-pinned');
      const chaps = $$('.dchap', disc), bar = $('.discover__bar i', disc);
      // the frame is shorter than the screen, so it holds in the middle of it, for half a screen of scrolling
      const pinEl = $('.discover__pin', disc);
      ScrollTrigger.create({ trigger: pinEl, start: 'center center', end: '+=50%', pin: pinEl, anticipatePin: 1, invalidateOnRefresh: true });
      // starts just before the panel settles (so the first chapter is already up when it does)
      // and ends as the pin lets go: 12% of a screen before the pin, plus the pin's 50%
      const tl = gsap.timeline({ scrollTrigger: { trigger: pinEl, start: 'center 62%', end: '+=62%', scrub: .4, invalidateOnRefresh: true } });
      chaps.forEach((c, i) => {
        const at = i * 1.3;
        tl.fromTo(c, { autoAlpha: 0, y: 40 }, { autoAlpha: 1, y: 0, duration: .3, ease: 'power3.out' }, at);
        if (bar) tl.to(bar, { scaleX: (i + 1) / chaps.length, duration: .3, ease: 'power2.out' }, at);
        if (i < chaps.length - 1) tl.to(c, { autoAlpha: 0, y: -30, duration: .25, ease: 'power2.in' }, at + 1);
      });
      tl.to({}, { duration: .5 });
      return () => { disc.classList.remove('is-pinned'); };
    });
    /* phones and short screens: the chapters are stacked and rise in one by one */
    mm.add('(max-width: 899px), (max-height: 599px)', () => {
      $$('.dchap', disc).forEach(el => {
        gsap.from(el, { autoAlpha: 0, y: 40, duration: 1.1, ease: 'power3.out', scrollTrigger: { trigger: el, start: 'top 88%', once: true } });
      });
    });
  }

  /* settle */
  const refresh = () => ScrollTrigger.refresh();
  addEventListener('load', refresh);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(refresh);
})();
