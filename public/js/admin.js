/* Top Mountain Resort admin panel — small hash-routed app, no framework. */
(function () {
  'use strict';
  const BOOT = window.BOOT || {};
  let MEDIA = BOOT.media || {};
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icon = (id, size = 17) => `<svg width="${size}" height="${size}" aria-hidden="true"><use href="#${id}"/></svg>`;
  const S = window.YDStaff; // printing, payment fields, invoices and receipts (staff.js)
  S.onPrintTip((msg) => toast(msg));

  let ME = null;
  /** What the signed-in login may use (Auth::ROLES on the server, which checks every request too). */
  const can = (perm) => !!(ME && Array.isArray(ME.perms) && ME.perms.includes(perm));
  let ROOMS = [];
  let CATS = []; // room categories (the rate sheet), some with a whole-villa price
  const STATUS = { pending: 'Pending', confirmed: 'Confirmed', checked_in: 'Checked in', checked_out: 'Checked out', cancelled: 'Cancelled' };

  // ---------- utils ----------
  const money = (n) => `${(ME && ME.currency) || 'LKR'} ${Number(n || 0).toLocaleString('en-US')}`;
  const toUTC = (s) => { const [y, m, d] = s.split('-').map(Number); return Date.UTC(y, m - 1, d); };
  const addDays = (s, n) => new Date(toUTC(s) + n * 86400000).toISOString().slice(0, 10);
  const diff = (a, b) => Math.round((toUTC(b) - toUTC(a)) / 86400000);
  const fd = (s, o = { day: 'numeric', month: 'short' }) => (s ? new Date(toUTC(s)).toLocaleDateString('en-GB', { timeZone: 'UTC', ...o }) : '—');
  const fdt = (iso) => new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  const today = () => (ME && ME.today) || BOOT.today;
  const st = (s) => `<span class="st st--${esc(s)}">${esc(STATUS[s] || s)}</span>`;
  const srcLabel = (s) => (ME && ME.sources[s]) || s;
  /** guests booked; a room booking may have none noted, then what the room sleeps */
  const guests = (b) => (b.source === 'block' ? 'closed'
    : b.adults || b.children ? `${b.adults} ad${b.children ? ` + ${b.children} ch` : ''}` : b.capacity ? `sleeps ${b.capacity}` : '—');
  const isDayOut = (b) => b.kind === 'day_out';
  const isGroup = (b) => b.kind === 'per_person';
  const isParty = (b) => b.kind === 'event'; // an outdoor party (admins and managers book them)
  const extrasOf = (b) => [b.partyArea && 'Party area', b.jblSpeaker && 'JBL speaker'].filter(Boolean);
  /** "Room 101", "Full board · Room 101, Room 102" or "Day out" */
  const whatOf = (b) => (b.kind === 'room' ? b.roomName : isDayOut(b) ? b.packageLabel : isParty(b) ? `${b.packageLabel}${b.roomName ? ` · rooms ${b.roomName}` : ''}` : `${b.packageLabel} · ${b.roomName || 'no rooms yet'}`);
  const whenOf = (b) => (isDayOut(b) || isParty(b) ? `${fd(b.checkIn)}${isParty(b) && b.arrivalTime ? ` · ${b.arrivalTime}` : ''}` : `${fd(b.checkIn)} → ${fd(b.checkOut)} · ${b.nights}n`);
  /** <select> value for what a booking is: "room", "pkg:<option id>" or "event" (an outdoor party) */
  const kindValue = (b) => (b.kind === 'room' ? 'room' : isParty(b) ? 'event' : `pkg:${b.packageId}`);
  const kindOptions = (sel) => {
    const pkg = (p) => `<option value="pkg:${p.id}" ${sel === `pkg:${p.id}` ? 'selected' : ''}>${esc(p.name)} — ${p.kind === 'day' ? 'day visit' : 'priced per person'}</option>`;
    const shown = ME.packages.filter((p) => p.active);
    const hidden = ME.packages.filter((p) => !p.active);
    return `<option value="room" ${sel === 'room' ? 'selected' : ''}>Rooms — choose rooms and a meal plan (RO / BB / HB / FB)</option>`
      + (shown.length ? `<optgroup label="Per person">${shown.map(pkg).join('')}</optgroup>` : '')
      + `<option value="event" ${sel === 'event' ? 'selected' : ''}>Outdoor party — day or night</option>`
      + (hidden.length ? `<optgroup label="Per person — not offered on the website">${hidden.map(pkg).join('')}</optgroup>` : '');
  };
  /** {kind, packageId} from that <select> value */
  const parseKind = (v) => {
    if (v === 'event') return { kind: 'event', packageId: null };
    if (v === 'room' || !v) return { kind: 'room', packageId: null };
    const p = ME.packages.find((x) => `pkg:${x.id}` === v);
    return { kind: p && p.kind === 'day' ? 'day_out' : 'per_person', packageId: p ? p.id : null };
  };
  /** Query for /check from a booking form (new booking or the drawer). */
  function checkQuery(form, exclude) {
    const { kind, packageId } = parseKind(form.what ? form.what.value : 'room');
    const num = (el) => (el && !el.closest('[hidden]') ? el.value || 0 : 0);
    const p = new URLSearchParams({ kind, checkIn: form.checkIn.value, adults: num(form.adults), children: num(form.children) });
    if (packageId) p.set('packageId', packageId);
    if (kind === 'room') p.set('roomId', form.roomId.value);
    if (kind === 'room' && form.mealPlan && !form.mealPlan.closest('[hidden]')) p.set('mealPlan', form.mealPlan.value);
    if (kind === 'room' && form.wholeCategory && form.wholeCategory.value && !form.wholeCategory.closest('[hidden]')) p.set('wholeCategory', form.wholeCategory.value);
    if (kind === 'event') p.set('eventOthers', partyBody(form).eventOthers); // a party: what other guests can book then
    if (kind !== 'day_out' && kind !== 'event') p.set('checkOut', form.checkOut.value);
    if (kind !== 'day_out' && kind !== 'event' && form.partyArea && form.partyArea.checked && !form.partyArea.closest('[hidden]')) p.set('partyArea', '1');
    if (form.jblSpeaker && form.jblSpeaker.checked && !form.jblSpeaker.closest('[hidden]')) p.set('jblSpeaker', '1');
    if (kind !== 'event') S.rateParams(form, kind, p); // rates agreed with the guest (empty = the price list)
    if (exclude) p.set('exclude', exclude);
    return p;
  }

  // ---------- outdoor parties: day or night, and what other guests can book then ----------
  const PARTY_SLOTS = { day: 'Day party', night: 'Night party', full: 'Day & night party' };
  const PARTY_OTHERS = [
    ['open', 'Open to others', 'Rooms and day visits can still be booked by other guests.'],
    ['rooms', 'No other stays', 'No other guests stay that night. Day visits stay open.'],
    ['private', 'Whole resort private', 'No other stays and no day visits — the website shows the day as closed.'],
  ];
  const partyFields = (b = {}) => `<div class="party-f" data-event-f hidden>
      <div class="party-f__row">
        <div class="fld"><span>Party</span><div class="seg seg--wide" role="radiogroup" aria-label="Party">${Object.entries(PARTY_SLOTS).map(([k, l]) => `<label><input type="radio" name="eventSlot" value="${k}" ${(b.eventSlot || 'night') === k ? 'checked' : ''}> ${esc(l)}</label>`).join('')}</div></div>
        <label class="fld"><span>Time <small>(optional)</small></span><input name="arrivalTime" maxlength="40" value="${esc(b.arrivalTime || '')}" placeholder="e.g. 7 pm – 1 am"></label>
      </div>
      <div class="fld"><span>Other guests during the party</span><div class="others-pick" role="radiogroup" aria-label="Other guests">${PARTY_OTHERS.map(([k, l, d]) => `<label class="others-pick__item"><input type="radio" name="eventOthers" value="${k}" ${(b.eventOthers || 'open') === k ? 'checked' : ''}><span><b>${esc(l)}</b><small>${esc(d)}</small></span></label>`).join('')}</div></div>
    </div>`;
  const partyBody = (form) => ({
    eventSlot: (form.querySelector('[name="eventSlot"]:checked') || {}).value || 'night',
    eventOthers: (form.querySelector('[name="eventOthers"]:checked') || {}).value || 'open',
    arrivalTime: form.arrivalTime ? form.arrivalTime.value : '',
  });
  /** What a /check answer says about a party: the party area, rooms held, and bookings already then. */
  const partyCheckText = (r, form, picked) => {
    const b = partyBody(form);
    const when = fd(form.checkIn.value, { weekday: 'long', day: 'numeric', month: 'long' });
    let msg = `✓ ${PARTY_SLOTS[b.eventSlot]} on ${when} in the party area.`;
    if (picked.length) msg += r.ok ? ` ${roomNames(picked)} held for party guests who stay.` : ' ✕ Some of the ticked rooms are taken that night.';
    if (r.alreadyBooked && r.alreadyBooked.length) {
      msg += ` ⚠ ${r.alreadyBooked.length} booking${r.alreadyBooked.length === 1 ? ' is' : 's are'} already on that ${b.eventSlot === 'day' ? 'day' : 'night'}: ${r.alreadyBooked.map((x) => `${x.guestName} (${x.ref})`).join(', ')} — they stay as they are; move them if needed.`;
    }
    return msg;
  };

  // rates agreed with the guest — the room's per night, or per adult / child; empty = the price list (staff.js, shared with POS walk-ins)
  const rateFields = (rates = {}, list = {}) => S.rateFieldsHtml(money, rates, list);
  // a room booking's meal plan (RO, BB, HB, FB) and an optional whole villa: every room of a category at one price (Rate sheet)
  const PLANS = () => (ME && ME.mealPlans && ME.mealPlans.length ? ME.mealPlans : [{ code: 'RO', name: 'Room only' }]);
  const planName = (code) => (PLANS().find((p) => p.code === code) || {}).name || code;
  const planFields = (plan = 'RO', wholeId = null) => `
    <label class="fld" data-plan-f><span>Meal plan</span><select name="mealPlan">${PLANS().map((p) => `<option value="${esc(p.code)}" ${p.code === plan ? 'selected' : ''}>${esc(p.code)} — ${esc(p.name)}</option>`).join('')}</select></label>
    <label class="fld" data-plan-f><span>Whole villa <small>(optional)</small></span><select name="wholeCategory"><option value="">No — rooms one by one</option>${CATS.filter((c) => c.whole || c.id === wholeId).map((c) => `<option value="${c.id}" ${c.id === wholeId ? 'selected' : ''}>${esc(c.name)} — ${esc(c.whole ? c.whole.label : 'whole')}</option>`).join('')}</select></label>`;
  const syncRates = (form, kind, r) => S.syncRates(form, kind, r, money);
  const rateBody = (form, kind) => S.rateBody(form, kind);

  /** Commission an agent or website takes: none, a % or an amount, and who takes it. */
  const commissionFields = (c = {}) => `
    <label class="fld"><span>Agent commission <small>(an agent or website that sold it)</small></span><span class="pair"><select name="commissionType" aria-label="Commission type"><option value="">None</option><option value="percent" ${c.type === 'percent' ? 'selected' : ''}>%</option><option value="amount" ${c.type === 'amount' ? 'selected' : ''}>${esc(ME.currency)}</option></select><input type="number" name="commissionValue" min="0" step="1" value="${c.type ? c.value : ''}" aria-label="Commission"></span><small data-comm-hint></small></label>
    <label class="fld"><span>Agent or website</span><input name="commissionTo" maxlength="120" value="${esc(c.to || '')}" placeholder="e.g. Booking.com, Kamal Tours"></label>`;
  const commissionBody = (form) => ({ commissionType: form.commissionType.value, commissionValue: form.commissionValue.value === '' ? 0 : Number(form.commissionValue.value), commissionTo: form.commissionTo.value });
  /** "Kamal Tours earns LKR 3,600 · you keep LKR 20,400" under the commission fields */
  const commissionHint = (form) => {
    const el = $('[data-comm-hint]', form);
    if (!el) return;
    const total = Number(form.totalAmount.value) || 0;
    const v = Number(form.commissionValue.value) || 0;
    const type = form.commissionType.value;
    form.commissionValue.disabled = !type;
    const amount = type === 'percent' ? Math.round((total * Math.min(100, v)) / 100) : type === 'amount' ? v : 0;
    const who = (form.commissionTo && form.commissionTo.value.trim()) || 'The agent';
    el.textContent = type && v ? `${who} earns ${money(amount)} · you keep ${money(total - amount)}. The guest still pays the full price. If the agent keeps it from a payment, note it on that payment.`
      : 'None. The guest pays the full price either way.';
  };
  const roomNames = (ids) => ids.map((id) => (ROOMS.find((r) => r.id === id) || {}).name || `#${id}`).join(', ');
  const capacityText = (r) => (r.capacity && r.capacity.over
    ? ` ⚠ Over the hotel's capacity: only ${Math.max(0, r.capacity.left)} guest place${r.capacity.left === 1 ? '' : 's'} left for those nights, and this needs ${r.capacity.needed}. You can still save it.` : '');
  // days the whole resort is closed (Calendar → Close days): staff can still book them, with a warning
  const CLOSE_REASONS = { private: 'Private booking', repairs: 'Closed for repairs', closed: 'Closed' };
  const daySpan = (a, b) => (a === b ? fd(a, { weekday: 'short', day: 'numeric', month: 'short' }) : `${fd(a, { weekday: 'short', day: 'numeric', month: 'short' })} – ${fd(b, { weekday: 'short', day: 'numeric', month: 'short' })}`);
  const closedText = (r) => (r.closed && r.closed.length
    ? ` ⚠ The resort is closed on ${daySpan(r.closed[0].date, r.closed[r.closed.length - 1].date)} (${r.closed[0].label.toLowerCase()}) — the website takes no bookings then. You can still save this one.` : '');
  const partyTakenText = (r) => (r.partyArea && !r.partyArea.free ? `The party area is already booked on ${r.partyArea.taken.map((s) => fd(s, { weekday: 'short', day: 'numeric', month: 'short' })).join(', ')}.` : '');
  const rackRate = (r) => S.priceLine(r, money);
  const img = (key, w = 640) => {
    const m = MEDIA[key];
    if (!m) return '';
    const size = m.sizes.reduce((best, s) => (Math.abs(s - w) < Math.abs(best - w) ? s : best), m.sizes[0]);
    return `${m.base || `/media/${key}`}-${size}.webp`; // the website's own photos say where they are
  };
  const waLink = (phone) => `https://wa.me/${String(phone || '').replace(/\D/g, '').replace(/^0/, '94')}`;
  /** "held until 24 Nov · 6 days left" for a reservation waiting for its advance */
  const holdText = (b) => {
    if (b.status !== 'pending' || !b.holdUntil || b.source === 'block') return '';
    const left = diff(today(), b.holdUntil);
    return `held until ${fd(b.holdUntil)} · ${left <= 0 ? 'last day' : `${left} day${left === 1 ? '' : 's'} left`}`;
  };
  /** The default hold date for a new reservation: Booking setup's days, but not past the arrival. */
  const defaultHold = (checkIn) => {
    const days = (ME && ME.holdDays) || 0;
    if (!days) return '';
    const d = addDays(today(), days);
    return checkIn && checkIn >= today() && d > checkIn ? checkIn : d;
  };

  // ---------- the advance a booking asks for (Booking setup's rule, or the booking's own) ----------
  /** Booking setup's advance in words: "30% of the total", "LKR 10,000", "the whole total" or "no advance". */
  const ruleText = () => {
    const r = (ME && ME.advanceRule) || {};
    if (!r.on) return 'no advance';
    if (r.type === 'amount') return r.amount ? money(r.amount) : 'the whole total';
    return r.pct ? `${r.pct}% of the total` : 'the whole total';
  };
  /** A booking's advance (b.advance) in words, for the booking drawer. */
  const advanceText = (a) => (!a || !a.required ? 'None — pays at the resort'
    : `${money(a.amount)}${a.full ? ' · the whole total' : a.pct !== null ? ` · ${a.pct}%` : ''}${a.source === 'booking' ? ' · set for this booking' : ''}`);
  /** The Advance field of the booking forms: as in Booking setup, none, or an amount for this booking. own: null | 0 | amount. */
  const advanceFields = (own = null) => {
    const mode = own === null || own === undefined ? 'default' : own > 0 ? 'own' : 'none';
    return `<div class="adv-row span2" data-adv-wrap>
      <label class="fld"><span>Advance</span><select name="advanceMode">
        <option value="default" ${mode === 'default' ? 'selected' : ''}>As in Booking setup (${esc(ruleText())})</option>
        <option value="none" ${mode === 'none' ? 'selected' : ''}>No advance — pays at the resort</option>
        <option value="own" ${mode === 'own' ? 'selected' : ''}>An amount for this booking</option>
      </select></label>
      <label class="fld" data-adv-own ${mode === 'own' ? '' : 'hidden'}><span>Advance (${esc(ME.currency)})</span><input type="number" name="advanceAmount" min="1" step="1" value="${mode === 'own' ? own : ''}"><small>Never more than the total</small></label>
    </div>`;
  };
  /** Whether the advance chosen in a booking form asks for one (a reservation with none isn't held). */
  const advanceNeeded = (form) => {
    const m = form.advanceMode ? form.advanceMode.value : 'default';
    return m === 'own' || (m === 'default' && !!(ME.advanceRule && ME.advanceRule.on));
  };
  /** The form's advance for the API: '' = as in Booking setup, 0 = none, or the amount. */
  const advanceBody = (form) => {
    const m = form.advanceMode.value;
    if (m === 'default') return '';
    if (m === 'none') return 0;
    return Math.round(Number(form.advanceAmount.value)) || 0;
  };

  function toast(msg, bad = false) {
    const t = document.createElement('div');
    t.className = `toast${bad ? ' toast--bad' : ''}`;
    t.textContent = msg;
    $('[data-toasts]').appendChild(t);
    setTimeout(() => t.remove(), bad ? 6000 : 3500);
  }

  // ---------- dialogs ----------
  const modal = $('[data-modal]');
  const modalPanel = $('[data-modal-panel]');
  function openModal(html, cls = '') {
    if (endSmall) endSmall(); // a small dialog still open answers "no"
    modalPanel.className = `drawer__panel modal${cls ? ` ${cls}` : ''}`;
    modalPanel.innerHTML = html;
    modalPanel.onsubmit = null;
    modalPanel.onclick = null;
    modalPanel.oninput = null;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    setTimeout(() => { const el = $('input:not([type="checkbox"]), select', modalPanel); if (el) el.focus(); }, 60);
  }
  function closeModal() {
    if (!modal.classList.contains('is-open')) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    modalPanel.innerHTML = '';
  }
  $('[data-modal-close]', modal).addEventListener('click', closeModal);
  modalPanel.addEventListener('click', (e) => {
    if (e.target.closest('[data-mclose]')) closeModal();
    const chip = e.target.closest('[data-chip]');
    if (chip) { const inp = $('[name="reason"]', modalPanel); if (inp) { inp.value = chip.textContent; inp.focus(); } }
  });
  window.addEventListener('hashchange', closeModal);
  const modalHead = (title, sub = '') => `<header class="dr-head"><div><h2 id="modal-title">${esc(title)}</h2>${sub ? `<p>${esc(sub)}</p>` : ''}</div>
    <button class="abtn abtn--ghost" type="button" data-mclose aria-label="Close">${icon('a-x', 20)}</button></header>`;
  const methodOptions = (sel = 'cash') => Object.entries(ME.methods || {}).map(([k, l]) => `<option value="${k}" ${k === sel ? 'selected' : ''}>${esc(l)}</option>`).join('');

  /** Ask for a reason (removing an item, voiding a bill…). */
  function reasonDialog({ title, sub, placeholder = '', chips = [], button, danger = false, submit }) {
    openModal(`${modalHead(title, sub)}<form class="dr-body">
      ${chips.length ? `<div class="chips-row">${chips.map((c) => `<button type="button" class="abtn abtn--sm" data-chip>${esc(c)}</button>`).join('')}</div>` : ''}
      <label class="fld"><span>Reason <small>(kept in the history)</small></span><input name="reason" maxlength="255" required placeholder="${esc(placeholder)}"></label>
      <div class="dlg-foot"><button type="button" class="abtn" data-mclose>Cancel</button><button class="abtn ${danger ? 'abtn--danger' : 'abtn--primary'}" type="submit">${esc(button)}</button></div>
    </form>`);
    modalPanel.onsubmit = async (e) => {
      e.preventDefault();
      const reason = e.target.reason.value.trim();
      if (!reason) return;
      try { await submit(reason); closeModal(); } catch (ex) { toast(ex.message, true); }
    };
  }

  /**
   * The admin panel's own small dialogs, instead of the browser's prompt() and confirm() (which can't be
   * styled, show "localhost says" and block the page). Both resolve once: askDialog with the text typed or
   * null, confirmBox with true or false. Escape, the scrim and Cancel all count as "no".
   */
  const smallHead = (title, sub, ico, tone = '') => `<header class="dr-head dlg-head${tone ? ` dlg-head--${tone}` : ''}">
    <span class="dlg-badge" aria-hidden="true">${icon(ico, 18)}</span>
    <div><h2 id="modal-title">${esc(title)}</h2>${sub ? `<p>${esc(sub)}</p>` : ''}</div>
    <button class="abtn abtn--ghost" type="button" data-mclose aria-label="Close">${icon('a-x', 20)}</button></header>`;
  let endSmall = null; // the open small dialog's "cancel", so a newer dialog never leaves it hanging
  /** Open a small dialog; onSubmit(form) returns the answer, or undefined to keep it open (an error shows). */
  function smallDialog(html, onSubmit, focusSel) {
    const back = document.activeElement; // focus goes back here afterwards
    return new Promise((resolve) => {
      let done = false;
      const finish = (v) => {
        if (done) return;
        done = true;
        endSmall = null;
        modal.removeEventListener('click', onClose, true);
        document.removeEventListener('keydown', onKey, true);
        window.removeEventListener('hashchange', onHash);
        if (back && back.isConnected) back.focus({ preventScroll: true });
        resolve(v);
      };
      const onClose = (e) => { if (e.target.closest('[data-mclose], [data-modal-close]')) finish(null); };
      const onKey = (e) => { if (e.key === 'Escape') { e.preventDefault(); closeModal(); finish(null); } };
      const onHash = () => finish(null);
      openModal(html, 'modal--small');
      endSmall = () => finish(null);
      modal.addEventListener('click', onClose, true);
      document.addEventListener('keydown', onKey, true);
      window.addEventListener('hashchange', onHash);
      if (focusSel) setTimeout(() => { const el = $(focusSel, modalPanel); if (el) el.focus(); }, 70);
      modalPanel.onsubmit = (e) => {
        e.preventDefault();
        const v = onSubmit(e.target);
        if (v === undefined) return;
        closeModal();
        finish(v);
      };
    });
  }
  /**
   * Ask for one line of text. { title, sub, label, placeholder, value, hint, button, max, icon,
   * validate(text) → an error message or '' } → the text, or null when cancelled
   */
  function askDialog({ title, sub = '', label, placeholder = '', value = '', hint = '', button = 'Save', max = 80, icon: ico = 'a-plus', validate = null }) {
    const p = smallDialog(`${smallHead(title, sub, ico)}<form class="dr-body dlg-body" novalidate>
      <label class="fld"><span>${esc(label)}</span><input name="answer" maxlength="${max}" value="${esc(value)}" placeholder="${esc(placeholder)}" autocomplete="off" spellcheck="false" aria-describedby="dlg-err"></label>
      ${hint ? `<p class="dlg-hint">${esc(hint)}</p>` : ''}
      <p class="dlg-err" id="dlg-err" data-dlg-err role="alert" hidden></p>
      <div class="dlg-foot"><button type="button" class="abtn" data-mclose>Cancel</button><button class="abtn abtn--primary" type="submit">${icon(ico, 16)}${esc(button)}</button></div>
    </form>`, (form) => {
      const v = form.answer.value.trim();
      const problem = !v ? `Please enter ${label.toLowerCase()}.` : (validate ? validate(v) : '');
      if (!problem) return v;
      const err = $('[data-dlg-err]', modalPanel);
      err.textContent = problem;
      err.hidden = false;
      form.answer.setAttribute('aria-invalid', 'true');
      form.answer.focus();
      return undefined;
    });
    // typing clears the error
    modalPanel.oninput = () => {
      const err = $('[data-dlg-err]', modalPanel);
      if (!err || err.hidden) return;
      err.hidden = true;
      $('[name="answer"]', modalPanel).removeAttribute('aria-invalid');
    };
    return p;
  }
  /** Ask yes or no. { title, text, button, danger, icon } → true / false */
  async function confirmBox({ title, text = '', button = 'OK', danger = false, icon: ico }) {
    const yes = await smallDialog(`${smallHead(title, '', ico || (danger ? 'a-trash' : 'a-check'), danger ? 'danger' : '')}<form class="dr-body dlg-body">
      ${text ? `<p class="dlg-text">${esc(text)}</p>` : ''}
      <div class="dlg-foot"><button type="button" class="abtn" data-mclose>Cancel</button><button class="abtn ${danger ? 'abtn--danger-solid' : 'abtn--primary'}" type="submit" data-dlg-yes>${danger ? icon(ico || 'a-trash', 16) : ''}${esc(button)}</button></div>
    </form>`, () => true, '[data-dlg-yes]');
    return yes === true;
  }

  /**
   * Record a payment: amount, how it was paid, the extra payment fields (Booking setup), any
   * commission / fee taken off, and a note. submit(payment) may return a function to run once
   * the dialog has closed (e.g. showing the receipt).
   */
  function paymentDialog({ title, sub, suggested = 0, method = 'cash', submit, commission = null, guestSlips = [] }) {
    const fields = (ME && ME.paymentFields) || [];
    openModal(`${modalHead(title, sub)}<form class="dr-body">
      <div class="grid2">
        <label class="fld"><span>Amount (${esc((ME && ME.currency) || 'LKR')})</span><input type="number" name="amount" min="1" step="1" value="${suggested > 0 ? suggested : ''}" required></label>
        <label class="fld"><span>Paid by</span><select name="method">${methodOptions(method)}</select></label>
        ${S.payFieldsHtml(fields, { commission, money })}
        <label class="fld span2"><span>Note <small>(optional)</small></span><input name="note" maxlength="255" placeholder="e.g. Advance, bank slip 4411"></label>
        ${S.slipFieldHtml('Payment slip', { guestSlips, money })}
      </div>
      <div class="dlg-foot"><button type="button" class="abtn" data-mclose>Cancel</button><button class="abtn abtn--primary" type="submit">Record payment</button></div>
    </form>`);
    S.bindPayFields(modalPanel, $('[name="method"]', modalPanel));
    S.bindSlipField(modalPanel, $('[name="amount"]', modalPanel));
    modalPanel.onsubmit = async (e) => {
      e.preventDefault();
      const f = e.target;
      const btn = $('[type="submit"]', f);
      btn.disabled = true;
      try {
        const slip = await S.formSlip(f);
        const then = await submit({ amount: Number(f.amount.value), method: f.method.value, note: f.note.value, ...S.readPayFields(f, fields), slip });
        closeModal();
        if (typeof then === 'function') then();
      } catch (ex) { toast(ex.message, true); btn.disabled = false; }
    };
  }

  async function api(path, { method = 'GET', body, raw } = {}) {
    const opts = { method, credentials: 'same-origin', headers: {} };
    if (raw) { opts.body = raw.data; opts.headers['Content-Type'] = raw.type; opts.headers['X-Filename'] = encodeURIComponent(raw.name || 'photo'); }
    else if (body !== undefined) { opts.body = JSON.stringify(body); opts.headers['Content-Type'] = 'application/json'; }
    const res = await fetch(`/api/admin${path}`, opts);
    if (res.status === 401 && path !== '/login') { showLogin(); throw new Error('Please sign in.'); }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
    return data;
  }

  // ---------- auth ----------
  function showLogin() {
    $('[data-app]').hidden = true;
    $('[data-login]').hidden = false;
    closeDrawer();
    setTimeout(() => $('[data-login-form] input').focus(), 50);
  }
  $('[data-login-form]').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const err = $('[data-login-err]');
    err.hidden = true;
    try {
      const who = await api('/login', { method: 'POST', body: { username: f.username.value, password: f.password.value } });
      f.password.value = '';
      // a front-desk login works on the POS screen
      if (Array.isArray(who.perms) && !who.perms.includes('bookings') && who.perms.includes('pos')) { location.href = '/pos'; return; }
      await start();
    } catch (ex) { err.textContent = ex.message; err.hidden = false; }
  });
  $('[data-logout]').addEventListener('click', async () => {
    await api('/logout', { method: 'POST', body: {} }).catch(() => {});
    ME = null;
    showLogin();
  });

  async function start() {
    ME = await api('/me');
    const rr = can('bookings') ? await api('/rooms') : { rooms: [], categories: [] };
    ROOMS = rr.rooms;
    CATS = rr.categories || [];
    $('[data-login]').hidden = true;
    $('[data-app]').hidden = false;
    $('[data-user]').textContent = ME.name || ME.username;
    // only the pages this login can use
    $$('[data-perm]').forEach((el) => { el.hidden = !el.dataset.perm.split(' ').some(can); });
    route();
    refreshBadge();
  }

  async function refreshBadge() {
    if (!can('bookings')) { document.title = 'Admin — Top Mountain'; return; }
    try {
      const { bookings } = await api('/bookings?status=pending&limit=100');
      const b = $('[data-pending-badge]');
      b.hidden = !bookings.length;
      b.textContent = bookings.length;
      document.title = `${bookings.length ? `(${bookings.length}) ` : ''}Admin — Top Mountain`;
    } catch { /* ignore */ }
  }

  // ---------- mobile sidebar ----------
  $('[data-side-open]').addEventListener('click', () => document.body.classList.add('side-open'));
  $('[data-side-close]').addEventListener('click', () => document.body.classList.remove('side-open'));

  // ---------- router ----------
  const view = $('[data-view]');
  function setHead(title, sub = '') {
    $('[data-title]').textContent = title;
    $('[data-subtitle]').textContent = sub;
  }
  function parseHash() {
    const h = location.hash.replace(/^#/, '') || '/';
    const [path, qs] = h.split('?');
    return { path, q: new URLSearchParams(qs || '') };
  }
  let lastScreen = '';
  const PAGE_PERM = { overview: 'bookings', calendar: 'bookings', bookings: 'bookings', new: 'bookings', menu: 'menu', coupons: 'coupons', finance: 'finance', bills: 'pos', setup: 'setup', website: 'site', chatbot: 'site', rooms: 'setup', rates: 'setup', users: 'users' };
  async function route() {
    if (!ME) return;
    document.body.classList.remove('side-open');
    const { path, q } = parseHash();
    const parts = path.split('/').filter(Boolean);
    const name = parts[0] || 'overview';
    // a page this login can't use: front-desk logins live on the POS screen
    const need = PAGE_PERM[name];
    if (need && !can(need)) {
      if (name === 'overview' && !can('bookings') && can('pos')) { location.replace('/pos'); return; }
      $$('[data-nav-link]').forEach((a) => a.classList.remove('is-active'));
      setHead('Not available', '');
      view.innerHTML = `<div class="card"><div class="empty">Your login (${esc(ME.roleLabel || ME.role)}) can't open this page. Ask an admin if you need it.</div></div>`;
      return;
    }
    if (name !== lastScreen) window.scrollTo(0, 0); // a new page starts at the top
    lastScreen = name;
    $$('[data-nav-link]').forEach((a) => a.classList.toggle('is-active', a.dataset.navLink === name));
    view.innerHTML = '<div class="loading">Loading…</div>';
    view.onclick = view.oninput = view.onchange = view.onsubmit = view.onkeydown = null; // each screen sets its own
    try {
      if (name === 'overview') await viewOverview();
      else if (name === 'calendar') await viewCalendar(q);
      else if (name === 'bookings') { await viewBookings(); if (parts[1]) openBooking(Number(parts[1])); }
      else if (name === 'new') await viewNew(q);
      else if (name === 'pos') { location.href = parts[1] && parts[1] !== 'menu' ? `/pos?guest=${encodeURIComponent(parts[1])}` : parts[1] ? '#/menu' : '/pos'; return; } // old links → the POS screen
      else if (name === 'menu') await POS.menu();
      else if (name === 'coupons') await COUPONS.coupons();
      else if (name === 'users') await USERS.users();
      else if (name === 'finance') await FIN.show(parts[1]);
      else if (name === 'bills') await POS.bills(parts.slice(1), q);
      else if (name === 'setup') await SETUP.setup();
      else if (name === 'website') await SITE.website();
      else if (name === 'chatbot') await CHAT.chatbot();
      else if (name === 'rooms') await viewRooms();
      else if (name === 'rates') await RATES.rates();
      else if (name === 'settings') await viewSettings();
      else location.hash = '#/';
    } catch (e) {
      view.innerHTML = `<div class="card"><div class="empty">${esc(e.message)}</div></div>`;
    }
  }
  window.addEventListener('hashchange', route);

  // invoices and receipts: preview, print, copy the link, WhatsApp or email (staff.js)
  const docH = { openModal: (html, cls) => openModal(html, cls), modalHead, toast, api, me: () => ME };
  const showReceipt = (p, b) => S.receiptDialog(docH, p, b, money);
  const showInvoice = (b, url) => S.invoiceDialog(docH, b, url, money);

  // the POS menu + bills (admin-pos.js), booking setup (admin-setup.js), the website (admin-site.js) and coupons (admin-coupons.js)
  const helpers = {
    $, $$, esc, icon, money, fd, fdt, api, toast, setHead, view, st, today, openModal, closeModal, modalHead, methodOptions, reasonDialog, paymentDialog, askDialog, confirmBox,
    openLibrary, img, hasPhoto: (k) => !!MEDIA[k], showReceipt, showInvoice, can, fdt,
    currency: () => (ME && ME.currency) || 'LKR', me: () => ME, refreshMe: async () => { ME = await api('/me'); return ME; },
  };
  const POS = window.YDPOS(helpers);
  const SETUP = window.YDSETUP(helpers);
  const SITE = window.YDSITE(helpers);
  const CHAT = window.YDCHAT(helpers);
  const COUPONS = window.YDCOUPONS(helpers);
  const RATES = window.YDRATES(helpers); // the room rate sheet (admin-rates.js)
  const CONFIRM = window.YDCONFIRM(helpers); // links for guests to check and confirm a booking (admin-confirm.js)
  const USERS = window.YDUSERS(helpers);
  const FIN = window.YDFINANCE(helpers);

  // ---------- shared booking row ----------
  function bookingRow(b, { actions = false } = {}) {
    return `<li><div class="brow" role="button" tabindex="0" data-open="${b.id}">
      <div><b>${esc(b.guestName)}</b> <span class="src">· ${esc(b.ref)}</span>
        <div class="brow__meta">${esc(whatOf(b))} · ${whenOf(b)} · ${guests(b)}${extrasOf(b).length ? ` · ${esc(extrasOf(b).join(', '))}` : ''}${holdText(b) ? ` · <span class="hold">${esc(holdText(b))}</span>` : ''}</div></div>
      <div class="brow__right">${st(b.status)}${CONFIRM.chip(b)}${b.slipWaiting ? '<span class="gc-chip gc-chip--slip" title="The guest sent a payment slip that is not recorded yet">🧾 Slip sent</span>' : ''}<span class="src">${esc(b.sourceLabel)}</span></div>
      ${actions ? `<div class="brow__actions">
        <button class="abtn abtn--sm abtn--primary" type="button" data-quick="confirmed" data-id="${b.id}">Confirm</button>
        <button class="abtn abtn--sm abtn--danger" type="button" data-quick="cancelled" data-id="${b.id}">Decline</button>
        ${b.guestPhone ? `<a class="abtn abtn--sm" href="${waLink(b.guestPhone)}" target="_blank" rel="noopener">${icon('a-wa', 15)}WhatsApp</a>` : ''}
      </div>` : ''}
    </div></li>`;
  }
  // ---------- confirm (with the advance) and give a group its rooms ----------
  /** Checkbox list of rooms for a per-person group or a room booking: free ones selectable, taken ones shown. */
  function roomPicker(choices, need, title = 'Rooms for the group') {
    const list = choices.slice().sort((a, b) => Number(b.assigned) - Number(a.assigned) || (a.priority ?? 999) - (b.priority ?? 999));
    return `<div class="room-pick" data-room-pick>
      <p class="section-title">${esc(title)} <span class="room-pick__sum" data-pick-sum></span></p>
      <div class="room-pick__list">${list.map((r) => `<label class="room-pick__item${r.free ? '' : ' is-taken'}">
        <input type="checkbox" name="room" value="${r.id}" data-cap="${r.capacity}" ${r.assigned ? 'checked' : ''} ${r.free || r.assigned ? '' : 'disabled'}>
        <span><b>${esc(r.name)}</b><small>sleeps ${r.capacity}${r.free ? '' : ` · taken (${esc(r.takenBy)})`}${r.active ? '' : ' · hidden'}</small></span>
      </label>`).join('')}</div>
    </div>`;
  }
  function bindRoomPicker(root, need) {
    const box = $('[data-room-pick]', root);
    if (!box) return;
    const update = () => {
      const beds = $$('[name="room"]:checked', box).reduce((s, x) => s + Number(x.dataset.cap), 0);
      const el = $('[data-pick-sum]', box);
      // guests not noted (a room booking): just what the rooms sleep
      el.textContent = need ? `· ${beds} bed${beds === 1 ? '' : 's'} for ${need} guest${need === 1 ? '' : 's'}` : `· sleep ${beds}`;
      el.classList.toggle('is-short', beds < need);
    };
    box.addEventListener('change', update);
    update();
  }
  const pickedRooms = (root) => $$('[name="room"]:checked', root).map((x) => Number(x.value));

  /** Confirm a pending booking once the advance is in: record it, and keep or change a group's rooms. */
  async function confirmDialog(id, onDone) {
    let b;
    let choices = [];
    let guestSlips = []; // payment slips the guest sent from their invoice link
    try {
      ({ booking: b, guestSlips = [] } = await api(`/bookings/${id}`));
      if (isGroup(b)) choices = (await api(`/bookings/${id}/rooms`)).rooms;
    } catch (ex) { toast(ex.message, true); return; }
    const need = b.adults + b.children;
    const fields = ME.paymentFields || [];
    const asked = b.advance || { required: true, amount: 0 };
    openModal(`${modalHead(`Confirm ${b.guestName}`, `${b.ref} · ${whatOf(b)} · ${whenOf(b)} · ${money(b.totalAmount)}`)}
      <form class="dr-body">
        <p class="section-title">${asked.required ? `Advance received <small class="src">· asked: ${esc(advanceText(asked))}</small>` : 'Payment received <small class="src">· no advance asked for this booking</small>'}</p>
        <div class="grid2">
          <label class="fld"><span>Amount (${esc(ME.currency)}) <small>${asked.required ? 'leave empty if none yet' : 'optional — leave empty if they pay at the resort'}</small></span><input type="number" name="amount" min="0" step="1" placeholder="${asked.required && asked.amount ? asked.amount : ''}"></label>
          <label class="fld"><span>Paid by</span><select name="method">${methodOptions('bank')}</select></label>
          ${S.payFieldsHtml(fields, { commission: b.commission, money })}
          <label class="fld span2"><span>Note <small>(optional)</small></span><input name="note" maxlength="255" placeholder="e.g. Bank slip 4411"></label>
          ${S.slipFieldHtml('Advance payment slip', { guestSlips, money })}
        </div>
        ${isGroup(b) ? roomPicker(choices, need) : ''}
        ${b.guestEmail ? '<label class="chk"><input type="checkbox" name="notify" checked> Email the guest that the booking is confirmed</label>' : ''}
        <div class="dlg-foot"><button type="button" class="abtn" data-mclose>Cancel</button><button class="abtn abtn--primary" type="submit">${icon('a-check', 16)}Confirm booking</button></div>
      </form>`);
    bindRoomPicker(modalPanel, need);
    S.bindPayFields(modalPanel, $('[name="method"]', modalPanel));
    S.bindSlipField(modalPanel, $('[name="amount"]', modalPanel)); // the guest's slip fills in what they paid
    modalPanel.onsubmit = async (e) => {
      e.preventDefault();
      const f = e.target;
      const rooms = isGroup(b) ? pickedRooms(f) : undefined;
      if (isGroup(b) && !rooms.length) { toast('Choose at least one room.', true); return; }
      const amount = Math.round(Number(f.amount.value)) || 0;
      // a slip typed with an extra digit: check before it goes on the receipt
      if (amount > b.totalAmount && !confirm(`${money(amount)} is more than the whole booking (${money(b.totalAmount)}). Record it anyway?`)) { f.amount.focus(); return; }
      const btn = $('[type="submit"]', f);
      btn.disabled = true;
      try {
        const file = S.slipFile(f);
        if (file && amount <= 0) { f.amount.focus(); throw new Error('Enter the advance amount for this slip, or remove the slip.'); }
        const adv = amount > 0 ? { amount, method: f.method.value, note: f.note.value, ...S.readPayFields(f, fields), slip: file ? await S.uploadSlip(file) : S.guestSlipKey(f) } : null;
        const r = await api(`/bookings/${b.id}/confirm`, { method: 'POST', body: { rooms, advance: adv, notifyGuest: !!(f.notify && f.notify.checked) } });
        closeModal();
        toast(`${r.booking.ref} confirmed${r.advance ? ` — ${money(r.advance)} advance recorded` : ''}${r.emailed ? ' — guest emailed' : ''}`);
        if (onDone) onDone(r.booking);
        if (r.payment) showReceipt(r.payment, r.booking); // print or share the receipt for the advance
      } catch (ex) { toast(ex.message, true); btn.disabled = false; }
    };
  }

  /** Change the rooms of a per-person group, a party, or a room booking (one booking can hold several rooms). */
  async function roomsDialog(b, onDone) {
    let choices;
    try { choices = (await api(`/bookings/${b.id}/rooms`)).rooms; } catch (ex) { toast(ex.message, true); return; }
    const need = b.adults + b.children;
    const isRoom = b.kind === 'room';
    const sub = isRoom
      ? `${b.ref} · ${b.rooms.length} room${b.rooms.length === 1 ? '' : 's'} · ${whenOf(b)}${need ? ` · ${need} guest${need === 1 ? '' : 's'}` : ''}`
      : `${b.ref} · ${b.packageLabel} · ${whenOf(b)} · ${need} guest${need === 1 ? '' : 's'}`;
    openModal(`${modalHead(`Rooms for ${b.guestName}`, sub)}
      <form class="dr-body">
        ${roomPicker(choices, need, isRoom ? 'Rooms in this booking' : 'Rooms for the group')}
        <p class="src">${isRoom
          ? 'Tick every room the guest is taking. Rooms taken by other bookings on these dates can\'t be chosen. The price is worked out again from the rooms\' prices, so a total typed by hand is replaced.'
          : 'Rooms taken by other bookings on these dates can\'t be chosen. The group was given rooms from the priority list (Booking setup) when it booked.'}</p>
        <div class="dlg-foot"><button type="button" class="abtn" data-mclose>Cancel</button><button class="abtn abtn--primary" type="submit">Save rooms</button></div>
      </form>`);
    bindRoomPicker(modalPanel, need);
    modalPanel.onsubmit = async (e) => {
      e.preventDefault();
      const rooms = pickedRooms(e.target);
      if (!rooms.length) { toast('Choose at least one room.', true); return; }
      try {
        const r = await api(`/bookings/${b.id}/rooms`, { method: 'POST', body: { rooms } });
        closeModal();
        toast(`Rooms saved: ${r.booking.roomName}${r.booking.kind === 'room' ? ` · ${money(r.booking.totalAmount)}` : ''}`);
        if (onDone) onDone(r.booking);
      } catch (ex) { toast(ex.message, true); }
    };
  }

  // open drawer from any booking link without full navigation
  document.addEventListener('click', async (e) => {
    const q = e.target.closest('[data-quick]');
    if (q) {
      e.preventDefault(); e.stopPropagation();
      const status = q.dataset.quick;
      if (status === 'confirmed') { confirmDialog(Number(q.dataset.id), () => { refreshBadge(); route(); }); return; }
      if (status === 'cancelled' && !confirm('Decline this booking? The guest will be emailed.')) return;
      try {
        const r = await api(`/bookings/${q.dataset.id}`, { method: 'PATCH', body: { status, notifyGuest: true } });
        toast(`${r.booking.ref} ${STATUS[status].toLowerCase()}${r.emailed ? ' — guest emailed' : ''}`);
        refreshBadge(); route();
      } catch (ex) { toast(ex.message, true); }
      return;
    }
    const o = e.target.closest('[data-open]');
    if (o && !e.target.closest('a[href]')) { e.preventDefault(); openBooking(Number(o.dataset.open)); }
  });
  document.addEventListener('keydown', (e) => {
    const o = e.key === 'Enter' && e.target.closest && e.target.closest('div[data-open]');
    if (o) openBooking(Number(o.dataset.open));
  });

  // ================= OVERVIEW =================
  async function viewOverview() {
    const hour = new Date().getHours();
    setHead(`Good ${hour < 12 ? 'morning' : hour < 17 ? 'afternoon' : 'evening'}, ${ME.name || ME.username}`, fd(today(), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));
    const [{ stats, arrivals, recent }, { bookings: pending }] = await Promise.all([api('/stats'), api('/bookings?status=pending&sort=created')]);
    const s = stats;
    view.innerHTML = `
      <div class="stats">
        <div class="card stat ${s.pending ? 'stat--alert' : ''}"><small>Waiting for you</small><b>${s.pending}</b><span>pending request${s.pending === 1 ? '' : 's'}</span></div>
        <div class="card stat"><small>Arriving today</small><b>${s.arrivalsToday}</b><span>check-ins${s.dayOutsToday ? ` · ${s.dayOutsToday} day out${s.dayOutsToday === 1 ? '' : 's'}` : ''}</span></div>
        <div class="card stat"><small>Leaving today</small><b>${s.departuresToday}</b><span>check-outs</span></div>
        <div class="card stat${s.capacity && s.capacityUsed >= s.capacity ? ' stat--alert' : ''}"><small>Tonight</small>${s.capacity
          ? `<b>${s.capacityUsed}<span style="font-size:1rem"> / ${s.capacity}</span></b><span>guest places taken · ${s.inHouse} of ${s.rooms} rooms</span>`
          : `<b>${s.guestsTonight}</b><span>guests · ${s.inHouse} of ${s.rooms} rooms taken</span>`}</div>
        <div class="card stat"><small>Next 30 days</small><b>${s.occupancy30}%</b><span>occupancy</span></div>
        <div class="card stat"><small>Upcoming confirmed</small><b style="font-size:1.35rem;margin-top:12px">${money(s.upcomingRevenue)}</b><span>+ ${money(s.pendingRevenue)} pending</span></div>
      </div>
      <div class="cols">
        <div class="stack">
          <section class="card">
            <div class="card__head"><h2>Needs your attention</h2><span class="src">New website requests</span></div>
            ${pending.length ? `<ul class="blist">${pending.map((b) => bookingRow(b, { actions: true })).join('')}</ul>` : '<div class="empty">All caught up — no pending requests. 🌿</div>'}
          </section>
          <section class="card">
            <div class="card__head"><h2>Arriving in the next 7 days</h2><a class="abtn abtn--sm" href="#/calendar">Calendar</a></div>
            ${arrivals.length ? `<ul class="blist">${arrivals.map((b) => bookingRow(b)).join('')}</ul>` : '<div class="empty">No arrivals this week.</div>'}
          </section>
        </div>
        <section class="card">
          <div class="card__head"><h2>Latest bookings</h2><a class="abtn abtn--sm" href="#/bookings">All bookings</a></div>
          ${recent.length ? `<ul class="blist">${recent.map((b) => bookingRow(b)).join('')}</ul>` : '<div class="empty">No bookings yet. They will appear here as soon as they come in.</div>'}
        </section>
      </div>`;
  }

  // ================= CALENDAR =================
  let calFrom = null;
  let calDays = 21;
  async function viewCalendar(q) {
    if (q.get('from')) calFrom = q.get('from');
    if (!calFrom) calFrom = addDays(today(), -2);
    setHead('Calendar', 'Every room, the party area and day outs at a glance. A per-person group shows on each room it was given. Click an empty day to add a booking.');
    const d = await api(`/calendar?from=${calFrom}&days=${calDays}`);
    const days = Array.from({ length: d.days }, (_, i) => addDays(d.from, i));
    // days the whole resort is closed
    const closedOn = {};
    (d.closures || []).forEach((c) => { for (let s = c.from; s <= c.to; s = addDays(s, 1)) closedOn[s] = closedOn[s] || c; });
    const closedCls = (s) => (closedOn[s] ? ' is-closed' : '');
    const head = days.map((s, i) => {
      const dt = new Date(toUTC(s));
      const dow = dt.getUTCDay();
      const showMonth = i === 0 || s.endsWith('-01');
      return `<div class="g-day${dow === 0 || dow === 6 ? ' is-weekend' : ''}${s === today() ? ' is-today' : ''}${closedCls(s)}"${closedOn[s] ? ` title="${esc(`Resort closed: ${closedOn[s].label}`)}"` : ''}>${showMonth ? `<span class="g-month">${dt.toLocaleDateString('en-GB', { month: 'short', timeZone: 'UTC' })}</span>` : `<span>${dt.toLocaleDateString('en-GB', { weekday: 'short', timeZone: 'UTC' }).slice(0, 2)}</span>`}<b>${dt.getUTCDate()}</b></div>`;
    }).join('');
    const cells = (attrs) => days.map((s) => {
      const dow = new Date(toUTC(s)).getUTCDay();
      return `<button type="button" class="g-day${dow === 0 || dow === 6 ? ' is-weekend' : ''}${s === today() ? ' is-today' : ''}${closedCls(s)}" ${attrs(s)}></button>`;
    }).join('');
    const tip = (b) => esc(`${b.guestName} · ${b.ref} · ${whatOf(b)} · ${whenOf(b)} · ${guests(b)}${extrasOf(b).length ? ` · ${extrasOf(b).join(', ')}` : ''} · ${STATUS[b.status]} · ${b.sourceLabel}`);
    // a stay runs from the afternoon of arrival to the morning of departure
    const stayBar = (b, lane = -1) => {
      const start = diff(d.from, b.checkIn) + 0.5;
      const end = diff(d.from, b.checkOut) + 0.5;
      const l = Math.max(0, start), rr = Math.min(d.days, end);
      if (rr <= 0 || l >= d.days) return '';
      const cls = b.source === 'block' ? 'block' : b.status;
      const small = isGroup(b) ? b.packageLabel : ['website', 'block'].includes(b.source) ? '' : b.sourceLabel;
      const stack = lane >= 0 ? `;top:${9 + lane * 26}px;bottom:auto;height:22px` : '';
      return `<button type="button" class="g-bar g-bar--${esc(cls)}${isGroup(b) ? ' g-bar--group' : ''}${start < 0 ? ' is-clip-l' : ''}${end > d.days ? ' is-clip-r' : ''}" style="left:${(l / d.days) * 100}%;width:${((rr - l) / d.days) * 100}%${stack}" data-open="${b.id}" title="${tip(b)}">
        <span>${esc(b.source === 'block' ? 'Blocked' : b.guestName)}</span><small>${esc(small)}</small></button>`;
    };
    // a per-person group shows on every room it was given
    // on every room it holds: a room booking can hold several, like a group or a party
    const onRoom = (b, r) => b.roomId === r.id || (b.rooms || []).some((x) => x.id === r.id);
    const rows = d.rooms.map((r) => {
      const bars = d.bookings.filter((b) => onRoom(b, r)).map((b) => stayBar(b)).join('');
      return `<div class="g-row"><div class="g-name${r.active ? '' : ' is-off'}"><b>${esc(r.name)}</b><small>${esc(r.type)} · sleeps ${r.capacity}${r.active ? '' : ' · hidden'}</small></div><div class="g-track">${cells((s) => `data-new-room="${r.id}" data-new-date="${s}" aria-label="New booking: ${esc(r.name)}, ${fd(s)}"`)}${bars}</div></div>`;
    }).join('');
    // the party area (one group per night) and day outs (several groups a day, stacked)
    // the party area: stays that added it (a night), and outdoor parties (their day)
    const partyBars = d.bookings.filter((b) => b.partyArea && !isParty(b)).map((b) => stayBar(b)).join('')
      + d.bookings.filter(isParty).map((b) => {
        const i = diff(d.from, b.checkIn);
        if (i < 0 || i >= d.days) return '';
        return `<button type="button" class="g-bar g-bar--${esc(b.status)} g-bar--party" style="left:calc(${(i / d.days) * 100}% + 2px);width:calc(${100 / d.days}% - 4px)" data-open="${b.id}"
          title="${tip(b)}"><span>${esc(b.guestName)}</span><small>${esc(b.packageLabel)}</small></button>`;
      }).join('');
    const lanes = {};
    const dayOuts = d.bookings.filter(isDayOut);
    const dayBars = dayOuts.map((b) => {
      const i = diff(d.from, b.checkIn);
      if (i < 0 || i >= d.days) return '';
      const lane = lanes[b.checkIn] = (lanes[b.checkIn] || 0) + 1;
      return `<button type="button" class="g-bar g-bar--${esc(b.status)} g-bar--day" style="left:calc(${(i / d.days) * 100}% + 3px);width:calc(${100 / d.days}% - 6px);top:${9 + (lane - 1) * 26}px" data-open="${b.id}" title="${tip(b)}">
        <span>${b.adults + b.children}</span><small>${esc(b.guestName)}</small></button>`;
    }).join('');
    const dayH = Math.max(56, 14 + Math.max(0, ...Object.values(lanes)) * 26);
    const dayPkg = ME.packages.find((p) => p.kind === 'day' && p.active) || ME.packages.find((p) => p.kind === 'day');
    // groups with no rooms (e.g. entered for past dates), stacked in lanes
    const loose = d.bookings.filter((b) => isGroup(b) && !b.rooms.length).sort((a, b) => a.checkIn.localeCompare(b.checkIn));
    const ends = [];
    const looseBars = loose.map((b) => {
      let lane = ends.findIndex((e) => e <= b.checkIn);
      if (lane < 0) lane = ends.length;
      ends[lane] = b.checkOut;
      return stayBar(b, lane);
    }).join('');
    // guest places taken each night, against the hotel's capacity (Booking setup)
    const capCells = days.map((s) => {
      const g = d.guests[s] || 0;
      const ratio = d.capacity ? g / d.capacity : 0;
      const cls = d.capacity && ratio >= 1 ? ' is-full' : ratio >= 0.8 ? ' is-near' : '';
      return `<div class="g-day g-cap${cls}" title="${esc(`${fd(s)}: ${g}${d.capacity ? ` of ${d.capacity}` : ''} guest places taken`)}">${d.capacity ? `<i style="height:${Math.min(100, Math.round(ratio * 100))}%"></i>` : ''}<b>${g}</b></div>`;
    }).join('');
    // closures as bars, whole days
    const closeBars = (d.closures || []).map((c) => {
      const l = Math.max(0, diff(d.from, c.from));
      const rr = Math.min(d.days, diff(d.from, c.to) + 1);
      if (rr <= 0 || l >= d.days) return '';
      // a party that closes its date opens that booking; a closure opens its own window
      return `<button type="button" class="g-bar g-bar--closed${c.bookingId ? ' g-bar--closed-party' : ''}" style="left:${(l / d.days) * 100}%;width:${((rr - l) / d.days) * 100}%" ${c.bookingId ? `data-open="${c.bookingId}"` : `data-closure="${c.id}"`}
        title="${esc(`${c.staffLabel || c.label} · ${daySpan(c.from, c.to)}${c.note ? ` · ${c.note}` : ''}`)}"><span>${esc(c.staffLabel || c.label)}</span><small>${esc(c.note)}</small></button>`;
    }).join('');
    const closedRow = closeBars || can('setup') ? `<div class="g-row g-row--extra g-row--closed"><div class="g-name"><b>Resort closed</b><small>No website bookings</small></div>
      <div class="g-track">${cells((s) => (can('setup') && !closedOn[s] ? `data-close-from="${s}" aria-label="Close the resort on ${fd(s)}"` : 'tabindex="-1" aria-hidden="true"'))}${closeBars}</div></div>` : '';
    const extraRows = `
      <div class="g-row g-row--extra g-row--cap"><div class="g-name"><b>Capacity</b><small>${d.capacity ? `${d.capacity} guests a night` : 'No limit set'}</small></div><div class="g-track">${capCells}</div></div>
      ${loose.length ? `<div class="g-row g-row--extra"><div class="g-name"><b>Groups, no rooms</b><small>Give them rooms</small></div><div class="g-track" style="min-height:${Math.max(56, 14 + ends.length * 26)}px">${cells(() => 'tabindex="-1" aria-hidden="true"')}${looseBars}</div></div>` : ''}
      <div class="g-row g-row--extra"><div class="g-name"><b>Party area</b><small>Outdoor parties · stays with the area</small></div><div class="g-track">${cells((s) => `data-new-party="${s}" aria-label="New outdoor party, ${fd(s)}"`)}${partyBars}</div></div>
      ${dayOuts.length || (dayPkg && dayPkg.active) ? `<div class="g-row g-row--extra"><div class="g-name"><b>Day outs</b><small>Guests per group</small></div><div class="g-track" style="min-height:${dayH}px">${cells((s) => (dayPkg ? `data-new-day="${s}" aria-label="New day out, ${fd(s)}"` : 'tabindex="-1" aria-hidden="true"'))}${dayBars}</div></div>` : ''}`;

    view.innerHTML = `<section class="card">
      <div class="cal-ctrl">
        <button class="abtn abtn--sm" type="button" data-cal-move="-7" aria-label="Back one week">${icon('a-left', 16)}</button>
        <button class="abtn abtn--sm" type="button" data-cal-today>Today</button>
        <button class="abtn abtn--sm" type="button" data-cal-move="7" aria-label="Forward one week">${icon('a-right', 16)}</button>
        <input class="inp" type="date" value="${d.from}" data-cal-jump style="width:auto;min-height:32px;padding:4px 10px">
        <span class="seg">${[14, 21, 31, 62].map((n) => `<button type="button" class="${n === calDays ? 'is-on' : ''}" data-cal-days="${n}">${n}d</button>`).join('')}</span>
        ${can('setup') ? `<button class="abtn abtn--sm" type="button" data-close-days>${icon('a-x', 15)}Close days</button>` : ''}
        <div class="legend">
          <span><i style="background:var(--st-pending)"></i>Pending</span><span><i style="background:var(--st-confirmed)"></i>Confirmed</span>
          <span><i style="background:var(--st-checked_in)"></i>Checked in</span><span><i style="background:var(--st-checked_out)"></i>Checked out</span><span><i style="background:#6b6f68"></i>Blocked</span><span><i class="lg-closed"></i>Resort closed</span>
          <span><i class="lg-group"></i>Per-person group</span>
        </div>
      </div>
      <div class="gantt" style="--days:${d.days}"><div class="gantt__inner">
        <div class="g-row g-head"><div class="g-name">Room</div><div class="g-track">${head}</div></div>
        ${closedRow}
        ${rows}
        ${extraRows}
      </div></div>
      <div class="cal-hint">Bars run from the afternoon of arrival to the morning of departure, so back-to-back stays sit side by side. Capacity counts a booked room as full and a per-person group by its guests. Day outs show the number of guests in each group.</div>
    </section>`;
    view.onclick = (e) => {
      const mv = e.target.closest('[data-cal-move]');
      if (mv) { calFrom = addDays(calFrom, Number(mv.dataset.calMove)); viewCalendar(new URLSearchParams()); }
      if (e.target.closest('[data-cal-today]')) { calFrom = addDays(today(), -2); viewCalendar(new URLSearchParams()); }
      const dd = e.target.closest('[data-cal-days]');
      if (dd) { calDays = Number(dd.dataset.calDays); viewCalendar(new URLSearchParams()); }
      const nb = e.target.closest('[data-new-room]');
      if (nb) location.hash = `#/new?room=${nb.dataset.newRoom}&checkIn=${nb.dataset.newDate}`;
      const np = e.target.closest('[data-new-party]');
      if (np) location.hash = `#/new?kind=event&checkIn=${np.dataset.newParty}`; // an outdoor party on that day
      const nd = e.target.closest('[data-new-day]');
      if (nd && dayPkg) location.hash = `#/new?kind=day_out&packageId=${dayPkg.id}&checkIn=${nd.dataset.newDay}`;
      if (e.target.closest('[data-close-days]')) closeDaysDialog(today());
      const cf = e.target.closest('[data-close-from]');
      if (cf) closeDaysDialog(cf.dataset.closeFrom);
      const cl = e.target.closest('[data-closure]');
      if (cl) closureDialog((d.closures || []).find((c) => c.id === Number(cl.dataset.closure)));
    };
    $('[data-cal-jump]').onchange = (e) => { if (e.target.value) { calFrom = e.target.value; viewCalendar(new URLSearchParams()); } };
  }

  // ================= BOOKINGS LIST =================
  const F = { q: '', status: '', source: '', kind: '', when: 'upcoming' };
  async function viewBookings() {
    setHead('Bookings', 'Every reservation — from the website and entered by hand.');
    view.innerHTML = `<section class="card">
      <div class="filters">
        <label class="search"><span class="sr-only" hidden>Search</span>${icon('a-search')}<input class="inp" type="search" placeholder="Search name, phone, email, ID or ref…" value="${esc(F.q)}" data-f="q"></label>
        <span class="seg">${[['upcoming', 'Upcoming'], ['past', 'Past'], ['all', 'All']].map(([k, l]) => `<button type="button" data-when="${k}" class="${F.when === k ? 'is-on' : ''}">${l}</button>`).join('')}</span>
        <select class="inp" data-f="status"><option value="">All statuses</option>${Object.entries(STATUS).map(([k, l]) => `<option value="${k}" ${F.status === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <select class="inp" data-f="source"><option value="">All sources</option>${Object.entries(ME.sources).map(([k, l]) => `<option value="${k}" ${F.source === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>
        <select class="inp" data-f="kind" aria-label="Type of booking"><option value="">All types</option>${Object.entries(ME.kinds).map(([k, l]) => `<option value="${k}" ${F.kind === k ? 'selected' : ''}>${esc(k === 'room' ? ME.labels.room : k === 'per_person' ? ME.labels.person : l)}</option>`).join('')}</select>
        <a class="abtn" data-export href="#">${icon('a-dl')}Export CSV</a>
      </div>
      <div data-table><div class="loading">Loading…</div></div>
    </section>`;
    let timer;
    view.oninput = (e) => {
      const f = e.target.dataset.f;
      if (!f) return;
      F[f] = e.target.value;
      clearTimeout(timer);
      timer = setTimeout(loadTable, f === 'q' ? 250 : 0);
    };
    view.onclick = (e) => {
      const w = e.target.closest('[data-when]');
      if (w) { F.when = w.dataset.when; $$('[data-when]').forEach((b) => b.classList.toggle('is-on', b === w)); loadTable(); }
      if (e.target.closest('[data-export]')) { e.preventDefault(); window.location.href = `/api/admin/bookings.csv?${query()}`; }
    };
    await loadTable();
  }
  function query() {
    const p = new URLSearchParams();
    if (F.q) p.set('q', F.q);
    if (F.status) p.set('status', F.status);
    if (F.source) p.set('source', F.source);
    if (F.kind) p.set('kind', F.kind);
    if (F.when === 'upcoming') p.set('from', today());
    if (F.when === 'past') p.set('to', today());
    return p.toString();
  }
  async function loadTable() {
    const box = $('[data-table]');
    if (!box) return;
    let { bookings } = await api(`/bookings?${query()}`);
    if (F.when === 'past') bookings = bookings.reverse();
    if (!bookings.length) { box.innerHTML = '<div class="empty">No bookings match these filters.</div>'; return; }
    const total = bookings.filter((b) => b.status !== 'cancelled').reduce((a, b) => a + b.totalAmount, 0);
    box.innerHTML = `<div class="table-wrap"><table class="tbl">
      <thead><tr><th>Ref</th><th>Guest</th><th>Booking</th><th>Dates</th><th>Guests</th><th class="num">Total</th><th>Source</th><th>Status</th></tr></thead>
      <tbody>${bookings.map((b) => `<tr data-open="${b.id}">
        <td class="ref">${esc(b.ref)}</td>
        <td><b>${esc(b.guestName)}</b><span class="sub">${esc(b.guestPhone || b.guestEmail || '')}</span></td>
        <td>${esc(b.kind === 'room' ? b.roomName : b.packageLabel)}<span class="sub">${esc([b.source === 'block' ? 'Blocked' : '', b.wholeCategory ? `${b.wholeCategory.name} ${b.wholeCategory.label.toLowerCase()}` : '', b.mealPlanLabel || '', isGroup(b) ? b.roomName || 'No rooms yet' : '', ...extrasOf(b)].filter(Boolean).join(' · '))}</span></td>
        <td>${isDayOut(b)
          ? `${fd(b.checkIn, { day: 'numeric', month: 'short', year: '2-digit' })}<span class="sub">Day visit</span>`
          : `${fd(b.checkIn)} → ${fd(b.checkOut, { day: 'numeric', month: 'short', year: '2-digit' })}<span class="sub">${b.nights} night${b.nights > 1 ? 's' : ''}</span>`}</td>
        <td>${guests(b)}</td>
        <td class="num">${money(b.totalAmount)}</td>
        <td>${esc(b.sourceLabel)}</td>
        <td>${st(b.status)}${CONFIRM.chip(b) ? `<span class="sub">${CONFIRM.chip(b)}</span>` : ''}${b.slipWaiting ? '<span class="sub"><span class="gc-chip gc-chip--slip" title="The guest sent a payment slip that is not recorded yet">🧾 Slip sent</span></span>' : ''}</td>
      </tr>`).join('')}</tbody></table></div>
      <div class="tbl-foot">${bookings.length} booking${bookings.length > 1 ? 's' : ''} · ${money(total)} excluding cancelled</div>`;
  }

  // ================= BOOKING DRAWER =================
  const drawer = $('[data-drawer]');
  const panel = $('[data-drawer-panel]');
  let drawerDirty = false;
  /** navigating = true when a link in the drawer is about to change the page anyway */
  function closeDrawer(navigating = false) {
    if (!drawer.classList.contains('is-open')) return;
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    if (navigating === true) { drawerDirty = false; return; }
    if (/^#\/bookings\/\d+/.test(location.hash)) history.replaceState(null, '', '#/bookings');
    if (drawerDirty) { drawerDirty = false; route(); refreshBadge(); }
  }
  $('[data-drawer-close]', drawer).addEventListener('click', closeDrawer);
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (modal.classList.contains('is-open')) closeModal();
    else { closeDrawer(); closeLibrary(); }
  });

  async function openBooking(id, quiet = false) {
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    if (!quiet) panel.innerHTML = '<div class="loading">Loading…</div>';
    let folio;
    // the room bill comes with the booking: payments so far and the balance
    try { folio = (await api(`/pos/folios/${id}`)).folio; } catch (e) { panel.innerHTML = `<div class="empty">${esc(e.message)}</div>`; return; }
    renderDrawer(folio.booking, folio);
    if (openInvoiceFor === id && folio.invoiceUrl) { openInvoiceFor = 0; showInvoice(folio.booking, folio.invoiceUrl); } // a new reservation
    if (openConfirmFor === id) { openConfirmFor = 0; CONFIRM.dialog(folio.booking, () => reloadDrawer(id)); } // a new booking for the guest to confirm
  }
  let openInvoiceFor = 0;
  let openConfirmFor = 0;
  /** After a change: show the booking again, and refresh the page behind it once the drawer closes. */
  const reloadDrawer = (id) => { drawerDirty = true; return openBooking(id, true); };

  /** A payment's extra fields, note and anything kept back: "Received into: BOC 1234 · Advance · Kamal Tours kept LKR 3,000 commission — LKR 17,000 reached the account" */
  const payInfo = (p, b) => {
    const c = (b && b.commission) || {};
    const kept = !p.fee ? '' : c.type ? `${c.to || 'The agent'} kept ${money(p.fee)} commission — ${money(p.net)} reached the account` : `${money(p.fee)} bank or card charges — ${money(p.net)} reached the account`;
    return [...(p.details || []).map((d) => `${d.label}: ${d.value}`), p.note, kept].filter(Boolean).join(' · ');
  };

  /** Agreed rates (vs the price list), the coupon and the commission, for the booking's summary. */
  function priceKv(b) {
    const out = [];
    const r = b.rates || {};
    const l = b.listRates || {};
    const per = isDayOut(b) ? '' : '/night';
    const rate = (label, agreed, list) => (agreed !== null && agreed !== undefined
      ? `<div><small>${label}</small>${money(agreed)}${per} <span class="src">list ${money(list)}${list > agreed ? ` · −${Math.round(((list - agreed) / list) * 100)}%` : ''}</span></div>` : '');
    if (b.kind === 'room') out.push(rate('Agreed rate', r.room, l.room));
    else { out.push(rate('Agreed adult rate', r.adult, l.adult)); out.push(rate('Agreed child rate', r.child, l.child)); }
    if (b.couponCode) out.push(`<div><small>Coupon</small>${esc(b.couponCode)} <span class="src">− ${money(b.couponDiscount)} off the room charge</span></div>`);
    const c = b.commission || {};
    if (c.type) {
      out.push(`<div><small>Agent commission${c.to ? ` · ${esc(c.to)}` : ''}</small>${c.type === 'percent' ? `${c.value}% = ` : ''}${money(c.amount)}
        <span class="src">${c.kept ? `${money(c.kept)} kept from payments · ` : ''}${c.toPay ? `${money(c.toPay)} still to pay them (Finance → Commissions)` : 'settled'} · you keep ${money(b.netAmount)}</span></div>`);
    }
    return out.join('');
  }

  function nextActions(b) {
    const a = [];
    if (b.source === 'block') return b.status === 'cancelled' ? [] : [['cancelled', 'Remove block', 'abtn--danger']];
    if (b.status === 'pending') a.push(['confirm', 'Confirm booking…', 'abtn--primary']);
    if (b.status === 'confirmed') a.push(['checked_in', 'Check in', 'abtn--primary']);
    if (b.status === 'cancelled') a.push(['confirmed', 'Restore as confirmed', '']);
    if (['pending', 'confirmed'].includes(b.status)) a.push(['cancelled', b.status === 'pending' ? 'Decline' : 'Cancel booking', 'abtn--danger']);
    return a;
  }

  function renderDrawer(b, folio) {
    const isBlock = b.source === 'block';
    const dayOut = isDayOut(b);
    const group = isGroup(b);
    const long = { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' };
    const guestsLong = b.adults + b.children > 0
      ? `${b.adults} adult${b.adults === 1 ? '' : 's'}${b.children ? `, ${b.children} child${b.children === 1 ? '' : 'ren'}` : ''}` : 'Not noted';
    const t = folio ? folio.totals : null;
    const billOpen = !!(folio && folio.open);
    const pays = folio ? folio.payments.filter((p) => !p.voided) : []; // advances (payments on a bill are on the bill)
    const partyB = isParty(b);
    const canRooms = (group || partyB || (b.kind === 'room' && !isBlock)) && ['pending', 'confirmed', 'checked_in'].includes(b.status) && !b.billId;
    const kindTitle = b.kind === 'room' ? ME.labels.room : dayOut ? 'Day visit' : partyB ? 'Outdoor party' : ME.labels.person;
    const dates = dayOut || partyB ? `<div><small>Date</small>${fd(b.checkIn, long)}</div>`
      : `<div><small>Check-in</small>${fd(b.checkIn, long)}</div><div><small>Check-out</small>${fd(b.checkOut, long)} · ${b.nights}n</div>`;
    panel.innerHTML = `
      <header class="dr-head">
        <div>
          <h2 id="drawer-title">${esc(isBlock ? 'Blocked dates' : b.guestName)}</h2>
          <p>${esc(b.ref)} · ${esc(b.sourceLabel)} · created ${fdt(b.createdAt)}${b.createdBy.startsWith('admin:') ? ` by ${esc(b.createdBy.slice(6))}` : ''}</p>
        </div>
        <button class="abtn abtn--ghost" type="button" data-dclose aria-label="Close">${icon('a-x', 20)}</button>
      </header>
      <div class="dr-body">
        <div class="card card__body" style="display:grid;gap:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">${st(b.status)}<b style="font-family:var(--display);font-size:1.3rem">${money(b.totalAmount)}</b></div>
          <div class="kv">
            ${isBlock ? `<div><small>Room</small>${esc(b.roomName)}</div><div><small>Dates</small>${fd(b.checkIn)} → ${fd(b.checkOut, long)} · ${b.nights}n</div>` : `
            <div${b.kind === 'room' && b.rooms.length > 1 ? ' class="kv-wide"' : ''}><small>${esc(b.kind === 'room' && b.rooms.length > 1 ? `${b.rooms.length} rooms` : kindTitle)}</small>${esc(b.kind === 'room' ? b.roomName : b.packageLabel)}${b.kind === 'room' && canRooms ? ' <button type="button" class="linkbtn" data-rooms>Add or change rooms</button>' : ''}</div>
            ${b.kind === 'room' && b.mealPlan ? `<div><small>Meal plan</small>${esc(b.mealPlan)} · ${esc(b.mealPlanLabel)}</div>` : ''}
            ${b.wholeCategory ? `<div class="kv-wide"><small>Whole villa</small>${esc(b.wholeCategory.name)} — ${esc(b.wholeCategory.label)} <span class="src">· one price for all its rooms</span></div>` : ''}
            <div><small>${b.kind === 'room' ? 'Sleeps' : 'Guests'}</small>${b.kind === 'room' ? `${b.capacity} guest${b.capacity === 1 ? '' : 's'}` : guestsLong}</div>
            ${group ? `<div class="kv-wide"><small>Rooms</small>${b.rooms.length ? `${esc(b.roomName)} <span class="src">· sleep ${b.capacity}</span>` : '<b class="is-due">None yet</b>'}${canRooms ? ' <button type="button" class="linkbtn" data-rooms>Change rooms</button>' : ''}</div>` : ''}
            ${partyB ? `<div><small>Time</small>${esc(b.arrivalTime || '—')}</div>
              <div class="kv-wide"><small>Other guests</small>${esc(b.eventOthersLabel)}</div>
              <div class="kv-wide"><small>Rooms held for party guests</small>${b.rooms.length ? esc(b.roomName) : 'None'}${canRooms ? ' <button type="button" class="linkbtn" data-rooms>Change rooms</button>' : ''}</div>` : ''}
            ${dates}
            ${b.kind === 'room' ? `<div><small>Guests</small>${guestsLong}</div>` : ''}`}
            ${extrasOf(b).length ? `<div><small>Extras</small>${esc(extrasOf(b).join(', '))}</div>` : ''}
            ${b.arrivalTime && !partyB ? `<div><small>Arrival</small>${esc(b.arrivalTime)}</div>` : ''}
            ${b.guestIdNo ? `<div><small>NIC / passport</small>${esc(b.guestIdNo)}</div>` : ''}
            ${b.country ? `<div><small>Country</small>${esc(b.country)}</div>` : ''}
            ${isBlock ? '' : priceKv(b)}
            ${isBlock || !['pending', 'confirmed'].includes(b.status) ? '' : `<div><small>Advance</small>${esc(advanceText(b.advance))}</div>`}
            ${billOpen || (t && t.paid) ? `<div><small>Paid so far</small>${money(t.paid)}${t.fees ? ` <span class="src">${money(t.received)} reached your accounts (${money(t.fees)} kept by the agent or bank)</span>` : ''}</div><div><small>Balance</small>${money(t.balance)}${t.items ? ` <span class="src">incl. ${money(t.items)} of items</span>` : ''}</div>` : ''}
          </div>
          ${b.specialRequests ? `<div class="note note--info"><b>Guest requests:</b> ${esc(b.specialRequests)}</div>` : ''}
          ${b.status === 'pending' && !isBlock ? (b.advance && !b.advance.required
            ? `<div class="note note--info"><b>Reservation</b> — no advance asked: the guest pays at the resort, and it is not released automatically. Confirm it once you have spoken to the guest. To ask for an advance after all, change <b>Advance</b> below.</div>`
            : `<div class="note note--warn">${b.holdUntil
            ? `<b>Reservation</b> held until <b>${fd(b.holdUntil, long)}</b> (${esc(holdText(b).split(' · ')[1])}). If no advance is recorded by then, it is released and the dates open again.`
            : '<b>Reservation</b> waiting for the advance (it is not released automatically).'}
            Send the invoice, then confirm the booking when the advance is paid — record the advance as you confirm and print the receipt.</div>`) : ''}
          ${nextActions(b).length ? `<div class="dr-actions">
            ${nextActions(b).map(([s, l, c]) => `<button type="button" class="abtn ${c}" data-set-status="${s}">${l}</button>`).join('')}
            ${b.guestEmail ? '<label class="chk"><input type="checkbox" data-notify checked> Email the guest</label>' : ''}
          </div>` : ''}
          ${!isBlock && folio && folio.invoiceUrl ? `<div class="dr-actions">
            <button type="button" class="abtn${b.status === 'pending' ? ' abtn--amber' : ''}" data-invoice>${icon('a-doc', 16)}${b.status === 'pending' ? 'Send the invoice' : 'Invoice'}</button>
            ${(b.status === 'confirmed' || (b.status === 'pending' && b.advance && b.advance.required)) && b.guestEmail ? `<button type="button" class="abtn" data-remind title="Email this guest ${b.status === 'pending' ? 'a payment' : 'an arrival'} reminder now">${icon('a-mail', 16)}Send reminder</button>` : ''}
          </div>` : ''}
          ${isBlock ? '' : '<div data-gc-slot></div>'}
          ${folio && folio.guestSlips && folio.guestSlips.length ? `<div class="pay-list"><p class="section-title">Payment slips from the guest</p>${folio.guestSlips.map((s) => `<div class="pay-row${s.paymentId ? '' : ' is-new'}">
            <div><b>${s.amount ? money(s.amount) : 'Amount not given'}</b> <span class="src">· sent ${fdt(s.uploadedAt)}</span>
              <span class="pay-row__sub">${esc([s.note && `“${s.note}”`, s.paymentId ? `Recorded as ${s.receiptNo}` : `Not recorded yet — check it, then ${b.status === 'pending' ? 'confirm the booking' : 'record the payment'}`].filter(Boolean).join(' · '))}</span></div>
            <span class="pay-row__acts"><a class="abtn abtn--sm" href="${esc(s.url)}" target="_blank" rel="noopener" title="Open the slip the guest sent">${icon('a-ext', 15)}Open slip</a></span>
          </div>`).join('')}</div>` : ''}
          ${pays.length ? `<div class="pay-list"><p class="section-title">Payments</p>${pays.map((p) => `<div class="pay-row">
            <div><b>${money(p.amount)}</b> · ${esc(p.methodLabel)} <span class="src">· ${fdt(p.createdAt)}</span>
              ${payInfo(p, b) ? `<span class="pay-row__sub">${esc(payInfo(p, b))}</span>` : ''}</div>
            <span class="pay-row__acts">${S.slipLink(p, true)}<button type="button" class="abtn abtn--sm" data-receipt="${p.id}" title="Print or share the receipt">${icon('a-print', 15)}${esc(p.receiptNo)}</button></span>
          </div>`).join('')}</div>` : ''}
          ${isBlock ? '' : b.billId ? `<div class="dr-actions"><a class="abtn" href="#/bills/${b.billId}">${icon('a-bill', 16)}Bill INV-${String(b.billId).padStart(5, '0')}</a></div>`
            : billOpen ? `<div class="dr-actions">
            ${['checked_in', 'checked_out'].includes(b.status) ? `<a class="abtn abtn--primary" href="/pos?guest=${b.id}&amp;checkout=1" target="ydpos">${icon('a-bill', 16)}Check out &amp; bill</a>` : ''}
            <a class="abtn" href="/pos?guest=${b.id}" target="ydpos">${icon('a-pos', 16)}Open in the POS</a>
            <button type="button" class="abtn" data-pay>Record payment</button></div>` : ''}
          <div class="dr-contact">
            ${b.guestPhone ? `<a class="abtn abtn--sm" href="tel:${esc(b.guestPhone)}">${icon('a-phone', 15)}${esc(b.guestPhone)}</a><a class="abtn abtn--sm" href="${waLink(b.guestPhone)}" target="_blank" rel="noopener">${icon('a-wa', 15)}WhatsApp</a>` : ''}
            ${b.guestEmail ? `<a class="abtn abtn--sm" href="mailto:${esc(b.guestEmail)}?subject=${encodeURIComponent(`Your Top Mountain Resort booking ${b.ref}`)}">${icon('a-mail', 15)}${esc(b.guestEmail)}</a>` : ''}
          </div>
        </div>

        <form class="card" data-edit>
          <div class="card__head"><h2>Edit booking</h2><span class="src" data-avail></span></div>
          <div class="card__body grid2">
            ${isBlock ? '' : `<label class="fld span2"><span>Booking</span><select name="what">${kindOptions(kindValue(b))}</select></label>`}
            <label class="fld span2" data-room-f><span>${b.kind === 'room' && b.rooms.length > 1 ? `Main room <small>· also ${esc(b.rooms.filter((x) => x.id !== b.roomId).map((x) => x.name).join(', '))} (Add or change rooms, above)</small>` : 'Room'}</span><select name="roomId">${ROOMS.map((r) => `<option value="${r.id}" ${r.id === b.roomId ? 'selected' : ''}>${esc(r.name)} — ${esc(r.type)} · sleeps ${r.capacity}</option>`).join('')}</select></label>
            ${isBlock ? '' : planFields(b.mealPlan || 'RO', b.wholeCategoryId)}
            <label class="fld"><span data-in-label>Check-in</span><input type="date" name="checkIn" value="${b.checkIn}" required></label>
            <label class="fld" data-stay-f><span>Check-out</span><input type="date" name="checkOut" value="${b.checkOut}"></label>
            ${isBlock ? '' : `<label class="fld"><span>Adults</span><input type="number" name="adults" min="0" max="50" value="${b.adults}"></label>
            <label class="fld"><span>Children</span><input type="number" name="children" min="0" max="30" value="${b.children}"></label>
            <label class="chk" data-party-f><input type="checkbox" name="partyArea" ${b.partyArea ? 'checked' : ''}> Party area</label>
            <label class="chk"><input type="checkbox" name="jblSpeaker" ${b.jblSpeaker ? 'checked' : ''}> JBL speaker</label>
            <div class="span2">${partyFields(b)}</div>`}
            <label class="fld span2"><span>${isBlock ? 'Label' : 'Guest name'}</span><input name="guestName" value="${esc(b.guestName)}" required maxlength="120"></label>
            <label class="fld"><span>Phone</span><input name="guestPhone" value="${esc(b.guestPhone)}" maxlength="40"></label>
            <label class="fld"><span>Email</span><input type="email" name="guestEmail" value="${esc(b.guestEmail)}" maxlength="160"></label>
            ${isBlock ? '' : `<label class="fld"><span>NIC / passport</span><input name="guestIdNo" value="${esc(b.guestIdNo)}" maxlength="30" autocapitalize="characters" spellcheck="false"></label>`}
            <label class="fld"><span>Country</span><input name="country" value="${esc(b.country)}" maxlength="60"></label>
            <label class="fld"><span>Source</span><select name="source">${Object.entries(ME.sources).map(([k, l]) => `<option value="${k}" ${k === b.source ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
            <label class="fld"><span>Status</span><select name="status">${Object.entries(STATUS).map(([k, l]) => `<option value="${k}" ${k === b.status ? 'selected' : ''}>${k === 'pending' ? 'Pending (reservation)' : l}</option>`).join('')}</select></label>
            ${isBlock ? '' : `${advanceFields(b.advance ? b.advance.own : null)}
            <label class="fld" data-hold-f><span>Reservation held until <small>empty = not released</small></span><input type="date" name="holdUntil" value="${esc(b.holdUntil || '')}" min="${today()}"><small>No advance by then → released automatically.</small></label>
            ${rateFields(b.rates, b.listRates)}`}
            <label class="fld"><span>Total (${esc(ME.currency)})${b.couponCode ? ` <small>after coupon ${esc(b.couponCode)}</small>` : ''}</span><input type="number" name="totalAmount" min="0" step="1" value="${b.totalAmount}"></label>
            ${isBlock ? '' : commissionFields(b.commission)}
            <label class="fld span2"><span>Guest requests</span><textarea name="specialRequests" maxlength="1500">${esc(b.specialRequests)}</textarea></label>
            <label class="fld span2"><span>Private notes (only admins see these)</span><textarea name="adminNotes" maxlength="2000" placeholder="Pickup at Diyatalawa station, celebrating a birthday, etc.">${esc(b.adminNotes)}</textarea></label>
          </div>
          <div class="form-foot"><span class="src">Changing what is booked, the dates, the room, the extras or a per-person group's guests re-prices the booking unless you edit the total.</span><button class="abtn abtn--primary" type="submit">Save changes</button></div>
        </form>
      </div>`;

    $('[data-dclose]', panel).onclick = closeDrawer;
    if (!isBlock) CONFIRM.mount($('[data-gc-slot]', panel), b, () => reloadDrawer(b.id)); // the guest's confirmation link
    panel.onclick = async (e) => {
      if (e.target.closest('a[href^="#/"]')) { closeDrawer(true); return; } // going to a bill
      if (e.target.closest('[data-rooms]')) { roomsDialog(b, () => reloadDrawer(b.id)); return; }
      if (e.target.closest('[data-invoice]')) { showInvoice(b, folio.invoiceUrl); return; }
      const rem = e.target.closest('[data-remind]');
      if (rem) {
        if (!confirm(`Email ${b.guestName} a ${b.status === 'pending' ? 'payment' : 'arrival'} reminder now?`)) return;
        rem.disabled = true;
        api(`/bookings/${b.id}/reminder`, { method: 'POST', body: {} })
          .then((r) => toast(r.outbox ? 'Saved to data/outbox (email isn\'t set up yet)' : `Reminder sent to ${r.to}`))
          .catch((ex) => toast(ex.message, true))
          .finally(() => { rem.disabled = false; });
        return;
      }
      const rc = e.target.closest('[data-receipt]');
      if (rc) { const p = pays.find((x) => x.id === Number(rc.dataset.receipt)); if (p) showReceipt(p, b); return; }
      if (e.target.closest('[data-pay]')) {
        paymentDialog({
          title: 'Record a payment', sub: `${b.guestName} · ${b.ref}. An advance or part payment counts towards the final bill.`, method: 'bank', commission: b.commission, guestSlips: folio.guestSlips,
          submit: async (p) => {
            const r = await api(`/pos/folios/${b.id}/payments`, { method: 'POST', body: p });
            toast(`${money(p.amount)} recorded`);
            await reloadDrawer(b.id);
            const paid = r.folio.payments.find((x) => x.id === r.folio.paymentId);
            return paid ? () => showReceipt(paid, r.folio.booking) : null; // print or share the receipt
          },
        });
        return;
      }
      const s = e.target.closest('[data-set-status]');
      if (!s) return;
      const status = s.dataset.setStatus;
      if (status === 'confirm') { confirmDialog(b.id, () => { refreshBadge(); reloadDrawer(b.id); }); return; }
      if (status === 'cancelled' && !confirm('Cancel this booking? The dates will become available again.')) return;
      const notify = $('[data-notify]', panel);
      try {
        const r = await api(`/bookings/${b.id}`, { method: 'PATCH', body: { status, notifyGuest: !!(notify && notify.checked) } });
        toast(`Booking ${STATUS[status].toLowerCase()}${r.emailed ? ' — guest emailed' : ''}`);
        refreshBadge();
        await reloadDrawer(b.id);
      } catch (ex) { toast(ex.message, true); }
    };

    const form = $('[data-edit]', panel);
    const original = { what: isBlock ? 'room' : kindValue(b), roomId: b.roomId, checkIn: b.checkIn, checkOut: b.checkOut };
    const kindNow = () => (isBlock ? 'room' : parseKind(form.what.value).kind);
    const syncFields = () => {
      const k = kindNow();
      $$('[data-stay-f], [data-party-f]', form).forEach((el) => { el.hidden = k === 'day_out' || k === 'event'; });
      $$('[data-room-f], [data-plan-f]', form).forEach((el) => { el.hidden = k !== 'room'; });
      $$('[data-event-f]', form).forEach((el) => { el.hidden = k !== 'event'; });
      $('[data-in-label]', form).textContent = k === 'day_out' || k === 'event' ? 'Date' : 'Check-in';
      if (!isBlock) {
        // a party can have many more guests than a stay
        form.adults.max = k === 'event' ? 1000 : 50;
        form.children.max = k === 'event' ? 1000 : 30;
        syncRates(form, k);
        if (k === 'event') $$('[data-rate-f]', form).forEach((el) => { el.hidden = true; }); // a party has an agreed price
        // the hold date is for a reservation waiting for its advance
        const held = form.status.value === 'pending' && advanceNeeded(form);
        $('[data-hold-f]', form).hidden = !held;
        $('[data-adv-own]', form).hidden = form.advanceMode.value !== 'own';
        // back to a reservation, or one that now asks for an advance: it waits the usual days for it
        if (held && (b.status !== 'pending' || !(b.advance && b.advance.required)) && !form.holdUntil.value) form.holdUntil.value = defaultHold(form.checkIn.value);
        commissionHint(form);
      }
    };
    syncFields();
    let totalTouched = false;
    form.totalAmount.addEventListener('input', () => { totalTouched = true; });
    form.addEventListener('input', (e) => { if (!isBlock && /^(commission|totalAmount)/.test(e.target.name)) commissionHint(form); });
    let seq = 0;
    form.addEventListener('change', async (e) => {
      const name = e.target.name;
      if (name === 'status' || name === 'advanceMode' || /^commission/.test(name)) { syncFields(); return; }
      // a different option or kind, meal plan or whole villa starts from the price list
      if (['what', 'mealPlan', 'wholeCategory'].includes(name) && !isBlock) ['rateRoom', 'rateAdult', 'rateChild'].forEach((n) => { form[n].value = ''; });
      if (!['what', 'roomId', 'checkIn', 'checkOut', 'adults', 'children', 'partyArea', 'jblSpeaker', 'rateRoom', 'rateAdult', 'rateChild', 'eventSlot', 'eventOthers', 'mealPlan', 'wholeCategory'].includes(name)) return;
      syncFields();
      const k = kindNow();
      const out = $('[data-avail]', panel);
      if (!form.checkIn.value || (k !== 'day_out' && k !== 'event' && !form.checkOut.value)) return;
      const p = checkQuery(form, b.id);
      // a group (or a party) keeps its rooms when only its dates or guests change
      const sameRooms = (group || partyB) && form.what.value === original.what && b.rooms.length > 0;
      if (sameRooms) p.set('rooms', b.rooms.map((x) => x.id).join(','));
      // a room booking with several rooms: the chosen main room plus its other rooms
      const otherRooms = b.kind === 'room' && k === 'room' && b.rooms.length > 1 ? b.rooms.map((x) => x.id).filter((id) => id !== b.roomId) : [];
      if (otherRooms.length) p.set('rooms', [form.roomId.value, ...otherRooms].join(','));
      const n = ++seq;
      const r = await api(`/check?${p}`).catch(() => null);
      if (!r || n !== seq) return;
      const party = partyTakenText(r);
      let text;
      if (r.reason === 'dates') text = 'Check the dates';
      else if (r.reason) text = 'Choose what is booked';
      else if (!r.ok) text = k === 'room' ? (otherRooms.length ? '✕ One of its rooms is booked on those dates' : '✕ That room is booked on those dates') : sameRooms ? '✕ Its rooms are taken on those dates' : '✕ Not enough free rooms for the group';
      else if (party) text = '✕ Party area already booked';
      else text = k === 'day_out' ? '✓ Day out' : k === 'event' ? `✓ Party area free${r.alreadyBooked && r.alreadyBooked.length ? ` · ⚠ ${r.alreadyBooked.length} booked that night` : ''}`
        : `✓ Available · ${r.nights} night${r.nights === 1 ? '' : 's'}${k === 'per_person' && !sameRooms ? ` · rooms ${roomNames(r.rooms)}` : ''}`;
      if (r.capacity && r.capacity.over) text += ' · ⚠ over the hotel capacity';
      if (r.closed && r.closed.length) text += ' · ⚠ resort closed';
      out.textContent = text;
      out.title = [party, capacityText(r).trim(), closedText(r).trim(), k === 'event' ? partyCheckText(r, form, sameRooms ? b.rooms.map((x) => x.id) : []) : ''].filter(Boolean).join(' ');
      out.style.color = r.ok && !party ? 'var(--moss)' : 'var(--danger)';
      // a room's price doesn't depend on its guests, so noting them keeps the total
      const priced = k !== 'room' || !['adults', 'children'].includes(name);
      if (!isBlock) syncRates(form, k, r);
      if (r.ok && priced && !totalTouched && !isBlock && k !== 'event') { form.totalAmount.value = r.total; commissionHint(form); }
    });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const { kind, packageId } = isBlock ? { kind: 'room', packageId: null } : parseKind(form.what.value);
      const dayOut = kind === 'day_out' || kind === 'event'; // one date
      const whatChanged = !isBlock && form.what.value !== original.what;
      const body = {
        guestName: form.guestName.value, guestPhone: form.guestPhone.value, guestEmail: form.guestEmail.value, country: form.country.value,
        source: form.source.value, status: form.status.value, specialRequests: form.specialRequests.value, adminNotes: form.adminNotes.value,
      };
      // a total typed by hand wins; otherwise the server prices what changed (agreed rates, coupon and all)
      if (totalTouched) body.totalAmount = form.totalAmount.value === '' ? '' : Number(form.totalAmount.value);
      if (!isBlock) Object.assign(body, kind === 'event' ? {} : rateBody(form, kind), commissionBody(form));
      if (!isBlock) {
        if (form.advanceMode.value === 'own' && !(Number(form.advanceAmount.value) >= 1)) { toast('Enter the advance for this booking, or choose another option.', true); form.advanceAmount.focus(); return; }
        body.advanceAmount = advanceBody(form);
      }
      if (!isBlock && form.status.value === 'pending' && advanceNeeded(form)) body.holdUntil = form.holdUntil.value;
      if (!isBlock) body.guestIdNo = form.guestIdNo.value;
      if (!isBlock) Object.assign(body, { adults: Number(form.adults.value || 0), children: Number(form.children.value || 0), partyArea: !dayOut && form.partyArea.checked, jblSpeaker: form.jblSpeaker.checked });
      if (kind === 'event') Object.assign(body, partyBody(form)); // day or night, the time, and what other guests can book
      if (whatChanged) Object.assign(body, { kind, packageId });
      if (kind === 'room' && (whatChanged || Number(form.roomId.value) !== original.roomId)) body.roomId = Number(form.roomId.value);
      if (kind === 'room' && !isBlock) {
        if (whatChanged || form.mealPlan.value !== (b.mealPlan || 'RO')) body.mealPlan = form.mealPlan.value;
        const whole = form.wholeCategory.value ? Number(form.wholeCategory.value) : null;
        if (whole !== b.wholeCategoryId) body.wholeCategory = whole === null ? '' : whole; // its rooms join the booking
      }
      // don't send unchanged dates (avoids needless re-checks of past stays)
      if (whatChanged || form.checkIn.value !== original.checkIn || (!dayOut && form.checkOut.value !== original.checkOut)) {
        body.checkIn = form.checkIn.value;
        if (!dayOut) body.checkOut = form.checkOut.value;
      }
      try {
        await api(`/bookings/${b.id}`, { method: 'PATCH', body });
        toast('Booking saved');
        await reloadDrawer(b.id);
      } catch (ex) { toast(ex.message, true); }
    });
  }

  // ================= NEW BOOKING =================
  async function viewNew(q) {
    setHead('New booking', 'Enter a booking from phone, WhatsApp, walk-in or another website — it blocks those dates on the website straight away.');
    const rr = await api('/rooms');
    ROOMS = rr.rooms;
    CATS = rr.categories || [];
    const plan0 = PLANS().some((p) => p.code === q.get('plan')) ? q.get('plan') : 'RO';
    const whole0 = CATS.find((c) => c.whole && String(c.id) === q.get('whole')) || null;
    const first = (kind) => ME.packages.find((p) => p.kind === kind && p.active) || ME.packages.find((p) => p.kind === kind);
    const asked = ME.packages.find((p) => String(p.id) === q.get('packageId'))
      || (q.get('kind') === 'per_person' ? first('stay') : q.get('kind') === 'day_out' ? first('day') : null);
    const what = q.get('kind') === 'event' ? 'event' : asked ? `pkg:${asked.id}` : 'room';
    const ci = q.get('checkIn') || today();
    const co = q.get('checkOut') || addDays(ci, 1);
    view.innerHTML = `<form class="card form-card" data-new>
      <div class="card__body">
        <p class="section-title">Booking</p>
        <div class="grid4">
          <label class="fld span2" data-guest-f><span>Booking</span><select name="what">${kindOptions(what)}</select></label>
          <label class="fld"><span>Source</span><select name="source">${Object.entries(ME.sources).filter(([k]) => k !== 'website').map(([k, l]) => `<option value="${k}" ${k === 'phone' ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
          <label class="fld"><span>Status</span><select name="status">${[['pending', ME.advanceRule && !ME.advanceRule.on ? 'Reservation — to confirm' : 'Reservation — waiting for the advance'], ['confirmed', 'Confirmed'], ['checked_in', 'Checked in']].map(([k, l]) => `<option value="${k}" ${(q.get('status') || 'pending') === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
          <label class="fld" data-hold-f hidden><span>Hold until <small>then released</small></span><input type="date" name="holdUntil" min="${today()}"><small>If no advance is recorded by then, the dates open again. Empty = never.</small></label>
          <label class="fld"><span data-in-label>Check-in</span><input type="date" name="checkIn" value="${ci}" required></label>
          <label class="fld" data-stay-f><span>Check-out</span><input type="date" name="checkOut" value="${co}"></label>
          <label class="fld" data-guest-f><span>Adults <small data-opt-guests>(optional)</small></span><input type="number" name="adults" min="0" max="50" value="${what === 'room' ? 0 : 2}"></label>
          <label class="fld" data-guest-f><span>Children</span><input type="number" name="children" min="0" max="30" value="0"></label>
          <label class="chk" data-guest-f data-party-f><input type="checkbox" name="partyArea" ${q.get('partyArea') === '1' ? 'checked' : ''}> Party area</label>
          <label class="chk" data-guest-f><input type="checkbox" name="jblSpeaker"> JBL speaker</label>
        </div>
        <!-- room bookings: pick the rooms (as on the rate sheet), the meal plan, or a whole villa -->
        <div class="rpick" data-room-f>
          <div class="rpick__head">
            <p class="section-title">Rooms <span class="rpick__sum" data-rpick-sum></span></p>
            <div class="rpick__plans" data-plan-f role="radiogroup" aria-label="Meal plan">
              ${PLANS().map((p) => `<label class="rpick__plan" title="${esc(p.includes || p.name)}"><input type="radio" name="planPick" value="${esc(p.code)}" ${p.code === plan0 ? 'checked' : ''}><b>${esc(p.code)}</b>${esc(p.name)}</label>`).join('')}
              <input type="hidden" name="mealPlan" value="${esc(plan0)}"><input type="hidden" name="wholeCategory" value="${whole0 ? whole0.id : ''}">
            </div>
          </div>
          <input type="hidden" name="roomId" value="">
          <div class="rpick__cats" data-rpick-list></div>
          <p class="src">Tap rooms to put them in this booking (several rooms are one booking and one bill), or take a whole villa at its one price. Booked rooms show the booking that has them.</p>
        </div>
        ${partyFields({})}
        <div class="room-pick" data-group-f hidden>
          <label class="chk"><input type="checkbox" name="pickRooms"> <span data-pick-label>Choose the group's rooms myself <span class="src">— otherwise they're taken from the room priority list (Booking setup)</span></span></label>
          <div class="room-pick__list" data-pick-list hidden>${ROOMS.map((r) => `<label class="room-pick__item"><input type="checkbox" name="room" value="${r.id}" data-cap="${r.capacity}"><span><b>${esc(r.name)}</b><small>sleeps ${r.capacity}${r.active ? '' : ' · hidden'}</small></span></label>`).join('')}</div>
        </div>
        <div class="note note--info" data-check>Checking availability…</div>

        <p class="section-title" data-guest-f>Guest</p>
        <div class="grid3" data-guest-f>
          <label class="fld"><span>Guest name</span><input name="guestName" maxlength="120" placeholder="Full name"></label>
          <label class="fld"><span>Phone / WhatsApp</span><input name="guestPhone" maxlength="40" placeholder="+94 7X XXX XXXX"></label>
          <label class="fld"><span>NIC / passport <small>(optional)</small></span><input name="guestIdNo" maxlength="30" autocapitalize="characters" spellcheck="false" placeholder="e.g. 199012345678"></label>
          <label class="fld"><span>Email <small>(optional)</small></span><input type="email" name="guestEmail" maxlength="160"></label>
          <label class="fld"><span>Country</span><input name="country" maxlength="60" placeholder="Sri Lanka"></label>
          <label class="fld"><span>Guest requests</span><input name="specialRequests" maxlength="1500" placeholder="Dinner, pickup, celebrations…"></label>
        </div>

        <p class="section-title" data-guest-f>Price</p>
        <div class="grid3" data-guest-f>
          ${rateFields({}, {})}
          <label class="fld"><span>Total (${esc(ME.currency)})</span><input type="number" name="totalAmount" min="0" step="1"><small data-total-hint></small></label>
          ${commissionFields({})}
          ${advanceFields(null)}
        </div>
        <p class="section-title">Notes</p>
        <div class="grid3">
          <label class="fld span3"><span>Private notes</span><input name="adminNotes" maxlength="2000" placeholder="e.g. Booking.com ref 123456"></label>
        </div>
        <label class="chk" data-guest-f data-notify-f><input type="checkbox" name="notifyGuest"> Email a confirmation to the guest (needs an email address)</label>
        <label class="chk" data-guest-f><input type="checkbox" name="sendConfirm"> Then send the guest a link to check the booking, fill in any missing details (NIC / passport, email…) and confirm it</label>
      </div>
      <div class="form-foot">
        <span class="src">A reservation holds the dates while the guest pays the advance — you can send them the invoice as soon as it is saved. Choose “Blocked dates” as the source to close a room for maintenance or owner use.</span>
        <button class="abtn abtn--primary" type="submit" data-save>${icon('a-plus')}Save booking</button>
      </div>
    </form>`;
    const form = $('[data-new]');
    const pickList = $('[data-pick-list]', form);
    let totalTouched = false;
    let guestsTouched = false;
    let holdTouched = false;
    form.totalAmount.addEventListener('input', () => { totalTouched = true; });
    form.holdUntil.addEventListener('input', () => { holdTouched = true; });
    form.addEventListener('input', (e) => { if (/^(commission|totalAmount)/.test(e.target.name)) commissionHint(form); });
    // a different option, meal plan or whole villa starts from the price list
    form.what.addEventListener('change', () => ['rateRoom', 'rateAdult', 'rateChild'].forEach((n) => { form[n].value = ''; }));
    // ---- the room picker: rooms by category, priced for the meal plan, free or booked on the dates ----
    const pickBox = $('[data-rpick-list]', form);
    let sel = ROOMS.filter((r) => String(r.id) === String(q.get('room'))).map((r) => r.id); // chosen rooms, the main room first
    let taken = {}; // room id -> the booking that has it on these dates
    const roomById = (id) => ROOMS.find((r) => r.id === id) || {};
    const catRoomIds = (c) => ROOMS.filter((r) => r.categoryId === c.id && r.active).map((r) => r.id);
    const plan = () => form.mealPlan.value || 'RO';
    const priceOf = (r) => Number((r.prices || {})[plan()]) || 0;
    const wholeCat = () => CATS.find((c) => c.whole && String(c.id) === form.wholeCategory.value) || null;
    if (wholeCat()) sel = [...new Set([...catRoomIds(wholeCat()), ...sel])];
    function syncPick() {
      const w = wholeCat(); // a whole villa stays only while every room of it is chosen
      if (w && !catRoomIds(w).every((id) => sel.includes(id))) form.wholeCategory.value = '';
      form.roomId.value = sel[0] || '';
    }
    function renderPick() {
      const isBlock = form.source.value === 'block';
      const w = isBlock ? null : wholeCat();
      const tile = (r) => {
        const on = sel.includes(r.id);
        const busy = taken[r.id] !== undefined;
        const price = priceOf(r);
        const off = !on && (busy || (!isBlock && !price));
        const state = busy ? `Booked${taken[r.id] ? ` · ${esc(taken[r.id])}` : ''}` : w && r.categoryId === w.id ? 'In the whole villa' : 'Free';
        return `<button type="button" class="rpick__room${on ? ' is-on' : ''}${busy ? ' is-busy' : ''}" data-pick-room="${r.id}" aria-pressed="${on}" ${off ? 'disabled' : ''}>
          <b>${esc(r.name)}</b><small>${esc(r.label || r.type)} · sleeps ${r.capacity}${r.active ? '' : ' · hidden'}</small>
          ${isBlock ? '' : `<span class="rpick__price">${price ? `${money(price)} / night` : `No ${esc(planName(plan()).toLowerCase())}`}</span>`}
          <span class="rpick__st">${state}</span>
        </button>`;
      };
      const groups = CATS.map((c) => ({ c, rooms: ROOMS.filter((r) => r.categoryId === c.id) })).filter((g) => g.rooms.length);
      const loose = ROOMS.filter((r) => !groups.some((g) => g.c.id === r.categoryId));
      pickBox.innerHTML = groups.map(({ c, rooms }) => {
        const ids = catRoomIds(c);
        const wp = c.whole ? Number(c.whole.prices[plan()]) || 0 : 0;
        const on = !!w && w.id === c.id;
        const can = !!c.whole && ids.length > 0 && wp > 0 && ids.every((id) => taken[id] === undefined);
        const wholeBtn = c.whole && !isBlock ? `<button type="button" class="rpick__whole${on ? ' is-on' : ''}" data-pick-whole="${c.id}" aria-pressed="${on}" ${can || on ? '' : 'disabled'}
          title="${can || on ? `Every room of ${esc(c.name)}, at one price` : 'Some of its rooms are booked on these dates, or it has no price for this meal plan'}">${on ? '✓ ' : ''}${esc(c.whole.label)}${wp ? ` · ${money(wp)} / night` : ''}</button>` : '';
        return `<section class="rpick__cat"><header><b>${esc(c.name)}</b>${wholeBtn}</header><div class="rpick__rooms">${rooms.map(tile).join('')}</div></section>`;
      }).join('') + (loose.length ? `<section class="rpick__cat"><header><b>${groups.length ? 'Other rooms' : 'Rooms'}</b></header><div class="rpick__rooms">${loose.map(tile).join('')}</div></section>` : '');
      const cap = sel.reduce((sum, id) => sum + (roomById(id).capacity || 0), 0);
      const night = (w ? Number(w.whole.prices[plan()]) || 0 : 0) + sel.filter((id) => !w || roomById(id).categoryId !== w.id).reduce((sum, id) => sum + priceOf(roomById(id)), 0);
      $('[data-rpick-sum]', form).textContent = !sel.length ? '· none chosen yet'
        : `· ${sel.length} room${sel.length === 1 ? '' : 's'}, sleep ${cap}${isBlock ? '' : ` · ${money(night)} a night, ${planName(plan()).toLowerCase()}`}`;
    }
    pickBox.addEventListener('click', (e) => {
      const wb = e.target.closest('[data-pick-whole]');
      const rb = e.target.closest('[data-pick-room]');
      if (!wb && !rb) return;
      if (wb) { // a whole villa: all its rooms, or none of them
        const c = CATS.find((x) => String(x.id) === wb.dataset.pickWhole);
        const ids = catRoomIds(c);
        const on = form.wholeCategory.value === String(c.id);
        const rest = sel.filter((id) => !ids.includes(id));
        if (!on && rest.length + ids.length > 10) { toast('A booking can have up to 10 rooms.', true); return; }
        sel = on ? rest : [...ids, ...rest];
        form.wholeCategory.value = on ? '' : String(c.id);
      } else {
        const id = Number(rb.dataset.pickRoom);
        if (!sel.includes(id) && sel.length >= 10) { toast('A booking can have up to 10 rooms.', true); return; }
        sel = sel.includes(id) ? sel.filter((x) => x !== id) : [...sel, id];
      }
      form.rateRoom.value = ''; // other rooms start from the price list
      syncPick();
      renderPick();
      check();
    });
    // the meal plan buttons (registered before the availability check, which then re-prices)
    form.addEventListener('change', (e) => {
      if (e.target.name !== 'planPick') return;
      form.mealPlan.value = e.target.value;
      form.rateRoom.value = '';
      renderPick();
    });
    syncPick();
    [form.adults, form.children].forEach((x) => x.addEventListener('input', () => { guestsTouched = true; }));
    const checkBox = $('[data-check]');
    let lastKind = parseKind(what).kind;
    let seq = 0;
    async function check() {
      const isBlock = form.source.value === 'block';
      if (isBlock) form.what.value = 'room'; // blocked dates close a room
      const { kind } = parseKind(form.what.value);
      const isPartyKind = kind === 'event';
      const dayOut = kind === 'day_out' || isPartyKind; // one date
      const group = kind === 'per_person';
      $$('[data-guest-f]', form).forEach((el) => { el.hidden = isBlock; });
      $$('[data-room-f]', form).forEach((el) => { el.hidden = kind !== 'room'; });
      $$('[data-plan-f]', form).forEach((el) => { el.hidden = kind !== 'room' || isBlock; });
      $$('[data-stay-f]', form).forEach((el) => { el.hidden = dayOut; });
      $$('[data-party-f]', form).forEach((el) => { el.hidden = isBlock || dayOut; });
      $$('[data-event-f]', form).forEach((el) => { el.hidden = !isPartyKind || isBlock; });
      const roomKind = kind === 'room';
      if (isBlock && form.wholeCategory.value) { form.wholeCategory.value = ''; syncPick(); } // blocked dates close rooms, no prices
      $('[data-group-f]', form).hidden = !(group || isPartyKind) || isBlock;
      $('[data-pick-label]', form).innerHTML = isPartyKind ? 'Hold rooms for party guests who stay the night <span class="src">— optional; the price is in the party\'s total</span>'
        : 'Choose the group\'s rooms myself <span class="src">— otherwise they\'re taken from the room priority list (Booking setup)</span>';
      form.adults.max = isPartyKind ? 1000 : 50;
      form.children.max = isPartyKind ? 1000 : 30;
      pickList.hidden = !form.pickRooms.checked;
      $('[data-opt-guests]', form).hidden = kind !== 'room';
      form.adults.min = kind === 'room' ? 0 : 1;
      // a room needs no guest numbers; per person does
      if (kind !== lastKind && !guestsTouched) form.adults.value = kind === 'room' ? 0 : 2;
      lastKind = kind;
      $('[data-in-label]', form).textContent = isPartyKind ? 'Party date' : dayOut ? 'Date' : 'Check-in';
      form.status.disabled = isBlock;
      const reservation = form.status.value === 'pending' && !isBlock;
      const held = reservation && advanceNeeded(form); // only a reservation waiting for an advance is held
      $('[data-hold-f]', form).hidden = !held;
      $('[data-adv-own]', form).hidden = form.advanceMode.value !== 'own';
      $('[data-notify-f]', form).hidden = isBlock || reservation; // a reservation gets its invoice instead
      if (held && !holdTouched) form.holdUntil.value = defaultHold(form.checkIn.value);
      syncRates(form, kind);
      if (isPartyKind) $$('[data-rate-f]', form).forEach((el) => { el.hidden = true; }); // a party has an agreed price
      commissionHint(form);
      if (!form.checkIn.value || (!dayOut && (!form.checkOut.value || form.checkOut.value <= form.checkIn.value))) {
        checkBox.className = 'note note--bad'; checkBox.textContent = isPartyKind ? 'Choose the party date.' : dayOut ? 'Choose the date of the day out.' : 'Check-out must be after check-in.'; return;
      }
      const p = checkQuery(form);
      const picked = (group || isPartyKind) && form.pickRooms.checked ? pickedRooms(pickList) : [];
      // a room booking: the rooms chosen in the picker, its main room first
      const roomList = roomKind ? sel.slice() : [];
      if (roomKind ? roomList.length > 1 : picked.length) p.set('rooms', (roomKind ? roomList : picked).join(','));
      const n = ++seq;
      const r = await api(`/check?${p}`).catch(() => null);
      if (!r || n !== seq) return;
      // the picker shows which rooms other bookings have on these dates
      taken = {};
      (r.busy || []).forEach((id) => { taken[id] = (r.busyRefs || {})[id] || ''; });
      if (roomKind) renderPick();
      // a group's or party's room list too
      $$('[name="room"]', pickList).forEach((x) => {
        const busy = (r.busy || []).includes(Number(x.value));
        x.closest('.room-pick__item').classList.toggle('is-taken', busy);
        x.disabled = busy && !x.checked;
      });
      if (roomKind && !sel.length) {
        checkBox.className = 'note note--info';
        checkBox.textContent = `Choose ${isBlock ? 'the rooms to close' : 'a room, several rooms or a whole villa'} below for ${r.nights} night${r.nights === 1 ? '' : 's'} (${fd(form.checkIn.value)} → ${fd(form.checkOut.value)}).${isBlock ? '' : closedText(r)}`;
        if (!totalTouched) form.totalAmount.value = '';
        $('[data-total-hint]').textContent = '';
        commissionHint(form);
        return;
      }
      const rm = roomById(sel[0]);
      const people = Number(form.adults.value || 0) + Number(form.children.value || 0);
      const nights = `${r.nights} night${r.nights === 1 ? '' : 's'}`;
      const party = partyTakenText(r);
      let good = r.ok;
      let msg;
      if (isPartyKind) {
        msg = partyCheckText(r, form, picked);
      } else if (dayOut) msg = `✓ Day out on ${fd(form.checkIn.value, { weekday: 'long', day: 'numeric', month: 'long' })} — any number of groups can come on the same day.`;
      else if (kind === 'room' && !isBlock && form.wholeCategory.value) {
        // a whole villa: all its rooms (and any added), at its one price
        const c = CATS.find((x) => String(x.id) === form.wholeCategory.value) || { name: 'Whole villa', whole: { label: '' } };
        const ids = r.wholeRooms && r.wholeRooms.length ? r.wholeRooms : roomList;
        const cap = ids.reduce((s, id) => s + ((ROOMS.find((x) => x.id === id) || {}).capacity || 0), 0);
        msg = r.ok ? `✓ ${c.name} — ${c.whole.label}: ${roomNames(ids)} (${ids.length} rooms, sleep ${cap}) are free for ${nights} (${fd(form.checkIn.value)} → ${fd(form.checkOut.value)}).`
          : '✕ One of its rooms is already booked for some of those nights. Pick other dates (see the Calendar), or book the rooms one by one.';
        if (people > cap) { msg += ` ✕ These rooms sleep ${cap}, not ${people}.`; good = false; }
      } else if (kind === 'room' && roomList.length > 1) {
        const cap = roomList.reduce((s, id) => s + ((ROOMS.find((x) => x.id === id) || {}).capacity || 0), 0);
        msg = r.ok ? `✓ ${roomNames(roomList)} (${roomList.length} rooms, sleep ${cap}) are free for ${nights} (${fd(form.checkIn.value)} → ${fd(form.checkOut.value)}).`
          : '✕ One of those rooms is already booked for some of those nights. Pick other dates or other rooms (see the Calendar).';
        if (!isBlock && people > cap) { msg += ` ✕ These rooms sleep ${cap}, not ${people}.`; good = false; }
      } else if (kind === 'room') {
        msg = r.ok ? `✓ ${rm.name} (sleeps ${rm.capacity}) is free for ${nights} (${fd(form.checkIn.value)} → ${fd(form.checkOut.value)}).`
          : `✕ ${rm.name} is already booked for some of those nights. Pick other dates or another room (see the Calendar).`;
        if (!isBlock && people > rm.capacity) { msg += ` ✕ ${rm.name} sleeps ${rm.capacity}, not ${people}.`; good = false; }
      } else if (picked.length) {
        const beds = picked.reduce((s, id) => s + ((ROOMS.find((x) => x.id === id) || {}).capacity || 0), 0);
        msg = r.ok ? `✓ ${roomNames(picked)} — free for ${nights}, sleeping ${beds}.${beds < people ? ` ⚠ That's fewer beds than the ${people} guests.` : ''}`
          : '✕ Some of the rooms you ticked are taken on those dates.';
      } else {
        msg = r.ok ? `✓ ${people} guest${people === 1 ? '' : 's'} for ${nights}. They get ${roomNames(r.rooms)} from the room priority list.`
          : `✕ The room priority list has no free rooms for ${people} guests on those nights. Tick “Choose the group's rooms myself”, or change the dates.`;
      }
      if (party) { msg += ` ✕ ${party} Untick it or pick other dates.`; good = false; }
      msg += capacityText(r);
      if (!isBlock) msg += closedText(r);
      const warn = (r.capacity && r.capacity.over) || (!isBlock && r.closed && r.closed.length) || (isPartyKind && r.alreadyBooked && r.alreadyBooked.length);
      checkBox.className = `note ${!good ? 'note--bad' : warn ? 'note--warn' : 'note--ok'}`;
      checkBox.textContent = msg;
      $('[data-total-hint]').textContent = isBlock ? '' : isPartyKind ? 'The price agreed for the party.' : rackRate(r);
      syncRates(form, kind, r);
      if (isPartyKind) $$('[data-rate-f]', form).forEach((el) => { el.hidden = true; });
      if (!totalTouched && !isPartyKind) form.totalAmount.value = isBlock ? 0 : r.total || '';
      commissionHint(form);
    }
    form.addEventListener('change', check);
    check();
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const isBlock = form.source.value === 'block';
      const { kind, packageId } = isBlock ? { kind: 'room', packageId: null } : parseKind(form.what.value);
      const dayOut = kind === 'day_out' || kind === 'event'; // one date
      if (!isBlock && !form.guestName.value.trim()) { toast('Please enter the guest name.', true); form.guestName.focus(); return; }
      if (kind === 'event' && form.totalAmount.value === '') { toast('Enter the price agreed for the party.', true); form.totalAmount.focus(); return; }
      const picking = (kind === 'per_person' || kind === 'event') && form.pickRooms.checked;
      const picked = picking ? pickedRooms(pickList) : [];
      if (kind === 'room' && !sel.length) {
        toast(isBlock ? 'Choose the rooms to close.' : 'Choose a room for this booking.', true);
        pickBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }
      if (picking && !picked.length) { toast(kind === 'event' ? 'Tick the rooms to hold, or untick “Hold rooms for party guests”.' : 'Tick the rooms for the group, or untick “Choose the group\'s rooms myself”.', true); return; }
      const body = {
        kind, packageId: packageId || undefined, roomId: kind === 'room' ? sel[0] : undefined,
        checkIn: form.checkIn.value, checkOut: dayOut ? undefined : form.checkOut.value,
        adults: isBlock ? 0 : Number(form.adults.value || 0), children: isBlock ? 0 : Number(form.children.value || 0),
        partyArea: !isBlock && !dayOut && form.partyArea.checked, jblSpeaker: !isBlock && form.jblSpeaker.checked,
        // a room booking's rooms (its main room first), or a group's / party's rooms
        rooms: kind === 'room' ? (sel.length > 1 ? sel : undefined) : (picked.length ? picked : undefined),
        // a room booking's meal plan, and a whole villa (all its rooms at one price)
        mealPlan: kind === 'room' && !isBlock ? form.mealPlan.value : undefined,
        wholeCategory: kind === 'room' && !isBlock && form.wholeCategory.value ? Number(form.wholeCategory.value) : undefined,
        source: form.source.value, status: form.status.value,
        guestName: form.guestName.value, guestPhone: form.guestPhone.value, guestEmail: form.guestEmail.value, country: form.country.value,
        guestIdNo: isBlock ? '' : form.guestIdNo.value,
        specialRequests: form.specialRequests.value, adminNotes: form.adminNotes.value, totalAmount: form.totalAmount.value,
        notifyGuest: form.notifyGuest.checked && form.status.value !== 'pending',
      };
      if (!isBlock) Object.assign(body, kind === 'event' ? partyBody(form) : rateBody(form, kind), commissionBody(form));
      if (!isBlock) {
        if (form.advanceMode.value === 'own' && !(Number(form.advanceAmount.value) >= 1)) { toast('Enter the advance for this booking, or choose another option.', true); form.advanceAmount.focus(); return; }
        body.advanceAmount = advanceBody(form);
      }
      if (!isBlock && form.status.value === 'pending' && advanceNeeded(form)) body.holdUntil = form.holdUntil.value;
      const btn = $('[data-save]'); btn.disabled = true;
      try {
        const r = await api('/bookings', { method: 'POST', body });
        toast(`Saved ${r.booking.ref}${r.emailed ? ' — guest emailed' : ''}`);
        if (!isBlock && form.sendConfirm.checked) {
          // the booking, with the window that sends the guest their confirmation link
          openConfirmFor = r.booking.id;
          location.hash = `#/bookings/${r.booking.id}`;
          return;
        }
        if (r.booking.status === 'pending' && !isBlock) {
          // a reservation: show it with its invoice, ready to send
          openInvoiceFor = r.booking.id;
          location.hash = `#/bookings/${r.booking.id}`;
          return;
        }
        calFrom = addDays(r.booking.checkIn, -2);
        location.hash = '#/calendar';
      } catch (ex) { toast(ex.message, true); btn.disabled = false; }
    });
  }

  // ================= ROOMS =================
  async function viewRooms() {
    setHead('Rooms', 'Names, descriptions and the photos shown on the website, and whether guests can book each room.');
    const rr = await api('/rooms');
    ROOMS = rr.rooms;
    CATS = rr.categories || [];
    view.innerHTML = `<div class="rooms-admin">
      <div class="note note--info">Each room's <b>category, room type, guests and prices</b> (room only, bed &amp; breakfast, half board, full board) are on the <a href="#/rates">Rate sheet</a>. Per-person options, the party area and JBL prices, the hotel's capacity and the order rooms are given to per-person groups are in <a href="#/setup">Booking setup</a>.</div>
      ${ROOMS.map(roomEditor).join('')}
    </div>`;
    view.onclick = (e) => {
      const rm = e.target.closest('[data-rm-img]');
      if (rm) {
        const card = rm.closest('[data-room-ed]');
        const imgs = JSON.parse(card.dataset.images).filter((k) => k !== rm.dataset.rmImg);
        if (!imgs.length) { toast('A room needs at least one photo.', true); return; }
        setRoomImages(card, imgs);
      }
      const add = e.target.closest('[data-add-img]');
      if (add) {
        const card = add.closest('[data-room-ed]');
        openLibrary(JSON.parse(card.dataset.images), (imgs) => setRoomImages(card, imgs));
      }
    };
    $$('[data-room-ed]').forEach((card) => card.addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = e.target;
      const body = {
        name: f.name.value, tagline: f.tagline.value, description: f.description.value,
        beds: f.beds.value, size: Number(f.size.value), active: f.active.checked,
        features: f.features.value.split('\n').map((s) => s.trim()).filter(Boolean),
        images: JSON.parse(card.dataset.images),
      };
      try {
        const r = await api(`/rooms/${card.dataset.roomEd}`, { method: 'PATCH', body });
        ROOMS = ROOMS.map((x) => (x.id === r.room.id ? r.room : x));
        toast(`${body.name} saved — the website is updated`);
      } catch (ex) { toast(ex.message, true); }
    }));
  }
  function thumbsHtml(images) {
    return images.map((k, i) => `<div class="thumb"><img src="${img(k, 640)}" alt="">${i === 0 ? '<span class="first">COVER</span>' : ''}<button type="button" data-rm-img="${esc(k)}" aria-label="Remove photo">${icon('a-x')}</button></div>`).join('');
  }
  function setRoomImages(card, imgs) {
    card.dataset.images = JSON.stringify(imgs);
    $('.room-ed__cover img', card).src = img(imgs[0], 640);
    $('.thumbs', card).innerHTML = thumbsHtml(imgs);
  }
  function roomEditor(r) {
    return `<form class="card room-ed" data-room-ed="${r.id}" data-images='${esc(JSON.stringify(r.images))}'>
      <div class="room-ed__media">
        <div class="room-ed__cover"><img src="${img(r.images[0], 640)}" alt=""></div>
        <div class="thumbs">${thumbsHtml(r.images)}</div>
        <button type="button" class="abtn abtn--sm" data-add-img>${icon('a-plus', 15)}Choose / upload photos</button>
      </div>
      <div class="room-ed__form">
        <div class="grid4">
          <label class="fld span2"><span>Name</span><input name="name" value="${esc(r.name)}" required maxlength="80"></label>
          <label class="fld"><span>Beds</span><input name="beds" value="${esc(r.beds)}" maxlength="80"></label>
          <label class="fld"><span>Size (m²)</span><input type="number" name="size" min="0" value="${r.size}"></label>
          <div class="fld full room-ed__rates"><span>Rate sheet <a href="#/rates" class="linkbtn">Edit</a></span><p>${esc(r.type)}${r.label ? ` · ${esc(r.label)}` : ''} · sleeps ${r.capacity} · ${PLANS().map((p) => `${esc(p.code)} ${money((r.prices || {})[p.code] || 0)}`).join(' · ')}</p></div>
          <label class="fld full"><span>Tagline</span><input name="tagline" value="${esc(r.tagline)}" maxlength="200"></label>
          <label class="fld full"><span>Features <small>(one per line)</small></span><textarea name="features" rows="3">${esc(r.features.join('\n'))}</textarea></label>
          <label class="fld full"><span>Description</span><textarea name="description" maxlength="2000">${esc(r.description)}</textarea></label>
        </div>
        <small class="room-ed__hint">“${esc(ME.labels.room)}” bookings pay the room's price for the meal plan chosen and take ${r.capacity} of the hotel's guest places, however many come. “Bookable on the website” lets guests choose this room themselves; per-person groups can be given it either way (the room list in Booking setup decides).</small>
        <div class="room-ed__foot">
          <label class="switch"><input type="checkbox" name="active" ${r.active ? 'checked' : ''}> Bookable on the website</label>
          <button class="abtn abtn--primary" type="submit">Save room</button>
        </div>
      </div>
    </form>`;
  }

  // ---------- photo library ----------
  const lib = $('[data-library]');
  const libPanel = $('[data-library-panel]');
  let libDone = null;
  let libSel = [];
  let libOpt = { max: 8, hint: '', saveHint: '' };
  function closeLibrary() { lib.classList.remove('is-open'); lib.setAttribute('aria-hidden', 'true'); }
  $('[data-library-close]', lib).addEventListener('click', closeLibrary);
  /**
   * Pick photos: done(keys) gets them in the order they were tapped.
   * opts.max (default 8; 1 = one photo, a tap swaps it), opts.hint, opts.saveHint (toast after).
   */
  function openLibrary(selected, done, opts = {}) {
    libOpt = { max: 8, hint: 'Tap photos to add them to this room — the first one is the cover.', saveHint: 'Photos updated — remember to save the room', ...opts };
    libSel = selected.slice(0, libOpt.max);
    libDone = done;
    lib.classList.add('is-open');
    lib.setAttribute('aria-hidden', 'false');
    renderLibrary();
  }
  const libAdd = (k) => (libOpt.max === 1 ? [k] : libSel.concat(k).slice(0, libOpt.max));
  function renderLibrary() {
    const keys = Object.keys(MEDIA).sort((a, b) => Number(b.startsWith('upload-')) - Number(a.startsWith('upload-')));
    libPanel.innerHTML = `
      <header class="dr-head"><div><h2>Photo library</h2><p>${esc(libOpt.hint)}</p></div><button class="abtn abtn--ghost" type="button" data-lclose aria-label="Close">${icon('a-x', 20)}</button></header>
      <div class="dr-body">
        <label class="drop" data-drop>${icon('a-up', 22)}<b>Upload new photos</b><br>Drag JPG / PNG files here or click to choose. They're resized and optimised automatically.
          <input type="file" accept="image/jpeg,image/png,image/webp" ${libOpt.max === 1 ? '' : 'multiple'} hidden data-file></label>
        <div class="lib-grid">${keys.map((k) => {
          const n = libSel.indexOf(k);
          return `<button type="button" class="lib-item${n >= 0 ? ' is-on' : ''}" data-pick="${esc(k)}"><img src="${img(k, 640)}" alt="" loading="lazy"><span class="tick">${libOpt.max === 1 ? '✓' : n + 1}</span>${k.startsWith('upload-') ? `<span class="lib-del" data-del="${esc(k)}" role="button">Delete</span>` : ''}</button>`;
        }).join('')}</div>
        <div style="display:flex;justify-content:flex-end;gap:8px"><button class="abtn" type="button" data-lclose>Cancel</button><button class="abtn abtn--primary" type="button" data-luse>${libOpt.max === 1 ? 'Use this photo' : `Use ${libSel.length} photo${libSel.length === 1 ? '' : 's'}`}</button></div>
      </div>`;
    const drop = $('[data-drop]', libPanel);
    const file = $('[data-file]', libPanel);
    file.onchange = () => upload([...file.files]);
    drop.ondragover = (e) => { e.preventDefault(); drop.classList.add('is-over'); };
    drop.ondragleave = () => drop.classList.remove('is-over');
    drop.ondrop = (e) => { e.preventDefault(); drop.classList.remove('is-over'); upload([...e.dataTransfer.files]); };
  }
  libPanel.addEventListener('click', async (e) => {
    if (e.target.closest('[data-lclose]')) { closeLibrary(); return; }
    const del = e.target.closest('[data-del]');
    if (del) {
      e.stopPropagation();
      if (!confirm('Delete this uploaded photo permanently?')) return;
      try {
        await api(`/media/${encodeURIComponent(del.dataset.del)}`, { method: 'DELETE' });
        const next = { ...MEDIA }; delete next[del.dataset.del]; MEDIA = next;
        libSel = libSel.filter((k) => k !== del.dataset.del);
        renderLibrary();
      } catch (ex) { toast(ex.message, true); }
      return;
    }
    const p = e.target.closest('[data-pick]');
    if (p) {
      const k = p.dataset.pick;
      libSel = libSel.includes(k) && libOpt.max !== 1 ? libSel.filter((x) => x !== k) : libAdd(k);
      renderLibrary();
      return;
    }
    if (e.target.closest('[data-luse]')) {
      if (!libSel.length) { toast('Pick at least one photo.', true); return; }
      libDone(libSel);
      closeLibrary();
      if (libOpt.saveHint) toast(libOpt.saveHint);
    }
  });
  async function upload(files) {
    for (const f of files) {
      if (!/^image\//.test(f.type)) continue;
      toast(`Uploading ${f.name}…`);
      try {
        const r = await api('/media', { method: 'POST', raw: { data: f, type: f.type, name: f.name } });
        MEDIA = { ...MEDIA, [r.key]: r.item };
        libSel = libAdd(r.key);
        renderLibrary();
      } catch (ex) { toast(`${f.name}: ${ex.message}`, true); }
    }
  }

  // ================= SETTINGS =================
  /** Booking notifications: the admin addresses emailed about every new website booking. */
  const emailRow = (e = '') => `<div class="nt-row nt-row--email" data-ne-row>
      <input class="inp" type="email" name="email" value="${esc(e)}" maxlength="254" placeholder="name@example.com" autocomplete="off" aria-label="Email address">
      <button type="button" class="abtn abtn--sm" data-ne-test title="Send a test email to this address">${icon('a-mail', 14)}Test</button>
      <button type="button" class="abtn abtn--sm abtn--ghost" data-ne-rm aria-label="Remove this email">${icon('a-x', 14)}</button>
    </div>`;
  const notifyCard = (n) => `<section class="card" data-ne-card data-max="${n.max}">
    <div class="card__head"><h2>Booking notifications</h2>${ME.emailEnabled ? '<span class="st st--confirmed">Email on</span>' : '<span class="st st--pending">Not set up</span>'}</div>
    <div class="card__body" style="display:grid;gap:14px">
      <p style="margin:0">Every new website booking is emailed to each address below, with the guest's details and a link to the booking. Emails are sent from <b>${esc(ME.mailFrom)}</b>.</p>
      ${ME.emailEnabled ? '' : `<div class="note note--info">Email sending isn't set up yet, so messages are being saved to <code>data/outbox/</code> on the server. To switch it on, create the mailbox <b>${esc(ME.mailFrom)}</b> in your hosting panel and put its password in the <code>.env</code> file as <code>SMTP_PASS</code> (with <code>SMTP_HOST</code> and <code>SMTP_USER</code>).</div>`}
      <form data-ne-form style="display:grid;gap:10px" novalidate>
        <p class="section-title">Admin emails</p>
        <div class="nt-list" data-ne-list>${(n.emails.length ? n.emails : ['']).map(emailRow).join('')}</div>
        ${n.emails.length ? '' : '<div class="note note--bad">Nobody is emailed about new bookings yet. Add at least one address and save.</div>'}
        ${n.fromEnv && n.emails.length ? '<p class="src" style="margin:0">These come from <code>ADMIN_NOTIFY_EMAILS</code> in <code>.env</code>. Once you save here, this list is used instead.</p>' : ''}
        <div style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="abtn" data-ne-add>+ Add an email</button><span style="flex:1"></span><button class="abtn abtn--primary" type="submit">Save emails</button></div>
      </form>
      <ul>
        <li>Guests get a “booking received” email with the total, the bank details and the number to call, plus an email when you confirm or cancel (if “Email the guest” is ticked).</li>
      </ul>
    </div>
  </section>`;

  /** WhatsApp alerts: a message to each of the resort's phones for new and confirmed bookings (CallMeBot). */
  const waRow = (n = {}) => `<div class="nt-row nt-row--wa" data-wa-row data-id="${esc(n.id || '')}">
      <label class="fld"><span>Name <small>optional</small></span><input name="name" value="${esc(n.name || '')}" maxlength="40" placeholder="e.g. Owner" autocomplete="off"></label>
      <label class="fld"><span>WhatsApp number</span><input name="phone" value="${esc(n.phone || '')}" maxlength="20" placeholder="94777368264" inputmode="tel" autocomplete="off" title="With the country code, e.g. 94777368264"></label>
      <button type="button" class="abtn abtn--sm abtn--ghost" data-wa-rm aria-label="Remove this number">${icon('a-x', 14)}</button>
      <label class="fld nt-key"><span>CallMeBot API key</span><input name="apiKey" maxlength="64" autocomplete="off" placeholder="${n.hasKey ? `${esc(n.keyHint)} saved — type to replace` : 'from the CallMeBot reply'}"></label>
      <button type="button" class="abtn abtn--sm" data-wa-test title="Send a test message to this number">${icon('a-wa', 14)}Test</button>
    </div>`;
  const waCard = (w) => {
    const missing = w.numbers.filter((n) => !n.hasKey).length;
    const status = !w.on ? '<span class="st st--checked_out">Paused</span>'
      : !w.numbers.length ? '<span class="st st--pending">No numbers</span>'
      : w.enabled ? `<span class="st st--confirmed">On${missing ? ` · ${missing} need${missing === 1 ? 's' : ''} a key` : ''}</span>`
      : '<span class="st st--pending">Needs the API key</span>';
    return `<section class="card" data-wa-card data-max="${w.max}">
    <div class="card__head"><h2>WhatsApp alerts</h2>${status}</div>
    <div class="card__body" style="display:grid;gap:14px">
      <p style="margin:0">Booking alerts (guest, ID, dates, total and a link to the booking) are sent by WhatsApp to every number below. Type each number with the country code, e.g. 94777368264.</p>
      <form data-wa-form style="display:grid;gap:12px">
        <div class="nt-list" data-wa-list>${(w.numbers.length ? w.numbers : [{}]).map(waRow).join('')}</div>
        <div><button type="button" class="abtn" data-wa-add>+ Add a number</button></div>
        <div style="display:grid;gap:8px">
          <p class="section-title">Send an alert</p>
          <label class="chk"><input type="checkbox" name="onNew" ${w.onNew ? 'checked' : ''}> When a guest books on the website</label>
          <label class="chk"><input type="checkbox" name="onConfirmed" ${w.onConfirmed ? 'checked' : ''}> When a booking is confirmed</label>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <label class="switch"><input type="checkbox" name="on" ${w.on ? 'checked' : ''}> Send alerts</label>
          <span style="flex:1"></span>
          <button class="abtn" type="button" data-wa-test-all ${w.numbers.length > 1 ? '' : 'hidden'}>${icon('a-wa', 16)}Test all</button>
          <button class="abtn abtn--primary" type="submit">Save</button>
        </div>
      </form>
      <details class="wa-help" ${missing || !w.numbers.length ? 'open' : ''}><summary>Get the free API key for each number (once, about 2 minutes)</summary>
        <ol>
          <li>On the phone with that WhatsApp number, save <b>+34 644 95 73 56</b> as a contact (CallMeBot).</li>
          <li>Send that contact this WhatsApp message: <code>I allow callmebot to send me messages</code></li>
          <li>It replies “API Activated … Your APIKEY is 123456”. Type that key next to the number above, press <b>Save</b>, then <b>Test</b>.</li>
        </ol>
        <p class="src">Each phone gets its own key, which only sends to that phone, so repeat these steps on every phone you add. CallMeBot is a free service for alerts to your own phones. If it doesn't reply within a few minutes, check its current number on callmebot.com. Until a number has its key, its alerts are kept in <code>data/outbox/</code>.</p>
      </details>
    </div>
  </section>`;
  };
  /** The toast after a WhatsApp test: [text, failed?]. */
  const waTestSummary = (rs) => {
    const sent = rs.filter((x) => x.sent).length;
    const box = rs.filter((x) => x.outbox).length;
    const bad = rs.filter((x) => x.error);
    const parts = [];
    if (sent) parts.push(`Test sent to ${sent === 1 ? (rs.length === 1 ? 'the number' : '1 number') : `${sent} numbers`} — check WhatsApp`);
    if (box) parts.push(`${box === 1 && rs.length === 1 ? 'No API key yet — the test was' : `${box} without an API key`} saved to data/outbox`);
    if (bad.length) parts.push(`failed for ${bad.map((x) => `+${x.phone}`).join(', ')}: ${bad[0].error}`);
    return [parts.join('; '), bad.length > 0];
  };

  /** Reminders: emails to guests before arrival and before an unpaid reservation is released. */
  const remindersCard = (r) => {
    const kindLabel = (k) => (k === 'arrival' ? 'Arrival' : 'Payment');
    const when = (d) => (d.kind === 'arrival' ? `check-in ${fd(d.checkIn)}` : `held until ${fd(d.holdUntil)}`);
    const left = (n) => (n <= 0 ? 'today' : n === 1 ? 'tomorrow' : `in ${n} days`);
    return `<section class="card" data-rem-card>
    <div class="card__head"><h2>Reminders</h2>${r.on ? '<span class="st st--confirmed">On</span>' : '<span class="st st--checked_out">Paused</span>'}</div>
    <div class="card__body" style="display:grid;gap:14px">
      <p style="margin:0">Guests are emailed a reminder before they arrive, and before an unpaid reservation is released. Each is sent once per booking, automatically (daily, or when the website is visited). You can also send them yourself at any time.</p>
      ${r.emailEnabled ? '' : '<div class="note note--info">Email sending isn\'t set up yet, so reminders are saved to <code>data/outbox/</code> instead of being sent.</div>'}
      <form class="grid2" data-rem-form style="align-items:end">
        <label class="fld"><span>Arrival reminder <small>days before check-in · 0 = off</small></span><input type="number" name="arrivalDays" min="0" max="30" value="${r.arrivalDays}" required></label>
        <label class="fld"><span>Payment reminder <small>days before the hold date · 0 = off</small></span><input type="number" name="paymentDays" min="0" max="30" value="${r.paymentDays}" required></label>
        <div class="full" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <label class="switch"><input type="checkbox" name="on" ${r.on ? 'checked' : ''}> Send reminders automatically</label>
          <span style="flex:1"></span>
          <button class="abtn abtn--primary" type="submit">Save</button>
        </div>
      </form>
      <div>
        <p class="section-title">Due now${r.due.length ? ` (${r.due.length})` : ''}</p>
        ${r.due.length ? `<div class="pay-list">${r.due.map((d) => `<div class="pay-row">
            <div><b>${esc(d.guestName)}</b> · <a href="#/bookings/${d.id}">${esc(d.ref)}</a> <span class="src">· ${kindLabel(d.kind)} reminder · ${when(d)} (${left(d.daysLeft)})</span></div>
          </div>`).join('')}</div>
          <div style="margin-top:10px"><button class="abtn abtn--primary" type="button" data-rem-send>${icon('a-mail', 16)}Send ${r.due.length === 1 ? 'this reminder' : `these ${r.due.length} reminders`} now</button></div>`
          : '<p class="src" style="margin:0">Nothing is due. To remind one guest at any time, open their booking and press <b>Send reminder</b>.</p>'}
      </div>
      ${r.recent.length ? `<div><p class="section-title">Recently sent</p><div class="pay-list">${r.recent.map((x) => `<div class="pay-row">
          <div>${esc(x.guestName)} · <a href="#/bookings/${x.id}">${esc(x.ref)}</a> <span class="src">· ${kindLabel(x.kind)} · ${fdt(x.sentAt)}</span></div></div>`).join('')}</div></div>` : ''}
    </div>
  </section>`;
  };

  /** Google Calendar: a private link (.ics) that calendar apps subscribe to. */
  const calCard = (c) => `<section class="card" data-cal-card>
    <div class="card__head"><h2>Google Calendar</h2><span class="src">${c.events} booking${c.events === 1 ? '' : 's'} in the calendar</span></div>
    <div class="card__body" style="display:grid;gap:14px">
      <p style="margin:0">Add this private link to Google Calendar once, and every booking appears there on its own. Changes and cancellations follow. Pending bookings are marked <b>Pending</b>, and blocked dates <b>Blocked</b>.</p>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <input class="inp cal-link" value="${esc(c.url)}" readonly aria-label="Calendar link" data-cal-url>
        <button class="abtn abtn--primary" type="button" data-cal-copy>${icon('a-check', 16)}Copy link</button>
      </div>
      <details class="wa-help" open><summary>Add it to Google Calendar (once, on a computer)</summary>
        <ol>
          <li>Press <b>Copy link</b> above.</li>
          <li>Open <a href="https://calendar.google.com/calendar/u/0/r/settings/addbyurl" target="_blank" rel="noopener">Google Calendar → Add calendar → From URL</a> (signed in to your Google account).</li>
          <li>Paste the link into <b>URL of calendar</b> and press <b>Add calendar</b>. “${esc(c.name)}” appears under <b>Other calendars</b>.</li>
          <li>On your phone it shows in the Google Calendar app as well. If not, open the app's <b>Settings</b>, tap “${esc(c.name)}” and turn on <b>Sync</b>.</li>
        </ol>
        <p class="src">Google reads the link again every few hours, on its own schedule (sometimes up to a day), so a new booking takes a while to appear. The email and WhatsApp alerts are instant. The link also works in Apple Calendar and Outlook.</p>
      </details>
      <div class="note note--info" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <span style="flex:1;min-width:220px">Keep the link private: anyone who has it can see your bookings. If it was shared by mistake, make a new one. The old link stops working, so add the new one to Google Calendar again.</span>
        <a class="abtn abtn--sm" href="${esc(c.url)}" download>${icon('a-dl', 15)}Download .ics</a>
        <button class="abtn abtn--sm" type="button" data-cal-reset>Make a new link</button>
      </div>
    </div>
  </section>`;

  async function viewSettings() {
    const site = can('site');
    const printing = can('setup');
    setHead('Settings', site ? 'Email, WhatsApp alerts, Google Calendar, printing and your account.' : 'Your account.');
    const [{ notify }, { whatsapp }, { feed }, { reminders }] = site
      ? await Promise.all([api('/notify-emails'), api('/whatsapp'), api('/calendar-feed'), api('/reminders')]) : [{}, {}, {}, {}];
    view.innerHTML = `<div class="settings">
      ${site ? `${notifyCard(notify)}
      ${waCard(whatsapp)}
      ${remindersCard(reminders)}
      ${calCard(feed)}` : ''}
      ${printing ? `<section class="card" data-print-card>
        <div class="card__head"><h2>Printing</h2><span class="src">Bills, invoices and receipts</span></div>
        <form class="card__body" style="display:grid;gap:14px" data-print-form>
          <p style="margin:0">Paper in the printer. Bills, payment invoices and receipts are laid out for it, and only the document prints — never the screen around it.</p>
          <div class="seg seg--wide" role="radiogroup" aria-label="Paper">${Object.entries(ME.papers || { A5: 'A5', A4: 'A4' }).map(([k, l]) => `<label><input type="radio" name="paper" value="${k}" ${ME.printPaper === k ? 'checked' : ''}> ${esc(l)}</label>`).join('')}</div>
          <p class="src" style="margin:0">Each page prints the date and time at the top left and who issued it at the top right — not the page title, link or page numbers. In the print window, choose the same paper size (and “Margins: default”, “Scale: 100%”). Load A5 paper and slide the tray's guides in to fit it.</p>
        </form>
      </section>
      <section class="card print-setup">
        <div class="card__head"><h2>Print without the print window</h2><span class="src">On the POS computer</span></div>
        <div class="card__body">
          <p style="margin:0 0 10px">A website can't skip the browser's print window by itself — every normal browser window asks first, on any computer and on the live website as well as here.
            The POS computer can skip it: open the POS with this shortcut, and Print goes straight to the printer.</p>
          <ol>
            <li>Make the <b>Pantum</b> the computer's <b>default printer</b>, with <b>A5</b> paper in its settings.</li>
            <li>On the POS computer, download the shortcut and open it:
              <div class="print-setup__dl"><a class="abtn" href="/downloads/topmountain-pos-windows.bat" download>${icon('a-dl', 15)}Windows</a>
                <a class="abtn" href="/downloads/topmountain-pos-linux.sh" download>${icon('a-dl', 15)}Linux</a></div>
              <small class="src">Windows: double-click the downloaded file (choose “More info → Run anyway” if Windows asks). It says which printer the prints go to. Linux: run <code>bash topmountain-pos-linux.sh</code> once — it adds a “Top Mountain POS” icon.</small></li>
            <li>Always open the POS from that shortcut. Sign in there and press <b>Print</b> — no window. Closing it and opening the POS in a normal browser window brings the print window back.</li>
          </ol>
        </div>
      </section>` : ''}
      <section class="card">
        <div class="card__head"><h2>Change password</h2><span class="src">Signed in as ${esc(ME.name || ME.username)}${ME.name && ME.name !== ME.username ? ` (${esc(ME.username)})` : ''} · ${esc(ME.roleLabel || '')}</span></div>
        <form class="card__body" style="display:grid;gap:14px" data-pw>
          <label class="fld"><span>Current password</span><input type="password" name="current" autocomplete="current-password" required></label>
          <label class="fld"><span>New password</span><input type="password" name="next" autocomplete="new-password" minlength="10" required><small>At least 10 characters.</small></label>
          <label class="fld"><span>Repeat new password</span><input type="password" name="again" autocomplete="new-password" minlength="10" required></label>
          <div><button class="abtn abtn--primary" type="submit">Update password</button></div>
        </form>
      </section>
    </div>`;
    if (site) bindSiteCards();
    if (printing) {
      $('[data-print-form]').onchange = async (e) => {
        if (e.target.name !== 'paper') return;
        try {
          const r = await api('/printing', { method: 'PATCH', body: { paper: e.target.value } });
          ME.printPaper = r.paper;
          toast(`Printing on ${r.paper} paper`);
        } catch (ex) { toast(ex.message, true); }
      };
    }
    $('[data-pw]').onsubmit = async (e) => {
      e.preventDefault();
      const f = e.target;
      if (f.next.value !== f.again.value) { toast('New passwords do not match.', true); return; }
      try {
        await api('/password', { method: 'POST', body: { current: f.current.value, next: f.next.value } });
        f.reset();
        toast('Password updated');
      } catch (ex) { toast(ex.message, true); }
    };
  }

  /** Settings: the notification emails, WhatsApp, reminders and Google Calendar cards (admins). */
  function bindSiteCards() {
    const bindNe = () => {
      const card = $('[data-ne-card]');
      const f = $('[data-ne-form]', card);
      const list = $('[data-ne-list]', card);
      const max = Number(card.dataset.max) || 10;
      const inputs = () => $$('[data-ne-row] input', list);
      const refresh = (n) => { ME.notifyEmails = n.emails; card.outerHTML = notifyCard(n); bindNe(); };
      card.onclick = async (e) => {
        if (e.target.closest('[data-ne-add]')) {
          if (inputs().length >= max) { toast(`Up to ${max} email addresses.`, true); return; }
          list.insertAdjacentHTML('beforeend', emailRow());
          inputs().pop().focus();
          return;
        }
        const rm = e.target.closest('[data-ne-rm]');
        if (rm) {
          rm.closest('[data-ne-row]').remove();
          if (!inputs().length) list.insertAdjacentHTML('beforeend', emailRow());
          return;
        }
        const test = e.target.closest('[data-ne-test]');
        if (test) {
          const input = $('input', test.closest('[data-ne-row]'));
          const to = input.value.trim();
          if (!to || !input.checkValidity()) { input.focus(); toast(to ? `“${to}” is not an email address.` : 'Type an email address first.', true); return; }
          test.disabled = true;
          try {
            const r = await api('/test-email', { method: 'POST', body: { to } });
            toast(r.outbox ? 'Saved to data/outbox (SMTP not configured yet)' : `Test email sent to ${to} — check the inbox`);
          } catch (ex) { toast(ex.message, true); }
          test.disabled = false;
        }
      };
      f.onsubmit = async (e) => {
        e.preventDefault();
        const bad = inputs().find((i) => i.value.trim() && !i.checkValidity());
        if (bad) { bad.focus(); toast(`“${bad.value.trim()}” is not an email address.`, true); return; }
        const emails = inputs().map((i) => i.value.trim()).filter(Boolean);
        try {
          refresh((await api('/notify-emails', { method: 'PUT', body: { emails } })).notify);
          toast(emails.length ? `New bookings will be emailed to ${emails.length === 1 ? emails[0] : `${emails.length} addresses`}` : 'Saved — nobody is emailed about new bookings', !emails.length);
        } catch (ex) { toast(ex.message, true); }
      };
    };
    bindNe();
    const bindWa = () => {
      const card = $('[data-wa-card]');
      const f = $('[data-wa-form]', card);
      const list = $('[data-wa-list]', card);
      const max = Number(card.dataset.max) || 10;
      let dirty = false; // unsaved changes: tests use the saved numbers and keys
      const refresh = (w) => { card.outerHTML = waCard(w); bindWa(); };
      const rows = () => $$('[data-wa-row]', list);
      f.oninput = () => { dirty = true; };
      const test = async (btn, id) => {
        if (dirty) { toast('Save your changes first, then send the test.', true); return; }
        btn.disabled = true;
        try {
          const r = await api('/whatsapp/test', { method: 'POST', body: id ? { id } : {} });
          toast(...waTestSummary(r.results));
        } catch (ex) { toast(ex.message, true); }
        btn.disabled = false;
      };
      card.onclick = (e) => {
        if (e.target.closest('[data-wa-add]')) {
          if (rows().length >= max) { toast(`Up to ${max} WhatsApp numbers.`, true); return; }
          list.insertAdjacentHTML('beforeend', waRow());
          $('[name="name"]', rows().pop()).focus();
          dirty = true;
          return;
        }
        const rm = e.target.closest('[data-wa-rm]');
        if (rm) {
          rm.closest('[data-wa-row]').remove();
          if (!rows().length) list.insertAdjacentHTML('beforeend', waRow());
          dirty = true;
          return;
        }
        const t = e.target.closest('[data-wa-test]');
        if (t) {
          const id = t.closest('[data-wa-row]').dataset.id;
          if (!id) { toast('Save this number first, then send the test.', true); return; }
          test(t, id);
          return;
        }
        const all = e.target.closest('[data-wa-test-all]');
        if (all) test(all, null);
      };
      f.onsubmit = async (e) => {
        e.preventDefault();
        const numbers = rows().map((r) => {
          const n = { id: r.dataset.id, name: $('[name="name"]', r).value, phone: $('[name="phone"]', r).value };
          const key = $('[name="apiKey"]', r).value.trim();
          if (key) n.apiKey = key;
          return n;
        });
        const el = f.elements;
        const body = { numbers, on: el.on.checked, onNew: el.onNew.checked, onConfirmed: el.onConfirmed.checked };
        try { refresh((await api('/whatsapp', { method: 'PATCH', body })).whatsapp); toast('WhatsApp alerts saved'); } catch (ex) { toast(ex.message, true); }
      };
    };
    bindWa();
    const bindRem = () => {
      const card = $('[data-rem-card]');
      const refresh = (r) => { card.outerHTML = remindersCard(r); bindRem(); };
      const f = $('[data-rem-form]', card);
      f.onsubmit = async (e) => {
        e.preventDefault();
        const body = { on: f.on.checked, arrivalDays: Number(f.arrivalDays.value), paymentDays: Number(f.paymentDays.value) };
        try { refresh((await api('/reminders', { method: 'PATCH', body })).reminders); toast('Reminder settings saved'); } catch (ex) { toast(ex.message, true); }
      };
      const send = $('[data-rem-send]', card);
      if (send) send.onclick = async () => {
        send.disabled = true;
        try {
          const r = await api('/reminders/send', { method: 'POST', body: {} });
          const n = r.sent.arrival + r.sent.payment;
          refresh(r.reminders);
          toast(r.outbox ? `${n} reminder${n === 1 ? '' : 's'} saved to data/outbox (email isn't set up yet)` : `${n} reminder${n === 1 ? '' : 's'} sent`);
        } catch (ex) { toast(ex.message, true); send.disabled = false; }
      };
    };
    bindRem();
    const bindCal = () => {
      const card = $('[data-cal-card]');
      const input = $('[data-cal-url]', card);
      input.onfocus = () => input.select();
      $('[data-cal-copy]', card).onclick = async () => {
        try { await navigator.clipboard.writeText(input.value); } catch { input.select(); document.execCommand('copy'); }
        toast('Link copied — paste it into Google Calendar');
      };
      $('[data-cal-reset]', card).onclick = async () => {
        if (!confirm('Make a new calendar link? The old link stops working, so you will need to add the new one to Google Calendar again.')) return;
        try { card.outerHTML = calCard((await api('/calendar-feed/reset', { method: 'POST', body: {} })).feed); bindCal(); toast('New link made — add it to Google Calendar'); } catch (ex) { toast(ex.message, true); }
      };
    };
    bindCal();
  }

  // ---------- days the whole resort is closed ----------
  /** Close the resort for one or more days: the website takes no bookings then. */
  function closeDaysDialog(from) {
    openModal(`${modalHead('Close the resort', 'For repairs, a private booking of the whole place, or any other reason')}<form class="dr-body">
      <div class="grid2">
        <label class="fld"><span>First day</span><input type="date" name="from" value="${esc(from)}" min="${today()}" required></label>
        <label class="fld"><span>Last day</span><input type="date" name="to" value="${esc(from)}" min="${today()}" required></label>
        <div class="fld span2"><span>Why</span><div class="seg seg--wide" role="radiogroup" aria-label="Why">${Object.entries(CLOSE_REASONS).map(([k, l], i) => `<label><input type="radio" name="reason" value="${k}" ${i === 0 ? 'checked' : ''}> ${esc(l)}</label>`).join('')}</div>
          <small>Guests see this on the booking page.</small></div>
        <label class="fld span2"><span>Note <small>(only staff see it)</small></span><input name="note" maxlength="255" placeholder="e.g. Wedding party — Perera family / roof repairs"></label>
      </div>
      <div class="note note--info">On these days the website takes no bookings — no rooms, no per-person stays and no day visits — and its calendar shows them crossed out.
        A guest who leaves on the morning of the first day is fine. You can still add bookings here yourself (for example the private booking), with a warning.</div>
      <div class="dlg-foot"><button type="button" class="abtn" data-mclose>Cancel</button><button class="abtn abtn--primary" type="submit">Close these days</button></div>
    </form>`);
    const f = $('form', modalPanel);
    f.from.addEventListener('change', () => { if (!f.to.value || f.to.value < f.from.value) f.to.value = f.from.value; f.to.min = f.from.value; });
    modalPanel.onsubmit = async (e) => {
      e.preventDefault();
      const btn = $('[type="submit"]', f);
      btn.disabled = true;
      try {
        const r = await api('/closures', { method: 'POST', body: { from: f.from.value, to: f.to.value, reason: f.reason.value, note: f.note.value } });
        const c = r.closure;
        toast(`Resort closed ${daySpan(c.from, c.to)} — ${c.label.toLowerCase()}`);
        if (r.bookings.length) {
          // bookings already on those days: they stay as they are, so staff can decide
          openModal(`${modalHead('Already booked on those days', `${r.bookings.length} booking${r.bookings.length === 1 ? '' : 's'} — they stay as they are`)}<div class="dr-body">
            <p>The resort is now closed ${esc(daySpan(c.from, c.to))}. These bookings were already made for those days — move or cancel them if needed.</p>
            <div class="closed-list">${r.bookings.map((b) => `<a class="closed-list__row" href="#/bookings/${b.id}"><b>${esc(b.guestName)}</b><span>${esc(b.ref)} · ${esc(whatOf(b))} · ${esc(whenOf(b))} · ${esc(STATUS[b.status] || b.status)}</span></a>`).join('')}</div>
            <div class="dlg-foot"><span></span><button type="button" class="abtn abtn--primary" data-mclose>OK</button></div></div>`);
        } else {
          closeModal();
        }
        if (/^#\/calendar/.test(location.hash) || location.hash === '') route();
      } catch (ex) { toast(ex.message, true); btn.disabled = false; }
    };
  }

  /** A closure on the calendar: what and why, and opening those days again. */
  function closureDialog(c) {
    if (!c) return;
    openModal(`${modalHead(c.label, `${daySpan(c.from, c.to)} · ${c.days} day${c.days === 1 ? '' : 's'}`)}<div class="dr-body">
      <p>The website takes no bookings on these days.${c.note ? `<br><b>Note:</b> ${esc(c.note)}` : ''}</p>
      <p class="src">Added by ${esc(c.createdBy || '—')}${c.createdAt ? ` · ${fdt(c.createdAt)}` : ''}</p>
      <div class="dlg-foot">${can('setup') ? '<button type="button" class="abtn abtn--danger" data-reopen>Open these days again</button>' : '<span></span>'}<button type="button" class="abtn" data-mclose>Close</button></div></div>`);
    modalPanel.onclick = async (e) => {
      if (!e.target.closest('[data-reopen]')) return;
      if (!confirm(`Open the resort again on ${daySpan(c.from, c.to)}? The website will take bookings for those days.`)) return;
      try { await api(`/closures/${c.id}`, { method: 'DELETE' }); toast(`Open again ${daySpan(c.from, c.to)}`); closeModal(); route(); } catch (ex) { toast(ex.message, true); }
    };
  }

  // ---------- boot ----------
  // refresh the pending badge every 2 minutes so new website bookings show up
  setInterval(() => { if (ME && !document.hidden) refreshBadge(); }, 120000);
  start().catch(() => showLogin());
})();
