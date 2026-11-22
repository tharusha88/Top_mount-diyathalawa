/* Admin → Coupons: codes for a % or an amount off the room charge (never food, drinks or
   extras), with a usage limit and optional dates. Make one, or a batch (random or numbered
   codes); guests use them on the booking page and staff at POS checkout. admin.js creates
   this with its helpers. */
window.YDCOUPONS = function (h) {
  'use strict';
  const { $, $$, esc, icon, money, fd, fdt, api, toast, setHead, view, openModal, modalHead, currency } = h;
  const F = { q: '', batch: '', state: '' };
  let LIST = [];
  let BATCHES = [];
  const STATE = { ready: ['Ready', 'confirmed'], used: ['Used up', 'checked_out'], expired: ['Expired', 'cancelled'], later: ['Starts later', 'pending'], off: ['Off', 'cancelled'] };
  const plural = (n, one, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;

  const discountFields = (prefix = '') => `
    <label class="fld"><span>Discount</span><span class="pair"><input type="number" name="${prefix}value" min="1" step="1" required placeholder="10" aria-label="Discount"><select name="${prefix}type" aria-label="Discount type"><option value="percent">%</option><option value="amount">${esc(currency())}</option></select></span><small>Off the room charge only — not food, drinks or extras.</small></label>
    <label class="fld"><span>Usage limit <small>${prefix ? 'each code' : ''}</small></span><input type="number" name="${prefix}maxUses" min="0" step="1" value="1" required><small>How many bookings or bills can use it. 0 = no limit.</small></label>
    <label class="fld"><span>Valid from <small>(optional)</small></span><input type="date" name="${prefix}validFrom"></label>
    <label class="fld"><span>Valid until <small>(optional)</small></span><input type="date" name="${prefix}validTo"></label>`;
  const readDiscount = (f, prefix = '') => ({
    type: f[`${prefix}type`].value, value: Number(f[`${prefix}value`].value), maxUses: Number(f[`${prefix}maxUses`].value),
    validFrom: f[`${prefix}validFrom`].value, validTo: f[`${prefix}validTo`].value,
  });

  async function viewCoupons() {
    setHead('Coupons', 'Codes for a discount on the room charge. Guests type them on the booking page; staff use them at POS checkout.');
    view.innerHTML = `<div class="coupons">
      <div class="coupons__make">
        <form class="card" data-one>
          <div class="card__head"><h2>New coupon</h2><span class="src">One code</span></div>
          <div class="card__body grid2">
            <label class="fld span2"><span>Code <small>(empty = a random one)</small></span><input name="code" maxlength="40" placeholder="e.g. SUMMER10" autocapitalize="characters" spellcheck="false"></label>
            ${discountFields()}
            <label class="fld span2"><span>Note <small>(optional, only staff see it)</small></span><input name="note" maxlength="255" placeholder="e.g. For the Colombo travel fair"></label>
            <div class="span2 setup__save"><button class="abtn abtn--primary" type="submit">${icon('a-plus', 16)}Make coupon</button></div>
          </div>
        </form>
        <form class="card" data-batch>
          <div class="card__head"><h2>Make a batch</h2><span class="src">Many codes at once</span></div>
          <div class="card__body grid2">
            <label class="fld"><span>How many</span><input type="number" name="count" min="1" max="500" step="1" value="20" required></label>
            <label class="fld"><span>Codes</span><select name="style"><option value="random">Random — e.g. YOD-7K3M9Q</option><option value="numbered">Numbered — YOD-001, YOD-002…</option></select></label>
            <label class="fld"><span>Prefix</span><input name="prefix" maxlength="16" placeholder="e.g. YOD" autocapitalize="characters" spellcheck="false"></label>
            <label class="fld" data-start-f hidden><span>First number</span><input type="number" name="start" min="0" step="1" value="1"></label>
            ${discountFields('b_')}
            <label class="fld span2"><span>Batch name <small>(optional)</small></span><input name="batch" maxlength="60" placeholder="e.g. Travel fair 2026"></label>
            <div class="span2 setup__save"><span class="src" data-batch-hint></span><button class="abtn abtn--primary" type="submit">${icon('a-tag', 16)}Make the coupons</button></div>
          </div>
        </form>
      </div>
      <section class="card">
        <div class="filters">
          <label class="search">${icon('a-search')}<input class="inp" type="search" placeholder="Find a code…" value="${esc(F.q)}" data-f="q" aria-label="Find a code"></label>
          <select class="inp" data-f="batch" aria-label="Batch"><option value="">All coupons</option></select>
          <select class="inp" data-f="state" aria-label="Status"><option value="">Any status</option>${Object.entries(STATE).map(([k, [l]]) => `<option value="${k}" ${F.state === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
          <span class="filters__gap"></span>
          <span data-batch-actions></span>
        </div>
        <div data-list><div class="loading">Loading…</div></div>
      </section>
    </div>`;
    bind();
    batchHint($('[data-batch]', view));
    await load();
  }

  async function load() {
    const p = new URLSearchParams();
    if (F.q) p.set('q', F.q);
    if (F.batch) p.set('batch', F.batch);
    const r = await api(`/coupons?${p}`);
    LIST = r.coupons;
    BATCHES = r.batches;
    const sel = $('[data-f="batch"]', view);
    if (sel) {
      if (F.batch && !BATCHES.some((b) => b.batch === F.batch)) F.batch = '';
      sel.innerHTML = `<option value="">All coupons</option>${BATCHES.map((b) => `<option value="${esc(b.batch)}" ${b.batch === F.batch ? 'selected' : ''}>${esc(b.batch)} (${b.count})</option>`).join('')}`;
    }
    render();
  }

  function render() {
    const box = $('[data-list]', view);
    if (!box) return;
    const list = LIST.filter((c) => !F.state || c.state === F.state);
    const acts = $('[data-batch-actions]', view);
    acts.innerHTML = F.batch ? `<a class="abtn abtn--sm" href="/api/admin/coupons.csv?batch=${encodeURIComponent(F.batch)}" download>${icon('a-dl', 15)}Download CSV</a>
      <button type="button" class="abtn abtn--sm" data-batch-on="0">Switch all off</button><button type="button" class="abtn abtn--sm" data-batch-on="1">Switch all on</button>` : '';
    if (!list.length) { box.innerHTML = `<div class="empty">${LIST.length ? 'No coupon matches.' : 'No coupons yet. Make one above.'}</div>`; return; }
    const valid = (c) => (c.validFrom || c.validTo ? `${c.validFrom ? fd(c.validFrom) : '…'} – ${c.validTo ? fd(c.validTo, { day: 'numeric', month: 'short', year: 'numeric' }) : '…'}` : 'Any time');
    box.innerHTML = `<div class="table-wrap"><table class="tbl coupons-tbl">
      <thead><tr><th>Code</th><th>Discount</th><th class="num">Used</th><th>Valid</th><th>Status</th><th>Batch</th><th></th></tr></thead>
      <tbody>${list.map((c) => `<tr class="${c.active ? '' : 'is-off'}">
        <td><button type="button" class="code-chip" data-copy="${esc(c.code)}" title="Copy">${esc(c.code)}</button>${c.note ? `<span class="sub">${esc(c.note)}</span>` : ''}</td>
        <td>${c.type === 'percent' ? `${c.value}%` : money(c.value)} <span class="sub">off the room charge</span></td>
        <td class="num">${c.uses ? `<button type="button" class="linkbtn" data-uses="${c.id}">${c.uses}</button>` : 0} / ${c.maxUses || '∞'} used</td>
        <td>${valid(c)}</td>
        <td><span class="st st--${STATE[c.state][1]}">${STATE[c.state][0]}</span></td>
        <td class="sub">${esc(c.batch || '—')}</td>
        <td class="num coupons-tbl__acts">
          <button type="button" class="abtn abtn--sm" data-toggle="${c.id}">${c.active ? 'Switch off' : 'Switch on'}</button>
          ${c.uses ? '' : `<button type="button" class="abtn abtn--sm abtn--ghost" data-del="${c.id}" aria-label="Delete ${esc(c.code)}">${icon('a-x', 14)}</button>`}
        </td></tr>`).join('')}</tbody></table></div>
      <div class="tbl-foot">${plural(list.length, 'coupon')}${list.length === LIST.length ? '' : ` of ${LIST.length}`} · ${list.reduce((s, c) => s + c.uses, 0)} uses · ${list.filter((c) => c.state === 'ready').length} ready to use</div>`;
  }

  function bind() {
    let timer;
    view.oninput = (e) => {
      if (e.target.dataset.f === 'q') { F.q = e.target.value.trim(); clearTimeout(timer); timer = setTimeout(load, 250); }
      const bf = e.target.closest('[data-batch]');
      if (bf) batchHint(bf);
    };
    view.onchange = (e) => {
      const k = e.target.dataset.f;
      if (k === 'batch') { F.batch = e.target.value; load(); }
      if (k === 'state') { F.state = e.target.value; render(); }
      if (e.target.name === 'style') { $('[data-start-f]', view).hidden = e.target.value !== 'numbered'; batchHint(e.target.form); }
    };
    view.onsubmit = async (e) => {
      e.preventDefault();
      const f = e.target;
      const btn = $('[type="submit"]', f);
      btn.disabled = true;
      try {
        if (f.matches('[data-one]')) {
          const r = await api('/coupons', { method: 'POST', body: { code: f.code.value, note: f.note.value, ...readDiscount(f) } });
          toast(`Coupon ${r.coupon.code} made — ${r.coupon.label}`);
          f.code.value = '';
          f.note.value = '';
          F.batch = '';
        } else {
          const r = await api('/coupons/batch', {
            method: 'POST',
            body: { count: Number(f.count.value), style: f.style.value, prefix: f.prefix.value, start: Number(f.start.value), batch: f.batch.value, ...readDiscount(f, 'b_') },
          });
          toast(`${plural(r.coupons.length, 'coupon')} made in “${r.batch}”`);
          F.batch = r.batch; // show the new batch, ready to download
        }
        F.q = '';
        const q = $('[data-f="q"]', view);
        if (q) q.value = '';
        await load();
      } catch (ex) { toast(ex.message, true); }
      btn.disabled = false;
    };
    view.onclick = async (e) => {
      const cp = e.target.closest('[data-copy]');
      if (cp) {
        try { await navigator.clipboard.writeText(cp.dataset.copy); toast(`${cp.dataset.copy} copied`); } catch { /* ignore */ }
        return;
      }
      const tg = e.target.closest('[data-toggle]');
      if (tg) {
        const c = LIST.find((x) => x.id === Number(tg.dataset.toggle));
        try { await api(`/coupons/${c.id}`, { method: 'PATCH', body: { active: !c.active } }); toast(`${c.code} switched ${c.active ? 'off' : 'on'}`); await load(); } catch (ex) { toast(ex.message, true); }
        return;
      }
      const del = e.target.closest('[data-del]');
      if (del) {
        const c = LIST.find((x) => x.id === Number(del.dataset.del));
        if (!confirm(`Delete coupon ${c.code}? It has never been used.`)) return;
        try { await api(`/coupons/${c.id}`, { method: 'DELETE' }); toast(`${c.code} deleted`); await load(); } catch (ex) { toast(ex.message, true); }
        return;
      }
      const bo = e.target.closest('[data-batch-on]');
      if (bo) {
        const on = bo.dataset.batchOn === '1';
        if (!on && !confirm(`Switch off every coupon in “${F.batch}”? Guests can't use them until you switch them on again.`)) return;
        try { const r = await api('/coupons/batch-active', { method: 'POST', body: { batch: F.batch, active: on } }); toast(`${plural(r.count, 'coupon')} switched ${on ? 'on' : 'off'}`); await load(); } catch (ex) { toast(ex.message, true); }
        return;
      }
      const us = e.target.closest('[data-uses]');
      if (us) usesDialog(LIST.find((x) => x.id === Number(us.dataset.uses)));
    };
  }

  function batchHint(f) {
    const n = Math.max(1, Math.min(500, Number(f.count.value) || 0));
    const prefix = f.prefix.value.trim().toUpperCase().replace(/\s+/g, '');
    const join = prefix ? `${prefix}-` : '';
    let ex;
    if (f.style.value === 'numbered') {
      const start = Math.max(0, Number(f.start.value) || 0);
      const digits = Math.max(3, String(start + n - 1).length);
      const pad = (x) => String(x).padStart(digits, '0');
      ex = prefix ? `${join}${pad(start)} … ${join}${pad(start + n - 1)}` : 'Numbered codes need a prefix';
    } else ex = `e.g. ${join}7K3M9Q`;
    $('[data-batch-hint]', f).textContent = `${plural(n, 'code')}: ${ex}`;
  }

  async function usesDialog(c) {
    let uses;
    try { uses = (await api(`/coupons/${c.id}/uses`)).uses; } catch (ex) { toast(ex.message, true); return; }
    openModal(`${modalHead(`Coupon ${c.code}`, `${c.label} · used ${c.uses} of ${c.maxUses || 'unlimited'} time${c.maxUses === 1 ? '' : 's'}`)}
      <div class="dr-body"><ul class="hist">${uses.map((u) => `<li><span>${u.kind === 'bill' ? 'Bill' : 'Booking'} ${u.kind === 'bill' ? `<a href="#/bills/${u.id}">${esc(u.ref)}</a>` : `<a href="#/bookings/${u.id}">${esc(u.ref)}</a>`} · ${esc(u.guestName)} · − ${money(u.discount)}</span>
        <small>${fdt(u.at)}${u.counts ? '' : ` · ${u.kind === 'bill' ? 'bill void' : 'booking cancelled'} — doesn't count`}</small></li>`).join('')}</ul></div>`);
  }

  return { coupons: viewCoupons };
};
