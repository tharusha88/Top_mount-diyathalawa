/* Booking flow: how to book (a room, or per person) & dates → room (room bookings only) →
   extras & details → done, with the "booking pending" window (who to call, where to pay the advance). */
(function () {
  'use strict';
  const { $, $$, esc, BOOT, site, src, imgTag, money, fmtDate, nights: countNights, guestsLabel, stepper } = window.YD;
  window.YD.boot();

  const ROOMS = BOOT.rooms || [];
  const LABELS = BOOT.labels || { room: 'Select room', person: 'Per person' };
  const OPTIONS = BOOT.packages || []; // per-person options on sale
  const PRICES = BOOT.prices || { partyArea: 0, jblSpeaker: 0 };
  const PAY = BOOT.payment || {};
  const MAX = BOOT.groupMax || { adults: 50, children: 30 };
  // the resort adds a service charge to the final bill (Admin → Booking setup)
  const SERVICE = Number(BOOT.serviceCharge) || 0;
  if (SERVICE) {
    const note = $('[data-sum-service]');
    if (note) { note.textContent = `+ ${SERVICE}% service charge, added to your final bill at the resort`; note.hidden = false; }
  }
  const DAY_IMAGE = 'estate-aerial';
  const PERSON_IMAGE = 'dining-deck';
  const params = new URLSearchParams(location.search);
  const isDate = (s) => /^\d{4}-\d{2}-\d{2}$/.test(s || '');
  const plural = (n, one, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;
  const long = { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' };

  const state = {
    step: 1,
    // no room bookable on its own: start on "per person"
    kind: (params.get('kind') === 'per_person' || !ROOMS.length) && OPTIONS.length ? 'per_person' : 'room',
    pkg: OPTIONS.find((o) => String(o.id) === params.get('package')) || OPTIONS[0] || null,
    checkIn: isDate(params.get('checkIn')) ? params.get('checkIn') : null,
    checkOut: isDate(params.get('checkOut')) ? params.get('checkOut') : null,
    adults: Math.min(MAX.adults, Math.max(1, parseInt(params.get('adults'), 10) || 2)),
    children: Math.min(MAX.children, Math.max(0, parseInt(params.get('children'), 10) || 0)),
    room: ROOMS.find((r) => r.slug === params.get('room')) || null,
    partyArea: false,
    jblSpeaker: false,
    party: null, // party-area status for the chosen nights: { free, taken }
    results: null, // rooms for the dates (room bookings)
    coupon: null, // { code, label, type, value } once applied — off the room charge only
    booking: null,
  };
  const isPerson = () => state.kind === 'per_person';
  const isDay = () => isPerson() && !!state.pkg && state.pkg.kind === 'day';
  const datesReady = () => (isDay() ? !!state.checkIn : !!(state.checkIn && state.checkOut));
  const people = () => state.adults + state.children;
  if (isDay()) state.checkOut = null;
  if (state.checkIn && state.checkIn < BOOT.today) state.checkIn = state.checkOut = null;
  if (state.checkOut && state.checkOut <= state.checkIn) state.checkIn = state.checkOut = null;

  const alertBox = $('[data-alert]');
  const showAlert = (msg, info = false) => {
    if (!msg) { alertBox.hidden = true; return; }
    alertBox.textContent = msg; alertBox.hidden = false; alertBox.classList.toggle('is-info', info);
  };

  // ---------- URL sync (so refresh / sharing keeps the selection) ----------
  function syncUrl() {
    const p = new URLSearchParams();
    p.set('kind', state.kind);
    if (isPerson() && state.pkg) p.set('package', state.pkg.id);
    if (!isPerson() && state.room) p.set('room', state.room.slug);
    if (state.checkIn) p.set('checkIn', state.checkIn);
    if (state.checkOut && !isDay()) p.set('checkOut', state.checkOut);
    if (isPerson()) { p.set('adults', state.adults); if (state.children) p.set('children', state.children); }
    history.replaceState(null, '', `/book?${p}`);
  }

  // ---------- steps ----------
  function renderSteps() {
    let n = 0;
    $$('[data-steps] li').forEach((li) => {
      const k = Number(li.dataset.goto);
      li.hidden = k === 2 && isPerson(); // per-person groups don't choose rooms
      if (!li.hidden) $('span', li).textContent = ++n;
      li.classList.toggle('is-current', k === state.step);
      li.classList.toggle('is-done', k < state.step && state.step < 4);
    });
  }
  function go(step) {
    if (state.step === 4 && step !== 4) return; // booking already made
    if (step === 2 && isPerson()) step = state.step > 2 ? 1 : 3;
    if (step > 1 && !datesReady()) step = 1;
    if (step > 2 && !isPerson() && !state.room) step = 2;
    state.step = step;
    $$('[data-panel]').forEach((p) => { p.hidden = Number(p.dataset.panel) !== step; });
    renderSteps();
    if (step === 3) {
      renderExtras();
      setTimeout(() => $('#f-name').focus({ preventScroll: true }), 300);
    }
    const top = $('.bk').getBoundingClientRect().top + window.scrollY - 140;
    if (window.scrollY > top) window.scrollTo({ top, behavior: 'smooth' });
  }
  document.addEventListener('click', (e) => {
    const g = e.target.closest('[data-goto]');
    if (!g) return;
    const n = Number(g.dataset.goto);
    if (g.closest('[data-steps]') && !(n < state.step && state.step < 4)) return;
    showAlert('');
    go(n);
  });

  // ---------- price (mirrors Bookings::quote on the server) ----------
  function quote() {
    const lines = [];
    let units, per;
    if (isPerson()) {
      const o = state.pkg;
      if (!o || !datesReady()) return null;
      const n = isDay() ? 1 : countNights(state.checkIn, state.checkOut);
      const tail = isDay() ? '' : ` × ${plural(n, 'night')}`;
      if (state.adults) lines.push([o.name, `${money(o.priceAdult)} × ${plural(state.adults, 'adult')}${tail}`, o.priceAdult * state.adults * n, true]);
      if (state.children) lines.push([`${o.name} — children`, `${money(o.priceChild)} × ${plural(state.children, 'child', 'children')}${tail}`, o.priceChild * state.children * n, true]);
      [units, per] = isDay() ? [1, '1 day'] : [n, plural(n, 'night')];
    } else {
      const r = state.room;
      const n = state.checkIn && state.checkOut ? countNights(state.checkIn, state.checkOut) : 0;
      if (!r || !n) return null;
      lines.push([r.name, `${money(r.price)} × ${plural(n, 'night')}`, r.price * n, true]);
      [units, per] = [n, plural(n, 'night')];
    }
    if (state.partyArea && !isDay()) lines.push(['Party area', `${money(PRICES.partyArea)} × ${per}`, PRICES.partyArea * units, false]);
    if (state.jblSpeaker) lines.push(['JBL speaker', `${money(PRICES.jblSpeaker)} × ${per}`, PRICES.jblSpeaker * units, false]);
    // the room charge (the room or the per-person rate) — what a coupon comes off
    const stay = lines.reduce((sum, l) => sum + (l[3] ? l[2] : 0), 0);
    const c = state.coupon;
    const off = !c ? 0 : c.type === 'percent' ? Math.round((stay * Math.min(100, c.value)) / 100) : Math.min(c.value, stay);
    return { lines, stay, off, total: lines.reduce((sum, l) => sum + l[2], 0) - off };
  }

  // ---------- summary ----------
  const media = $('[data-sum-media]');
  const placeholder = media.innerHTML;
  function renderSummary() {
    const r = isPerson() ? null : state.room;
    $('[data-sum-eyebrow]').textContent = isDay() ? 'Your visit' : 'Your stay';
    $('[data-sum-room]').textContent = isPerson() ? (state.pkg ? state.pkg.name : LABELS.person) : r ? r.name : 'Choose a room';
    const img = isDay() ? DAY_IMAGE : isPerson() ? PERSON_IMAGE : r ? r.images[0] : '';
    if ((media.dataset.key || '') !== img) {
      media.dataset.key = img;
      media.innerHTML = img ? imgTag(img, { sizes: '360px', alt: r ? r.name : '', eager: true }) : placeholder;
    }
    $('[data-sum-pkg]').textContent = isPerson() ? `${LABELS.person}${state.pkg ? ` · ${state.pkg.name}` : ''}` : LABELS.room;
    $('[data-sum-in-label]').textContent = isDay() ? 'Date' : 'Check-in';
    $('[data-sum-in]').textContent = state.checkIn ? fmtDate(state.checkIn, long) : '—';
    $('[data-sum-out-row]').hidden = isDay();
    $('[data-sum-out]').textContent = state.checkOut ? fmtDate(state.checkOut, long) : '—';
    $('[data-sum-guests]').textContent = isPerson() ? guestsLabel(state.adults, state.children) : r ? `Sleeps ${r.capacity}` : '—';
    const extras = [!isDay() && state.partyArea && 'Party area', state.jblSpeaker && 'JBL speaker'].filter(Boolean);
    $('[data-sum-extras-row]').hidden = !extras.length;
    $('[data-sum-extras]').textContent = extras.join(', ');
    const q = quote();
    $('[data-sum-price]').hidden = !q;
    if (q) {
      $('[data-sum-lines]').innerHTML = q.lines.map(([label, detail, amount]) => `<div class="sum__line"><span>${esc(label)}<small>${esc(detail)}</small></span><span>${money(amount)}</span></div>`).join('')
        + (q.off ? `<div class="sum__line sum__line--off"><span>Coupon ${esc(state.coupon.code)}<small>${esc(state.coupon.label)}</small></span><span>− ${money(q.off)}</span></div>` : '');
      $('[data-sum-total]').textContent = money(q.total);
    }
    const meals = isPerson() && state.pkg ? [state.pkg.description, isDay() ? '' : 'Rooms prepared for your group'].filter(Boolean).join(' · ') : '';
    $('[data-sum-meals]').hidden = !meals;
    $('[data-sum-meals] span').textContent = meals;
    $('[data-sum-times]').hidden = isDay();
  }

  // ---------- step 1: how to book ----------
  const kindsBox = $('[data-kinds]');
  const optsBox = $('[data-packages]');
  const minOf = (list) => (list.length ? Math.min(...list) : 0);
  function renderKinds() {
    const caps = ROOMS.map((r) => r.capacity);
    const roomFrom = minOf(ROOMS.map((r) => r.price));
    const personFrom = minOf(OPTIONS.map((o) => o.priceAdult));
    const card = (k, title, sub, price, off) => `<label class="kind${off ? ' is-off' : ''}">
      <input type="radio" name="kind" value="${k}" ${state.kind === k ? 'checked' : ''} ${off ? 'disabled' : ''}>
      <span class="kind__name">${esc(title)}</span>
      <span class="kind__sub">${esc(sub)}</span>
      <span class="kind__price">${price}</span>
    </label>`;
    kindsBox.innerHTML = card('room', LABELS.room, `Choose your own room. Priced per room, per night${caps.length ? ` · sleeps ${Math.min(...caps)}–${Math.max(...caps)}` : ''}.`,
      `<small>from</small> ${money(roomFrom)}<span class="kind__unit">per room, per night</span>`, !ROOMS.length)
      + card('per_person', LABELS.person, `${OPTIONS.map((o) => o.name).join(', ') || 'Per-person options'}. Priced per guest; we prepare rooms for your group.`,
        OPTIONS.length ? `<small>from</small> ${money(personFrom)}<span class="kind__unit">per adult</span>` : 'Not available', !OPTIONS.length);
  }
  function renderOptions() {
    optsBox.hidden = !isPerson();
    if (!isPerson()) return;
    optsBox.innerHTML = OPTIONS.map((o) => `<label class="pkg${o.kind === 'day' ? ' pkg--day' : ''}">
      <input type="radio" name="option" value="${o.id}" ${state.pkg && state.pkg.id === o.id ? 'checked' : ''}>
      <span class="pkg__name">${esc(o.name)}</span>
      <span class="pkg__sub">${esc(o.description || (o.kind === 'day' ? 'A day visit' : ''))}</span>
      <span class="pkg__price">${money(o.priceAdult)}<span class="pkg__unit">per adult${o.kind === 'day' ? '' : ', per night'}</span>${o.priceChild !== o.priceAdult ? `<span class="pkg__unit">${money(o.priceChild)} per child</span>` : ''}</span>
    </label>`).join('');
  }
  kindsBox.addEventListener('change', (e) => { if (e.target.name === 'kind') setKind(e.target.value); });
  optsBox.addEventListener('change', (e) => { if (e.target.name === 'option') setOption(OPTIONS.find((o) => String(o.id) === e.target.value)); });

  function afterChoiceChange(wasDay) {
    state.results = null; state.party = null; state.partyArea = false;
    if (isDay() !== wasDay) {
      state.checkOut = null; // a day visit has none; a stay needs a fresh check-out
      cal.setSingle(isDay());
      cal.setRange(state.checkIn, null);
    }
    $('[data-guests]').hidden = !isPerson();
    renderOptions(); loadCalendar(); updateStep1(); renderSummary(); syncUrl();
  }
  function setKind(kind) {
    const wasDay = isDay();
    state.kind = kind;
    if (kind === 'per_person' && !state.pkg) state.pkg = OPTIONS[0] || null;
    showAlert('');
    afterChoiceChange(wasDay);
  }
  function setOption(o) {
    const wasDay = isDay();
    state.pkg = o || null;
    afterChoiceChange(wasDay);
  }

  // ---------- step 1: calendar + guests ----------
  const toRooms = $('[data-to-rooms]');
  const roomNote = $('[data-room-note]');
  const calEl = $('[data-cal]');
  const monthsFor = () => (calEl.clientWidth >= 650 ? 2 : 1);
  const cal = new window.RangeCalendar(calEl, {
    months: monthsFor(),
    minDate: BOOT.today,
    single: isDay(),
    checkIn: state.checkIn,
    checkOut: state.checkOut,
    onChange: ({ checkIn, checkOut }) => {
      state.checkIn = checkIn; state.checkOut = isDay() ? null : checkOut;
      state.results = null; state.party = null;
      updateStep1(); renderSummary(); syncUrl();
    },
  });
  new ResizeObserver(() => cal.setMonths(monthsFor())).observe(calEl);

  function updateStep1() {
    toRooms.disabled = !datesReady();
    $('[data-to-rooms-label]').textContent = !isPerson() ? 'See available rooms' : isDay() ? 'Continue' : 'Check availability';
    $('[data-when-title]').textContent = isDay() ? 'Which day are you coming?' : 'When are you coming?';
  }

  let calToken = 0;
  let calTimer = null;
  function loadCalendar() {
    const token = ++calToken;
    clearTimeout(calTimer);
    const to = window.RangeCalendar.addDays(BOOT.today, 540);
    if (isDay()) {
      // any number of groups can visit on a day — only days the resort is closed are crossed out
      cal.setUnavailable(new Set());
      roomNote.textContent = 'Pick the day of your visit. Crossed-out days are closed.';
      fetch(`/api/calendar?from=${BOOT.today}&to=${to}&kind=day_out`).then((r) => r.json())
        .then((d) => { if (token === calToken) cal.setUnavailable(new Set(d.unavailable || [])); }).catch(() => {});
      return;
    }
    let q = `kind=${state.kind}`;
    if (isPerson()) q += `&guests=${people()}`;
    else if (state.room) q += `&room=${encodeURIComponent(state.room.slug)}`;
    calTimer = setTimeout(() => {
      fetch(`/api/calendar?from=${BOOT.today}&to=${to}&${q}`).then((r) => r.json())
        .then((d) => { if (token === calToken) cal.setUnavailable(new Set(d.unavailable || [])); }).catch(() => {});
    }, isPerson() ? 250 : 0);
    if (isPerson()) {
      roomNote.textContent = `Pick your arrival and departure dates. Crossed-out nights don't have space for ${plural(people(), 'guest')}.`;
    } else if (state.room) {
      roomNote.innerHTML = `Showing availability for <b>${esc(state.room.name)}</b> (sleeps ${state.room.capacity}) — crossed-out nights are already booked. <button type="button" class="linkish" data-any-room>Show all rooms</button>`;
    } else {
      roomNote.textContent = 'Pick your arrival and departure dates. Crossed-out nights are fully booked.';
    }
  }
  roomNote.addEventListener('click', (e) => {
    if (e.target.closest('[data-any-room]')) { state.room = null; loadCalendar(); renderSummary(); syncUrl(); }
  });
  $('[data-clear-dates]').addEventListener('click', () => cal.clear());

  // start the steppers on the URL's values (creating a stepper reports its own default)
  [['adults', state.adults, MAX.adults], ['children', state.children, MAX.children]].forEach(([k, v, max]) => {
    const el = $(`[data-stepper="${k}"]`);
    el.dataset.max = max;
    $('output', el).textContent = v;
  });
  const onGuests = (k) => (v) => { state[k] = v; state.results = null; renderSummary(); syncUrl(); if (isPerson()) loadCalendar(); };
  stepper($('[data-stepper="adults"]'), onGuests('adults'));
  stepper($('[data-stepper="children"]'), onGuests('children'));

  toRooms.addEventListener('click', () => (isPerson() ? checkGroup() : findRooms()));

  // ---------- per person: is there space for the group? ----------
  async function checkGroup() {
    if (!datesReady() || !state.pkg) return;
    showAlert('');
    toRooms.classList.add('is-busy');
    try {
      const q = new URLSearchParams({ kind: isDay() ? 'day_out' : 'per_person', packageId: state.pkg.id, checkIn: state.checkIn, adults: state.adults, children: state.children });
      if (!isDay()) q.set('checkOut', state.checkOut);
      const res = await fetch(`/api/availability?${q}`);
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Could not check availability.');
      if (!data.available) {
        showAlert(`Sorry — we don't have space for ${plural(people(), 'guest')} on some of those nights. Try other dates or a smaller group, or contact us — we may be able to help.`, true);
        return;
      }
      state.party = data.partyArea || null;
      go(3);
    } catch (err) {
      showAlert(err.message);
    } finally {
      toRooms.classList.remove('is-busy');
    }
  }

  // ---------- step 2: rooms ----------
  async function fetchRooms() {
    const q = new URLSearchParams({ kind: 'room', checkIn: state.checkIn, checkOut: state.checkOut });
    const res = await fetch(`/api/availability?${q}`);
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Could not check availability.');
    state.results = data.rooms;
    state.party = data.partyArea || null;
    if (state.party && !state.party.free) state.partyArea = false;
  }

  async function findRooms({ autoAdvance = true } = {}) {
    if (!datesReady() || isPerson()) return;
    showAlert('');
    toRooms.classList.add('is-busy');
    try {
      await fetchRooms();
      renderRooms();
      const pre = state.room && state.results.find((r) => r.slug === state.room.slug);
      if (autoAdvance && pre && pre.available) { go(3); return; }
      if (pre && !pre.available) {
        showAlert(`${pre.name} is booked on some of those nights — but these rooms are free.`, true);
        state.room = null; renderSummary(); syncUrl();
      }
      go(2);
    } catch (err) {
      showAlert(err.message);
      go(1);
    } finally {
      toRooms.classList.remove('is-busy');
    }
  }

  const partyDays = () => (state.party && !state.party.free ? state.party.taken.map((d) => fmtDate(d, { weekday: 'short', day: 'numeric', month: 'short' })).join(', ') : '');
  function renderPartyWarning() {
    const days = partyDays();
    $$('[data-party-warning]').forEach((el) => {
      el.hidden = !days;
      el.innerHTML = days ? `<b>A party is booked on ${esc(days)}.</b> Another group has reserved the party area for that evening, so there may be music at the resort. To have it yourselves — or to be sure of a quiet evening — choose dates when it is free and reserve it first: whoever reserves it first has it.` : '';
    });
  }

  function renderRooms() {
    const n = countNights(state.checkIn, state.checkOut);
    const list = state.results.slice().sort((a, b) => Number(b.available) - Number(a.available));
    const free = list.filter((r) => r.available).length;
    $('[data-rooms-sub]').textContent = `${fmtDate(state.checkIn)} – ${fmtDate(state.checkOut)} · ${plural(n, 'night')} · ${free ? `${free} of ${plural(list.length, 'room')} available` : 'nothing free for these dates'}`;
    if (!free) showAlert('Every room is taken for those dates. Try different dates, or book per person — or contact us, we may be able to help.', true);
    renderPartyWarning();
    $('[data-room-list]').innerHTML = list.map((r) => {
      const status = r.available ? '<span class="pill pill--ok">Available</span>' : `<span class="pill pill--no">${r.free ? 'Fully booked' : 'Booked'}</span>`;
      return `<article class="bk-room${r.available ? '' : ' is-off'}${state.room && state.room.slug === r.slug ? ' is-selected' : ''}">
        <div class="bk-room__img">${imgTag(r.images[0], { sizes: '190px', alt: r.name })}</div>
        <div>
          <span class="bk-room__type">${esc(r.type)}</span>
          <h3>${esc(r.name)}</h3>
          <p>${esc(r.tagline)}</p>
          <ul class="room__specs">
            <li><svg width="16" height="16"><use href="#i-users"/></svg>Sleeps ${r.capacity}</li>
            ${r.beds ? `<li><svg width="16" height="16"><use href="#i-bed"/></svg>${esc(r.beds)}</li>` : ''}
          </ul>
        </div>
        <div class="bk-room__side">
          ${status}
          <div class="price"><b>${money(r.total)}</b><small>${money(r.price)} × ${plural(n, 'night')}</small></div>
          ${r.available ? `<button class="btn btn--sm btn--amber" type="button" data-pick="${esc(r.slug)}">Select</button>` : ''}
        </div>
      </article>`;
    }).join('');
  }
  $('[data-room-list]').addEventListener('click', (e) => {
    const b = e.target.closest('[data-pick]');
    if (!b) return;
    state.room = ROOMS.find((r) => r.slug === b.dataset.pick);
    showAlert('');
    renderSummary(); syncUrl();
    go(3);
  });

  // ---------- step 3: extras + guest details ----------
  const form = $('[data-form]');
  function renderExtras() {
    const day = isDay();
    const taken = !day && state.party && !state.party.free;
    if (taken) state.partyArea = false;
    const party = form.elements.partyArea;
    // an extra without a price isn't offered (Admin → Booking setup → Extras)
    const offerParty = !day && PRICES.partyArea > 0, offerJbl = PRICES.jblSpeaker > 0;
    if (!offerParty) state.partyArea = false;
    if (!offerJbl) state.jblSpeaker = false;
    $('[data-extra="partyArea"]').hidden = !offerParty;
    $('[data-extra="jblSpeaker"]').hidden = !offerJbl;
    $('.bk-extras').hidden = !offerParty && !offerJbl;
    $('[data-extra="partyArea"]').classList.toggle('is-off', !!taken);
    party.disabled = !!taken;
    party.checked = !day && state.partyArea;
    $('[data-party-note]').textContent = taken
      ? `Already reserved by another group on ${partyDays()}.`
      : 'Optional — but if you don\'t reserve it, another guest can, and then it won\'t be available to you. If nobody reserves it, anyone can use it.';
    $('[data-party-price]').textContent = `+ ${money(PRICES.partyArea)} / night`;
    form.elements.jblSpeaker.checked = state.jblSpeaker;
    $('[data-jbl-price]').textContent = `+ ${money(PRICES.jblSpeaker)} / ${day ? 'day' : 'night'}`;
    $('[data-details-sub]').textContent = isPerson()
      ? `So we can prepare ${day ? 'for your visit' : 'rooms for your group'} and confirm your booking.`
      : 'So we can prepare your room and confirm your booking.';
    renderPartyWarning();
    renderSummary();
  }
  form.addEventListener('change', (e) => {
    if (e.target.name === 'partyArea') { state.partyArea = e.target.checked; renderSummary(); }
    if (e.target.name === 'jblSpeaker') { state.jblSpeaker = e.target.checked; renderSummary(); }
  });

  const req = $('#f-req');
  $('[data-req-chips]').addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    const label = b.textContent.trim();
    const parts = req.value.split(/\s*,\s*|\n/).map((s) => s.trim()).filter(Boolean);
    const has = parts.includes(label);
    req.value = (has ? parts.filter((p) => p !== label) : parts.concat(label)).join(', ');
    b.classList.toggle('is-on', !has);
  });

  function fieldError(input, msg) {
    const wrap = input.closest('.field, .check');
    wrap.classList.toggle('has-error', !!msg);
    let el = wrap.querySelector('.field__err');
    if (msg && !el && wrap.classList.contains('field')) { el = document.createElement('span'); el.className = 'field__err'; wrap.appendChild(el); }
    if (el) el.textContent = msg || '';
  }
  function validate() {
    const f = form.elements;
    let first = null;
    const check = (input, ok, msg) => { fieldError(input, ok ? '' : msg); if (!ok && !first) first = input; };
    check(f.guestName, f.guestName.value.trim().length >= 2, 'Please tell us your name.');
    const idNo = f.guestIdNo.value.trim().toUpperCase();
    check(f.guestIdNo, /^[A-Z0-9][A-Z0-9 \-/]{3,39}$/.test(idNo) && (idNo.match(/[A-Z0-9]/g) || []).length >= 5, 'Please enter your NIC or passport number.');
    check(f.guestEmail, /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(f.guestEmail.value.trim()), 'Please enter a valid email.');
    check(f.guestPhone, f.guestPhone.value.replace(/\D/g, '').length >= 7, 'Please enter a phone number we can reach you on.');
    check(f.agree, f.agree.checked, '');
    if (first) first.focus();
    return !first;
  }
  form.addEventListener('input', (e) => { if (e.target.closest('.has-error')) fieldError(e.target, ''); });

  // ---------- coupon: off the room charge (not extras), checked by the server again on booking ----------
  const couponMsg = $('[data-coupon-msg]');
  async function applyCoupon() {
    const input = form.elements.couponCode;
    const code = input.value.trim().toUpperCase().replace(/\s+/g, '');
    state.coupon = null;
    couponMsg.className = 'bk-coupon__msg';
    if (!code) { couponMsg.textContent = ''; renderSummary(); return true; }
    couponMsg.textContent = 'Checking…';
    const p = new URLSearchParams({ code, kind: isDay() ? 'day_out' : state.kind, checkIn: state.checkIn || '' });
    if (!isDay() && state.checkOut) p.set('checkOut', state.checkOut);
    if (isPerson()) { p.set('packageId', state.pkg ? state.pkg.id : ''); p.set('adults', state.adults); p.set('children', state.children); } else if (state.room) p.set('room', state.room.slug);
    try {
      const res = await fetch(`/api/coupon?${p}`);
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || 'That coupon could not be checked.');
      state.coupon = data.coupon;
      input.value = data.coupon.code;
      couponMsg.textContent = `✓ ${data.coupon.label}`;
      couponMsg.classList.add('is-ok');
      renderSummary();
      return true;
    } catch (err) {
      couponMsg.textContent = err.message;
      couponMsg.classList.add('is-bad');
      renderSummary();
      return false;
    }
  }
  $('[data-coupon-apply]').addEventListener('click', applyCoupon);
  form.elements.couponCode.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); applyCoupon(); } });
  form.elements.couponCode.addEventListener('input', () => {
    if (state.coupon) { state.coupon = null; renderSummary(); }
    couponMsg.textContent = '';
    couponMsg.className = 'bk-coupon__msg';
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!validate()) return;
    // a code typed but not applied yet: check it first
    if (form.elements.couponCode.value.trim() && !state.coupon && !(await applyCoupon())) { form.elements.couponCode.focus(); return; }
    const btn = $('[data-submit]');
    btn.disabled = true; btn.classList.add('is-busy');
    showAlert('');
    const f = form.elements;
    const body = {
      kind: isDay() ? 'day_out' : state.kind,
      packageId: isPerson() && state.pkg ? state.pkg.id : undefined,
      room: isPerson() ? undefined : state.room.slug,
      checkIn: state.checkIn, checkOut: isDay() ? undefined : state.checkOut,
      adults: isPerson() ? state.adults : undefined, children: isPerson() ? state.children : undefined,
      partyArea: !isDay() && state.partyArea, jblSpeaker: state.jblSpeaker,
      guestName: f.guestName.value, guestIdNo: f.guestIdNo.value, guestEmail: f.guestEmail.value, guestPhone: f.guestPhone.value,
      country: f.country.value, arrivalTime: f.arrivalTime.value, specialRequests: f.specialRequests.value,
      couponCode: state.coupon ? state.coupon.code : '',
      agree: f.agree.checked, website: f.website.value,
    };
    try {
      const res = await fetch('/api/bookings', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        if (res.status === 409 && data.code === 'party_area_unavailable') {
          showAlert(data.error); state.partyArea = false;
          if (state.party) state.party.free = false;
          renderExtras();
          return;
        }
        if (data.code === 'coupon') {
          // the coupon went (used up or switched off) — book without it, or try another
          state.coupon = null;
          couponMsg.textContent = data.error;
          couponMsg.className = 'bk-coupon__msg is-bad';
          renderSummary();
          showAlert(data.error);
          return;
        }
        if (res.status === 409 && !isPerson()) { showAlert(data.error); state.room = null; renderSummary(); await findRooms({ autoAdvance: false }); return; }
        if (res.status === 409) { showAlert(data.error); go(1); return; }
        throw new Error(data.error || 'Something went wrong — please try again.');
      }
      state.booking = data.booking;
      done();
    } catch (err) {
      showAlert(err.message);
    } finally {
      btn.disabled = false; btn.classList.remove('is-busy');
    }
  });

  // ---------- step 4: done, and the "booking pending" window ----------
  const tel = (s) => String(s || '').replace(/[^\d+]/g, '');
  function payHtml(b) {
    const hasBank = !!PAY.accountNo;
    const rows = [
      b.advanceDue ? ['Advance', `<b>${money(b.advanceDue)}</b> <small>(${PAY.advancePct}% of ${money(b.totalAmount)})</small>`] : null,
      hasBank && PAY.bank ? ['Bank', esc(PAY.bank)] : null,
      hasBank && PAY.accountName ? ['Account name', esc(PAY.accountName)] : null,
      hasBank ? ['Account number', `<b class="bk-pay__no">${esc(PAY.accountNo)}</b> <button type="button" class="linkish" data-copy="${esc(PAY.accountNo)}">Copy</button>`] : null,
      hasBank && PAY.branch ? ['Branch', esc(PAY.branch)] : null,
      ['Payment reference', `<b>${esc(b.ref)}</b>`],
    ].filter(Boolean);
    const wa = String(PAY.whatsapp || site.whatsapp || '').replace(/\D/g, '');
    return `<p>${hasBank ? 'To confirm it, please pay the advance to the account below and call us on' : 'To confirm it, please call us on'} <a href="tel:${esc(tel(PAY.phone))}"><b>${esc(PAY.phone || site.phone || '')}</b></a>${hasBank ? '' : ' to arrange the advance payment'}. We confirm your booking as soon as the advance is received.</p>
      <dl class="bk-paylist">${rows.map(([k, v]) => `<div><dt>${k}</dt><dd>${v}</dd></div>`).join('')}</dl>
      ${PAY.note ? `<p class="bk-pay__note">${esc(PAY.note)}</p>` : ''}
      <div class="bk-pay__actions">
        <a class="btn btn--sm" href="tel:${esc(tel(PAY.phone))}">Call ${esc(PAY.phone || '')}</a>
        ${wa ? `<a class="btn btn--sm btn--ghost" href="https://wa.me/${wa}?text=${encodeURIComponent(`Hello Top Mountain Resort! My booking reference is ${b.ref}.`)}" target="_blank" rel="noopener">WhatsApp us</a>` : ''}
      </div>`;
  }
  const modal = $('[data-pay-modal]');
  const closeModal = () => { modal.hidden = true; };
  modal.addEventListener('click', (e) => { if (e.target.closest('[data-pay-close]')) closeModal(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
  document.addEventListener('click', (e) => {
    const c = e.target.closest('[data-copy]');
    if (!c) return;
    navigator.clipboard?.writeText(c.dataset.copy).then(() => { c.textContent = 'Copied'; setTimeout(() => { c.textContent = 'Copy'; }, 1500); }).catch(() => {});
  });

  function done() {
    const b = state.booking;
    const day = b.package === 'day_out';
    go(4);
    $('[data-done-title]').textContent = `Thank you, ${b.guestName.split(' ')[0]}!`;
    const range = `from <b>${fmtDate(b.checkIn, { day: 'numeric', month: 'long' })}</b> to <b>${fmtDate(b.checkOut, { day: 'numeric', month: 'long', year: 'numeric' })}</b>`;
    $('[data-done-text]').innerHTML = day
      ? `Your request for a <b>${esc(b.packageLabel.toLowerCase())}</b> on <b>${fmtDate(b.checkIn, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</b> for ${plural(b.adults + b.children, 'guest')} is with us, and a copy is on its way to ${esc(b.guestEmail)}.`
      : b.kind === 'room'
        ? `Your request for <b>${esc(b.roomName)}</b> ${range} is with us, and a copy is on its way to ${esc(b.guestEmail)}.`
        : `Your request for <b>${esc(b.packageLabel.toLowerCase())}</b> for ${plural(b.adults + b.children, 'guest')} ${range} is with us, and a copy is on its way to ${esc(b.guestEmail)}.`;
    $('[data-done-ref]').textContent = b.ref;
    const inv = $('[data-done-invoice]');
    if (inv && b.invoiceUrl) { inv.href = b.invoiceUrl; inv.hidden = false; }
    $('[data-done-pay]').innerHTML = payHtml(b);
    $('[data-pay-body]').innerHTML = payHtml(b);
    modal.hidden = false;
    setTimeout(() => { const btn = $('.bk-modal__card .btn', modal); if (btn) btn.focus(); }, 50);
    const what = day ? `a ${b.packageLabel.toLowerCase()} on ${b.checkIn}` : b.kind === 'room' ? `${b.roomName} (${b.checkIn} to ${b.checkOut})` : `${b.packageLabel.toLowerCase()} for ${b.adults + b.children} (${b.checkIn} to ${b.checkOut})`;
    $('[data-done-wa]').href = `https://wa.me/${String(site.whatsapp || '').replace(/\D/g, '')}?text=${encodeURIComponent(`Hello Top Mountain Resort! I just booked ${what}. My booking reference is ${b.ref}.`)}`;
    history.replaceState(null, '', '/book?done=1');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  $('[data-ics]').addEventListener('click', () => {
    const b = state.booking;
    if (!b) return;
    const day = b.package === 'day_out';
    const d = (s) => s.replace(/-/g, '');
    const ics = [
      'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Top Mountain Resort//Booking//EN', 'BEGIN:VEVENT',
      `UID:${b.ref}@topmountain`, `DTSTAMP:${new Date().toISOString().replace(/[-:]/g, '').slice(0, 15)}Z`,
      `DTSTART;VALUE=DATE:${d(b.checkIn)}`, `DTEND;VALUE=DATE:${d(b.checkOut)}`,
      day ? `SUMMARY:${b.packageLabel} at ${site.name}` : `SUMMARY:Stay at ${site.name}${b.roomName ? ` — ${b.roomName}` : ''}`,
      day ? `DESCRIPTION:Booking reference ${b.ref}.` : `DESCRIPTION:Booking reference ${b.ref}. Check-in from ${site.checkIn}\\, check-out by ${site.checkOut}.`,
      'END:VEVENT', 'END:VCALENDAR',
    ].join('\r\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([ics], { type: 'text/calendar' }));
    a.download = `topmountain-${b.ref}.ics`;
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 2000);
  });

  // ---------- start ----------
  const heroKey = $('.bk-hero').dataset.bg;
  if (heroKey) $('.bk-hero').style.backgroundImage = `url(${src(heroKey, 1800)})`;
  $('[data-guests]').hidden = !isPerson();
  renderKinds();
  renderOptions();
  loadCalendar();
  updateStep1();
  renderSummary();
  if (datesReady() && !isPerson()) findRooms();
  else go(1);
})();
