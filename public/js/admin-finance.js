/* Admin → Finance: a summary for any period (with a PDF), expenses, staff and salaries, the
   service charge shared among the staff who served, money paid into each bank account, stock,
   commissions, and room and food figures. Admins only (Auth::ROLES 'finance').
   admin.js creates this with its helpers; the server work is in Finance.php. */
window.YDFINANCE = function (h) {
  'use strict';
  const { $, $$, esc, icon, money, fd, api, toast, setHead, view, today, openModal, closeModal, modalHead } = h;
  const S = window.YDStaff;
  const TABS = [['summary', 'Summary'], ['transactions', 'Transactions'], ['expenses', 'Expenses'], ['staff', 'Staff & salaries'], ['service', 'Service charge'], ['bank', 'Bank'],
    ['stock', 'Stock'], ['commissions', 'Commissions'], ['analytics', 'Rooms & food']];
  let META = null;          // accounts, expense categories, kinds of staff payment
  const P = { from: '', to: '' }; // the period shown, kept while moving between tabs
  let TAB = 'summary';
  const q = () => `from=${P.from}&to=${P.to}`;
  const plural = (n, one, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;
  const dmy = (d) => (d ? fd(d, { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
  const num = (v) => Math.round(Number(v)) || 0;
  const qty = (n) => String(Math.round(Number(n) * 100) / 100);
  const panel = () => $('[data-modal-panel]');

  async function loadMeta(force = false) {
    if (!META || force) META = await api('/finance/meta');
    if (!P.from) { P.from = META.period.from; P.to = META.period.to; }
    return META;
  }
  function setMonth(ym) {
    const [y, m] = ym.split('-').map(Number);
    P.from = `${ym}-01`;
    P.to = `${ym}-${String(new Date(Date.UTC(y, m, 0)).getUTCDate()).padStart(2, '0')}`;
  }
  const periodText = () => (P.from.slice(0, 7) === P.to.slice(0, 7) && P.from.endsWith('-01') && Number(P.to.slice(8)) >= 28
    ? fd(P.from, { month: 'long', year: 'numeric' }) : `${dmy(P.from)} – ${dmy(P.to)}`);

  // ---------- shared bits ----------
  const lists = () => `<datalist id="fin-acct">${META.accounts.map((a) => `<option value="${esc(a)}">`).join('')}</datalist>
    <datalist id="fin-cat">${META.expenseCategories.map((c) => `<option value="${esc(c)}">`).join('')}</datalist>`;
  const kpi = (label, value, sub = '', cls = '') => `<div class="fin-kpi ${cls}"><small>${esc(label)}</small><b>${value}</b>${sub ? `<span>${sub}</span>` : ''}</div>`;
  /** A small card with label / amount rows. rows: [[label, value, sub?]] */
  const card = (title, rows, total, extra = '') => `<section class="card fin-card"><div class="card__head"><h2>${esc(title)}</h2>${extra}</div>
    ${rows.length ? `<table class="tbl fin-tbl"><tbody>${rows.map(([l, v, sub]) => `<tr><td>${l}${sub ? `<span class="sub">${sub}</span>` : ''}</td><td class="num">${v}</td></tr>`).join('')}
    ${total ? `<tr class="is-sum"><td>${total[0]}</td><td class="num">${total[1]}</td></tr>` : ''}</tbody></table>` : '<div class="empty">Nothing in this period.</div>'}</section>`;
  const bar = (pct) => `<span class="fin-barline"><i style="width:${Math.max(0, Math.min(100, pct))}%"></i></span>`;
  const delBtn = (attr, id) => `<button type="button" class="abtn abtn--sm abtn--ghost" ${attr}="${id}" aria-label="Delete">${icon('a-x', 14)}</button>`;

  /** A dialog with a form; save(form) does the work and may throw to keep it open. */
  function formDialog(title, sub, fields, button, save) {
    openModal(`${modalHead(title, sub)}<form class="dr-body"><div class="grid2">${fields}</div>
      <div class="dlg-foot"><button type="button" class="abtn" data-mclose>Cancel</button><button class="abtn abtn--primary" type="submit">${esc(button)}</button></div></form>${lists()}`);
    const p = panel();
    p.onsubmit = async (e) => {
      e.preventDefault();
      const f = e.target;
      const btn = $('[type="submit"]', f);
      btn.disabled = true;
      try { await save(f); closeModal(); await render(); } catch (ex) { toast(ex.message, true); btn.disabled = false; }
    };
    setTimeout(() => { const first = $('input:not([type="hidden"]), select', p); if (first) first.focus(); }, 60);
  }

  // ---------- the page ----------
  async function show(tab) {
    await loadMeta();
    TAB = TABS.some(([k]) => k === tab) ? tab : 'summary';
    setHead('Finance', 'Money in and out, staff pay, the service charge, the bank, stock and figures.');
    view.innerHTML = `<div class="fin">
      <nav class="fin-tabs" aria-label="Finance">${TABS.map(([k, l]) => `<a href="#/finance/${k}" class="${k === TAB ? 'is-active' : ''}">${esc(l)}</a>`).join('')}</nav>
      <div class="fin-bar">
        <label class="fld"><span>Month</span><input type="month" data-month value="${P.from.slice(0, 7)}"></label>
        <label class="fld"><span>From</span><input type="date" data-from value="${P.from}"></label>
        <label class="fld"><span>To</span><input type="date" data-to value="${P.to}"></label>
        <span class="fin-bar__text" data-period-text>${esc(periodText())}</span>
      </div>
      <div data-fin><div class="loading">Loading…</div></div>
      ${lists()}
    </div>`;
    const bar = $('.fin-bar', view);
    bar.onchange = (e) => {
      if (e.target.matches('[data-month]') && e.target.value) setMonth(e.target.value);
      if (e.target.matches('[data-from]') && e.target.value) P.from = e.target.value;
      if (e.target.matches('[data-to]') && e.target.value) P.to = e.target.value;
      if (P.to < P.from) [P.from, P.to] = [P.to, P.from];
      $('[data-from]', bar).value = P.from;
      $('[data-to]', bar).value = P.to;
      $('[data-month]', bar).value = P.from.slice(0, 7);
      $('[data-period-text]', bar).textContent = periodText();
      render();
    };
    await render();
  }

  async function render() {
    const box = $('[data-fin]', view);
    if (!box) return;
    box.onclick = box.onsubmit = box.onchange = box.oninput = null;
    try { await VIEWS[TAB](box); } catch (ex) { box.innerHTML = `<div class="card"><div class="empty">${esc(ex.message)}</div></div>`; }
  }

  // ================= SUMMARY =================
  async function viewSummary(box) {
    const { summary: s } = await api(`/finance/summary?${q()}`);
    const r = s.received;
    const sales = s.sales;
    box.innerHTML = `
      <div class="fin-actions"><span class="src">Everything for ${esc(periodText())}.</span>
        <button type="button" class="abtn" data-sum-print>${icon('a-print', 16)}Print</button>
        <button type="button" class="abtn abtn--primary" data-sum-pdf>${icon('a-doc', 16)}Download PDF</button></div>
      <div class="fin-kpis">
        ${kpi('Money received', money(r.net), `reached your accounts${r.fees ? ` · guests paid ${money(r.total)}, ${money(r.fees)} kept by agents or banks` : ` · ${plural(r.count, 'payment')}`}`)}
        ${kpi('Spent', money(s.expenseTotal + s.staffTotal), `expenses ${money(s.expenseTotal)} · staff ${money(s.staffTotal)}`)}
        ${kpi('Net', money(s.net), 'money received, less spending', s.net < 0 ? 'is-bad' : 'is-good')}
        ${kpi('Bills issued', money(sales.total), plural(sales.bills, 'bill'))}
      </div>
      <div class="fin-cols">
        ${card('Received — into each account', r.accounts.map((a) => [esc(a.account), money(a.amount), a.kept ? `guests paid ${money(a.paid)} · ${money(a.kept)} kept by agents or banks` : '']), r.accounts.length ? ['Total', `<b>${money(r.net)}</b>`] : null)}
        ${card('Received — by how it was paid', r.methods.map((a) => [esc(a.label), money(a.amount)]))}
        ${card('Bills issued', sales.bills ? [['Stays', money(sales.stay)], ['Food, drinks and other items', money(sales.items)], ...(sales.service ? [['Service charge', money(sales.service), 'shared with the staff who served']] : []),
          ...(sales.discount ? [['Discounts', `− ${money(sales.discount)}`]] : []), ...(sales.coupon ? [['Coupons', `− ${money(sales.coupon)}`]] : [])] : [], sales.bills ? ['Billed', `<b>${money(sales.total)}</b>`] : null)}
        ${card('Expenses', s.expenses.map((e) => [esc(e.category), money(e.amount), plural(e.count, 'entry', 'entries')]), s.expenses.length ? ['Total', `<b>${money(s.expenseTotal)}</b>`] : null)}
        ${card('Paid to staff', s.staff.map((x) => [esc(x.label), money(x.amount)]), s.staff.length ? ['Total', `<b>${money(s.staffTotal)}</b>`] : null)}
        ${card('Paid into bank accounts', s.bank.map((x) => [esc(x.account), money(x.amount), plural(x.count, 'deposit')]), s.bank.length ? ['Total', `<b>${money(s.bankTotal)}</b>`] : null)}
        ${card('Commissions', [['Earned by agents and websites', money(s.commissions.earned), 'stays arriving in this period'], ['Kept by agents from payments', money(s.commissions.kept)],
          ['Paid to them by you', money(s.commissions.paid)], ['Still to pay (all bookings)', `<b>${money(s.commissions.toPay)}</b>`], ...(s.commissions.charges ? [['Bank or card charges', money(s.commissions.charges), 'taken off payments']] : [])])}
        ${card('Rooms', [['Room nights sold', String(s.rooms.nights)], ['Occupancy', `${s.rooms.occupancy}%`], ['Room revenue', money(s.rooms.revenue)], ['Average rate a night', money(s.rooms.adr)], ['Day visits', String(s.rooms.dayVisits)]])}
        ${card('Food and drinks', [['Sold', money(s.food.total)], ...s.food.top.map((i) => [esc(i.name), money(i.amount), `${i.qty} sold · ${esc(i.category)}`])])}
        ${card('Stock', [['Bought in this period', money(s.stockBought)], ...s.lowStock.map((i) => [`${esc(i.name)} <span class="st st--pending">Low</span>`, `${qty(i.onHand)} ${esc(i.unit)}`, `reorder at ${qty(i.reorderLevel)}`])])}
      </div>`;
    const url = `/finance/summary?${q()}`;
    box.onclick = async (e) => {
      if (e.target.closest('[data-sum-print]')) { S.printUrl(url); return; }
      const b = e.target.closest('[data-sum-pdf]');
      if (b) {
        b.disabled = true;
        try { window.YDPdf.download(await window.YDPdf.fromUrl(url), `Finance summary ${P.from} to ${P.to}.pdf`); } catch (ex) { toast(`${ex.message} Use Print → Save as PDF instead.`, true); }
        b.disabled = false;
      }
    };
  }

  // ================= EXPENSES =================
  async function viewExpenses(box) {
    const { expenses } = await api(`/finance/expenses?${q()}`);
    const total = expenses.reduce((s, e) => s + e.amount, 0);
    const byCat = {};
    expenses.forEach((e) => { byCat[e.category] = (byCat[e.category] || 0) + e.amount; });
    box.innerHTML = `<div class="fin-grid">
      <form class="card" data-exp-form>
        <div class="card__head"><h2 data-exp-title>Add an expense</h2></div>
        <div class="card__body grid2">
          <label class="fld"><span>Date</span><input type="date" name="spentOn" value="${today()}" required></label>
          <label class="fld"><span>Amount (${esc(h.currency())})</span><input type="number" name="amount" min="1" step="1" required></label>
          <label class="fld span2"><span>Category</span><input name="category" list="fin-cat" maxlength="60" required placeholder="Choose or type a category"></label>
          <label class="fld span2"><span>What for</span><input name="description" maxlength="255" placeholder="e.g. Electricity bill for October"></label>
          <label class="fld"><span>Paid to <small>(optional)</small></span><input name="payee" maxlength="120" placeholder="Shop, supplier or agent"></label>
          <label class="fld"><span>Paid from <small>(optional)</small></span><input name="account" list="fin-acct" maxlength="120" placeholder="Cash in hand, a bank account…"></label>
          <label class="fld span2"><span>Note <small>(optional)</small></span><input name="note" maxlength="255" placeholder="Bill or invoice number"></label>
          <div class="span2 setup__save"><button type="button" class="abtn" data-exp-cancel hidden>Cancel</button><button class="abtn abtn--primary" type="submit">${icon('a-plus', 16)}Add expense</button></div>
        </div>
      </form>
      <section class="card">
        <div class="card__head"><h2>Expenses</h2><span class="src">${plural(expenses.length, 'entry', 'entries')} · ${money(total)}</span></div>
        ${Object.keys(byCat).length ? `<div class="fin-chips">${Object.entries(byCat).sort((a, b) => b[1] - a[1]).map(([c, v]) => `<span class="fin-chip">${esc(c)} <b>${money(v)}</b></span>`).join('')}</div>` : ''}
        ${expenses.length ? `<div class="table-wrap"><table class="tbl fin-tbl"><thead><tr><th>Date</th><th>Category</th><th>What for</th><th class="num">Amount</th><th></th></tr></thead>
          <tbody>${expenses.map((e) => `<tr><td>${dmy(e.spentOn)}</td><td>${esc(e.category)}</td>
            <td>${esc(e.description || '—')}<span class="sub">${esc([e.payee && `to ${e.payee}`, e.account && `from ${e.account}`, e.note].filter(Boolean).join(' · '))}</span></td>
            <td class="num">${money(e.amount)}</td><td class="num fin-acts"><button type="button" class="abtn abtn--sm" data-exp-edit="${e.id}">Edit</button>${delBtn('data-exp-del', e.id)}</td></tr>`).join('')}</tbody></table></div>`
          : '<div class="empty">No expenses in this period.</div>'}
      </section></div>`;
    const form = $('[data-exp-form]', box);
    let editing = 0;
    const reset = () => {
      editing = 0;
      form.reset();
      form.spentOn.value = today();
      $('[data-exp-title]', form).textContent = 'Add an expense';
      $('[type="submit"]', form).innerHTML = `${icon('a-plus', 16)}Add expense`;
      $('[data-exp-cancel]', form).hidden = true;
    };
    box.onsubmit = async (e) => {
      e.preventDefault();
      const body = { spentOn: form.spentOn.value, amount: num(form.amount.value), category: form.category.value, description: form.description.value, payee: form.payee.value, account: form.account.value, note: form.note.value };
      try {
        if (editing) await api(`/finance/expenses/${editing}`, { method: 'PATCH', body });
        else await api('/finance/expenses', { method: 'POST', body });
        toast(editing ? 'Expense saved' : `Expense added — ${money(body.amount)}`);
        await loadMeta(true);
        await render();
      } catch (ex) { toast(ex.message, true); }
    };
    box.onclick = async (e) => {
      if (e.target.closest('[data-exp-cancel]')) { reset(); return; }
      const ed = e.target.closest('[data-exp-edit]');
      if (ed) {
        const x = expenses.find((y) => y.id === Number(ed.dataset.expEdit));
        editing = x.id;
        Object.entries({ spentOn: x.spentOn, amount: x.amount, category: x.category, description: x.description, payee: x.payee, account: x.account, note: x.note }).forEach(([k, v]) => { form[k].value = v; });
        $('[data-exp-title]', form).textContent = 'Edit the expense';
        $('[type="submit"]', form).textContent = 'Save changes';
        $('[data-exp-cancel]', form).hidden = false;
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
      }
      const del = e.target.closest('[data-exp-del]');
      if (del && confirm('Delete this expense?')) {
        try { await api(`/finance/expenses/${del.dataset.expDel}`, { method: 'DELETE' }); toast('Expense deleted'); await render(); } catch (ex) { toast(ex.message, true); }
      }
    };
  }

  // ================= STAFF & SALARIES =================
  function employeeDialog(emp) {
    const v = (k, d = '') => esc(emp && emp[k] !== null && emp[k] !== undefined ? emp[k] : d);
    formDialog(emp ? `Edit ${emp.name}` : 'Add an employee', 'Staff who work at the resort — they don\'t need a login.', `
      <label class="fld"><span>Name</span><input name="name" maxlength="120" required value="${v('name')}"></label>
      <label class="fld"><span>Job</span><input name="position" maxlength="80" value="${v('position')}" placeholder="e.g. Waiter, cook, room boy"></label>
      <label class="fld"><span>Phone</span><input name="phone" maxlength="40" inputmode="tel" value="${v('phone')}"></label>
      <label class="fld"><span>NIC</span><input name="idNo" maxlength="30" value="${v('idNo')}"></label>
      <label class="fld"><span>Joined on <small>(optional)</small></span><input type="date" name="joinedOn" value="${v('joinedOn')}"></label>
      <label class="fld"><span>Monthly salary (${esc(h.currency())})</span><input type="number" name="salary" min="0" step="1" value="${v('salary', 0)}"></label>
      <label class="chk span2"><input type="checkbox" name="serviceShare" ${!emp || emp.serviceShare ? 'checked' : ''}> Shares the service charge (shows in the “Who served?” list at checkout)</label>
      ${emp ? `<label class="chk span2"><input type="checkbox" name="active" ${emp.active ? 'checked' : ''}> Works here now <small class="src">(untick when someone leaves — their records stay)</small></label>` : ''}
      <label class="fld span2"><span>Note <small>(optional)</small></span><input name="note" maxlength="255" value="${v('note')}"></label>`,
    emp ? 'Save' : 'Add employee', async (f) => {
      const body = { name: f.name.value, position: f.position.value, phone: f.phone.value, idNo: f.idNo.value, joinedOn: f.joinedOn.value, salary: num(f.salary.value), serviceShare: f.serviceShare.checked, note: f.note.value };
      if (f.active) body.active = f.active.checked;
      await api(emp ? `/finance/employees/${emp.id}` : '/finance/employees', { method: emp ? 'PATCH' : 'POST', body });
      toast(emp ? `${body.name} saved` : `${body.name} added`);
    });
  }

  /** Pay a member of staff: a salary (for a month), their service charge share, an advance or a bonus. */
  function payDialog(employees, { employeeId = 0, kind = 'salary', amount = 0, period = '' } = {}) {
    const emp = employees.find((x) => x.id === employeeId);
    formDialog(emp ? `Pay ${emp.name}` : 'Pay a member of staff', 'It goes in the staff payments and the summary.', `
      ${emp ? `<input type="hidden" name="employeeId" value="${emp.id}">` : `<label class="fld span2"><span>Who</span><select name="employeeId" required><option value="">—</option>${employees.filter((x) => x.active).map((x) => `<option value="${x.id}">${esc(x.name)}${x.position ? ` — ${esc(x.position)}` : ''}</option>`).join('')}</select></label>`}
      <label class="fld"><span>For</span><select name="kind">${Object.entries(META.payKinds).map(([k, l]) => `<option value="${k}" ${k === kind ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
      <label class="fld" data-period-f><span>Salary for</span><input type="month" name="period" value="${period || P.from.slice(0, 7)}"></label>
      <label class="fld"><span>Amount (${esc(h.currency())})</span><input type="number" name="amount" min="1" step="1" required value="${amount || ''}"></label>
      <label class="fld"><span>Paid on</span><input type="date" name="paidOn" value="${today()}" required></label>
      <label class="fld"><span>Paid from <small>(optional)</small></span><input name="account" list="fin-acct" maxlength="120" placeholder="Cash in hand, a bank account…"></label>
      <label class="fld"><span>Note <small>(optional)</small></span><input name="note" maxlength="255"></label>`,
    'Record payment', async (f) => {
      const r = await api('/finance/staff-payments', { method: 'POST', body: { employeeId: Number(f.employeeId.value), kind: f.kind.value, period: f.period.value, amount: num(f.amount.value), paidOn: f.paidOn.value, account: f.account.value, note: f.note.value } });
      toast(`${money(r.payment.amount)} paid to ${r.payment.employee} — ${r.payment.kindLabel.toLowerCase()}`);
    });
    const p = panel();
    const sync = () => { $('[data-period-f]', p).hidden = p.querySelector('[name="kind"]').value !== 'salary'; };
    p.querySelector('[name="kind"]').addEventListener('change', sync);
    sync();
  }

  async function viewStaff(box) {
    const month = P.from.slice(0, 7);
    const [{ employees }, { salaries }, { payments }] = await Promise.all([api('/finance/employees'), api(`/finance/salaries?month=${month}`), api(`/finance/staff-payments?${q()}`)]);
    const paidTotal = payments.reduce((s, x) => s + x.amount, 0);
    box.innerHTML = `
      <section class="card">
        <div class="card__head"><h2>Staff</h2><button type="button" class="abtn abtn--primary abtn--sm" data-emp-add>${icon('a-plus', 15)}Add employee</button></div>
        ${employees.length ? `<div class="table-wrap"><table class="tbl fin-tbl"><thead><tr><th>Name</th><th>Phone</th><th class="num">Monthly salary</th><th>Service charge</th><th>Status</th><th></th></tr></thead>
          <tbody>${employees.map((x) => `<tr class="${x.active ? '' : 'is-off'}"><td><b>${esc(x.name)}</b><span class="sub">${esc([x.position, x.joinedOn && `since ${dmy(x.joinedOn)}`].filter(Boolean).join(' · '))}</span></td>
            <td>${esc(x.phone || '—')}</td><td class="num">${x.salary ? money(x.salary) : '—'}</td><td>${x.serviceShare ? 'Shares' : '—'}</td>
            <td>${x.active ? '<span class="st st--confirmed">Working</span>' : '<span class="st st--cancelled">Left</span>'}</td>
            <td class="num"><button type="button" class="abtn abtn--sm" data-emp-edit="${x.id}">Edit</button></td></tr>`).join('')}</tbody></table></div>`
          : '<div class="empty">No staff yet. Add the people who work here — they appear in “Who served?” at checkout and in salaries.</div>'}
      </section>
      <section class="card">
        <div class="card__head"><h2>Salaries — ${esc(fd(`${month}-01`, { month: 'long', year: 'numeric' }))}</h2><span class="src">${money(salaries.paid)} paid · ${money(salaries.due)} still to pay</span></div>
        ${salaries.rows.length ? `<div class="table-wrap"><table class="tbl fin-tbl"><thead><tr><th>Employee</th><th class="num">Salary</th><th class="num">Paid</th><th class="num">Still to pay</th><th></th></tr></thead>
          <tbody>${salaries.rows.map((r) => `<tr><td>${esc(r.employee.name)}<span class="sub">${esc(r.employee.position)}</span></td><td class="num">${money(r.salary)}</td><td class="num">${money(r.paid)}</td>
            <td class="num">${r.due ? `<b>${money(r.due)}</b>` : '<span class="st st--confirmed">Paid</span>'}</td>
            <td class="num">${r.due ? `<button type="button" class="abtn abtn--sm abtn--primary" data-pay-salary="${r.employee.id}" data-amount="${r.due}">Pay</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`
          : '<div class="empty">Give your staff a monthly salary to see it here.</div>'}
      </section>
      <section class="card">
        <div class="card__head"><h2>Payments to staff</h2><span class="src">${plural(payments.length, 'payment')} · ${money(paidTotal)}</span>
          <button type="button" class="abtn abtn--sm" data-pay-other>${icon('a-plus', 15)}Record a payment</button></div>
        ${payments.length ? `<div class="table-wrap"><table class="tbl fin-tbl"><thead><tr><th>Date</th><th>Employee</th><th>For</th><th class="num">Amount</th><th></th></tr></thead>
          <tbody>${payments.map((x) => `<tr><td>${dmy(x.paidOn)}</td><td>${esc(x.employee)}</td>
            <td>${esc(x.kindLabel)}${x.period ? ` — ${esc(fd(`${x.period}-01`, { month: 'short', year: 'numeric' }))}` : ''}<span class="sub">${esc([x.account && `from ${x.account}`, x.note].filter(Boolean).join(' · '))}</span></td>
            <td class="num">${money(x.amount)}</td><td class="num">${delBtn('data-pay-del', x.id)}</td></tr>`).join('')}</tbody></table></div>`
          : '<div class="empty">No payments to staff in this period.</div>'}
      </section>`;
    box.onclick = async (e) => {
      if (e.target.closest('[data-emp-add]')) { employeeDialog(null); return; }
      const ed = e.target.closest('[data-emp-edit]');
      if (ed) { employeeDialog(employees.find((x) => x.id === Number(ed.dataset.empEdit))); return; }
      const ps = e.target.closest('[data-pay-salary]');
      if (ps) { payDialog(employees, { employeeId: Number(ps.dataset.paySalary), kind: 'salary', amount: Number(ps.dataset.amount), period: month }); return; }
      if (e.target.closest('[data-pay-other]')) { payDialog(employees, { kind: 'advance' }); return; }
      const del = e.target.closest('[data-pay-del]');
      if (del && confirm('Delete this payment?')) {
        try { await api(`/finance/staff-payments/${del.dataset.payDel}`, { method: 'DELETE' }); toast('Payment deleted'); await render(); } catch (ex) { toast(ex.message, true); }
      }
    };
  }

  // ================= SERVICE CHARGE =================
  async function viewService(box) {
    const [{ bills, balances }, { employees }] = await Promise.all([api(`/finance/service?${q()}`), api('/finance/employees')]);
    const notShared = bills.filter((b) => !b.shares.length);
    box.innerHTML = `
      <section class="card">
        <div class="card__head"><h2>What each person is owed</h2><span class="src">Their shares of the service charge, less what they were paid</span></div>
        ${balances.length ? `<div class="table-wrap"><table class="tbl fin-tbl"><thead><tr><th>Employee</th><th class="num">Earned this period</th><th class="num">Paid this period</th><th class="num">Owed now</th><th></th></tr></thead>
          <tbody>${balances.map((x) => `<tr><td>${esc(x.employee.name)}<span class="sub">${esc(x.employee.position)}</span></td><td class="num">${money(x.earnedInPeriod)}</td><td class="num">${money(x.paidInPeriod)}</td>
            <td class="num">${x.owed > 0 ? `<b>${money(x.owed)}</b>` : money(x.owed)}</td>
            <td class="num">${x.owed > 0 ? `<button type="button" class="abtn abtn--sm abtn--primary" data-svc-pay="${x.employee.id}" data-amount="${x.owed}">Pay</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`
          : '<div class="empty">Add your staff in “Staff &amp; salaries” first.</div>'}
      </section>
      <section class="card">
        <div class="card__head"><h2>Bills with a service charge</h2><span class="src">${plural(bills.length, 'bill')}${notShared.length ? ` · <b>${notShared.length} not shared yet</b>` : ''}</span></div>
        ${bills.length ? `<div class="table-wrap"><table class="tbl fin-tbl"><thead><tr><th>Bill</th><th>Guest</th><th class="num">Service charge</th><th>Shared among</th><th></th></tr></thead>
          <tbody>${bills.map((b) => `<tr><td><span class="ref">${esc(b.billNo)}</span><span class="sub">${esc(h.fdt(b.createdAt))}</span></td><td>${esc(b.guestName)}</td><td class="num">${money(b.service)}</td>
            <td>${b.shares.length ? esc(b.shares.map((x) => `${x.name} ${money(x.amount)}`).join(', ')) : '<span class="st st--pending">Not shared</span>'}</td>
            <td class="num"><button type="button" class="abtn abtn--sm" data-svc-bill="${b.id}" data-no="${esc(b.billNo)}">Who served?</button></td></tr>`).join('')}</tbody></table></div>`
          : '<div class="empty">No bills with a service charge in this period.</div>'}
      </section>`;
    box.onclick = (e) => {
      const pay = e.target.closest('[data-svc-pay]');
      if (pay) { payDialog(employees, { employeeId: Number(pay.dataset.svcPay), kind: 'service', amount: Number(pay.dataset.amount) }); return; }
      const sb = e.target.closest('[data-svc-bill]');
      if (sb) S.serviceDialog(h, { id: Number(sb.dataset.svcBill), billNo: sb.dataset.no }, money, () => { closeModal(); render(); });
    };
  }

  // ================= BANK =================
  async function viewBank(box) {
    const { moves, received: r } = await api(`/finance/bank?${q()}`);
    const byAcct = {};
    moves.forEach((m) => { byAcct[m.account] = (byAcct[m.account] || 0) + m.amount; });
    const total = moves.reduce((s, m) => s + m.amount, 0);
    box.innerHTML = `<div class="fin-grid">
      <form class="card" data-bank-form>
        <div class="card__head"><h2>Money paid into a bank account</h2></div>
        <div class="card__body grid2">
          <label class="fld"><span>Date</span><input type="date" name="movedOn" value="${today()}" required></label>
          <label class="fld"><span>Amount (${esc(h.currency())})</span><input type="number" name="amount" min="1" step="1" required></label>
          <label class="fld span2"><span>Into</span><input name="account" list="fin-acct" maxlength="120" required placeholder="The bank account"></label>
          <label class="fld"><span>From <small>(optional)</small></span><input name="fromAccount" list="fin-acct" maxlength="120" placeholder="e.g. Cash in hand"></label>
          <label class="fld"><span>Slip / reference <small>(optional)</small></span><input name="reference" maxlength="120"></label>
          <label class="fld span2"><span>Note <small>(optional)</small></span><input name="note" maxlength="255"></label>
          <div class="span2 setup__save"><button class="abtn abtn--primary" type="submit">${icon('a-plus', 16)}Add</button></div>
        </div>
      </form>
      <div class="fin-stack">
        <section class="card fin-card"><div class="card__head"><h2>Payments received — into each account</h2><span class="src">What reached the account</span></div>
          ${r.accounts.length ? `<table class="tbl fin-tbl"><thead><tr><th>Account</th><th class="num">Guests paid</th><th class="num">Kept</th><th class="num">Reached it</th></tr></thead>
          <tbody>${r.accounts.map((a) => `<tr><td>${esc(a.account)}</td><td class="num">${money(a.paid)}</td><td class="num">${a.kept ? `− ${money(a.kept)}` : '—'}</td><td class="num"><b>${money(a.amount)}</b></td></tr>`).join('')}
          <tr class="is-sum"><td>Total</td><td class="num">${money(r.total)}</td><td class="num">${r.fees ? `− ${money(r.fees)}` : '—'}</td><td class="num">${money(r.net)}</td></tr></tbody></table>
          ${r.fees ? `<p class="src fin-foot">“Kept” is what an agent took as their commission (${money(r.commissionKept)}) or a bank took as charges (${money(r.charges)}) before passing the money on.</p>` : ''}`
          : '<div class="empty">No payments in this period.</div>'}</section>
        ${card('Paid into each account', Object.entries(byAcct).sort((a, b) => b[1] - a[1]).map(([a, v]) => [esc(a), money(v)]), moves.length ? ['Total', `<b>${money(total)}</b>`] : null)}
      </div>
      <section class="card span-all">
        <div class="card__head"><h2>Deposits and transfers</h2><span class="src">${plural(moves.length, 'entry', 'entries')}</span></div>
        ${moves.length ? `<div class="table-wrap"><table class="tbl fin-tbl"><thead><tr><th>Date</th><th>Into</th><th>From</th><th>Reference</th><th class="num">Amount</th><th></th></tr></thead>
          <tbody>${moves.map((m) => `<tr><td>${dmy(m.movedOn)}</td><td>${esc(m.account)}</td><td>${esc(m.fromAccount || '—')}</td><td>${esc(m.reference || '—')}${m.note ? `<span class="sub">${esc(m.note)}</span>` : ''}</td>
            <td class="num">${money(m.amount)}</td><td class="num">${delBtn('data-bank-del', m.id)}</td></tr>`).join('')}</tbody></table></div>`
          : '<div class="empty">Nothing paid into the bank in this period.</div>'}
      </section></div>`;
    box.onsubmit = async (e) => {
      e.preventDefault();
      const f = e.target;
      try {
        await api('/finance/bank', { method: 'POST', body: { movedOn: f.movedOn.value, amount: num(f.amount.value), account: f.account.value, fromAccount: f.fromAccount.value, reference: f.reference.value, note: f.note.value } });
        toast(`${money(num(f.amount.value))} into ${f.account.value}`);
        await loadMeta(true);
        await render();
      } catch (ex) { toast(ex.message, true); }
    };
    box.onclick = async (e) => {
      const del = e.target.closest('[data-bank-del]');
      if (del && confirm('Delete this entry?')) {
        try { await api(`/finance/bank/${del.dataset.bankDel}`, { method: 'DELETE' }); toast('Deleted'); await render(); } catch (ex) { toast(ex.message, true); }
      }
    };
  }

  // ================= STOCK =================
  function itemDialog(item) {
    const v = (k, d = '') => esc(item && item[k] !== null && item[k] !== undefined ? item[k] : d);
    formDialog(item ? `Edit ${item.name}` : 'Add a stock item', 'Things you buy and use up: food, drinks, gas, soap, linen…', `
      <label class="fld span2"><span>Item</span><input name="name" maxlength="120" required value="${v('name')}" placeholder="e.g. Rice, Lion lager, LP gas"></label>
      <label class="fld"><span>Group <small>(optional)</small></span><input name="category" maxlength="60" value="${v('category')}" placeholder="e.g. Kitchen, Bar, Rooms"></label>
      <label class="fld"><span>Counted in</span><input name="unit" maxlength="20" value="${v('unit', 'pcs')}" placeholder="kg, bottles, pcs"></label>
      <label class="fld"><span>Warn when down to <small>(optional)</small></span><input type="number" name="reorderLevel" min="0" step="0.01" value="${item ? qty(item.reorderLevel) : ''}"></label>
      ${item ? `<label class="chk"><input type="checkbox" name="active" ${item.active ? 'checked' : ''}> Still used</label>` : ''}`,
    item ? 'Save' : 'Add item', async (f) => {
      await api(item ? `/finance/stock/items/${item.id}` : '/finance/stock/items', { method: item ? 'PATCH' : 'POST', body: { name: f.name.value, category: f.category.value, unit: f.unit.value, reorderLevel: f.reorderLevel.value || 0, active: f.active ? f.active.checked : true } });
      toast(item ? 'Item saved' : `${f.name.value} added`);
    });
  }

  function moveDialog(item, dir) {
    const into = dir === 'in';
    formDialog(`${into ? 'Stock in' : 'Used'} — ${item.name}`, `In stock now: ${qty(item.onHand)} ${item.unit}`, `
      <label class="fld"><span>How many (${esc(item.unit)})</span><input type="number" name="qty" min="0.01" step="0.01" required></label>
      ${into ? `<label class="fld"><span>Price for one (${esc(h.currency())}) <small>(optional)</small></span><input type="number" name="unitCost" min="0" step="1" value="${item.lastCost || ''}"></label>` : ''}
      <label class="fld"><span>Date</span><input type="date" name="movedOn" value="${today()}" required></label>
      <label class="fld"><span>Note <small>(optional)</small></span><input name="note" maxlength="255" placeholder="${into ? 'Supplier or bill number' : 'e.g. Kitchen, room 3, broken'}"></label>`,
    into ? 'Add to stock' : 'Take from stock', async (f) => {
      const n = Number(f.qty.value);
      const r = await api('/finance/stock/moves', { method: 'POST', body: { itemId: item.id, qty: into ? n : -n, unitCost: f.unitCost ? num(f.unitCost.value) : 0, movedOn: f.movedOn.value, note: f.note.value } });
      toast(`${item.name}: ${qty(r.item.onHand)} ${item.unit} in stock${r.item.low ? ' — running low' : ''}`, r.item.low);
    });
  }

  async function viewStock(box) {
    const { items, moves } = await api(`/finance/stock?${q()}`);
    const low = items.filter((i) => i.low);
    box.innerHTML = `
      <section class="card">
        <div class="card__head"><h2>Stock</h2><span class="src">${plural(items.filter((i) => i.active).length, 'item')}${low.length ? ` · <b>${low.length} running low</b>` : ''}</span>
          <button type="button" class="abtn abtn--primary abtn--sm" data-item-add>${icon('a-plus', 15)}Add item</button></div>
        ${items.length ? `<div class="table-wrap"><table class="tbl fin-tbl"><thead><tr><th>Item</th><th class="num">In stock</th><th class="num">Warn at</th><th class="num">Last price</th><th></th></tr></thead>
          <tbody>${items.map((i) => `<tr class="${i.active ? '' : 'is-off'}"><td><b>${esc(i.name)}</b>${i.low ? ' <span class="st st--pending">Low</span>' : ''}<span class="sub">${esc(i.category || '')}</span></td>
            <td class="num"><b>${qty(i.onHand)}</b> ${esc(i.unit)}</td><td class="num">${i.reorderLevel ? qty(i.reorderLevel) : '—'}</td><td class="num">${i.lastCost ? money(i.lastCost) : '—'}</td>
            <td class="num fin-acts"><button type="button" class="abtn abtn--sm" data-move-in="${i.id}">+ In</button><button type="button" class="abtn abtn--sm" data-move-out="${i.id}">− Used</button>
              <button type="button" class="abtn abtn--sm abtn--ghost" data-item-edit="${i.id}">Edit</button></td></tr>`).join('')}</tbody></table></div>`
          : '<div class="empty">No stock items yet. Add what you buy and use up, then note what comes in and goes out.</div>'}
      </section>
      <section class="card">
        <div class="card__head"><h2>In and out</h2><span class="src">${esc(periodText())}</span></div>
        ${moves.length ? `<div class="table-wrap"><table class="tbl fin-tbl"><thead><tr><th>Date</th><th>Item</th><th class="num">In / out</th><th class="num">Cost</th><th>Note</th></tr></thead>
          <tbody>${moves.map((m) => `<tr><td>${dmy(m.movedOn)}</td><td>${esc(m.name)}</td><td class="num">${m.qty > 0 ? `+${qty(m.qty)}` : qty(m.qty)} ${esc(m.unit)}</td>
            <td class="num">${m.cost ? money(m.cost) : '—'}</td><td>${esc(m.note || '')}<span class="sub">${esc(m.createdBy || '')}</span></td></tr>`).join('')}</tbody></table></div>`
          : '<div class="empty">Nothing in or out in this period.</div>'}
      </section>`;
    const find = (id) => items.find((i) => i.id === Number(id));
    box.onclick = (e) => {
      if (e.target.closest('[data-item-add]')) { itemDialog(null); return; }
      const ed = e.target.closest('[data-item-edit]');
      if (ed) { itemDialog(find(ed.dataset.itemEdit)); return; }
      const mi = e.target.closest('[data-move-in]');
      if (mi) { moveDialog(find(mi.dataset.moveIn), 'in'); return; }
      const mo = e.target.closest('[data-move-out]');
      if (mo) moveDialog(find(mo.dataset.moveOut), 'out');
    };
  }

  // ================= COMMISSIONS =================
  async function viewCommissions(box) {
    const { commissions: c } = await api(`/finance/commissions?${q()}`);
    box.innerHTML = `
      <div class="note note--info fin-explain">An agent's commission is set on the booking (Edit booking → Agent commission) — the guest still pays the full price.
        The agent is settled either by <b>keeping it</b> from a payment they pass on (noted on that payment as “commission kept”), or by <b>you paying them</b> here.</div>
      <div class="fin-kpis">
        ${kpi('Earned by agents', money(c.earned), 'on stays arriving in this period')}
        ${kpi('Kept from payments', money(c.kept), 'agents took it before passing money on')}
        ${kpi('Paid by you', money(c.paid), 'this period')}
        ${kpi('Still to pay', money(c.toPay), 'all bookings so far', c.toPay > 0 ? 'is-bad' : '')}
      </div>
      <section class="card">
        <div class="card__head"><h2>Agents and websites</h2></div>
        ${c.agents.length ? `<div class="table-wrap"><table class="tbl fin-tbl"><thead><tr><th>Agent</th><th class="num">Bookings</th><th class="num">Earned</th><th class="num">Kept</th><th class="num">Paid by you</th><th class="num">Still to pay</th><th></th></tr></thead>
          <tbody>${c.agents.map((a) => `<tr><td><b>${esc(a.to)}</b></td><td class="num">${a.bookings}</td><td class="num">${money(a.earned)}</td><td class="num">${money(a.kept)}</td><td class="num">${money(a.paid)}</td>
            <td class="num">${a.toPay > 0 ? `<b>${money(a.toPay)}</b>` : a.toPay < 0 ? `<span class="src">overpaid ${money(-a.toPay)}</span>` : '<span class="st st--confirmed">Settled</span>'}</td>
            <td class="num">${a.toPay > 0 ? `<button type="button" class="abtn abtn--sm abtn--primary" data-com-pay="${esc(a.to)}" data-amount="${a.toPay}">Pay</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`
          : '<div class="empty">No commission on stays in this period.</div>'}
      </section>
      ${c.bookings.length ? `<section class="card"><div class="card__head"><h2>Bookings with a commission</h2><span class="src">Arriving in this period</span></div>
        <div class="table-wrap"><table class="tbl fin-tbl"><thead><tr><th>Booking</th><th>Arrives</th><th>Agent</th><th class="num">Booking total</th><th class="num">Commission</th><th class="num">Kept</th><th class="num">Still to pay</th></tr></thead>
        <tbody>${c.bookings.map((b) => `<tr><td><a class="ref" href="#/bookings/${b.id}">${esc(b.ref)}</a><span class="sub">${esc(b.guestName)}</span></td><td>${dmy(b.checkIn)}</td><td>${esc(b.to)}</td>
          <td class="num">${money(b.total)}</td><td class="num">${money(b.amount)}${b.type === 'percent' ? `<span class="sub">${b.value}%</span>` : ''}</td><td class="num">${b.kept ? money(b.kept) : '—'}</td>
          <td class="num">${b.toPay > 0 ? money(b.toPay) : '<span class="st st--confirmed">Kept</span>'}</td></tr>`).join('')}</tbody></table></div></section>` : ''}
      ${c.charges ? `<p class="src">Bank or card charges taken off payments in this period (not commission): ${money(c.charges)}.</p>` : ''}`;
    box.onclick = (e) => {
      const pay = e.target.closest('[data-com-pay]');
      if (!pay) return;
      const to = pay.dataset.comPay;
      formDialog(`Pay ${to}`, 'Saved as an expense in the “Commission” category.', `
        <label class="fld"><span>Amount (${esc(h.currency())})</span><input type="number" name="amount" min="1" step="1" required value="${pay.dataset.amount}"></label>
        <label class="fld"><span>Paid on</span><input type="date" name="spentOn" value="${today()}" required></label>
        <label class="fld span2"><span>Paid from <small>(optional)</small></span><input name="account" list="fin-acct" maxlength="120"></label>
        <label class="fld span2"><span>Note <small>(optional)</small></span><input name="note" maxlength="255"></label>`,
      'Record payment', async (f) => {
        await api('/finance/expenses', { method: 'POST', body: { spentOn: f.spentOn.value, amount: num(f.amount.value), category: 'Commission', payee: to === 'Not named' ? '' : to, description: `Commission to ${to}`, account: f.account.value, note: f.note.value } });
        toast(`${money(num(f.amount.value))} commission paid to ${to}`);
      });
    };
  }

  // ================= TRANSACTIONS =================
  const TX = { type: '', account: '', q: '' };
  async function viewTransactions(box) {
    const p = new URLSearchParams({ from: P.from, to: P.to, type: TX.type, account: TX.account, q: TX.q });
    const { transactions: t } = await api(`/finance/transactions?${p}`);
    const tt = t.totals;
    const accounts = Array.from(new Set([...META.accounts, ...t.rows.map((r) => r.account).filter(Boolean)]));
    const amt = (n, cls = '') => (n ? `<span class="${cls}">${money(n)}</span>` : '');
    box.innerHTML = `
      <div class="fin-kpis">
        ${kpi('Money in', money(tt.netIn), tt.kept ? `guests paid ${money(tt.in)} · ${money(tt.kept)} kept by agents or banks` : 'payments from guests')}
        ${kpi('Money out', money(tt.out), 'expenses and staff pay')}
        ${kpi('Net', money(tt.net), 'in, less out', tt.net < 0 ? 'is-bad' : 'is-good')}
        ${kpi('Moved into the bank', money(tt.moved), 'deposits and transfers')}
      </div>
      <section class="card">
        <div class="filters">
          <label class="search">${icon('a-search')}<input class="inp" type="search" placeholder="Find a guest, booking, payee…" value="${esc(TX.q)}" data-tx="q" aria-label="Find"></label>
          <select class="inp" data-tx="type" aria-label="Type">${[['', 'Everything'], ['in', 'Money in'], ['out', 'Money out'], ['move', 'Into the bank']].map(([k, l]) => `<option value="${k}" ${TX.type === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
          <select class="inp" data-tx="account" aria-label="Account"><option value="">Every account</option>${accounts.map((a) => `<option ${TX.account === a ? 'selected' : ''}>${esc(a)}</option>`).join('')}<option value="—" ${TX.account === '—' ? 'selected' : ''}>Not noted</option></select>
          <span class="filters__gap"></span>
          <a class="abtn abtn--sm" href="/api/admin/finance/transactions.csv?${p}" download>${icon('a-dl', 15)}Download CSV</a>
        </div>
        ${t.rows.length ? `<div class="table-wrap"><table class="tbl fin-tbl tx-tbl"><thead><tr><th>Date</th><th>What</th><th>Account</th><th class="num">In</th><th class="num">Kept</th><th class="num">Out</th><th class="num">Into bank</th></tr></thead>
          <tbody>${t.rows.map((r) => `<tr class="tx--${r.type}"><td>${dmy(r.day)}${r.time ? `<span class="sub">${esc(r.time)}</span>` : ''}</td>
            <td><span class="tx-kind">${esc(r.kind)}</span> <b>${esc(r.what)}</b>${r.detail ? `<span class="sub">${esc(r.detail)}</span>` : ''}${r.keptBy ? `<span class="sub">${esc(r.keptBy)}</span>` : ''}</td>
            <td>${esc(r.account || '—')}</td><td class="num">${amt(r.in, 'tx-in')}</td><td class="num">${r.kept ? `− ${money(r.kept)}` : ''}</td><td class="num">${amt(r.out, 'tx-out')}</td><td class="num">${amt(r.moved)}</td></tr>`).join('')}
          <tr class="is-sum"><td colspan="3">${plural(t.rows.length, 'transaction')}</td><td class="num">${money(tt.in)}</td><td class="num">${tt.kept ? `− ${money(tt.kept)}` : ''}</td><td class="num">${money(tt.out)}</td><td class="num">${money(tt.moved)}</td></tr></tbody></table></div>`
          : '<div class="empty">No transactions match.</div>'}
      </section>`;
    let timer;
    box.oninput = (e) => { if (e.target.dataset.tx === 'q') { TX.q = e.target.value.trim(); clearTimeout(timer); timer = setTimeout(() => render().then(() => { const i = $('[data-tx="q"]', view); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }), 300); } };
    box.onchange = (e) => { const k = e.target.dataset.tx; if (k && k !== 'q') { TX[k] = e.target.value; render(); } };
  }

  // ================= ROOMS & FOOD =================
  async function viewAnalytics(box) {
    const { analytics: a } = await api(`/finance/analytics?${q()}`);
    const maxRoom = Math.max(1, ...a.rooms.map((r) => r.revenue));
    const foodMax = Math.max(1, ...a.food.categories.map((c) => c.amount));
    box.innerHTML = `
      <div class="fin-kpis">
        ${kpi('Occupancy', `${a.occupancy}%`, `${a.roomNights} of ${a.available} room nights`)}
        ${kpi('Room revenue', money(a.roomRevenue), `average ${money(a.adr)} a night`)}
        ${kpi('Revenue per available room', money(a.revpar), 'a night')}
        ${kpi('Guest nights', String(a.guestNights), a.dayVisits ? `${plural(a.dayVisits, 'day visit')} · ${money(a.dayRevenue)}` : 'no day visits')}
        ${kpi('Food and drinks', money(a.food.total), `${a.food.qty} sold`)}
      </div>
      <div class="fin-cols fin-cols--2">
        <section class="card"><div class="card__head"><h2>Rooms</h2><span class="src">Stays confirmed, in or out — their price shared over the nights</span></div>
          <div class="table-wrap"><table class="tbl fin-tbl"><thead><tr><th>Room</th><th class="num">Nights</th><th>Occupancy</th><th class="num">Revenue</th></tr></thead>
          <tbody>${a.rooms.map((r) => `<tr><td>${esc(r.name)}</td><td class="num">${r.nights}</td><td>${bar(r.occupancy)} <small>${r.occupancy}%</small></td><td class="num">${money(r.revenue)}${bar((r.revenue * 100) / maxRoom)}</td></tr>`).join('')}</tbody></table></div></section>
        <section class="card"><div class="card__head"><h2>Food and drinks — by group</h2></div>
          ${a.food.categories.length ? `<div class="table-wrap"><table class="tbl fin-tbl"><thead><tr><th>Group</th><th class="num">Sold</th><th class="num">Amount</th></tr></thead>
          <tbody>${a.food.categories.map((c) => `<tr><td>${esc(c.category)}</td><td class="num">${c.qty}</td><td class="num">${money(c.amount)}${bar((c.amount * 100) / foodMax)}</td></tr>`).join('')}</tbody></table></div>` : '<div class="empty">Nothing sold in this period.</div>'}
        </section>
        <section class="card span-all"><div class="card__head"><h2>Best sellers</h2></div>
          ${a.food.items.length ? `<div class="table-wrap"><table class="tbl fin-tbl"><thead><tr><th>Item</th><th>Group</th><th class="num">Sold</th><th class="num">Amount</th></tr></thead>
          <tbody>${a.food.items.map((i) => `<tr><td>${esc(i.name)}</td><td>${esc(i.category)}</td><td class="num">${i.qty}</td><td class="num">${money(i.amount)}</td></tr>`).join('')}</tbody></table></div>` : '<div class="empty">Nothing sold in this period.</div>'}
        </section>
      </div>`;
  }

  const VIEWS = { summary: viewSummary, transactions: viewTransactions, expenses: viewExpenses, staff: viewStaff, service: viewService, bank: viewBank, stock: viewStock, commissions: viewCommissions, analytics: viewAnalytics };
  return { show };
};
