/* Admin → Booking setup: the two booking categories, the per-person options and their prices,
   the hotel's capacity, the order rooms are given to per-person groups, the extras, and the
   advance-payment details guests see after booking. admin.js creates this with its helpers. */
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
    setHead('Booking setup', 'What guests can book and the prices, the hotel’s capacity, which rooms per-person groups get, and how guests pay the advance.');
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
    const pct = Number(p.advancePct) || 0;
    const sample = 50000;
    return `<div class="pay-preview">
      <p class="section-title">Guests see, after booking</p>
      <b>Your booking is pending</b>
      <p>Please call <b>${esc(p.phone || S.defaultPhone)}</b> to confirm it${pct ? `, and pay an advance of <b>${pct}%</b> (${money(Math.round(sample * pct / 100))} on a ${money(sample)} booking)` : ' and arrange the advance'}.</p>
      ${p.bank || p.accountNo ? `<dl>${[['Bank', p.bank], ['Account name', p.accountName], ['Account number', p.accountNo], ['Branch', p.branch]].filter(([, v]) => v).map(([k, v]) => `<dt>${k}</dt><dd>${esc(v)}</dd>`).join('')}<dt>Reference</dt><dd>their booking ref</dd></dl>` : '<p class="is-due">Add your bank details so guests know where to pay.</p>'}
      ${p.note ? `<p>${esc(p.note)}</p>` : ''}
    </div>`;
  };
  const paymentCard = () => `<section class="card" data-card="payment">
    <div class="card__head"><h2>Advance payment</h2><span class="src">Shown after booking and in the guest's email</span></div>
    <div class="card__body pay-wrap">
      <form class="grid2" data-form="payment">
        <label class="fld"><span>Phone to call</span><input name="phone" value="${esc(S.payment.phone)}" maxlength="40" placeholder="${esc(S.defaultPhone)}"><small>Empty = the resort phone</small></label>
        <label class="fld"><span>Advance <small>% of the total</small></span><input type="number" name="advancePct" min="0" max="100" step="1" value="${S.payment.advancePct}"><small>0 = don't show an amount</small></label>
        <label class="fld span2"><span>Hold reservations for <small>days</small></span><input type="number" name="holdDays" min="0" max="365" step="1" value="${S.holdDays}"><small>A reservation (website request or one you enter) keeps its dates this long for the advance, then is released if nothing was paid. 0 = never release. You can change it on each booking.</small></label>
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

  // half-width cards in pairs (labels | capacity, extras | payment), the wide ones between
  const CARD = { labels: labelsCard, capacity: capacityCard, options: optionsCard, priority: priorityCard, extras: extrasCard, payment: paymentCard, payfields: payFieldsCard };
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
        const p = Object.fromEntries(['phone', 'bank', 'branch', 'accountName', 'accountNo', 'note'].map((k) => [k, f[k].value.trim()]));
        p.advancePct = Number(f.advancePct.value) || 0;
        return save({ payment: p, holdDays: Number(f.holdDays.value) || 0 }, 'payment', 'Payment details saved');
      }
      if (form === 'payfields') return save({ paymentFields: readPf() }, 'payfields', 'Payment details fields saved — staff see them when recording a payment');
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
      const f = e.target.closest('[data-form="payment"]');
      if (!f) return;
      const p = Object.fromEntries(['phone', 'bank', 'branch', 'accountName', 'accountNo', 'note', 'advancePct'].map((k) => [k, f[k].value.trim()]));
      $('[data-pay-preview]', view).innerHTML = payPreview(p);
    };
  }

  return { setup: viewSetup };
};
