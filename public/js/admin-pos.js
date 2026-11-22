/* Admin: the POS menu (categories and items) and the bill history. The POS screen itself is
   /pos (pos.js). admin.js creates this with its helpers. */
window.YDPOS = function (h) {
  'use strict';
  const { $, $$, esc, icon, money, fd, fdt, api, toast, setHead, view, today, currency, reasonDialog, paymentDialog, showReceipt, me, can } = h;
  const plural = (n, one) => `${n} ${one}${n === 1 ? '' : 's'}`;
  const moveBtns = (i, n, attr) => `<span class="row-move">
    <button type="button" class="abtn abtn--sm abtn--ghost" ${attr}="-1" ${i === 0 ? 'disabled' : ''} aria-label="Move up">${icon('a-chevup', 15)}</button>
    <button type="button" class="abtn abtn--sm abtn--ghost" ${attr}="1" ${i === n - 1 ? 'disabled' : ''} aria-label="Move down">${icon('a-chevdown', 15)}</button></span>`;

  // ================= MENU =================
  async function viewMenu() {
    setHead('POS menu', 'The categories and items on the POS screen. Hidden ones disappear from the POS but stay on old bills.');
    const [{ categories }, { items }] = await Promise.all([api('/pos/categories'), api('/pos/items')]);
    const catOptions = (sel) => categories.map((c) => `<option value="${c.id}" ${c.id === sel ? 'selected' : ''}>${esc(c.name)}${c.active ? '' : ' (hidden)'}</option>`).join('');
    const itemRow = (i) => `<form class="menu-row${i.active ? '' : ' is-off'}" data-item-ed="${i.id}" data-cat="${i.categoryId ?? ''}">
        <input class="inp" name="name" value="${esc(i.name)}" maxlength="120" required aria-label="Name">
        <select class="inp" name="categoryId" aria-label="Category">${i.categoryId === null ? '<option value="" selected>No category</option>' : ''}${catOptions(i.categoryId)}</select>
        <input class="inp" type="number" name="price" min="0" step="1" value="${i.price}" required aria-label="Price">
        <label class="switch"><input type="checkbox" name="active" ${i.active ? 'checked' : ''}> On the POS</label>
        <button class="abtn abtn--sm" type="submit">Save</button>
      </form>`;
    const loose = items.filter((i) => i.categoryId === null);
    view.innerHTML = `
      <div class="bill-bar"><span class="bill-bar__gap"></span><a class="abtn abtn--primary" href="/pos" target="ydpos">${icon('a-pos', 16)}Open the POS</a></div>
      <div class="stack">
        <section class="card">
          <div class="card__head"><h2>Categories</h2><span class="src">The tabs along the top of the POS, in this order</span></div>
          <div class="cat-rows">${categories.map((c, i) => `<form class="cat-row${c.active ? '' : ' is-off'}" data-cat-ed="${c.id}">
              ${moveBtns(i, categories.length, 'data-cat-move')}
              <input class="inp" name="name" value="${esc(c.name)}" maxlength="60" required aria-label="Category name">
              <span class="src">${plural(items.filter((x) => x.categoryId === c.id).length, 'item')}</span>
              <label class="switch"><input type="checkbox" name="active" ${c.active ? 'checked' : ''}> On the POS</label>
              <button class="abtn abtn--sm" type="submit">Save</button>
            </form>`).join('') || '<div class="empty">No categories yet.</div>'}</div>
          <form class="card__body cat-new" data-cat-new>
            <label class="fld"><span>New category</span><input name="name" maxlength="60" required placeholder="e.g. Desserts"></label>
            <button class="abtn" type="submit">${icon('a-plus', 15)}Add category</button>
          </form>
        </section>
        <section class="card"><div class="card__head"><h2>Add an item</h2></div>
          ${categories.length ? `<form class="card__body grid4 menu-new" data-item-new>
            <label class="fld span2"><span>Name</span><input name="name" maxlength="120" required placeholder="e.g. Chicken kottu"></label>
            <label class="fld"><span>Category</span><select name="categoryId" required>${catOptions((categories.find((c) => c.active) || categories[0]).id)}</select></label>
            <label class="fld"><span>Price (${esc(currency())})</span><input type="number" name="price" min="0" step="1" required></label>
            <div class="full menu-new__save"><button class="abtn abtn--primary" type="submit">${icon('a-plus', 15)}Add item</button></div>
          </form>` : '<div class="empty">Add a category first.</div>'}
        </section>
        ${categories.map((c) => {
          const list = items.filter((i) => i.categoryId === c.id);
          return `<section class="card${c.active ? '' : ' is-off-card'}"><div class="card__head"><h2>${esc(c.name)}</h2><span class="src">${plural(list.length, 'item')}${c.active ? '' : ' · hidden from the POS'}</span></div>
            ${list.length ? `<div class="menu-rows">${list.map(itemRow).join('')}</div>` : '<div class="empty">Nothing here yet.</div>'}</section>`;
        }).join('')}
        ${loose.length ? `<section class="card"><div class="card__head"><h2>No category</h2><span class="src">Shown under “Other” — give them a category</span></div><div class="menu-rows">${loose.map(itemRow).join('')}</div></section>` : ''}
      </div>`;

    view.onchange = (e) => {
      if (e.target.name !== 'active') return;
      const row = e.target.closest('.menu-row, .cat-row');
      if (row) row.classList.toggle('is-off', !e.target.checked);
    };
    view.onclick = async (e) => {
      const mv = e.target.closest('[data-cat-move]');
      if (!mv) return;
      // reorder, then save every category whose position changed
      const id = Number(mv.closest('[data-cat-ed]').dataset.catEd);
      const order = categories.slice();
      const i = order.findIndex((c) => c.id === id);
      const j = i + Number(mv.dataset.catMove);
      if (j < 0 || j >= order.length) return;
      [order[i], order[j]] = [order[j], order[i]];
      try {
        for (const [n, c] of order.entries()) if (c.sort !== n + 1) await api(`/pos/categories/${c.id}`, { method: 'PATCH', body: { sort: n + 1 } });
        await viewMenu();
      } catch (ex) { toast(ex.message, true); }
    };
    view.onsubmit = async (e) => {
      e.preventDefault();
      const f = e.target;
      try {
        if (f.matches('[data-cat-new]')) {
          await api('/pos/categories', { method: 'POST', body: { name: f.name.value } });
          toast(`${f.name.value.trim()} added — it is on the POS now`);
          await viewMenu();
        } else if (f.matches('[data-cat-ed]')) {
          await api(`/pos/categories/${f.dataset.catEd}`, { method: 'PATCH', body: { name: f.name.value, active: f.active.checked } });
          toast(`${f.name.value.trim()} saved`);
          await viewMenu(); // names show in every item's category list
        } else if (f.matches('[data-item-new]')) {
          await api('/pos/items', { method: 'POST', body: { name: f.name.value, categoryId: Number(f.categoryId.value), price: Number(f.price.value) } });
          toast(`${f.name.value.trim()} added to the menu`);
          await viewMenu();
        } else if (f.matches('[data-item-ed]')) {
          const body = { name: f.name.value, price: Number(f.price.value), active: f.active.checked };
          if (f.categoryId.value) body.categoryId = Number(f.categoryId.value);
          await api(`/pos/items/${f.dataset.itemEd}`, { method: 'PATCH', body });
          toast(`${body.name.trim()} saved`);
          if (String(body.categoryId ?? '') !== f.dataset.cat) await viewMenu(); // it moves to another category
        }
      } catch (ex) { toast(ex.message, true); }
    };
  }

  // ================= BILLS =================
  const BF = { q: '', period: 'month', status: '' };
  const billStatus = (b) => (b.status === 'void' ? '<span class="st st--cancelled">Void</span>'
    : b.balance > 0 ? '<span class="st st--pending">Balance due</span>' : '<span class="st st--confirmed">Paid</span>');

  async function viewBills(parts) {
    if (parts[0]) return viewBill(Number(parts[0]));
    setHead('Bills', `Every bill issued at checkout. Open one to print or email it${can('void') ? ', take a late payment, or void it' : ' or take a late payment'}.`);
    view.innerHTML = `<section class="card">
      <div class="filters">
        <label class="search">${icon('a-search')}<input class="inp" type="search" placeholder="Search bill no, guest, booking ref or room…" value="${esc(BF.q)}" data-bf="q" aria-label="Search bills"></label>
        <span class="seg">${[['today', 'Today'], ['month', 'This month'], ['all', 'All']].map(([k, l]) => `<button type="button" data-period="${k}" class="${BF.period === k ? 'is-on' : ''}">${l}</button>`).join('')}</span>
        <select class="inp" data-bf="status" aria-label="Status">${[['', 'All bills'], ['due', 'Balance due'], ['issued', 'Issued'], ['void', 'Void']].map(([k, l]) => `<option value="${k}" ${BF.status === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
      </div>
      <div data-bills><div class="loading">Loading…</div></div>
    </section>`;
    let timer;
    view.oninput = (e) => {
      const k = e.target.dataset.bf;
      if (!k) return;
      BF[k] = e.target.value;
      clearTimeout(timer);
      timer = setTimeout(loadBills, k === 'q' ? 250 : 0);
    };
    view.onclick = (e) => {
      const p = e.target.closest('[data-period]');
      if (p) { BF.period = p.dataset.period; $$('[data-period]', view).forEach((x) => x.classList.toggle('is-on', x === p)); loadBills(); }
      const row = e.target.closest('[data-bill]');
      if (row) location.hash = `#/bills/${row.dataset.bill}`;
    };
    view.onkeydown = (e) => { const row = e.key === 'Enter' && e.target.closest('[data-bill]'); if (row) location.hash = `#/bills/${row.dataset.bill}`; };
    await loadBills();
  }

  async function loadBills() {
    const box = $('[data-bills]', view);
    if (!box) return;
    const p = new URLSearchParams();
    if (BF.q) p.set('q', BF.q);
    if (BF.status) p.set('status', BF.status);
    if (BF.period === 'today') p.set('from', today());
    if (BF.period === 'month') p.set('from', `${today().slice(0, 7)}-01`);
    const { bills } = await api(`/pos/bills?${p}`);
    if (!bills.length) { box.innerHTML = '<div class="empty">No bills match.</div>'; return; }
    const issued = bills.filter((b) => b.status === 'issued');
    const sum = (k) => issued.reduce((s, b) => s + b[k], 0);
    box.innerHTML = `<div class="table-wrap"><table class="tbl bills-tbl">
      <thead><tr><th>Bill</th><th>Date</th><th>Billed to</th><th>Booking</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th><th>Status</th></tr></thead>
      <tbody>${bills.map((b) => `<tr data-bill="${b.id}" tabindex="0" class="${b.status === 'void' ? 'is-void' : ''}">
        <td class="ref">${esc(b.billNo)}</td>
        <td>${fdt(b.createdAt)}</td>
        <td><b>${esc(b.guestName)}</b><span class="sub">${esc(b.refs.join(', '))}</span></td>
        <td>${esc(b.places.join(' + '))}</td>
        <td class="num">${money(b.total)}</td>
        <td class="num">${b.status === 'void' ? '—' : money(b.paid)}</td>
        <td class="num">${b.status === 'void' ? '—' : money(b.balance)}</td>
        <td>${billStatus(b)}</td>
      </tr>`).join('')}</tbody></table></div>
      <div class="tbl-foot">${plural(bills.length, 'bill')} · ${money(sum('total'))} billed · ${money(sum('paid'))} received${sum('balance') > 0 ? ` · <b>${money(sum('balance'))} still due</b>` : ''} (void bills not counted)</div>`;
  }

  async function viewBill(id) {
    const { bill } = await api(`/pos/bills/${id}`);
    const s = bill.snapshot;
    const issued = bill.status === 'issued';
    setHead(`Bill ${bill.billNo}`, `${bill.guestName} · ${fdt(bill.createdAt)}${issued ? '' : ' · VOID'}`);
    view.innerHTML = `
      <div class="bill-bar no-print">
        <a class="abtn" href="#/bills">${icon('a-left', 15)}All bills</a>
        <span class="bill-bar__gap"></span>
        <button type="button" class="abtn" data-print>${icon('a-print', 16)}Print</button>
        ${issued && s.guest.email ? `<button type="button" class="abtn" data-bill-email>${icon('a-mail', 16)}Email to guest</button>` : ''}
        ${issued && bill.balance > 0 ? '<button type="button" class="abtn abtn--primary" data-bill-pay>Record payment</button>' : ''}
        ${issued && can('void') ? '<button type="button" class="abtn abtn--danger" data-bill-void>Void bill</button>' : ''}
      </div>
      ${issued ? '' : `<div class="note note--bad no-print bill-voided">Voided ${fdt(bill.voidedAt)} by ${esc(bill.voidedBy)}: ${esc(bill.voidReason)}</div>`}
      ${issued && bill.serviceCharge > 0 ? `<div class="note note--info no-print bill-svc"><span><b>Service charge ${money(bill.serviceCharge)}</b> — ${(bill.serviceShares || []).length
        ? `shared: ${bill.serviceShares.map((x) => `${esc(x.name)} ${money(x.amount)}`).join(', ')}` : 'not shared with the staff yet'}</span>
        <button type="button" class="abtn abtn--sm" data-bill-svc>Who served?</button></div>` : ''}
      <div class="doc-dlg__frame bill-doc"><iframe src="${esc(bill.billUrl)}?embed=1" title="Bill ${esc(bill.billNo)}" data-bill-frame></iframe></div>
      ${bill.payments.length ? `<section class="card no-print bill-pays"><div class="card__head"><h2>Receipts</h2><span class="src">One for every payment on this bill</span></div>
        <div class="card__body pay-list">${bill.payments.map((p) => `<div class="pay-row">
          <div><b>${money(p.amount)}</b> · ${esc(p.methodLabel)} <span class="src">· ${fdt(p.createdAt)}</span>
            ${[...(p.details || []).map((d) => `${d.label}: ${d.value}`), p.note, p.fee ? `${money(p.fee)} kept by the agent or bank — ${money(p.net)} reached the account` : ''].filter(Boolean).length
              ? `<span class="pay-row__sub">${esc([...(p.details || []).map((d) => `${d.label}: ${d.value}`), p.note, p.fee ? `${money(p.fee)} kept by the agent or bank — ${money(p.net)} reached the account` : ''].filter(Boolean).join(' · '))}</span>` : ''}</div>
          <button type="button" class="abtn abtn--sm" data-receipt="${p.id}">${icon('a-print', 15)}${esc(p.receiptNo)}</button></div>`).join('')}</div></section>` : ''}`;
    const guestOf = () => ({ guestName: s.guest.name, guestPhone: s.guest.phone || '', guestEmail: s.guest.email || '', ref: bill.billNo });
    view.onclick = async (e) => {
      if (e.target.closest('[data-bill-svc]')) { window.YDStaff.serviceDialog(h, bill, money, () => { h.closeModal(); viewBill(id); }); return; }
      // printed like invoices and receipts: the bill document, on the paper chosen in Settings
      if (e.target.closest('[data-print]')) { window.YDStaff.printDocFrame($('[data-bill-frame]', view), bill.billUrl); return; }
      const rc = e.target.closest('[data-receipt]');
      if (rc) { const p = bill.payments.find((x) => x.id === Number(rc.dataset.receipt)); if (p) showReceipt(p, guestOf()); return; }
      if (e.target.closest('[data-bill-email]')) {
        try { await api(`/pos/bills/${id}/email`, { method: 'POST', body: {} }); toast(`Bill emailed to ${s.guest.email}`); } catch (ex) { toast(ex.message, true); }
        return;
      }
      if (e.target.closest('[data-bill-pay]')) {
        paymentDialog({
          title: 'Record a payment', sub: `${bill.billNo} · ${money(bill.balance)} still due`, suggested: bill.balance,
          submit: async (p) => {
            const r = await api(`/pos/bills/${id}/payments`, { method: 'POST', body: p });
            toast(`${money(p.amount)} recorded`);
            await viewBill(id);
            const paid = r.bill.payments.find((x) => x.id === r.bill.paymentId);
            return paid ? () => showReceipt(paid, guestOf()) : null;
          },
        });
        return;
      }
      if (e.target.closest('[data-bill-void]')) {
        reasonDialog({
          title: `Void bill ${bill.billNo}?`,
          sub: 'It stays in the history marked VOID. Its guests go back to checked in with their room bills open, so you can correct them and bill again.',
          placeholder: 'e.g. Guest is staying another night', button: 'Void bill', danger: true,
          submit: async (reason) => { await api(`/pos/bills/${id}/void`, { method: 'POST', body: { reason } }); toast(`${bill.billNo} voided — the room bill is open again`); await viewBill(id); },
        });
      }
    };
  }

  return { menu: viewMenu, bills: viewBills };
};
