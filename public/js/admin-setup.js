/* Admin → Booking setup: the two booking categories, the per-person options and their prices,
   the hotel's capacity, the order rooms are given to per-person groups, the extras, and the
   advance-payment details guests see after booking, and the cancellation policy at the bottom of
   the booking page. admin.js creates this with its helpers. */
window.YDSETUP = function (h) {
  'use strict';
  const { $, $$, esc, icon, money, api, toast, setHead, view, currency, refreshMe } = h;
  let S = null; // the last /setup answer
  let prio = []; // the room order being edited: [{id, name, type, capacity, active, on}]

  const plural = (n, one, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;
  const moveBtns = (i, n, attr) => `<span class="row-move">
    <button type="button" class="abtn abtn--sm abtn--ghost" ${attr}="-1" ${i === 0 ? 'disabled' : ''} aria-label="Move up">${icon('a-chevup', 15)}</button>
    <button type="button" class="abtn abtn--sm abtn--ghost" ${attr}="1" ${i === n - 1 ? 'disabled' : ''} aria-label="Move down">${icon('a-chevdown', 15)}</button></span>`;

  async function viewSetup() {
    setHead('Booking setup', 'What guests can book and the prices, the hotel’s capacity, which rooms per-person groups get, how guests pay the advance, and your cancellation policy.');
    load((await api('/setup')).setup);
    view.innerHTML = `<div class="setup">${CARDS.map((c) => c()).join('')}</div>`;
    bind();
  }

  function load(setup) {
    S = setup;
    const byId = Object.fromEntries(S.rooms.map((r) => [r.id, r]));
    prio = [...S.priority.map((id) => ({ ...byId[id], on: true })), ...S.rooms.filter((r) => !S.priority.includes(r.id)).map((r) => ({ ...r, on: false }))];
  }

  /** Save part of the setup, then redraw that card (other cards keep any unsaved edits). */
  async function save(body, card, message) {
    try {
      const r = await api('/setup', { method: 'PATCH', body });
      load(r.setup);
      await refreshMe();
      redraw(card);
      toast(message);
    } catch (ex) { toast(ex.message, true); }
  }
  function redraw(card) {
    const el = $(`[data-card="${card}"]`, view);
    if (el) el.outerHTML = CARD[card]();
  }

  // ---------- 1. the two categories at the top of the booking page ----------
  const labelsCard = () => `<section class="card" data-card="labels">
    <div class="card__head"><h2>Booking categories</h2><span class="src">The two choices at the top of the booking page</span></div>
    <form class="card__body grid2" data-form="labels">
      <label class="fld"><span>Booking a whole room</span><input name="room" value="${esc(S.labels.room)}" maxlength="60" required></label>
      <label class="fld"><span>Booking per person</span><input name="person" value="${esc(S.labels.person)}" maxlength="60" required></label>
      <p class="setup__note full">A room is priced per night and needs no guest numbers — it shows how many it sleeps. Per person shows the options below and asks for adults and children.</p>
      <div class="full setup__save"><button class="abtn abtn--primary" type="submit">Save names</button></div>
    </form>
  </section>`;

  // ---------- 2. per-person options ----------
  const per = (kind) => (kind === 'day' ? 'per visit' : 'per night');
  const optionForm = (p, i, n) => `<form class="opt${p.active ? '' : ' is-off'}" data-opt="${p.id}">
      <div class="opt__head">${moveBtns(i, n, 'data-opt-move')}<b>${esc(p.name)}</b>${p.active ? '' : '<span class="st st--checked_out">Hidden</span>'}</div>
      <div class="grid4">
        <label class="fld span2"><span>Name</span><input name="name" value="${esc(p.name)}" maxlength="120" required></label>
        <label class="fld"><span>Adult (${esc(currency())}) <small data-per>${per(p.kind)}</small></span><input type="number" name="priceAdult" min="0" step="1" value="${p.priceAdult}" required></label>
        <label class="fld"><span>Child (${esc(currency())}) <small data-per>${per(p.kind)}</small></span><input type="number" name="priceChild" min="0" step="1" value="${p.priceChild}" required></label>
        <label class="fld span2"><span>What's included <small>(shown to guests)</small></span><input name="description" value="${esc(p.description)}" maxlength="255" placeholder="e.g. Breakfast, lunch and dinner"></label>
        <label class="fld"><span>Type</span><select name="kind"><option value="stay" ${p.kind === 'stay' ? 'selected' : ''}>Overnight stay</option><option value="day" ${p.kind === 'day' ? 'selected' : ''}>Day visit</option></select></label>
        <div class="opt__foot"><label class="switch"><input type="checkbox" name="active" ${p.active ? 'checked' : ''}> On the website</label><button class="abtn abtn--sm abtn--primary" type="submit">Save</button></div>
      </div>
    </form>`;
  const optionsCard = () => `<section class="card" data-card="options">
    <div class="card__head"><h2>${esc(S.labels.person)} options</h2><span class="src">Priced per adult and per child</span></div>
    <div class="opts">${S.packages.map((p, i) => optionForm(p, i, S.packages.length)).join('') || '<div class="empty">No options yet — add one below.</div>'}</div>
    <details class="opt-new"><summary>${icon('a-plus', 15)}Add an option</summary>
      <form class="grid4" data-form="option-new">
        <label class="fld span2"><span>Name</span><input name="name" maxlength="120" required placeholder="e.g. Bed &amp; breakfast"></label>
        <label class="fld"><span>Adult (${esc(currency())})</span><input type="number" name="priceAdult" min="0" step="1" required></label>
        <label class="fld"><span>Child (${esc(currency())})</span><input type="number" name="priceChild" min="0" step="1" required></label>
        <label class="fld span2"><span>What's included</span><input name="description" maxlength="255"></label>
        <label class="fld"><span>Type</span><select name="kind"><option value="stay">Overnight stay</option><option value="day">Day visit</option></select></label>
        <div class="opt__foot"><button class="abtn abtn--primary" type="submit">Add option</button></div>
      </form>
    </details>
    <p class="setup__note setup__note--pad">Hidden options stay on old bookings but can't be booked on the website. The booking page counts children as aged 2–12 (13 and over pay the adult price); infants are free.</p>
  </section>`;

  // ---------- 3. capacity ----------
  const capacityCard = () => `<section class="card" data-card="capacity">
    <div class="card__head"><h2>Hotel capacity</h2><span class="src">Guests a night, across every booking</span></div>
    <form class="card__body grid2" data-form="capacity">
      <label class="fld"><span>Most guests a night</span><input type="number" name="capacity" min="0" max="10000" step="1" value="${S.capacity}" required><small>0 = no limit beyond the rooms themselves</small></label>
      <div class="setup__stat"><small>Your rooms in use sleep</small><b>${plural(S.roomsCapacity, 'guest')}</b></div>
      <p class="setup__note full">Each night counts a booked room as full (however many come) and a per-person group by its guests. When the limit is reached the website stops taking bookings for that night. You can still go over it from the admin panel — you'll get a warning.</p>
      <div class="full setup__save"><button class="abtn abtn--primary" type="submit">Save capacity</button></div>
    </form>
  </section>`;

  // ---------- 4. room priority for per-person groups ----------
  /**
   * The rooms a group of n gets from the list, until they sleep everyone. Every room switched on
   * here counts, even one that isn't bookable on the website (guests just can't pick it themselves).
   */
  function allocate(n) {
    const out = [];
    let beds = 0;
    for (const r of prio) {
      if (!r.on) continue;
      out.push(r);
      beds += r.capacity;
      if (beds >= n) return out;
    }
    return null;
  }
  const examples = () => [1, 2, 3, 4, 5, 6, 8, 10].map((n) => {
    const a = allocate(n);
    return `<li><b>${plural(n, 'guest')}</b><span>${a ? esc(a.map((r) => r.name).join(' + ')) : '<span class="is-due">not enough rooms</span>'}</span></li>`;
  }).join('');
  const priorityList = () => prio.map((r, i) => `<li class="prio${r.on ? '' : ' is-off'}" data-prio="${r.id}">
      ${moveBtns(i, prio.length, 'data-prio-move')}
      <span class="prio__no">${r.on ? prio.filter((x, j) => x.on && j <= i).length : '—'}</span>
      <span class="prio__name"><b>${esc(r.name)}</b><small>${esc(r.type)} · sleeps ${r.capacity}${r.active ? '' : ` · not bookable on the website${r.on ? ' — still given to groups' : ''}`}</small></span>
      <label class="switch"><input type="checkbox" data-prio-on ${r.on ? 'checked' : ''}> For groups</label>
    </li>`).join('');
  const priorityCard = () => `<section class="card" data-card="priority">
    <div class="card__head"><h2>Rooms for per-person groups</h2><span class="src">Given in this order</span></div>
    <div class="card__body prio-wrap">
      <div>
        <p class="setup__note" style="margin-top:0">A per-person booking takes rooms from the top of this list until they sleep the whole group, and nobody else can book those rooms for those nights. Rooms switched off here are never given to groups. A room that isn't bookable on the website (Admin → Rooms) can still be used here — guests just can't choose it themselves under “Select room”. You can change a group's rooms when you confirm it.</p>
        <ol class="prio-list" data-prio-list>${priorityList()}</ol>
      </div>
      <div class="prio-eg"><p class="section-title">With this order</p><ul data-prio-eg>${examples()}</ul></div>
      <div class="full setup__save"><button class="abtn abtn--primary" type="button" data-prio-save>Save room order</button></div>
    </div>
  </section>`;
  const redrawPriority = () => {
    $('[data-prio-list]', view).innerHTML = priorityList();
    $('[data-prio-eg]', view).innerHTML = examples();
  };

  // ---------- 5. extras ----------
  const extrasCard = () => `<section class="card" data-card="extras">
    <div class="card__head"><h2>Extras &amp; service charge</h2><span class="src">Optional extras, and the charge on bills</span></div>
    <form class="card__body grid2" data-form="extras">
      <label class="fld"><span>Party area (${esc(currency())}) <small>per night</small></span><input type="number" name="partyArea" min="0" step="1" value="${S.prices.partyArea}" required></label>
      <label class="fld"><span>JBL speaker (${esc(currency())}) <small>per night, or per day for a day visit</small></span><input type="number" name="jblSpeaker" min="0" step="1" value="${S.prices.jblSpeaker}" required></label>
      <p class="setup__note full">The party area is for one group a night and comes with overnight stays. Guests don't have to reserve it — the booking page tells them that if someone else reserves it, it won't be free for them, and room bookings on a reserved night are told a party is on.</p>
      <label class="fld"><span>Service charge <small>% added to every bill</small></span><input type="number" name="serviceCharge" min="0" max="100" step="1" value="${S.serviceCharge}" required><small>0 = no service charge</small></label>
      <p class="setup__note" style="align-self:center">Added to the whole bill at checkout (stay and items, after any discount). Staff can leave it off a bill.</p>
      <div class="full setup__save"><button class="abtn abtn--primary" type="submit">Save</button></div>
    </form>
  </section>`;

  // ---------- 6. advance payment ----------
  const payPreview = (p) => {
    const sample = 50000;
    const phone = esc(p.phone || S.defaultPhone);
    const note = p.note ? `<p>${esc(p.note)}</p>` : '';
    if (!p.advanceOn) {
      return `<div class="pay-preview">
      <p class="section-title">Guests see, after booking</p>
      <b>Your booking is pending</b>
      <p>Nothing to pay before you arrive: the total is paid at the resort. We call you to confirm your booking — questions: <b>${phone}</b>.</p>
      ${note}
      <p class="src">No advance is asked for, and reservations aren't released. You can still ask one guest for an advance: open their booking → Edit booking → Advance.</p>
    </div>`;
    }
    const pct = Number(p.advancePct) || 0;
    const amt = Number(p.advanceAmount) || 0;
    const ask = p.advanceType === 'amount'
      ? (amt ? `, and pay an advance of <b>${money(amt)}</b> (or the total, if it is less)` : ', and pay the whole total')
      : (pct ? `, and pay an advance of <b>${pct}%</b> (${money(Math.round(sample * pct / 100))} on a ${money(sample)} booking)` : ', and pay the whole total');
    return `<div class="pay-preview">
      <p class="section-title">Guests see, after booking</p>
      <b>Your booking is pending</b>
      <p>Please call <b>${phone}</b> to confirm it${ask}.</p>
      ${p.bank || p.accountNo ? `<dl>${[['Bank', p.bank], ['Account name', p.accountName], ['Account number', p.accountNo], ['Branch', p.branch]].filter(([, v]) => v).map(([k, v]) => `<dt>${k}</dt><dd>${esc(v)}</dd>`).join('')}<dt>Reference</dt><dd>their booking ref</dd></dl>` : '<p class="is-due">Add your bank details so guests know where to pay.</p>'}
      ${note}
    </div>`;
  };
  /** The advance-payment form as typed (unsaved edits included). */
  const readPay = (f) => ({
    ...Object.fromEntries(['phone', 'bank', 'branch', 'accountName', 'accountNo', 'note'].map((k) => [k, f[k].value.trim()])),
    advanceOn: f.advanceOn.checked,
    advanceType: f.advanceType.value,
    advancePct: Number(f.advancePct.value) || 0,
    advanceAmount: Math.round(Number(f.advanceAmount.value)) || 0,
  });
  /** Show the advance fields that apply: none when it is off, the % or the amount. */
  const syncAdvance = (f) => {
    const on = f.advanceOn.checked;
    $$('[data-adv-f]', f).forEach((el) => { el.hidden = !on; });
    $('[data-adv-pct]', f).hidden = !on || f.advanceType.value !== 'pct';
    $('[data-adv-amt]', f).hidden = !on || f.advanceType.value !== 'amount';
  };
  const paymentCard = () => {
    const p = S.payment;
    const fixed = p.advanceType === 'amount';
    return `<section class="card" data-card="payment">
    <div class="card__head"><h2>Advance payment</h2><span class="src">Shown after booking and in the guest's email</span></div>
    <div class="card__body pay-wrap">
      <form class="grid2" data-form="payment">
        <label class="chk span2 adv-switch"><input type="checkbox" name="advanceOn" ${p.advanceOn ? 'checked' : ''}><span><b>Collect an advance before arrival</b><small>Off: guests pay everything at the resort. Bookings still wait for you to confirm them, and aren't released. You can set a different advance on any booking.</small></span></label>
        <label class="fld" data-adv-f ${p.advanceOn ? '' : 'hidden'}><span>Advance is</span><select name="advanceType"><option value="pct" ${fixed ? '' : 'selected'}>% of total</option><option value="amount" ${fixed ? 'selected' : ''}>Fixed amount</option></select></label>
        <label class="fld" data-adv-pct ${p.advanceOn && !fixed ? '' : 'hidden'}><span>Advance <small>% of the total</small></span><input type="number" name="advancePct" min="0" max="100" step="1" value="${p.advancePct}"><small>0 = the whole total</small></label>
        <label class="fld" data-adv-amt ${p.advanceOn && fixed ? '' : 'hidden'}><span>Advance <small>${esc(currency())}</small></span><input type="number" name="advanceAmount" min="0" step="1" value="${p.advanceAmount}"><small>Never more than the booking's total. 0 = the whole total</small></label>
        <label class="fld"><span>Phone to call</span><input name="phone" value="${esc(p.phone)}" maxlength="40" placeholder="${esc(S.defaultPhone)}"><small>Empty = the resort phone</small></label>
        <label class="fld span2" data-adv-f ${p.advanceOn ? '' : 'hidden'}><span>Hold reservations for <small>days</small></span><input type="number" name="holdDays" min="0" max="365" step="1" value="${S.holdDays}"><small>A reservation (website request or one you enter) keeps its dates this long for the advance, then is released if nothing was paid. 0 = never release. You can change it on each booking.</small></label>
        <label class="fld"><span>Bank</span><input name="bank" value="${esc(S.payment.bank)}" maxlength="80" placeholder="e.g. Commercial Bank"></label>
        <label class="fld"><span>Branch</span><input name="branch" value="${esc(S.payment.branch)}" maxlength="80"></label>
        <label class="fld"><span>Account name</span><input name="accountName" value="${esc(S.payment.accountName)}" maxlength="120"></label>
        <label class="fld"><span>Account number</span><input name="accountNo" value="${esc(S.payment.accountNo)}" maxlength="60"></label>
        <label class="fld span2"><span>Note <small>(optional)</small></span><textarea name="note" maxlength="500" rows="2" placeholder="e.g. Send the payment slip on WhatsApp.">${esc(S.payment.note)}</textarea></label>
        <div class="full setup__save"><button class="abtn abtn--primary" type="submit">Save payment details</button></div>
      </form>
      <div data-pay-preview>${payPreview(S.payment)}</div>
    </div>
  </section>`;
  };

  // ---------- 7. details staff note for every payment ----------
  const pfRow = (f, i) => `<div class="pf-row" data-pf-row="${i}">
      <label class="fld"><span>Field</span><input name="pf_label" value="${esc(f.label)}" maxlength="60" required placeholder="e.g. Received into"></label>
      <label class="fld"><span>Type</span><select name="pf_type"><option value="choice" ${f.type === 'choice' ? 'selected' : ''}>A list</option><option value="text" ${f.type === 'text' ? 'selected' : ''}>Text</option></select></label>
      <label class="fld pf-row__opts" ${f.type === 'choice' ? '' : 'hidden'}><span>Choices <small>one per line</small></span><textarea name="pf_options" rows="3" placeholder="Cash in hand&#10;BOC current 1234567">${esc((f.options || []).join('\n'))}</textarea></label>
      <label class="chk"><input type="checkbox" name="pf_required" ${f.required ? 'checked' : ''}> Must be filled in</label>
      <button type="button" class="abtn abtn--sm abtn--ghost" data-pf-rm="${i}" aria-label="Remove this field">${icon('a-x', 14)}</button>
    </div>`;
  const payFieldsCard = () => `<section class="card" data-card="payfields">
    <div class="card__head"><h2>Payment details</h2><span class="src">Asked whenever staff record a payment</span></div>
    <form class="card__body" data-form="payfields" style="display:grid;gap:12px">
      <p class="setup__note">Note the exact account the money went into, a bank reference, the cashier… For a list, staff pick one; it is printed on the receipt. Every payment can also note what an agent kept as their commission, or what a bank took as charges, before the money reached the account — the guest's receipt still shows the full amount.</p>
      <div class="pf-list" data-pf-list>${S.paymentFields.length ? S.paymentFields.map(pfRow).join('') : '<p class="src" data-pf-empty>No extra fields — staff only enter the amount and how it was paid.</p>'}</div>
      <div class="setup__save"><button type="button" class="abtn" data-pf-add>+ Add a field</button><button class="abtn abtn--primary" type="submit">Save payment details</button></div>
    </form>
  </section>`;
  /** The fields as typed (unsaved edits included). */
  const readPf = () => $$('[data-pf-row]', view).map((r) => ({
    label: $('[name="pf_label"]', r).value, type: $('[name="pf_type"]', r).value,
    options: $('[name="pf_options"]', r).value.split('\n').map((s) => s.trim()).filter(Boolean), required: $('[name="pf_required"]', r).checked,
  }));
  const redrawPf = (list) => {
    $('[data-pf-list]', view).innerHTML = list.length ? list.map(pfRow).join('') : '<p class="src" data-pf-empty>No extra fields — staff only enter the amount and how it was paid.</p>';
  };

  // ---------- 8. cancellation policy at the bottom of the booking page ----------
  const POLICY_MAX = 5000;
  const POLICY_STARTER = [
    'Your booking is confirmed once we receive the advance payment.',
    '',
    '- Cancel 14 days or more before arrival: the advance is refunded in full.',
    '- Cancel 7 to 13 days before arrival: half of the advance is refunded.',
    '- Cancel less than 7 days before arrival, or not arriving: the advance is not refunded.',
    '',
    'You can change your dates once at no charge, subject to availability, if you tell us at least 7 days before arrival.',
    'To cancel or change a booking, call us or message us on WhatsApp with your booking reference.',
  ].join('\n');
  /** The policy as guests see it: same rules as App::policySection (blank line = new paragraph, "- " lines = a list). */
  const policyHtml = (text) => {
    let html = '';
    let para = [];
    let items = [];
    const flush = () => {
      if (para.length) html += `<p>${para.join('<br>')}</p>`;
      if (items.length) html += `<ul>${items.map((i) => `<li>${i}</li>`).join('')}</ul>`;
      para = []; items = [];
    };
    for (const raw of String(text).replace(/\r\n/g, '\n').trim().split('\n')) {
      const line = raw.trim();
      if (!line) { flush(); continue; }
      const m = line.match(/^[-*•]\s*(.+)$/);
      if (m) { if (para.length) flush(); items.push(esc(m[1])); } else { if (items.length) flush(); para.push(esc(line)); }
    }
    flush();
    return html;
  };
  const policyPreview = (text) => (text.trim()
    ? `<div class="policy-preview"><p class="section-title">Guests see, under the booking steps</p><b>Cancellation policy</b><div class="policy-preview__body">${policyHtml(text)}</div></div>`
    : '<div class="policy-preview is-empty"><p class="section-title">Not on the booking page yet</p><p>Write your policy, or start from the template, then save. While it is empty, the booking page doesn’t show a cancellation policy.</p></div>');
  const policyCard = () => `<section class="card" data-card="policy">
    <div class="card__head"><h2>Cancellation policy</h2><span class="src">Shown at the bottom of the booking page</span></div>
    <div class="card__body policy-wrap">
      <form class="policy-form" data-form="policy">
        <label class="fld"><span>Policy <small>plain text</small></span><textarea name="policy" rows="12" maxlength="${POLICY_MAX}" placeholder="e.g. Cancel 14 days or more before arrival for a full refund of the advance.">${esc(S.cancellationPolicy || '')}</textarea>
          <small>Leave a blank line between paragraphs. Start a line with “- ” to make a bulleted list. <span data-policy-count>${(S.cancellationPolicy || '').length}</span> / ${POLICY_MAX}</small></label>
        <div class="setup__save policy-form__save">
          <button type="button" class="abtn" data-policy-starter ${(S.cancellationPolicy || '').trim() ? 'hidden' : ''}>Use a starter template</button>
          <button class="abtn abtn--primary" type="submit">Save policy</button>
        </div>
      </form>
      <div data-policy-preview>${policyPreview(S.cancellationPolicy || '')}</div>
    </div>
  </section>`;
  const syncPolicy = (f) => {
    const text = f.policy.value;
    $('[data-policy-preview]', view).innerHTML = policyPreview(text);
    $('[data-policy-count]', f).textContent = text.length;
    $('[data-policy-starter]', f).hidden = !!text.trim();
  };

  // half-width cards in pairs (labels | capacity, extras | payment), the wide ones between
  const CARD = { labels: labelsCard, capacity: capacityCard, options: optionsCard, priority: priorityCard, extras: extrasCard, payment: paymentCard, payfields: payFieldsCard, policy: policyCard };
  const CARDS = Object.values(CARD);

  function bind() {
    view.onsubmit = async (e) => {
      e.preventDefault();
      const f = e.target;
      const form = f.dataset.form;
      if (form === 'labels') return save({ labels: { room: f.room.value, person: f.person.value } }, 'labels', 'Names saved — the booking page is updated');
      if (form === 'capacity') return save({ capacity: Number(f.capacity.value) }, 'capacity', 'Capacity saved');
      if (form === 'extras') return save({ prices: { partyArea: Number(f.partyArea.value), jblSpeaker: Number(f.jblSpeaker.value) }, serviceCharge: Number(f.serviceCharge.value) }, 'extras', 'Prices and service charge saved');
      if (form === 'payment') {
        const p = readPay(f);
        return save({ payment: p, holdDays: Number(f.holdDays.value) || 0 }, 'payment', p.advanceOn ? 'Payment details saved' : 'Saved — guests now pay at the resort, with no advance');
      }
      if (form === 'payfields') return save({ paymentFields: readPf() }, 'payfields', 'Payment details fields saved — staff see them when recording a payment');
      if (form === 'policy') {
        const text = f.policy.value.trim();
        return save({ cancellationPolicy: text }, 'policy', text ? 'Cancellation policy saved — the booking page is updated' : 'Cancellation policy removed from the booking page');
      }
      // per-person options
      const body = { name: f.name.value, description: f.description.value, kind: f.kind.value, priceAdult: Number(f.priceAdult.value), priceChild: Number(f.priceChild.value) };
      try {
        if (form === 'option-new') await api('/packages', { method: 'POST', body: { ...body, active: true } });
        else if (f.dataset.opt) await api(`/packages/${f.dataset.opt}`, { method: 'PATCH', body: { ...body, active: f.active.checked } });
        else return;
        load((await api('/setup')).setup);
        await refreshMe();
        redraw('options');
        toast(form === 'option-new' ? `${body.name.trim()} added` : `${body.name.trim()} saved — the website is updated`);
      } catch (ex) { toast(ex.message, true); }
    };
    view.onclick = async (e) => {
      if (e.target.closest('[data-policy-starter]')) {
        const f = $('[data-form="policy"]', view);
        f.policy.value = POLICY_STARTER;
        syncPolicy(f);
        f.policy.focus();
        toast('Starter template added — change it to match your rules, then save');
        return;
      }
      if (e.target.closest('[data-pf-add]')) {
        const list = readPf();
        if (list.length >= 8) { toast('Up to 8 payment fields.', true); return; }
        redrawPf([...list, { label: '', type: 'text', options: [], required: false }]);
        const rows = $$('[data-pf-row]', view);
        $('[name="pf_label"]', rows[rows.length - 1]).focus();
        return;
      }
      const pr = e.target.closest('[data-pf-rm]');
      if (pr) { const list = readPf(); list.splice(Number(pr.dataset.pfRm), 1); redrawPf(list); return; }
      const om = e.target.closest('[data-opt-move]');
      if (om) {
        const id = Number(om.closest('[data-opt]').dataset.opt);
        const order = S.packages.slice();
        const i = order.findIndex((p) => p.id === id);
        const j = i + Number(om.dataset.optMove);
        if (j < 0 || j >= order.length) return;
        [order[i], order[j]] = [order[j], order[i]];
        try {
          for (const [n, p] of order.entries()) if (p.sort !== n + 1) await api(`/packages/${p.id}`, { method: 'PATCH', body: { sort: n + 1 } });
          load((await api('/setup')).setup);
          await refreshMe();
          redraw('options');
        } catch (ex) { toast(ex.message, true); }
        return;
      }
      const pm = e.target.closest('[data-prio-move]');
      if (pm) {
        const i = prio.findIndex((r) => r.id === Number(pm.closest('[data-prio]').dataset.prio));
        const j = i + Number(pm.dataset.prioMove);
        if (j < 0 || j >= prio.length) return;
        [prio[i], prio[j]] = [prio[j], prio[i]];
        redrawPriority();
        return;
      }
      if (e.target.closest('[data-prio-save]')) {
        if (!prio.some((r) => r.on)) { toast('Switch on at least one room, or groups can never be given rooms.', true); return; }
        save({ priority: prio.filter((r) => r.on).map((r) => r.id) }, 'priority', 'Room order saved');
      }
    };
    view.onchange = (e) => {
      if (e.target.name === 'pf_type') { $('.pf-row__opts', e.target.closest('[data-pf-row]')).hidden = e.target.value !== 'choice'; return; }
      const payForm = e.target.closest('[data-form="payment"]');
      if (payForm) { syncAdvance(payForm); $('[data-pay-preview]', view).innerHTML = payPreview(readPay(payForm)); return; }
      const on = e.target.closest('[data-prio-on]');
      if (on) {
        prio.find((r) => r.id === Number(on.closest('[data-prio]').dataset.prio)).on = on.checked;
        redrawPriority();
        return;
      }
      if (e.target.name === 'kind') $$('[data-per]', e.target.closest('form')).forEach((x) => { x.textContent = per(e.target.value); });
      if (e.target.name === 'active' && e.target.closest('[data-opt]')) e.target.closest('[data-opt]').classList.toggle('is-off', !e.target.checked);
    };
    view.oninput = (e) => {
      const pol = e.target.closest('[data-form="policy"]');
      if (pol) { syncPolicy(pol); return; }
      const f = e.target.closest('[data-form="payment"]');
      if (!f) return;
      syncAdvance(f);
      $('[data-pay-preview]', view).innerHTML = payPreview(readPay(f));
    };
  }

  return { setup: viewSetup };
};
