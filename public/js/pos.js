/* Top Mountain Resort POS: check guests in, tap items onto their bill (every tap adds one), take payments,
   move items between guests, and check out on one bill with extra lines and a discount.
   It uses the admin API, so staff sign in with an admin account. */
(function () {
  'use strict';
  const BOOT = window.BOOT || {};
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icon = (id, size = 17) => `<svg width="${size}" height="${size}" aria-hidden="true"><use href="#${id}"/></svg>`;
  const CUR = (BOOT.site && BOOT.site.currency) || 'LKR';
  const money = (n) => `${CUR} ${Number(n || 0).toLocaleString('en-US')}`;
  const toUTC = (s) => { const [y, m, d] = s.split('-').map(Number); return Date.UTC(y, m - 1, d); };
  const fd = (s, o = { day: 'numeric', month: 'short' }) => (s ? new Date(toUTC(s)).toLocaleDateString('en-GB', { timeZone: 'UTC', ...o }) : '—');
  const fdt = (iso) => new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  const plural = (n, one, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;
  const PALETTE = ['#d98a3a', '#3f8fa0', '#8a6bb8', '#4d7a42', '#c0564b', '#b08d2a', '#5b7bb5', '#7d8a80'];
  const long = { weekday: 'short', day: 'numeric', month: 'short' };

  let ME = null;     // who is signed in
  /** What this login may use (Auth::ROLES on the server, which checks every request too). */
  const can = (perm) => !!(ME && Array.isArray(ME.perms) && ME.perms.includes(perm));
  let BOARD = null;  // GET /pos: open room bills (folios), the menu, payment methods
  let ARRIVALS = []; // bookings to check in
  let CATS = [];     // category tabs [{id, name, color}]
  let FOLIO = null;  // the selected guest's room bill, as last saved
  let SEL = 0;       // the selected guest (booking id)
  let cat = '';      // the category tab on show
  let itemQ = '';
  let guestQ = '';
  let LINES = [];    // the bill lines on screen

  // ---------- helpers ----------
  function toast(msg, bad = false) {
    const t = document.createElement('div');
    t.className = `toast${bad ? ' toast--bad' : ''}`;
    t.textContent = msg;
    $('[data-toasts]').appendChild(t);
    setTimeout(() => t.remove(), bad ? 6000 : 3000);
  }

  async function api(path, { method = 'GET', body } = {}) {
    const opts = { method, credentials: 'same-origin', headers: {} };
    if (body !== undefined) { opts.body = JSON.stringify(body); opts.headers['Content-Type'] = 'application/json'; }
    const res = await fetch(`/api/admin${path}`, opts);
    if (res.status === 401 && path !== '/login') { showLogin(); throw new Error('Please sign in again.'); }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
    return data;
  }

  const whenText = (b) => (b.kind === 'day_out' ? `Day visit · ${fd(b.checkIn, long)}` : b.kind === 'event' ? `${b.packageLabel} · ${fd(b.checkIn, long)}${b.arrivalTime ? ` · ${b.arrivalTime}` : ''}`
    : `${fd(b.checkIn)} → ${fd(b.checkOut, long)} · ${plural(b.nights, 'night')}`);
  const guestsText = (b) => (b.adults || b.children ? `${plural(b.adults, 'adult')}${b.children ? `, ${plural(b.children, 'child', 'children')}` : ''}` : `sleeps ${b.capacity}`);
  /** "Room 101", "Full board · Room 101, Room 102" or "Day out" */
  const placeOf = (b) => b.place || b.roomName || b.packageLabel;
  const extrasOf = (b) => [b.partyArea && 'Party area', b.jblSpeaker && 'JBL speaker'].filter(Boolean);
  const billNo = (id) => `INV-${String(id).padStart(5, '0')}`;
  const methodOptions = (sel = 'cash') => Object.entries(BOARD.methods).map(([k, l]) => `<option value="${k}" ${k === sel ? 'selected' : ''}>${esc(l)}</option>`).join('');
  const lineKey = (category, name, price) => `${category}\u001f${name}\u001f${price}`;
  const addDays = (s, n) => new Date(toUTC(s) + n * 86400000).toISOString().slice(0, 10);
  /** The service charge (Admin → Booking setup), as the server works it out. */
  const servicePct = () => (BOARD && BOARD.serviceCharge) || 0;
  const serviceOn = (amount, pct = servicePct()) => (pct > 0 && amount > 0 ? Math.round((amount * pct) / 100) : 0);
  const colorOf = (name) => (CATS.find((c) => c.name === name) || {}).color || PALETTE[7];
  // receipts and printing (staff.js)
  const S = window.YDStaff;
  S.onPrintTip((msg) => toast(msg));
  const payFields = () => (ME && ME.paymentFields) || [];
  const showReceipt = (p, b) => S.receiptDialog({ openModal, modalHead, toast, api, me: () => ME }, p, b, money);
  /** The receipt for the payment a folio answer just recorded (its paymentId). */
  const receiptOf = (folio) => {
    const p = folio && folio.paymentId ? folio.payments.find((x) => x.id === folio.paymentId) : null;
    if (p) showReceipt(p, folio.booking);
  };
  const catIndex = (name) => { const i = CATS.findIndex((c) => c.name === name); return i < 0 ? 999 : i; };

  // the guests on the right
  const here = () => BOARD.folios.filter((f) => f.state === 'in_house');
  const toBill = () => BOARD.folios.filter((f) => f.state === 'checked_out');
  // only check-ins dated today (a stay that began earlier and was never checked in is "late", in the check-in list)
  const arrivingToday = () => BOARD.folios.filter((f) => f.state === 'expected' && f.booking.checkIn === BOARD.today);
  const chargeable = () => [...here(), ...toBill()];
  const entryOf = (id) => BOARD.folios.find((f) => f.booking.id === id) || null;

  // ---------- dialogs ----------
  const modal = $('[data-modal]');
  const modalPanel = $('[data-modal-panel]');
  function openModal(html, cls = '') {
    modalPanel.className = `drawer__panel modal${cls ? ` ${cls}` : ''}`;
    modalPanel.innerHTML = html;
    modalPanel.onsubmit = null;
    modalPanel.onclick = null;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    setTimeout(() => { const el = $('[autofocus], input:not([type="checkbox"]):not([type="hidden"]), select', modalPanel); if (el) el.focus(); }, 60);
  }
  function closeModal() {
    if (!modal.classList.contains('is-open')) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    modalPanel.innerHTML = '';
  }
  $('[data-modal-close]', modal).addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => {
    if (e.target.closest('[data-mclose]')) closeModal();
    const chip = e.target.closest('[data-chip]');
    if (chip) { const inp = $('[name="reason"]', modalPanel); if (inp) { inp.value = chip.textContent; inp.focus(); } }
  });
  const modalHead = (title, sub = '') => `<header class="dr-head"><div><h2 id="modal-title">${esc(title)}</h2>${sub ? `<p>${esc(sub)}</p>` : ''}</div>
    <button class="abtn abtn--ghost" type="button" data-mclose aria-label="Close">${icon('a-x', 20)}</button></header>`;

  // ---------- sign in ----------
  function showLogin() {
    closeModal();
    $('[data-app]').hidden = true;
    $('[data-login]').hidden = false;
    setTimeout(() => $('[data-login-form] input').focus(), 50);
  }
  $('[data-login-form]').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const err = $('[data-login-err]');
    err.hidden = true;
    try {
      await api('/login', { method: 'POST', body: { username: f.username.value, password: f.password.value } });
      f.password.value = '';
      await start();
    } catch (ex) { err.textContent = ex.message; err.hidden = false; }
  });
  $('[data-logout]').addEventListener('click', async () => {
    await saveTaps();
    await api('/logout', { method: 'POST', body: {} }).catch(() => {});
    ME = null;
    showLogin();
  });

  // ---------- loading ----------
  async function loadBoard() {
    const [board, arr] = await Promise.all([api('/pos'), api('/pos/arrivals')]);
    BOARD = board;
    ARRIVALS = arr.bookings;
    CATS = BOARD.categories.map((c, i) => ({ id: c.id, name: c.name, color: PALETTE[i % PALETTE.length] }));
    BOARD.items.forEach((i) => { if (!CATS.some((c) => c.name === i.category)) CATS.push({ id: null, name: i.category, color: PALETTE[CATS.length % PALETTE.length] }); });
    if (!CATS.some((c) => c.name === cat)) cat = CATS.length ? CATS[0].name : '';
  }

  async function start() {
    ME = await api('/me');
    $('[data-login]').hidden = true;
    $('[data-app]').hidden = false;
    $('[data-user]').textContent = ME.name || ME.username;
    $('[data-admin-link]').hidden = !can('bookings'); // front-desk logins only have the POS and its bills
    $('[data-date]').textContent = fd(ME.today, { weekday: 'long', day: 'numeric', month: 'long' });
    await loadBoard();
    renderAll();
    // /pos?guest=ID (&checkout=1) from the admin panel
    const url = new URLSearchParams(location.search);
    const want = Number(url.get('guest')) || 0;
    if (url.has('guest')) history.replaceState(null, '', '/pos');
    if (want) await openGuest(want, url.get('checkout') === '1');
  }

  /** Show a guest's bill — or, if they haven't arrived yet, check them in. */
  async function openGuest(id, checkout = false) {
    const f = entryOf(id);
    if (f && f.state !== 'expected') {
      await select(id);
      if (checkout) openCheckout();
      return;
    }
    try {
      const { booking } = await api(`/bookings/${id}`);
      if (booking.billId) toast(`${booking.guestName} is already billed on ${billNo(booking.billId)}.`, true);
      else if (['pending', 'confirmed'].includes(booking.status) && booking.source !== 'block') checkInForm(booking);
      else toast(`${booking.guestName}'s booking is ${booking.status.replace('_', ' ')}.`, true);
    } catch (ex) { toast(ex.message, true); }
  }

  // ---------- saving taps ----------
  // Every change to a bill runs one after another (run), so the screen and the server never disagree.
  // Taps wait a moment (queued), then go together in one request (sending) — tap 5 times, 5 go on the bill.
  let chain = Promise.resolve();
  const run = (fn) => (chain = chain.then(fn).catch((ex) => toast(ex.message, true)));
  const queued = new Map(); // booking id → Map(line key → {line, meta, qty})
  const sending = new Map();
  let flushTimer = 0;
  const scheduleFlush = () => { clearTimeout(flushTimer); flushTimer = setTimeout(() => run(flush), 450); };
  const saveTaps = () => { clearTimeout(flushTimer); return run(flush); };

  async function flush() {
    for (const bid of [...queued.keys()]) {
      const m = queued.get(bid);
      queued.delete(bid);
      if (!m || !m.size) continue;
      sending.set(bid, m);
      try {
        const r = await api(`/pos/folios/${bid}/charges`, { method: 'POST', body: { lines: [...m.values()].map((e) => ({ ...e.line, qty: e.qty })) } });
        setFolio(r.folio);
      } catch (ex) {
        toast(`Not added to the bill: ${ex.message}`, true);
      } finally {
        sending.delete(bid);
      }
      changed(bid);
    }
  }
  // if the page closes with taps still waiting, send them anyway
  window.addEventListener('pagehide', () => {
    queued.forEach((m, bid) => {
      if (!m.size) return;
      fetch(`/api/admin/pos/folios/${bid}/charges`, {
        method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ lines: [...m.values()].map((e) => ({ ...e.line, qty: e.qty })) }),
      }).catch(() => {});
    });
    queued.clear();
  });

  /** Taps not saved yet for a guest, by line. */
  function unsaved(bid) {
    const out = new Map();
    [sending.get(bid), queued.get(bid)].forEach((m) => m && m.forEach((e, k) => {
      const cur = out.get(k);
      out.set(k, cur ? { ...cur, qty: cur.qty + e.qty } : { ...e });
    }));
    return out;
  }
  const unsavedTotal = (bid) => [...unsaved(bid).values()].reduce((s, e) => s + e.qty * e.meta.price, 0);

  function queue(bid, key, line, meta) {
    let m = queued.get(bid);
    if (!m) queued.set(bid, (m = new Map()));
    const e = m.get(key);
    if (e) e.qty = Math.min(999, e.qty + 1);
    else m.set(key, { line, meta, qty: 1 });
    scheduleFlush();
  }

  /** Keep the list on the right in step with a room bill the server just returned. */
  function setFolio(f) {
    const b = f.booking;
    const t = f.totals;
    const state = b.status === 'checked_in' ? 'in_house' : b.status === 'checked_out' ? 'checked_out' : 'expected';
    const i = BOARD.folios.findIndex((x) => x.booking.id === b.id);
    const entry = { booking: b, state, stay: t.stay, items: t.items, itemCount: t.itemCount, paid: t.paid, total: t.total, balance: t.balance };
    if (!f.open) { if (i >= 0) BOARD.folios.splice(i, 1); }
    else if (i >= 0) BOARD.folios[i] = entry;
    else BOARD.folios.push(entry);
    if (b.id === SEL) FOLIO = f;
  }

  // ---------- choosing a guest ----------
  function select(id) {
    if (SEL && SEL !== id && queued.has(SEL)) saveTaps(); // the last guest's taps go now
    SEL = id;
    FOLIO = null;
    closeSheets();
    renderAll();
    return run(async () => {
      const r = await api(`/pos/folios/${id}`);
      setFolio(r.folio);
      changed(id);
    });
  }
  function askGuest() {
    toast('Choose a checked-in guest first.', true);
    if (window.matchMedia('(max-width: 899px)').matches) openSheet('guests');
    else { const p = $('.pos-guests'); p.classList.remove('is-flash'); void p.offsetWidth; p.classList.add('is-flash'); }
  }

  // ---------- tapping items ----------
  function tapItem(item, el) {
    if (!SEL) { askGuest(); return; }
    if (FOLIO && FOLIO.booking.id === SEL && !FOLIO.open) { toast('This bill is closed.', true); return; }
    queue(SEL, lineKey(item.category, item.name, item.price), { itemId: item.id }, { category: item.category, name: item.name, price: item.price });
    if (el) { el.classList.remove('is-hit'); void el.offsetWidth; el.classList.add('is-hit'); }
    changed(SEL);
  }
  /** One more of a bill line: the menu item if it's still on the menu, otherwise the same custom line. */
  function incLine(l) {
    const item = BOARD.items.find((i) => lineKey(i.category, i.name, i.price) === l.k);
    if (item) { tapItem(item); return; }
    const c = CATS.find((x) => x.name === l.category);
    queue(SEL, l.k, { name: l.name, price: l.unitPrice, categoryId: c && c.id ? c.id : null }, { category: l.category, name: l.name, price: l.unitPrice });
    changed(SEL);
  }
  /** One less: a tap not sent yet just comes off; otherwise the newest charge loses one. */
  function decLine(l) {
    const m = queued.get(SEL);
    const e = m && m.get(l.k);
    if (e) { e.qty -= 1; if (!e.qty) m.delete(l.k); changed(SEL); return; }
    if (!l.saved) return; // still saving — try again in a moment
    removeQty(l.k, 1, 'Taken off at the POS');
  }
  function removeQty(key, qty, reason) {
    const bid = SEL;
    return run(async () => {
      const f = FOLIO;
      if (!f || f.booking.id !== bid) return;
      const line = f.lines.find((x) => lineKey(x.category, x.name, x.unitPrice) === key);
      if (!line) return;
      const charges = f.charges.filter((c) => line.chargeIds.includes(c.id) && !c.voided).sort((a, b) => b.id - a.id);
      let left = qty;
      let last = null;
      for (const c of charges) {
        if (left <= 0) break;
        const n = Math.min(left, c.qty);
        last = await api(`/pos/charges/${c.id}/void`, { method: 'POST', body: { qty: n, reason } });
        left -= n;
      }
      if (last) { setFolio(last.folio); changed(bid); }
    });
  }

  // ---------- drawing ----------
  function renderAll() {
    renderBill(); // first: it works out the lines the tiles count
    renderTabs();
    renderTiles();
    renderGuests();
    renderDock();
    const n = ARRIVALS.filter((b) => b.checkIn === BOARD.today).length;
    const badge = $('[data-arrivals]');
    badge.hidden = !n;
    badge.textContent = n;
  }
  /** After a bill changed: redraw what shows it. */
  function changed(bid) {
    renderGuests();
    if (bid === SEL) { renderBill(); updateBadges(); renderDock(); }
  }

  function renderTabs() {
    $('[data-tabs]').innerHTML = CATS.map((c) => {
      const on = c.name === cat && !itemQ;
      return `<button type="button" role="tab" class="tab${on ? ' is-on' : ''}" aria-selected="${on}" style="--c:${c.color}" data-cat="${esc(c.name)}">${esc(c.name)}</button>`;
    }).join('');
  }

  /** How many of each line are on the selected bill (saved and not yet saved). */
  function onBill() {
    const out = new Map();
    LINES.forEach((l) => out.set(l.k, l.qty));
    return out;
  }
  function renderTiles() {
    const q = itemQ.trim().toLowerCase();
    const list = BOARD.items.filter((i) => (q ? i.name.toLowerCase().includes(q) : i.category === cat));
    const counts = onBill();
    $('.pos-menu').classList.toggle('no-guest', !SEL);
    $('[data-hint]').textContent = SEL ? 'Tap an item to add one to the bill.' : 'Choose a checked-in guest, then tap items to add them.';
    $('[data-tiles]').innerHTML = list.length ? list.map((i) => {
      const n = counts.get(lineKey(i.category, i.name, i.price)) || 0;
      return `<button type="button" class="tile" style="--c:${colorOf(i.category)}" data-item="${i.id}" data-k="${esc(lineKey(i.category, i.name, i.price))}">
        <span class="tile__name">${esc(i.name)}</span>${q ? `<span class="tile__cat">${esc(i.category)}</span>` : ''}<span class="tile__price">${money(i.price)}</span><b class="tile__n"${n ? '' : ' hidden'}>${n}</b></button>`;
    }).join('') : `<div class="empty">${q ? 'No item matches.' : 'Nothing in this category yet. Add items in Admin → POS menu.'}</div>`;
  }
  function updateBadges() {
    const counts = onBill();
    $$('.tile', $('[data-tiles]')).forEach((t) => {
      const n = counts.get(t.dataset.k) || 0;
      const b = $('.tile__n', t);
      b.hidden = !n;
      b.textContent = n;
    });
  }

  function guestCard(f) {
    const b = f.booking;
    const items = f.itemCount + [...unsaved(b.id).values()].reduce((s, e) => s + e.qty, 0);
    const meta = f.state === 'checked_out' ? 'Checked out · not billed' : b.kind === 'day_out' ? 'Day visit' : b.kind === 'event' ? b.packageLabel : `Until ${fd(b.checkOut, long)}`;
    const sub = f.stay + f.items + unsavedTotal(b.id);
    const due = sub + serviceOn(sub) - f.paid;
    return `<button type="button" class="gcard${b.id === SEL ? ' is-on' : ''}${f.state === 'checked_out' ? ' is-out' : ''}" data-guest="${b.id}" aria-pressed="${b.id === SEL}">
      <span class="gcard__place">${esc(placeOf(b))}</span>
      <b class="gcard__name">${esc(b.guestName)}</b>
      <span class="gcard__meta">${esc(meta)}${items ? ` · ${plural(items, 'item')}` : ''}</span>
      <span class="gcard__sum">${money(due)}</span>
    </button>`;
  }
  function renderGuests() {
    const q = guestQ.trim().toLowerCase();
    const match = (f) => !q || [f.booking.guestName, f.booking.ref, placeOf(f.booking), f.booking.guestPhone].join(' ').toLowerCase().includes(q);
    const inHouse = here().filter(match);
    const out = toBill().filter(match);
    const arr = arrivingToday().filter(match);
    $('[data-guest-count]').textContent = here().length ? `· ${here().length}` : '';
    $('[data-guests]').innerHTML = `
      ${inHouse.length ? inHouse.map(guestCard).join('') : `<div class="g-empty"><p>${q ? 'No checked-in guest matches.' : 'Nobody is checked in yet.'}</p>${q ? '' : '<div class="g-empty__btns"><button type="button" class="abtn abtn--sm abtn--amber" data-checkin>Check in a guest</button><button type="button" class="abtn abtn--sm" data-walkin>Walk-in</button></div>'}</div>`}
      ${out.length ? `<p class="g-sec">To bill</p>${out.map(guestCard).join('')}` : ''}
      ${arr.length ? `<p class="g-sec">Arriving today</p>${arr.map((f) => `<div class="gcard is-arr">
          <span class="gcard__place">${esc(placeOf(f.booking))}</span><b class="gcard__name">${esc(f.booking.guestName)}</b>
          <span class="gcard__meta">${esc(guestsText(f.booking))}</span>
          <button type="button" class="abtn abtn--sm" data-checkin-id="${f.booking.id}">Check in</button></div>`).join('')}` : ''}`;
  }

  /** The selected bill as it should look now: the saved lines plus taps still on their way. */
  function billView() {
    const entry = entryOf(SEL);
    const f = FOLIO && FOLIO.booking.id === SEL ? FOLIO : null;
    const lines = f ? f.lines.map((l) => ({ ...l, k: lineKey(l.category, l.name, l.unitPrice), saved: l.qty, extra: 0 })) : [];
    unsaved(SEL).forEach((e, k) => {
      const l = lines.find((x) => x.k === k);
      if (l) l.extra += e.qty;
      else lines.push({ k, category: e.meta.category, name: e.meta.name, unitPrice: e.meta.price, chargeIds: [], saved: 0, extra: e.qty });
    });
    lines.forEach((l) => { l.qty = l.saved + l.extra; l.amount = l.qty * l.unitPrice; });
    lines.sort((a, b) => catIndex(a.category) - catIndex(b.category));
    const stay = f ? f.totals.stay : entry ? entry.stay : 0;
    const paid = f ? f.totals.paid : entry ? entry.paid : 0;
    const items = lines.reduce((s, l) => s + l.amount, 0);
    const service = serviceOn(stay + items);
    const total = stay + items + service;
    return { f, entry, lines, stay, paid, items, service, total, balance: total - paid, count: lines.reduce((s, l) => s + l.qty, 0) };
  }

  function renderBill() {
    const box = $('[data-bill]');
    const v = SEL ? billView() : null;
    LINES = v ? v.lines : [];
    if (!v || (!v.f && !v.entry)) {
      box.innerHTML = `<div class="pb-none">${icon('a-user', 34)}<b>No guest chosen</b><p>Choose a checked-in guest${window.matchMedia('(max-width: 899px)').matches ? '' : ' on the right'} to see their bill and add items.</p>
        <button type="button" class="abtn abtn--amber" data-checkin>${icon('a-key', 16)}Check in a guest</button></div>`;
      return;
    }
    const b = v.f ? v.f.booking : v.entry.booking;
    if (v.f && !v.f.open) {
      box.innerHTML = `<div class="pb-none"><b>${esc(b.guestName)}</b><p>${b.billId ? `Billed on ${billNo(b.billId)}.` : 'This bill is closed.'}</p>
        ${b.billId ? `<a class="abtn" href="/admin/#/bills/${b.billId}" target="ydadmin">${icon('a-bill', 16)}Open the bill</a>` : ''}</div>`;
      return;
    }
    const scroll = $('[data-lines]', box);
    const top = scroll ? scroll.scrollTop : 0;
    let lastCat = null;
    const lines = v.lines.map((l, i) => {
      const head = l.category !== lastCat ? `<div class="pb-cat" style="--c:${colorOf(l.category)}">${esc(l.category)}</div>` : '';
      lastCat = l.category;
      return `${head}<div class="pb-line${l.extra ? ' is-saving' : ''}">
        <button type="button" class="pb-line__name" data-line-menu="${i}"><b>${esc(l.name)}</b><small>${money(l.unitPrice)}${l.extra ? ' · saving…' : ''}</small></button>
        <span class="pb-qty"><button type="button" data-dec="${i}" aria-label="One less ${esc(l.name)}">${icon('a-minus', 16)}</button><b>${l.qty}</b><button type="button" data-inc="${i}" aria-label="One more ${esc(l.name)}">${icon('a-plus', 16)}</button></span>
        <b class="pb-line__amt">${money(l.amount)}</b>
      </div>`;
    }).join('');
    const state = v.entry ? v.entry.state : 'in_house';
    box.innerHTML = `
      <header class="pb-head">
        <div class="pb-head__who"><small>${esc(placeOf(b))}</small><h2>${esc(b.guestName)}</h2><span>${esc(b.ref)} · ${state === 'checked_out' ? 'checked out, not billed' : esc(whenText(b))}</span></div>
        <button type="button" class="abtn abtn--sm" data-guest-info>Details</button>
        <button type="button" class="abtn abtn--ghost abtn--sm pos-sheet-close" data-sheet-close aria-label="Close">${icon('a-x', 18)}</button>
      </header>
      <div class="pb-lines" data-lines>
        <div class="pb-stay"><span><b>Stay</b><small>${esc([b.kind === 'room' ? '' : b.packageLabel, guestsText(b), ...extrasOf(b)].filter(Boolean).join(' · '))}</small></span><b>${money(v.stay)}</b></div>
        ${lines || '<p class="pb-empty">No items yet — tap items on the menu to add them.</p>'}
        ${v.f ? '' : '<p class="pb-empty">Loading the bill…</p>'}
      </div>
      <div class="pb-totals">
        ${v.count ? `<div><span>Items · ${v.count}</span><span>${money(v.items)}</span></div>` : ''}
        ${v.service ? `<div><span>Service charge ${servicePct()}%</span><span>${money(v.service)}</span></div>` : ''}
        <div class="is-total"><span>Total</span><b>${money(v.total)}</b></div>
        ${v.paid ? `<div class="is-paid"><span>Paid</span><span>− ${money(v.paid)}</span></div>` : ''}
        <div class="is-balance"><span>${v.balance < 0 ? 'In credit' : 'Balance'}</span><b>${money(Math.abs(v.balance))}</b></div>
      </div>
      <div class="pb-actions">
        <button type="button" class="abtn" data-pay>${icon('a-cash', 16)}Payment</button>
        <button type="button" class="abtn" data-move ${v.lines.length ? '' : 'disabled'}>${icon('a-move', 16)}Move</button>
        <button type="button" class="abtn" data-history>${icon('a-clock', 16)}History</button>
        <button type="button" class="abtn abtn--primary pb-actions__out" data-checkout>${icon('a-bill', 16)}Check out &amp; bill</button>
      </div>`;
    const sc = $('[data-lines]', box);
    if (sc) sc.scrollTop = top;
  }

  function renderDock() {
    const v = SEL ? billView() : null;
    const b = v && (v.f ? v.f.booking : v.entry && v.entry.booking);
    $('[data-dock-guest]').textContent = b ? `${b.guestName} · ${placeOf(b)}` : 'Choose a guest';
    $('[data-dock-total]').textContent = b ? money(v.balance) : '';
  }

  // ---------- phone sheets (the guest list and the bill slide up) ----------
  function openSheet(which) { document.body.classList.remove('sheet-guests', 'sheet-bill'); document.body.classList.add(`sheet-${which}`); }
  function closeSheets() { document.body.classList.remove('sheet-guests', 'sheet-bill'); }

  // ---------- events ----------
  document.addEventListener('click', (e) => {
    if (!BOARD || $('[data-app]').hidden) return;
    const t = e.target;
    if (t.closest('[data-modal]')) return; // dialogs handle their own clicks
    const tab = t.closest('[data-cat]');
    if (tab) { cat = tab.dataset.cat; itemQ = ''; $('[data-item-q]').value = ''; renderTabs(); renderTiles(); return; }
    const tile = t.closest('[data-item]');
    if (tile) { tapItem(BOARD.items.find((i) => i.id === Number(tile.dataset.item)), tile); return; }
    const g = t.closest('[data-guest]');
    if (g) { select(Number(g.dataset.guest)); return; }
    const ci = t.closest('[data-checkin-id]');
    if (ci) { openGuest(Number(ci.dataset.checkinId)); return; }
    if (t.closest('[data-walkin]')) { walkInDialog(); return; }
    if (t.closest('[data-checkin]')) { checkInDialog(); return; }
    const inc = t.closest('[data-inc]');
    if (inc) { incLine(LINES[Number(inc.dataset.inc)]); return; }
    const dec = t.closest('[data-dec]');
    if (dec) { decLine(LINES[Number(dec.dataset.dec)]); return; }
    const lm = t.closest('[data-line-menu]');
    if (lm) { lineDialog(LINES[Number(lm.dataset.lineMenu)]); return; }
    if (t.closest('[data-custom]')) { customDialog(); return; }
    if (t.closest('[data-pay]')) { payDialog(); return; }
    if (t.closest('[data-move]')) { moveDialog(); return; }
    if (t.closest('[data-history]')) { historyDialog(); return; }
    if (t.closest('[data-checkout]')) { openCheckout(); return; }
    if (t.closest('[data-guest-info]')) { guestDialog(); return; }
    if (t.closest('[data-open-guests]')) { openSheet('guests'); return; }
    if (t.closest('[data-open-bill]')) { if (SEL) openSheet('bill'); else openSheet('guests'); return; }
    if (t.closest('[data-sheet-close]')) closeSheets();
  });
  document.addEventListener('input', (e) => {
    if (e.target.matches('[data-item-q]')) { itemQ = e.target.value; renderTabs(); renderTiles(); }
    if (e.target.matches('[data-guest-q]')) { guestQ = e.target.value; renderGuests(); }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (modal.classList.contains('is-open')) closeModal();
    else closeSheets();
  });

  // ---------- check in ----------
  const arrRow = (b) => {
    const when = b.checkIn < BOARD.today ? 'Late' : b.checkIn === BOARD.today ? 'Today' : fd(b.checkIn, long);
    return `<button type="button" class="arr" data-arr="${b.id}">
      <span class="arr__when${b.checkIn <= BOARD.today ? ' is-now' : ''}">${esc(when)}</span>
      <span class="arr__who"><b>${esc(b.guestName)}</b><small>${esc(placeOf(b))} · ${esc(whenText(b))} · ${esc(guestsText(b))}</small></span>
      <span class="st st--${b.status}">${b.status === 'pending' ? 'Pending' : 'Confirmed'}</span>
    </button>`;
  };
  function checkInDialog() {
    openModal(`${modalHead('Check in a guest', 'Arrivals for today and the next two weeks. Search to find any other booking.')}
      <div class="dr-body">
        <div class="arr-top"><label class="pos-find">${icon('a-search')}<input class="inp" type="search" placeholder="Name, phone or booking ref" data-arr-q aria-label="Find a booking"></label>
          <button type="button" class="abtn abtn--amber" data-walkin>${icon('a-plus', 16)}Walk-in — no booking</button></div>
        <div class="arr-list" data-arr-list>${ARRIVALS.length ? ARRIVALS.map(arrRow).join('') : '<div class="empty">No arrivals in the next two weeks.</div>'}</div>
      </div>`, 'modal--wide');
    let list = ARRIVALS;
    let timer;
    $('[data-arr-q]', modalPanel).addEventListener('input', (e) => {
      clearTimeout(timer);
      timer = setTimeout(async () => {
        try {
          const q = e.target.value.trim();
          list = q ? (await api(`/pos/arrivals?q=${encodeURIComponent(q)}`)).bookings : ARRIVALS;
          const box = $('[data-arr-list]', modalPanel);
          if (box) box.innerHTML = list.length ? list.map(arrRow).join('') : '<div class="empty">No booking to check in matches.</div>';
        } catch (ex) { toast(ex.message, true); }
      }, 250);
    });
    modalPanel.onclick = (e) => {
      if (e.target.closest('[data-walkin]')) { walkInDialog(); return; }
      const r = e.target.closest('[data-arr]');
      if (r) checkInForm(list.find((b) => b.id === Number(r.dataset.arr)));
    };
  }

  /** Rooms a per-person group can be given: free ones can be ticked, taken ones are shown. */
  function roomPicker(choices) {
    const list = choices.slice().sort((a, b) => Number(b.assigned) - Number(a.assigned) || (a.priority ?? 999) - (b.priority ?? 999));
    return `<div class="room-pick" data-room-pick>
      <p class="section-title">Rooms <span class="room-pick__sum" data-pick-sum></span></p>
      <div class="room-pick__list">${list.map((r) => `<label class="room-pick__item${r.free ? '' : ' is-taken'}">
        <input type="checkbox" name="room" value="${r.id}" data-cap="${r.capacity}" ${r.assigned ? 'checked' : ''} ${r.free || r.assigned ? '' : 'disabled'}>
        <span><b>${esc(r.name)}</b><small>sleeps ${r.capacity}${r.free ? '' : ` · taken (${esc(r.takenBy)})`}</small></span></label>`).join('')}</div>
    </div>`;
  }
  function bindPicker(form, need) {
    const box = $('[data-room-pick]', form);
    if (!box) return;
    const update = () => {
      const beds = $$('[name="room"]:checked', box).reduce((s, x) => s + Number(x.dataset.cap), 0);
      const n = need();
      const el = $('[data-pick-sum]', box);
      el.textContent = `· ${plural(beds, 'bed')} for ${plural(n, 'guest')}`;
      el.classList.toggle('is-short', beds < n);
    };
    form.addEventListener('change', update);
    form.addEventListener('input', update);
    update();
  }
  const pickedRooms = (form) => $$('[name="room"]:checked', form).map((x) => Number(x.value));

  async function checkInForm(b) {
    if (!b) return;
    let choices = [];
    if (b.kind === 'per_person') {
      try { choices = (await api(`/bookings/${b.id}/rooms`)).rooms; } catch (ex) { toast(ex.message, true); return; }
    }
    const room = b.kind === 'room';
    openModal(`${modalHead(`Check in ${b.guestName}`, `${b.ref} · ${placeOf(b)} · ${whenText(b)}`)}
      <form class="dr-body">
        ${b.status === 'pending' ? '<div class="note note--warn">This booking is still pending. Checking in confirms it — make sure the advance is settled.</div>' : ''}
        ${room ? `<div class="note note--info"><b>${esc(b.roomName)}</b> — sleeps ${b.capacity}. Noting the guests is optional and doesn't change the price.</div>` : ''}
        ${b.specialRequests ? `<div class="note note--info"><b>Requests:</b> ${esc(b.specialRequests)}</div>` : ''}
        <label class="fld"><span>NIC / passport number <small>— check it against their ID</small></span><input name="guestIdNo" value="${esc(b.guestIdNo || '')}" maxlength="30" autocapitalize="characters" spellcheck="false" placeholder="e.g. 199012345678"></label>
        <div class="grid2">
          <label class="fld"><span>Adults</span><input type="number" name="adults" min="${room ? 0 : 1}" max="50" step="1" value="${b.adults || (room ? '' : 1)}"></label>
          <label class="fld"><span>Children <small>2–12</small></span><input type="number" name="children" min="0" max="30" step="1" value="${b.children || (room ? '' : 0)}"></label>
        </div>
        ${room ? '' : '<p class="src" data-reprice hidden>A different number of guests re-prices the stay.</p>'}
        ${b.kind === 'per_person' ? roomPicker(choices) : ''}
        <p class="section-title">Payment now <small>(optional)</small></p>
        <div class="grid2">
          <label class="fld"><span>Amount (${esc(CUR)})</span><input type="number" name="amount" min="0" step="1"></label>
          <label class="fld"><span>Paid by</span><select name="method">${methodOptions('cash')}</select></label>
          ${S.payFieldsHtml(payFields(), { fee: false })}
        </div>
        <div class="dlg-foot"><button type="button" class="abtn" data-mclose>Cancel</button><button class="abtn abtn--primary" type="submit">${icon('a-key', 16)}Check in</button></div>
      </form>`);
    const form = $('form', modalPanel);
    S.bindPayFields(form, form.method);
    const need = () => (Number(form.adults.value) || 0) + (Number(form.children.value) || 0);
    bindPicker(form, need);
    form.addEventListener('input', () => {
      const rp = $('[data-reprice]', form);
      if (rp) rp.hidden = Number(form.adults.value) === b.adults && (Number(form.children.value) || 0) === b.children;
    });
    modalPanel.onsubmit = async (e) => {
      e.preventDefault();
      const body = {};
      if (form.guestIdNo.value.trim() !== (b.guestIdNo || '')) body.guestIdNo = form.guestIdNo.value;
      if (form.adults.value !== '') body.adults = Number(form.adults.value);
      if (form.children.value !== '') body.children = Number(form.children.value);
      if (b.kind === 'per_person') {
        body.rooms = pickedRooms(form);
        if (!body.rooms.length) { toast('Tick the rooms for the group.', true); return; }
      }
      const amount = Math.round(Number(form.amount.value)) || 0;
      if (amount > 0) body.advance = { amount, method: form.method.value, note: 'Paid at check-in', ...S.readPayFields(form, payFields()) };
      const btn = $('[type="submit"]', form);
      btn.disabled = true;
      try {
        const r = await api(`/pos/checkin/${b.id}`, { method: 'POST', body });
        closeModal();
        await loadBoard();
        setFolio(r.folio);
        toast(`${b.guestName} is checked in${r.folio.booking.roomName && b.kind !== 'day_out' ? ` — ${r.folio.booking.roomName}` : ''}`);
        await select(b.id);
        receiptOf(r.folio); // a payment at check-in gets its receipt
      } catch (ex) { toast(ex.message, true); btn.disabled = false; }
    };
  }

  // ---------- walk-ins: guests who arrive without a booking ----------
  function walkInDialog() {
    const pk = ME.packages || [];
    const labels = ME.labels || { room: 'Room', person: 'Per person' };
    const whatOptions = `<option value="room">${esc(labels.room)} — priced per room</option>`
      + pk.map((p) => `<option value="pkg:${p.id}">${esc(p.name)} — ${p.kind === 'day' ? 'day visit' : 'per person'}${p.active ? '' : ' (not on the website)'}</option>`).join('');
    const rooms = BOARD.rooms.slice().sort((a, b) => Number(b.active) - Number(a.active));
    openModal(`${modalHead('Walk-in guest', `No booking — enter their details and check them in now. Arriving today, ${fd(BOARD.today, long)}.`)}
      <form class="dr-body wk" autocomplete="off">
        <p class="section-title">Guest</p>
        <div class="grid2">
          <label class="fld"><span>Name</span><input name="guestName" maxlength="120" required autofocus placeholder="Full name"></label>
          <label class="fld"><span>Phone / WhatsApp</span><input name="guestPhone" maxlength="40" inputmode="tel" placeholder="+94 7X XXX XXXX"></label>
          <label class="fld"><span>NIC / passport number</span><input name="guestIdNo" maxlength="30" autocapitalize="characters" spellcheck="false" placeholder="e.g. 199012345678"></label>
          <label class="fld"><span>Email <small>(optional, for the bill)</small></span><input type="email" name="guestEmail" maxlength="160"></label>
          <label class="fld span2"><span>Country</span><input name="country" maxlength="60" placeholder="Sri Lanka"></label>
        </div>
        <p class="section-title">Stay</p>
        <div class="grid2">
          <label class="fld span2"><span>Booking</span><select name="what">${whatOptions}</select></label>
          <label class="fld" data-wk-room><span>Room</span><select name="roomId">${rooms.map((r) => `<option value="${r.id}">${esc(r.name)} · sleeps ${r.capacity}${r.active ? '' : ' (hidden)'}</option>`).join('')}</select></label>
          <label class="fld" data-wk-stay><span>Nights</span><input type="number" name="nights" min="1" max="30" step="1" value="1" required><small data-wk-out></small></label>
          <label class="fld"><span>Adults <small data-wk-opt>(optional)</small></span><input type="number" name="adults" min="0" max="50" step="1" value="2"></label>
          <label class="fld"><span>Children <small>2–12</small></span><input type="number" name="children" min="0" max="30" step="1" value="0"></label>
          <label class="chk" data-wk-stay><input type="checkbox" name="partyArea"> Party area</label>
          <label class="chk"><input type="checkbox" name="jblSpeaker"> JBL speaker</label>
        </div>
        <div class="room-pick" data-wk-group hidden>
          <p class="section-title">Rooms for the group <span class="room-pick__sum" data-wk-sum></span></p>
          <div class="room-pick__list">${rooms.map((r) => `<label class="room-pick__item"><input type="checkbox" name="room" value="${r.id}" data-cap="${r.capacity}"><span><b>${esc(r.name)}</b><small>sleeps ${r.capacity}${r.active ? '' : ' · hidden'}</small></span></label>`).join('')}</div>
          <p class="src">Ticked from the room priority list — change them if you like.</p>
        </div>
        <div class="note note--info" data-wk-check>Checking…</div>
        <p class="section-title">Price <small>— type the rate you agreed, or leave it for the price list</small></p>
        <div class="grid2">
          ${S.rateFieldsHtml(money)}
          <label class="fld"><span>Stay total (${esc(CUR)})</span><input type="number" name="totalAmount" min="0" step="1" required><small>Worked out from the rates. Type a total only for a special price.</small></label>
          <p class="src span2 wk-rate" data-wk-rate></p>
        </div>
        <p class="section-title">Payment now <small>(optional)</small></p>
        <div class="grid2">
          <label class="fld"><span>Amount (${esc(CUR)})</span><input type="number" name="amount" min="0" step="1"></label>
          <label class="fld"><span>Paid by</span><select name="method">${methodOptions('cash')}</select></label>
          ${S.payFieldsHtml(payFields(), { fee: false })}
        </div>
        <div class="dlg-foot"><button type="button" class="abtn" data-mclose>Cancel</button><button class="abtn abtn--primary" type="submit">${icon('a-key', 16)}Check in walk-in</button></div>
      </form>`, 'modal--wide');
    const form = $('form', modalPanel);
    S.bindPayFields(form, form.method);
    const box = $('[data-wk-check]', form);
    let totalTouched = false;
    let roomsTouched = false;
    let roomChosen = false; // a room picked (by staff, or the first free one)
    let last = null; // the last check, for saving
    let seq = 0;
    const kindOf = () => {
      if (form.what.value === 'room') return { kind: 'room', packageId: null };
      const p = pk.find((x) => `pkg:${x.id}` === form.what.value);
      return { kind: p && p.kind === 'day' ? 'day_out' : 'per_person', packageId: p ? p.id : null };
    };
    const people = () => (Number(form.adults.value) || 0) + (Number(form.children.value) || 0);
    const picked = () => pickedRooms($('[data-wk-group]', form));
    const checkOut = () => addDays(BOARD.today, Math.max(1, Math.min(30, Math.round(Number(form.nights.value)) || 1)));
    const roomName = (id) => (BOARD.rooms.find((r) => r.id === id) || {}).name || `#${id}`;
    function sumBeds() {
      const beds = picked().reduce((s, id) => s + ((BOARD.rooms.find((r) => r.id === id) || {}).capacity || 0), 0);
      const el = $('[data-wk-sum]', form);
      el.textContent = `· ${plural(beds, 'bed')} for ${plural(people(), 'guest')}`;
      el.classList.toggle('is-short', beds < people());
    }
    async function check() {
      const { kind, packageId } = kindOf();
      const day = kind === 'day_out';
      $$('[data-wk-room]', form).forEach((el) => { el.hidden = kind !== 'room'; });
      $$('[data-wk-stay]', form).forEach((el) => { el.hidden = day; });
      $('[data-wk-group]', form).hidden = kind !== 'per_person';
      $('[data-wk-opt]', form).hidden = kind !== 'room';
      form.adults.min = kind === 'room' ? 0 : 1;
      $('[data-wk-out]', form).textContent = `Leaving ${fd(checkOut(), long)}`;
      const p = new URLSearchParams({ kind, checkIn: BOARD.today, adults: Number(form.adults.value) || 0, children: Number(form.children.value) || 0 });
      if (packageId) p.set('packageId', packageId);
      if (kind === 'room') p.set('roomId', form.roomId.value);
      if (!day) p.set('checkOut', checkOut());
      if (!day && form.partyArea.checked) p.set('partyArea', '1');
      if (form.jblSpeaker.checked) p.set('jblSpeaker', '1');
      if (kind === 'per_person' && roomsTouched && picked().length) p.set('rooms', picked().join(','));
      S.rateParams(form, kind, p); // rates agreed with the guest (empty = the price list)
      S.syncRates(form, kind);
      const n = ++seq;
      let r;
      try { r = await api(`/check?${p}`); } catch (ex) { box.className = 'note note--bad'; box.textContent = ex.message; return; }
      if (n !== seq) return;
      last = r;
      const busy = r.busy || [];
      // taken rooms can't be chosen; start on the first free one
      $$('option', form.roomId).forEach((o) => { o.disabled = busy.includes(Number(o.value)); });
      if (kind === 'room' && !roomChosen && busy.includes(Number(form.roomId.value))) {
        const free = $$('option', form.roomId).find((o) => !o.disabled);
        if (free) { form.roomId.value = free.value; roomChosen = true; check(); return; }
      }
      $$('[data-wk-group] [name="room"]', form).forEach((x) => {
        const taken = busy.includes(Number(x.value));
        if (!roomsTouched) x.checked = (r.rooms || []).includes(Number(x.value));
        x.disabled = taken && !x.checked;
        x.closest('.room-pick__item').classList.toggle('is-taken', taken);
      });
      if (kind === 'per_person') sumBeds();
      const nights = plural(r.nights, 'night');
      let good = r.ok;
      let msg;
      if (r.reason) { msg = 'Choose what they are booking.'; good = false; }
      else if (day) msg = `✓ Day visit today for ${plural(people(), 'guest')}.`;
      else if (kind === 'room') {
        const rm = BOARD.rooms.find((x) => String(x.id) === form.roomId.value) || {};
        msg = r.ok ? `✓ ${rm.name} is free for ${nights}.` : `✕ ${rm.name} is booked on some of those nights — choose another room.`;
        if (people() > rm.capacity) { msg += ` ✕ It sleeps ${rm.capacity}, not ${people()}.`; good = false; }
      } else if (roomsTouched && picked().length) {
        msg = r.ok ? `✓ ${picked().map(roomName).join(', ')} — free for ${nights}.` : '✕ Some of the ticked rooms are taken on those nights.';
      } else {
        msg = r.ok ? `✓ ${plural(people(), 'guest')} for ${nights} in ${r.rooms.map(roomName).join(', ')}.`
          : `✕ The room priority list has no free rooms for ${plural(people(), 'guest')} tonight — tick the rooms yourself.`;
      }
      if (r.partyArea && !r.partyArea.free) { msg += ' ✕ The party area is already reserved tonight.'; good = false; }
      if (r.capacity && r.capacity.over) msg += ` ⚠ This goes over the hotel's capacity (${Math.max(0, r.capacity.left)} guest places left). You can still check them in.`;
      if (r.closed && r.closed.length) msg += ` ⚠ The resort is closed ${r.closed[0].date === BOARD.today ? 'today' : 'on some of these days'} (${r.closed[0].label.toLowerCase()}). You can still check them in.`;
      box.className = `note ${!good ? 'note--bad' : (r.capacity && r.capacity.over) || (r.closed && r.closed.length) ? 'note--warn' : 'note--ok'}`;
      box.textContent = msg;
      S.syncRates(form, kind, r, money);
      $('[data-wk-rate]', form).textContent = S.priceLine(r, money);
      if (!totalTouched && r.total !== undefined) form.totalAmount.value = r.total;
    }
    let rateTimer;
    form.addEventListener('input', (e) => {
      if (e.target.name === 'totalAmount') totalTouched = form.totalAmount.value !== ''; // emptied: worked out again
      if (e.target.name === 'room') roomsTouched = true;
      if (['rateRoom', 'rateAdult', 'rateChild'].includes(e.target.name)) { clearTimeout(rateTimer); rateTimer = setTimeout(check, 300); }
      if (e.target.name === 'totalAmount' && !totalTouched) check();
    });
    form.addEventListener('change', (e) => {
      if (e.target.name === 'room') { roomsTouched = true; sumBeds(); }
      if (e.target.name === 'roomId') roomChosen = true;
      if (e.target.name === 'what') ['rateRoom', 'rateAdult', 'rateChild'].forEach((n) => { form[n].value = ''; }); // another option: its own price list
      if (['what', 'roomId', 'nights', 'adults', 'children', 'partyArea', 'jblSpeaker', 'room'].includes(e.target.name)) check();
    });
    check();
    modalPanel.onsubmit = async (e) => {
      e.preventDefault();
      const { kind, packageId } = kindOf();
      const day = kind === 'day_out';
      if (!form.guestName.value.trim()) { toast('Please enter the guest name.', true); form.guestName.focus(); return; }
      if (kind !== 'room' && (Number(form.adults.value) || 0) < 1) { toast('A per-person booking needs at least one adult.', true); return; }
      if (kind === 'per_person' && roomsTouched && !picked().length) { toast('Tick the rooms for the group.', true); return; }
      if (last && last.partyArea && !last.partyArea.free && form.partyArea.checked) { toast('The party area is already reserved tonight. Untick it.', true); return; }
      const amount = Math.round(Number(form.amount.value)) || 0;
      const body = {
        guestName: form.guestName.value.trim(), guestPhone: form.guestPhone.value, guestEmail: form.guestEmail.value, country: form.country.value,
        guestIdNo: form.guestIdNo.value,
        kind, packageId, checkIn: BOARD.today, checkOut: day ? undefined : checkOut(),
        roomId: kind === 'room' ? Number(form.roomId.value) : undefined,
        rooms: kind === 'per_person' && roomsTouched ? picked() : undefined,
        adults: Number(form.adults.value) || 0, children: Number(form.children.value) || 0,
        partyArea: !day && form.partyArea.checked, jblSpeaker: form.jblSpeaker.checked,
        ...S.rateBody(form, kind), // the agreed rates are kept on the booking
        totalAmount: totalTouched ? form.totalAmount.value : undefined, // otherwise worked out from the rates
        payment: amount > 0 ? { amount, method: form.method.value, note: 'Paid at check-in', ...S.readPayFields(form, payFields()) } : null,
      };
      const btn = $('[type="submit"]', form);
      btn.disabled = true;
      try {
        const r = await api('/pos/walkin', { method: 'POST', body });
        closeModal();
        await loadBoard();
        setFolio(r.folio);
        const b = r.folio.booking;
        toast(`${b.guestName} is checked in${b.kind !== 'day_out' && b.roomName ? ` — ${b.roomName}` : ''}`);
        await select(b.id);
        receiptOf(r.folio);
      } catch (ex) { toast(ex.message, true); btn.disabled = false; }
    };
  }

  // ---------- a line on the bill ----------
  async function lineDialog(l) {
    if (!l) return;
    await saveTaps();
    const line = FOLIO && FOLIO.lines.find((x) => lineKey(x.category, x.name, x.unitPrice) === l.k);
    if (!line) return;
    openModal(`${modalHead(line.name, `${line.qty} on the bill · ${money(line.unitPrice)} each · ${line.category}`)}
      <form class="dr-body">
        <div class="grid2">
          <label class="fld"><span>How many to remove</span><input type="number" name="qty" min="1" max="${line.qty}" step="1" value="${line.qty}" required></label>
          <span></span>
        </div>
        <div class="chips-row">${['Entered by mistake', 'Order cancelled', 'Complimentary', 'Charged twice'].map((c) => `<button type="button" class="abtn abtn--sm" data-chip>${c}</button>`).join('')}</div>
        <label class="fld"><span>Reason <small>(kept in the history)</small></span><input name="reason" maxlength="255" required></label>
        <div class="dlg-foot"><button type="button" class="abtn" data-line-move>${icon('a-move', 16)}Move to another guest</button><button class="abtn abtn--danger" type="submit">Remove</button></div>
      </form>`);
    modalPanel.onclick = (e) => { if (e.target.closest('[data-line-move]')) moveDialog(l.k); };
    modalPanel.onsubmit = (e) => {
      e.preventDefault();
      const f = e.target;
      const qty = Math.max(1, Math.min(line.qty, Math.round(Number(f.qty.value)) || 1));
      closeModal();
      removeQty(l.k, qty, f.reason.value.trim()).then(() => toast(`${plural(qty, line.name, line.name)} removed`));
    };
  }

  // ---------- custom item ----------
  function customDialog() {
    if (!SEL) { askGuest(); return; }
    const opts = CATS.filter((c) => c.id).map((c) => `<option value="${c.id}" ${c.name === cat ? 'selected' : ''}>${esc(c.name)}</option>`).join('');
    openModal(`${modalHead('Custom item', 'Something that isn’t on the menu.')}
      <form class="dr-body">
        <label class="fld"><span>Item</span><input name="name" maxlength="160" required placeholder="e.g. Birthday cake"></label>
        <div class="grid3">
          <label class="fld"><span>Price (${esc(CUR)})</span><input type="number" name="price" min="0" step="1" required></label>
          <label class="fld"><span>How many</span><input type="number" name="qty" min="1" max="999" step="1" value="1" required></label>
          <label class="fld"><span>Category</span><select name="categoryId">${opts}<option value="">Other</option></select></label>
        </div>
        <label class="fld"><span>Note <small>(optional)</small></span><input name="note" maxlength="255"></label>
        <div class="dlg-foot"><button type="button" class="abtn" data-mclose>Cancel</button><button class="abtn abtn--primary" type="submit">${icon('a-plus', 16)}Add to the bill</button></div>
      </form>`);
    modalPanel.onsubmit = (e) => {
      e.preventDefault();
      const f = e.target;
      const bid = SEL;
      const line = { name: f.name.value.trim(), price: Math.round(Number(f.price.value)) || 0, qty: Math.round(Number(f.qty.value)) || 1, categoryId: f.categoryId.value ? Number(f.categoryId.value) : null, note: f.note.value };
      closeModal();
      saveTaps();
      run(async () => {
        const r = await api(`/pos/folios/${bid}/charges`, { method: 'POST', body: { lines: [line] } });
        setFolio(r.folio);
        changed(bid);
        toast(`${line.name} added`);
      });
    };
  }

  // ---------- payment ----------
  async function payDialog() {
    if (!SEL) return;
    await saveTaps();
    const f = FOLIO;
    if (!f) return;
    const b = f.booking;
    openModal(`${modalHead('Take a payment', `${b.guestName} · ${placeOf(b)}. It counts towards the final bill.`)}
      <form class="dr-body">
        <div class="grid2">
          <label class="fld"><span>Amount (${esc(CUR)})</span><input type="number" name="amount" min="1" step="1" value="${f.totals.balance > 0 ? f.totals.balance : ''}" required></label>
          <label class="fld"><span>Paid by</span><select name="method">${methodOptions('cash')}</select></label>
          ${S.payFieldsHtml(payFields())}
          <label class="fld span2"><span>Note <small>(optional)</small></span><input name="note" maxlength="255" placeholder="e.g. Part payment"></label>
        </div>
        <div class="dlg-foot"><span class="src">Balance now ${money(f.totals.balance)}</span><button class="abtn abtn--primary" type="submit">${icon('a-cash', 16)}Record payment</button></div>
      </form>`);
    S.bindPayFields(modalPanel, $('[name="method"]', modalPanel));
    modalPanel.onsubmit = (e) => {
      e.preventDefault();
      const fm = e.target;
      const p = { amount: Math.round(Number(fm.amount.value)) || 0, method: fm.method.value, note: fm.note.value, ...S.readPayFields(fm, payFields()) };
      closeModal();
      run(async () => {
        const r = await api(`/pos/folios/${b.id}/payments`, { method: 'POST', body: p });
        setFolio(r.folio);
        changed(b.id);
        toast(`${money(p.amount)} recorded`);
        receiptOf(r.folio); // print or share the receipt
      });
    };
  }

  // ---------- move items to another guest ----------
  async function moveDialog(onlyKey = null) {
    if (!SEL) return;
    await saveTaps();
    const f = FOLIO;
    if (!f || !f.lines.length) return;
    const targets = chargeable().filter((x) => x.booking.id !== f.booking.id);
    if (!targets.length) { toast('There is no other checked-in guest to move items to.', true); return; }
    openModal(`${modalHead('Move items to another guest', `From ${f.booking.guestName} · ${placeOf(f.booking)}`)}
      <form class="dr-body">
        <label class="fld"><span>Move to</span><select name="to" required>${targets.map((x) => `<option value="${x.booking.id}">${esc(x.booking.guestName)} — ${esc(placeOf(x.booking))}</option>`).join('')}</select></label>
        <div class="mv-lines">${f.lines.map((l, i) => `<label class="mv-line"><span><b>${esc(l.name)}</b><small>${money(l.unitPrice)} · ${l.qty} on the bill</small></span>
          <input class="inp" type="number" name="q${i}" min="0" max="${l.qty}" step="1" value="${onlyKey === null || lineKey(l.category, l.name, l.unitPrice) === onlyKey ? l.qty : 0}" aria-label="How many ${esc(l.name)} to move"></label>`).join('')}</div>
        <div class="dlg-foot"><span class="src">The move shows on both bills.</span><button class="abtn abtn--primary" type="submit">${icon('a-move', 16)}Move</button></div>
      </form>`);
    modalPanel.onsubmit = (e) => {
      e.preventDefault();
      const fm = e.target;
      const to = Number(fm.to.value);
      // each line's quantity comes from its newest charges first
      const lines = [];
      f.lines.forEach((l, i) => {
        let left = Math.max(0, Math.min(l.qty, Math.round(Number(fm[`q${i}`].value)) || 0));
        f.charges.filter((c) => l.chargeIds.includes(c.id)).sort((a, b) => b.id - a.id).forEach((c) => {
          if (left <= 0) return;
          const n = Math.min(left, c.qty);
          lines.push({ chargeId: c.id, qty: n });
          left -= n;
        });
      });
      if (!lines.length) { toast('Choose how many of each item to move.', true); return; }
      closeModal();
      run(async () => {
        const r = await api('/pos/transfer', { method: 'POST', body: { from: f.booking.id, to, lines } });
        setFolio(r.from);
        setFolio(r.to);
        changed(f.booking.id);
        changed(to);
        toast(`Moved to ${r.to.booking.guestName} — ${placeOf(r.to.booking)}`);
      });
    };
  }

  // ---------- history and booking details ----------
  async function historyDialog() {
    if (!SEL) return;
    await saveTaps();
    const f = FOLIO;
    if (!f) return;
    openModal(`${modalHead('History', `${f.booking.guestName} · ${f.booking.ref}`)}
      <div class="dr-body">
        ${f.log.length ? `<ul class="hist">${f.log.map((l) => `<li><span>${esc(l.detail)}</span><small>${fdt(l.at)} · ${esc(l.by)}${l.amount !== null ? ` · ${l.amount < 0 ? '− ' : ''}${money(Math.abs(l.amount))}` : ''}</small></li>`).join('')}</ul>` : '<div class="empty">Nothing yet.</div>'}
        ${f.payments.filter((p) => !p.voided).length ? `<p class="section-title">Payments</p><ul class="hist">${f.payments.filter((p) => !p.voided).map((p) => `<li><span>${money(p.amount)} · ${esc(p.methodLabel)}${[...(p.details || []).map((d) => `${d.label}: ${d.value}`), p.note, p.fee ? `${money(p.fee)} kept by the agent or bank — ${money(p.net)} reached the account` : ''].filter(Boolean).map((s) => ` · ${esc(s)}`).join('')}</span><small>${esc(p.receiptNo)} · ${fdt(p.createdAt)} · ${esc(p.createdBy)} · <button type="button" class="linkbtn" data-receipt="${p.id}">receipt</button>${can('void') ? ` · <button type="button" class="linkbtn" data-void-pay="${p.id}">cancel this payment</button>` : ''}</small></li>`).join('')}</ul>` : ''}
      </div>`, 'modal--wide');
    modalPanel.onclick = (e) => {
      const rc = e.target.closest('[data-receipt]');
      if (rc) { const p = f.payments.find((x) => x.id === Number(rc.dataset.receipt)); if (p) showReceipt(p, f.booking); return; }
      const vp = e.target.closest('[data-void-pay]');
      if (!vp || !confirm('Cancel this payment? It stays in the history.')) return;
      closeModal();
      run(async () => {
        const r = await api(`/pos/payments/${vp.dataset.voidPay}/void`, { method: 'POST', body: {} });
        setFolio(r.folio);
        changed(r.folio.booking.id);
        toast('Payment cancelled');
      });
    };
  }

  function guestDialog() {
    const v = billView();
    const b = v.f ? v.f.booking : v.entry.booking;
    openModal(`${modalHead(b.guestName, `${b.ref} · ${b.sourceLabel}`)}
      <div class="dr-body">
        <div class="kv">
          <div><small>${b.kind === 'room' ? 'Room' : 'Booking'}</small>${esc(b.kind === 'room' ? b.roomName : b.packageLabel)}</div>
          ${b.kind === 'per_person' ? `<div><small>Rooms</small>${esc(b.roomName || 'None yet')}</div>` : ''}
          ${b.kind === 'event' ? `<div><small>Party</small>${esc(b.packageLabel)}${b.arrivalTime ? ` · ${esc(b.arrivalTime)}` : ''}</div><div><small>Other guests</small>${esc(b.eventOthersLabel)}</div>${b.roomName ? `<div><small>Rooms held</small>${esc(b.roomName)}</div>` : ''}` : ''}
          <div><small>Dates</small>${esc(whenText(b))}</div>
          <div><small>Guests</small>${esc(guestsText(b))}</div>
          ${extrasOf(b).length ? `<div><small>Extras</small>${esc(extrasOf(b).join(', '))}</div>` : ''}
          ${b.guestPhone ? `<div><small>Phone</small><a href="tel:${esc(b.guestPhone)}">${esc(b.guestPhone)}</a></div>` : ''}
          ${b.guestIdNo ? `<div><small>NIC / passport</small>${esc(b.guestIdNo)}</div>` : ''}
        </div>
        ${b.specialRequests ? `<div class="note note--info"><b>Requests:</b> ${esc(b.specialRequests)}</div>` : ''}
        ${b.adminNotes ? `<div class="note note--info"><b>Notes:</b> ${esc(b.adminNotes)}</div>` : ''}
        <div class="dlg-foot">${can('bookings') ? `<a class="abtn" href="/admin/#/bookings/${b.id}" target="ydadmin">${icon('a-ext', 15)}Open the booking</a>` : ''}
          ${b.kind === 'per_person' && b.status === 'checked_in' ? '<button type="button" class="abtn" data-rooms>Change rooms</button>' : ''}</div>
      </div>`);
    modalPanel.onclick = async (e) => {
      if (!e.target.closest('[data-rooms]')) return;
      let choices;
      try { choices = (await api(`/bookings/${b.id}/rooms`)).rooms; } catch (ex) { toast(ex.message, true); return; }
      openModal(`${modalHead(`Rooms for ${b.guestName}`, `${b.packageLabel} · ${guestsText(b)}`)}
        <form class="dr-body">${roomPicker(choices)}
          <div class="dlg-foot"><button type="button" class="abtn" data-mclose>Cancel</button><button class="abtn abtn--primary" type="submit">Save rooms</button></div></form>`);
      const form = $('form', modalPanel);
      bindPicker(form, () => b.adults + b.children);
      modalPanel.onsubmit = async (ev) => {
        ev.preventDefault();
        const rooms = pickedRooms(form);
        if (!rooms.length) { toast('Tick at least one room.', true); return; }
        try {
          await api(`/bookings/${b.id}/rooms`, { method: 'POST', body: { rooms } });
          closeModal();
          run(async () => { const r = await api(`/pos/folios/${b.id}`); setFolio(r.folio); changed(b.id); });
          toast('Rooms saved');
        } catch (ex) { toast(ex.message, true); }
      };
    };
  }

  // ---------- check out ----------
  async function openCheckout() {
    if (!SEL) return;
    const id = SEL;
    await saveTaps();
    try {
      const [{ folio }] = await Promise.all([api(`/pos/folios/${id}`), loadBoard()]);
      setFolio(folio);
      changed(id);
    } catch (ex) { toast(ex.message, true); return; }
    const f = FOLIO;
    if (!f || !f.open) { toast('This bill is already closed.', true); return; }
    const b = f.booking;
    const t = f.totals;
    const others = chargeable().filter((x) => x.booking.id !== b.id);
    const catOpts = `<option value="">Other</option>${CATS.filter((c) => c.id).map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}`;
    const lineRow = () => `<div class="co-line" data-co-line><input class="inp" name="ln" maxlength="160" placeholder="e.g. Late check-out" aria-label="Line"><input class="inp" type="number" name="la" min="0" step="1" placeholder="${esc(CUR)}" aria-label="Amount"><select class="inp" name="lc" aria-label="Category">${catOpts}</select><button type="button" class="abtn abtn--sm abtn--ghost" data-co-rm aria-label="Remove">${icon('a-x', 14)}</button></div>`;
    let payN = 0;
    const payRow = (amount) => {
      const pre = `p${++payN}_`; // each payment row has its own extra fields (Booking setup)
      return `<div class="co-pay-wrap" data-co-pay data-pre="${pre}"><div class="co-pay"><select class="inp" name="pm" aria-label="Paid by">${methodOptions('cash')}</select><input class="inp" type="number" name="pa" min="0" step="1" value="${amount}" aria-label="Amount"><button type="button" class="abtn abtn--sm abtn--ghost" data-co-rm aria-label="Remove">${icon('a-x', 14)}</button></div>
        ${payFields().length ? `<div class="co-pay__more grid2">${S.payFieldsHtml(payFields(), { fee: false, prefix: pre })}</div>` : ''}</div>`;
    };
    const bindPayRow = (row) => S.bindPayFields(row, $('[name="pm"]', row), row.dataset.pre);
    let lastCat = null;
    const itemRows = f.lines.map((l) => {
      const head = l.category !== lastCat ? `<tr class="co-cat"><td colspan="3">${esc(l.category)}</td></tr>` : '';
      lastCat = l.category;
      return `${head}<tr><td>${esc(l.name)}</td><td class="num">${l.qty} × ${money(l.unitPrice)}</td><td class="num">${money(l.amount)}</td></tr>`;
    }).join('');
    openModal(`${modalHead('Check out & bill', `${b.guestName} · ${placeOf(b)} · ${b.ref}`)}
      <form class="dr-body co">
        <table class="co-tbl">
          <tr><td><b>Stay</b><span class="sub">${esc(whenText(b))} · ${esc(guestsText(b))}</span></td><td></td><td class="num">${money(t.stay)}</td></tr>
          ${itemRows}
          <tbody data-co-more></tbody>
        </table>
        ${others.length ? `<details class="co-also"><summary>Put other guests on this bill</summary><div>
          ${others.map((x) => `<label class="chk"><input type="checkbox" name="also" value="${x.booking.id}"> ${esc(x.booking.guestName)} — ${esc(placeOf(x.booking))} <span class="src">${money(x.total)}${x.paid ? `, ${money(x.paid)} paid` : ''}</span></label>`).join('')}
        </div></details>` : ''}
        <div><p class="section-title">Extra lines</p><div class="co-lines" data-co-lines></div><button type="button" class="linkbtn" data-co-add-line>+ Add a line</button></div>
        <div class="co-disc"><p class="section-title">Discount</p>
          <div class="co-disc__row"><span class="seg" role="group" aria-label="Discount type"><button type="button" class="is-on" data-disc="amount">${esc(CUR)}</button><button type="button" data-disc="percent">%</button></span>
          <input class="inp" type="number" name="disc" min="0" step="1" placeholder="0" aria-label="Discount"><span class="src" data-disc-out></span></div></div>
        <div class="co-disc"><p class="section-title">Coupon <small>(room charge only — not food or drinks)</small></p>
          <div class="co-disc__row"><input class="inp co-coupon" name="coupon" maxlength="40" placeholder="Code" autocapitalize="characters" spellcheck="false" aria-label="Coupon code">
          <button type="button" class="abtn abtn--sm" data-co-coupon>${icon('a-tag', 15)}Apply</button><span class="src" data-coupon-out></span></div></div>
        ${servicePct() ? `<label class="chk"><input type="checkbox" name="service" checked> Add the ${servicePct()}% service charge</label>` : ''}
        <div class="co-sum" data-co-sum></div>
        <div><p class="section-title">Payment now</p><div class="co-pays" data-co-pays>${payRow(0)}</div><button type="button" class="linkbtn" data-co-split>+ Split the payment</button></div>
        <label class="fld"><span>Note on the bill <small>(optional)</small></span><input name="note" maxlength="1000"></label>
        ${b.guestEmail ? `<label class="chk"><input type="checkbox" name="emailGuest" checked> Email the bill to ${esc(b.guestEmail)}</label>` : ''}
        <div class="dlg-foot"><span data-co-bal></span><button class="abtn abtn--primary" type="submit">${icon('a-bill', 16)}Issue bill &amp; check out</button></div>
      </form>`, 'modal--wide');
    const form = $('form', modalPanel);
    $$('[data-co-pay]', form).forEach(bindPayRow);
    let discType = 'amount';
    let touched = false; // the first payment follows the balance until it is typed in
    let coupon = null;   // {code, label, discount} once applied
    const ids = () => [b.id, ...$$('[name="also"]:checked', form).map((x) => Number(x.value))];
    const extras = () => $$('[data-co-line]', form).map((r) => ({ name: $('[name="ln"]', r).value.trim(), amount: Math.round(Number($('[name="la"]', r).value)) || 0, categoryId: $('[name="lc"]', r).value ? Number($('[name="lc"]', r).value) : null }));
    const pays = () => $$('[data-co-pay]', form).map((r) => ({ method: $('[name="pm"]', r).value, amount: Math.round(Number($('[name="pa"]', r).value)) || 0, ...S.readPayFields(r, payFields(), r.dataset.pre) }));
    function sums() {
      const also = others.filter((x) => ids().includes(x.booking.id));
      const extra = extras().filter((l) => l.name && l.amount > 0).reduce((s, l) => s + l.amount, 0);
      const sub = t.stay + t.items + also.reduce((s, x) => s + x.stay + x.items, 0) + extra;
      const dv = Math.max(0, Math.round(Number(form.disc.value)) || 0);
      const disc = discType === 'percent' ? Math.round((sub * Math.min(100, dv)) / 100) : dv;
      const cpn = coupon ? coupon.discount : 0;
      // the service charge goes on what is left after the discount and the coupon
      const service = form.service && form.service.checked && disc + cpn <= sub ? serviceOn(sub - disc - cpn) : 0;
      const total = sub - disc - cpn + service;
      const paid = t.paid + also.reduce((s, x) => s + x.paid, 0);
      const now = pays().reduce((s, p) => s + p.amount, 0);
      return { also, extra, sub, dv, disc, cpn, service, total, paid, now, due: total - paid };
    }
    /** Check the coupon against the stays on this bill (the room charge only). */
    async function applyCoupon() {
      const code = form.coupon.value.trim();
      const out = $('[data-coupon-out]', form);
      coupon = null;
      if (!code) { out.textContent = ''; update(); return; }
      out.textContent = 'Checking…';
      try {
        coupon = (await api(`/pos/coupon?code=${encodeURIComponent(code)}&bookingIds=${ids().join(',')}`)).coupon;
        out.innerHTML = `<b class="is-ok">✓ ${esc(coupon.label)}: − ${money(coupon.discount)}</b>`;
      } catch (ex) { out.innerHTML = `<b class="is-due">${esc(ex.message)}</b>`; }
      update();
    }
    function update() {
      const s0 = sums();
      if (!touched) { const first = $('[name="pa"]', form); if (first) first.value = Math.max(0, s0.due - (s0.now - (Number(first.value) || 0))); }
      const s = sums();
      $('[data-co-more]', form).innerHTML = s.also.map((x) => `<tr><td>${esc(x.booking.guestName)} — ${esc(placeOf(x.booking))}<span class="sub">Stay ${money(x.stay)}${x.items ? ` + items ${money(x.items)}` : ''}</span></td><td></td><td class="num">${money(x.stay + x.items)}</td></tr>`).join('');
      $('[data-disc-out]', form).textContent = discType === 'percent' && s.dv ? `= ${money(s.disc)}` : '';
      $('[data-co-sum]', form).innerHTML = `<table class="co-tbl co-tbl--sum">
        ${s.extra || s.disc || s.cpn || s.service ? `<tr><td>Subtotal</td><td class="num">${money(s.sub)}</td></tr>` : ''}
        ${s.disc ? `<tr><td>Discount${discType === 'percent' ? ` (${s.dv}%)` : ''}</td><td class="num">− ${money(s.disc)}</td></tr>` : ''}
        ${s.cpn ? `<tr><td>Coupon ${esc(coupon.code)} <span class="sub">room charge</span></td><td class="num">− ${money(s.cpn)}</td></tr>` : ''}
        ${s.service ? `<tr><td>Service charge (${servicePct()}%)</td><td class="num">${money(s.service)}</td></tr>` : ''}
        <tr class="is-total"><td>Total</td><td class="num">${money(s.total)}</td></tr>
        ${s.paid ? `<tr><td>Paid already</td><td class="num">− ${money(s.paid)}</td></tr>` : ''}
        <tr><td>Paying now</td><td class="num">− ${money(s.now)}</td></tr></table>`;
      const bal = s.total - s.paid - s.now;
      $('[data-co-bal]', form).innerHTML = bal > 0 ? `<b class="is-due">Still due ${money(bal)}</b>` : bal < 0 ? `<b>Change ${money(-bal)}</b>` : '<b class="is-ok">Paid in full</b>';
    }
    update();
    form.addEventListener('input', (e) => {
      if (e.target.name === 'pa') touched = true;
      if (e.target.name === 'coupon' && coupon) { coupon = null; $('[data-coupon-out]', form).textContent = 'Press Apply'; }
      update();
    });
    form.addEventListener('change', (e) => { if (e.target.name === 'also' && coupon) applyCoupon(); else update(); });
    form.coupon.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); applyCoupon(); } });
    modalPanel.onclick = (e) => {
      const d = e.target.closest('[data-disc]');
      if (d) { discType = d.dataset.disc; $$('[data-disc]', form).forEach((x) => x.classList.toggle('is-on', x === d)); update(); return; }
      if (e.target.closest('[data-co-coupon]')) { applyCoupon(); return; }
      if (e.target.closest('[data-co-add-line]')) { $('[data-co-lines]', form).insertAdjacentHTML('beforeend', lineRow()); $('[data-co-lines] [data-co-line]:last-child [name="ln"]', form).focus(); return; }
      if (e.target.closest('[data-co-split]')) {
        const s = sums();
        touched = true;
        $('[data-co-pays]', form).insertAdjacentHTML('beforeend', payRow(Math.max(0, s.total - s.paid - s.now)));
        bindPayRow($('[data-co-pays] [data-co-pay]:last-child', form));
        update();
        return;
      }
      const rm = e.target.closest('[data-co-rm]');
      if (rm) { const row = rm.closest('[data-co-line], [data-co-pay]'); if (row.matches('[data-co-pay]')) touched = true; row.remove(); update(); }
    };
    modalPanel.onsubmit = async (e) => {
      e.preventDefault();
      const s = sums();
      const lines = extras();
      if (lines.some((l) => (l.name && !l.amount) || (!l.name && l.amount))) { toast('Give each extra line a name and an amount, or remove it.', true); return; }
      if (form.coupon.value.trim() && !coupon) { toast('Press Apply to use the coupon, or clear it.', true); form.coupon.focus(); return; }
      if (s.disc + s.cpn > s.sub) { toast('The discount is more than the bill.', true); return; }
      const payments = pays().filter((p) => p.amount > 0);
      const bal = s.total - s.paid - payments.reduce((a, p) => a + p.amount, 0);
      if (bal > 0 && !confirm(`${money(bal)} will still be due. Issue the bill with a balance due?`)) return;
      const btn = $('[type="submit"]', form);
      btn.disabled = true;
      try {
        const r = await api('/pos/checkout', {
          method: 'POST',
          body: {
            bookingIds: ids(), lines: lines.filter((l) => l.name && l.amount > 0),
            discount: { type: discType, value: s.dv }, serviceCharge: !!(form.service && form.service.checked), payments, note: form.note.value,
            coupon: coupon ? coupon.code : '', emailGuest: !!(form.emailGuest && form.emailGuest.checked),
          },
        });
        SEL = 0;
        FOLIO = null;
        await loadBoard();
        renderAll();
        showBill(r.bill, r.emailed);
      } catch (ex) { toast(ex.message, true); btn.disabled = false; }
    };
  }

  /**
   * After checkout: who served (the service charge is shared among them), then the bill — the same
   * window, page and printing as invoices and receipts.
   */
  function showBill(bill, emailed) {
    const h = { openModal, modalHead, toast, api, closeModal, me: () => ME };
    const open = () => S.billDialog(h, bill, money, {
      sub: `${bill.guestName} — checked out · ${money(bill.total)}${emailed ? ' · emailed to the guest' : ''}`,
      extra: `<a class="abtn abtn--ghost" href="/admin/#/bills/${bill.id}" target="ydadmin">${icon('a-bill', 15)}Bills</a>`,
    });
    toast(`Bill ${bill.billNo} issued`);
    if (bill.status === 'issued' && bill.serviceCharge > 0) S.serviceDialog(h, bill, money, open);
    else open();
  }

  // ---------- keep up with other devices ----------
  setInterval(() => {
    if (!ME || document.hidden || modal.classList.contains('is-open') || queued.size || sending.size) return;
    run(async () => {
      await loadBoard();
      if (SEL) { const r = await api(`/pos/folios/${SEL}`); setFolio(r.folio); }
      renderAll();
    });
  }, 45000);

  start().catch(() => showLogin());
})();
