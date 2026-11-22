/* Shared by the admin panel and the POS:
   - invoices, receipts and bills: one document page each (Docs.php), shown in the same window and
     printed the same way, so all three come out alike on the paper chosen in Admin → Settings
     (A5 by default) — only the document prints, never the screen around it;
   - the extra payment fields (Admin → Booking setup), and what an agent or bank kept back from a payment;
   - the invoice / receipt dialog: preview, print, copy the link, WhatsApp or email it;
   - the rates agreed with a guest (admin booking forms and POS walk-ins). */
window.YDStaff = (function () {
  'use strict';
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icon = (id, size = 16) => `<svg width="${size}" height="${size}" aria-hidden="true"><use href="#${id}"/></svg>`;

  // ---------- printing ----------
  // In a POS window opened with kiosk printing, print() returns at once; in a normal browser
  // window it waits for the print window. If it waited, remind staff (once a day) how to skip it.
  let tip = null;
  const onPrintTip = (fn) => { tip = fn; };
  function timedPrint(win) {
    const t = Date.now();
    win.print();
    if (Date.now() - t < 2500 || !tip) return;
    const today = new Date().toDateString();
    try { if (localStorage.getItem('ydPrintTip') === today) return; localStorage.setItem('ydPrintTip', today); } catch { /* private mode */ }
    tip('Tip: to print straight to the printer without the print window, open the POS with the “Top Mountain POS” shortcut — Admin → Settings → Printing.');
  }
  /** Print what loads in a hidden frame; the frame goes once printing is done. before(win) runs just before printing. */
  function printFrame(setup, before) {
    const f = document.createElement('iframe');
    f.setAttribute('aria-hidden', 'true');
    f.tabIndex = -1;
    // the size of the preview in the document window, off screen, so the page lays out the same as when printed from there
    f.style.cssText = 'position:fixed;left:-10000px;top:0;width:700px;height:900px;border:0;opacity:0;pointer-events:none';
    let done = false;
    const cleanup = () => { if (!done) { done = true; setTimeout(() => f.remove(), 500); } };
    f.addEventListener('load', () => {
      const w = f.contentWindow;
      if (!w) return;
      w.addEventListener('afterprint', cleanup);
      const go = () => setTimeout(() => {
        try { if (before) before(w); } catch { /* print anyway */ }
        try { w.focus(); timedPrint(w); } finally { setTimeout(cleanup, 60000); }
      }, 120);
      const fonts = w.document && w.document.fonts;
      if (fonts && fonts.ready) fonts.ready.then(go); else go();
    }, { once: true });
    setup(f);
    document.body.appendChild(f);
  }

  // What prints in the page margins (the date and time, and who issued it) is set by the document
  // page itself (Docs::pageCss, docs.js), so the browser adds no title, link or page numbers.

  /** Put the time of printing (and a name, if given) in a document page's margins (docs.js). */
  function markDoc(win, issuedBy) {
    if (win && win.YDDoc) win.YDDoc.marks(issuedBy || '');
  }

  /** Print an invoice, receipt or bill page (it sets its own paper size). issuedBy: replaces the name it shows. */
  function printUrl(url, issuedBy = '') {
    printFrame((f) => { f.src = `${url}${url.includes('?') ? '&' : '?'}embed=1`; }, (w) => markDoc(w, issuedBy));
  }

  /** Print the document showing in a preview frame — how the invoice, receipt and bill windows print. */
  function printDocFrame(fr, url, issuedBy = '') {
    try { markDoc(fr.contentWindow, issuedBy); fr.contentWindow.focus(); timedPrint(fr.contentWindow); } catch { printUrl(url, issuedBy); }
  }

  // ---------- payment fields ----------
  /**
   * What an agent or bank kept back from a payment before passing it on (stored as the payment's
   * "fee"). On a booking with an agent commission it is the commission they kept — it counts
   * towards what they earn on the booking; otherwise it is bank or card charges.
   * c: the booking's commission {to, amount, kept} (or none); money: formats an amount.
   */
  function keptFieldHtml(prefix, c, money) {
    const name = `${prefix}fee`;
    if (c && c.amount > 0 && money) {
      const left = Math.max(0, c.amount - (c.kept || 0));
      const who = c.to || 'the agent';
      return `<label class="fld span2"><span>Commission ${esc(who)} kept from this payment <small>(if they took it before passing the money on)</small></span>
        <span class="pair"><input type="number" name="${name}" min="0" step="1" placeholder="0">${left ? `<button type="button" class="abtn abtn--sm" data-fee-fill="${left}" data-fee-name="${esc(name)}">They kept ${esc(money(left))}</button>` : ''}</span>
        <small>${esc(who)} earns ${esc(money(c.amount))} on this booking${c.kept ? `, ${esc(money(c.kept))} already kept` : ''}${left ? ` — ${esc(money(left))} still to settle` : ' — all settled'}. The account gets the rest; the guest's receipt still shows the full amount.</small></label>`;
    }
    return `<label class="fld"><span>Bank or card charges <small>(optional)</small></span><input type="number" name="${name}" min="0" step="1" placeholder="0"><small>Taken off before the money reached the account. The guest's receipt still shows the full amount.</small></label>`;
  }

  /** The admin's extra fields (e.g. "Received into"), and what an agent or bank kept back, for a payment form. */
  function payFieldsHtml(fields, { fee = true, prefix = '', commission = null, money = null } = {}) {
    const name = (k) => `${prefix}pf_${k}`;
    return (fields || []).map((f) => (f.type === 'choice'
      ? `<label class="fld"><span>${esc(f.label)}${f.required ? '' : ' <small>(optional)</small>'}</span><select name="${name(f.key)}" ${f.required ? 'required' : ''} data-pf="${esc(f.key)}"><option value="">—</option>${f.options.map((o) => `<option>${esc(o)}</option>`).join('')}</select></label>`
      : `<label class="fld"><span>${esc(f.label)}${f.required ? '' : ' <small>(optional)</small>'}</span><input name="${name(f.key)}" maxlength="120" ${f.required ? 'required' : ''} data-pf="${esc(f.key)}"></label>`)).join('')
      + (fee ? keptFieldHtml(prefix, commission, money) : '');
  }

  /** {details: {key: value}, fee} from a form (or any element holding the fields). */
  function readPayFields(root, fields, prefix = '') {
    const details = {};
    (fields || []).forEach((f) => {
      const el = root.querySelector(`[name="${prefix}pf_${f.key}"]`);
      if (el && el.value.trim()) details[f.key] = el.value.trim();
    });
    const feeEl = root.querySelector(`[name="${prefix}fee"]`);
    return { details, fee: feeEl ? Math.max(0, Math.round(Number(feeEl.value)) || 0) : 0 };
  }

  /** Pick the likely "Received into" choice from how it was paid (cash → cash; others → the bank account), until staff choose. */
  function bindPayFields(root, methodEl, prefix = '') {
    if (!methodEl) return;
    const selects = Array.from(root.querySelectorAll(`select[name^="${prefix}pf_"]`));
    const touched = new Set();
    selects.forEach((s) => s.addEventListener('change', () => touched.add(s)));
    const pick = () => selects.forEach((s) => {
      if (touched.has(s)) return;
      const opts = Array.from(s.options).filter((o) => o.value);
      const cash = opts.find((o) => /cash/i.test(o.value));
      const other = opts.find((o) => !/cash/i.test(o.value));
      const want = methodEl.value === 'cash' ? cash : other;
      if (want && (cash || other) && opts.length <= 6) s.value = want.value;
    });
    methodEl.addEventListener('change', pick);
    pick();
    // "They kept LKR …": fills in the commission still to settle
    root.addEventListener('click', (e) => {
      const b = e.target.closest('[data-fee-fill]');
      if (!b) return;
      const input = root.querySelector(`[name="${b.dataset.feeName}"]`);
      if (input) { input.value = b.dataset.feeFill; input.dispatchEvent(new Event('input', { bubbles: true })); }
    });
  }

  // ---------- rates agreed with the guest ----------
  const RATE_NAMES = { room: 'rateRoom', adult: 'rateAdult', child: 'rateChild' };

  /** The rate fields: the room's per night, or per adult / child. Empty = the price list. */
  function rateFieldsHtml(money, rates = {}, list = {}) {
    const v = (x) => (x === null || x === undefined ? '' : x);
    const hint = (k) => (list[k] !== null && list[k] !== undefined ? `list ${money(list[k])}` : '');
    return `<label class="fld" data-rate-f="room"><span>Room rate / night <small data-list="room">${hint('room')}</small></span><input type="number" name="rateRoom" min="0" step="1" value="${v(rates.room)}" placeholder="${v(list.room)}"><small>Empty = the price list. Type the rate you agreed to give a discount.</small></label>
      <label class="fld" data-rate-f="pp"><span>Adult rate <small data-list="adult">${hint('adult')}</small></span><input type="number" name="rateAdult" min="0" step="1" value="${v(rates.adult)}" placeholder="${v(list.adult)}"><small>Per adult per night (per visit for a day out). Empty = the price list.</small></label>
      <label class="fld" data-rate-f="pp"><span>Child rate <small data-list="child">${hint('child')}</small></span><input type="number" name="rateChild" min="0" step="1" value="${v(rates.child)}" placeholder="${v(list.child)}"></label>`;
  }

  /** Show the rate fields for what is booked, and the price-list rates from a /check answer. */
  function syncRates(form, kind, r, money) {
    form.querySelectorAll('[data-rate-f]').forEach((el) => { el.hidden = el.dataset.rateF !== (kind === 'room' ? 'room' : 'pp'); });
    if (r && r.listRates) {
      Object.entries(r.listRates).forEach(([k, val]) => {
        const name = RATE_NAMES[k];
        if (form[name]) form[name].placeholder = val;
        const h = form.querySelector(`[data-list="${k}"]`);
        if (h) h.textContent = `list ${money(val)}`;
      });
    }
  }

  /** The agreed rates to save ('' = back to the price list). */
  const rateBody = (form, kind) => (kind === 'room'
    ? { rateRoom: form.rateRoom.value === '' ? '' : Number(form.rateRoom.value) }
    : { rateAdult: form.rateAdult.value === '' ? '' : Number(form.rateAdult.value), rateChild: form.rateChild.value === '' ? '' : Number(form.rateChild.value) });

  /** Add the agreed rates to a /check query. */
  function rateParams(form, kind, p) {
    const rate = (n) => (form[n] && !form[n].closest('[hidden]') && form[n].value !== '' ? String(Math.max(0, Math.round(Number(form[n].value)) || 0)) : '');
    if (kind === 'room' && rate('rateRoom')) p.set('rateRoom', rate('rateRoom'));
    if (kind !== 'room') { if (rate('rateAdult')) p.set('rateAdult', rate('rateAdult')); if (rate('rateChild')) p.set('rateChild', rate('rateChild')); }
    return p;
  }

  /** "Price: Adult LKR 13,000 (list LKR 15,000) × 2 adults × 1 night = LKR 26,000" from a /check answer. */
  const priceLine = (r, money) => (r.lines && r.lines.length
    ? `Price: ${r.lines.map((l) => `${l.label} ${money(l.unit)}${l.list && l.list !== l.unit ? ` (list ${money(l.list)})` : ''} × ${l.detail}`).join(' + ')}${r.couponDiscount ? ` − coupon ${money(r.couponDiscount)}` : ''} = ${money(r.total)}` : '');

  // ---------- invoice / receipt dialog ----------
  const waNumber = (phone) => String(phone || '').replace(/\D/g, '').replace(/^0/, '94');

  const pdfName = (title) => `${String(title).replace(/[\\/:*?"<>|]+/g, '-').trim()}.pdf`;

  /** Can this browser hand a PDF to another app (phones, Windows)? */
  const canShareFiles = () => {
    try { return !!(navigator.share && navigator.canShare && navigator.canShare({ files: [new File([new Blob(['%PDF'])], 'x.pdf', { type: 'application/pdf' })] })); } catch { return false; }
  };
  const isPhone = () => !!(navigator.userAgentData && navigator.userAgentData.mobile) || /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '');

  /**
   * Send a document's PDF on WhatsApp (blob: the PDF, already made).
   * Phones and Windows: the share window opens with the PDF alone — WhatsApp drops the file when
   * text comes with it — and it must open straight after the tap, so nothing is awaited before it.
   * A browser that can't share files: the PDF is saved; on a computer the guest's chat opens to
   * attach it. (WhatsApp doesn't let a website put a file into a chat by itself.)
   */
  function sendPdf(h, o, blob) {
    const name = pdfName(o.title);
    let file = null;
    try { file = new File([blob], name, { type: 'application/pdf', lastModified: Date.now() }); } catch { /* old browser */ }
    if (file && navigator.canShare && navigator.share && navigator.canShare({ files: [file] })) {
      navigator.share({ files: [file] }).catch((ex) => {
        if (ex && ex.name === 'AbortError') return; // the share window was closed
        window.YDPdf.download(blob, name);
        h.toast(`Sharing didn't open, so ${name} is saved — open WhatsApp and attach it (📎 → Document).`, true);
      });
      return;
    }
    window.YDPdf.download(blob, name);
    if (isPhone()) {
      h.toast(`This browser can't send files to WhatsApp, so ${name} is saved in Downloads — open WhatsApp and attach it (📎 → Document). In Chrome the WhatsApp button sends it directly.`, true);
      return;
    }
    const n = waNumber(o.phone);
    if (n.length >= 9) {
      window.open(`https://wa.me/${n}?text=${encodeURIComponent(o.waText || o.title)}`, '_blank', 'noopener');
      h.toast(`${name} is saved — attach it in the WhatsApp chat that opened (drag it in, or press + then Document)`);
    } else {
      h.toast(`${name} is saved — attach it in WhatsApp`);
    }
  }

  /**
   * Show a document in a dialog: preview, Print, PDF, WhatsApp (sends the PDF), Copy link and Email.
   * h: {openModal, modalHead, toast, api}; o: {title, sub, url, phone, email, emailPath, waText, note, issuedBy, extra (more buttons), noLink}
   */
  function docDialog(h, o) {
    const wa = !!o.phone && waNumber(o.phone).length >= 9;
    // the PDF is made as the window opens: a phone only opens its share window straight after a
    // tap, so the WhatsApp button waits ("Preparing PDF…") until the PDF is ready
    let pdf = null;
    let ready = null; // the finished PDF
    let made = false; // tried making it (done or failed)
    // Where the PDF can be shared (phones, Windows), WhatsApp is two taps: the first opens the
    // guest's chat (a website can't choose the chat for a file), the second shares the PDF — the
    // guest is then at the top of WhatsApp's list. Elsewhere one tap saves it and opens the chat.
    const twoStep = wa && canShareFiles();
    let step = 1;
    const getPdf = () => {
      if (!pdf) {
        pdf = window.YDPdf ? window.YDPdf.fromUrl(o.url) : Promise.reject(new Error('PDF is not available.'));
        pdf.then((b) => { ready = b; made = true; waState(); }, () => { pdf = null; made = true; waState(); });
      }
      return pdf;
    };
    const waState = () => {
      const b = document.querySelector('[data-modal-panel] [data-doc-wa]');
      if (!b) return;
      const second = twoStep && step === 2;
      const waiting = (!twoStep || second) && !made;
      b.disabled = waiting;
      b.classList.toggle('abtn--wa', second);
      b.innerHTML = `${icon('a-wa')}${waiting ? 'Preparing PDF…' : second ? 'Send the PDF' : 'WhatsApp'}`;
      b.title = second ? 'Choose the guest — their chat is at the top of the list' : twoStep ? "Opens the guest's WhatsApp chat" : '';
    };
    h.openModal(`${h.modalHead(o.title, o.sub || '')}
      <div class="dr-body doc-dlg">
        ${o.note ? `<div class="note note--warn doc-dlg__note">${o.note}</div>` : ''}
        <div class="doc-dlg__frame"><iframe src="${esc(o.url)}?embed=1" title="${esc(o.title)}" data-doc-frame></iframe></div>
        <div class="dlg-foot doc-dlg__foot">
          <button type="button" class="abtn abtn--primary" data-doc-print>${icon('a-print')}Print</button>
          <button type="button" class="abtn" data-doc-pdf>${icon('a-doc')}PDF</button>
          ${wa ? `<button type="button" class="abtn" data-doc-wa>${icon('a-wa')}WhatsApp</button>` : ''}
          ${o.noLink ? '' : `<button type="button" class="abtn" data-doc-copy>${icon('a-check')}Copy link</button>`}
          ${o.email && o.emailPath ? `<button type="button" class="abtn" data-doc-email>${icon('a-mail')}Email</button>` : ''}
          <a class="abtn abtn--ghost" href="${esc(o.url)}" target="_blank" rel="noopener">${icon('a-ext')}Open</a>
          ${o.extra || ''}
        </div>
      </div>`, 'modal--doc');
    const panel = document.querySelector('[data-modal-panel]');
    getPdf().catch(() => {});
    const busy = async (btn, work) => {
      btn.disabled = true;
      try { await work(); } catch (ex) { h.toast(ex && ex.message ? `${ex.message} Use Print → Save as PDF instead.` : 'The PDF could not be made. Use Print → Save as PDF instead.', true); }
      btn.disabled = false;
    };
    panel.onclick = async (e) => {
      if (e.target.closest('[data-mclose]')) return; // the dialog's own close button
      if (e.target.closest('[data-doc-print]')) {
        printDocFrame(panel.querySelector('[data-doc-frame]'), o.url, o.issuedBy);
        return;
      }
      const pb = e.target.closest('[data-doc-pdf]');
      if (pb) {
        if (ready) window.YDPdf.download(ready, pdfName(o.title));
        else busy(pb, async () => window.YDPdf.download(await getPdf(), pdfName(o.title)));
        return;
      }
      const wb = e.target.closest('[data-doc-wa]');
      if (wb) {
        if (twoStep && step === 1) {
          // tap 1: the guest's chat, with a short message
          window.open(`https://wa.me/${waNumber(o.phone)}?text=${encodeURIComponent(o.waText || o.title)}`, '_blank', 'noopener');
          step = 2;
          waState();
          h.toast('Send the message in WhatsApp, then come back here and tap “Send the PDF” — the guest will be at the top of the list.');
          return;
        }
        if (ready) { sendPdf(h, o, ready); return; } // at once, while the tap still counts
        busy(wb, async () => { await getPdf(); h.toast('The PDF is ready — tap the button again to send it.'); }); // it failed before: try again
        return;
      }
      if (e.target.closest('[data-doc-copy]')) {
        try { await navigator.clipboard.writeText(o.url); } catch {
          const t = document.createElement('textarea'); t.value = o.url; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove();
        }
        h.toast('Link copied — paste it in WhatsApp, SMS or email');
        return;
      }
      const em = e.target.closest('[data-doc-email]');
      if (em) {
        em.disabled = true;
        try {
          const r = await h.api(o.emailPath, { method: 'POST', body: {} });
          h.toast(r.outbox ? `Email isn't set up yet — saved to data/outbox for ${r.to}` : `Emailed to ${r.to}`);
        } catch (ex) { h.toast(ex.message, true); }
        em.disabled = false;
      }
    };
  }

  /** The receipt dialog for a payment (as the API returns it) and its booking. */
  function receiptDialog(h, p, b, money) {
    docDialog(h, {
      title: `Receipt ${p.receiptNo}`, sub: `${money(p.amount)} · ${p.methodLabel} · ${b.guestName} · ${b.ref}`, url: p.receiptUrl,
      phone: b.guestPhone, email: b.guestEmail, emailPath: `/pos/payments/${p.id}/receipt/email`,
      waText: `Thank you! Here is your receipt ${p.receiptNo} for ${money(p.amount)} (booking ${b.ref}).`,
    });
  }

  /**
   * Who served? Share a bill's service charge equally among the staff who were working.
   * h: {openModal, modalHead, toast, api, closeModal}; done(): runs after saving or "Later".
   */
  async function serviceDialog(h, bill, money, done) {
    let data;
    try { data = await h.api(`/pos/bills/${bill.id}/service`); } catch (ex) { h.toast(ex.message, true); if (done) done(); return; }
    const service = data.service;
    if (!(service > 0)) { if (done) done(); return; }
    const had = new Set((data.shares || []).map((x) => x.employeeId));
    const staff = data.staff.slice();
    (data.shares || []).forEach((x) => { if (!staff.some((s) => s.id === x.employeeId)) staff.push({ id: x.employeeId, name: x.name, position: '' }); });
    h.openModal(`${h.modalHead('Who served?', `${bill.billNo} · service charge ${money(service)}, shared equally`)}<form class="dr-body svc">
      ${staff.length ? `<div class="svc-list">${staff.map((s) => `<label class="svc-item"><input type="checkbox" name="emp" value="${s.id}" ${had.has(s.id) ? 'checked' : ''}>
        <span><b>${esc(s.name)}</b>${s.position ? `<small>${esc(s.position)}</small>` : ''}</span><em data-share="${s.id}"></em></label>`).join('')}</div>`
        : '<div class="note note--info">No staff yet. Add them in Admin → Finance → Staff &amp; salaries, then share this bill from Finance → Service charge.</div>'}
      <p class="src" data-svc-sum></p>
      <div class="dlg-foot"><button type="button" class="abtn" data-svc-later>Later</button>${staff.length ? '<button class="abtn abtn--primary" type="submit">Save</button>' : ''}</div>
    </form>`);
    const panel = document.querySelector('[data-modal-panel]');
    const form = panel.querySelector('form');
    const picked = () => Array.from(form.querySelectorAll('[name="emp"]:checked')).map((x) => Number(x.value));
    const sum = () => {
      const ids = picked();
      const each = ids.length ? Math.floor(service / ids.length) : 0;
      const odd = ids.length ? service - each * ids.length : 0;
      form.querySelectorAll('[data-share]').forEach((el) => {
        const i = ids.indexOf(Number(el.dataset.share));
        el.textContent = i < 0 ? '' : money(each + (i < odd ? 1 : 0));
      });
      const out = form.querySelector('[data-svc-sum]');
      out.textContent = ids.length ? `${money(service)} shared by ${ids.length} — ${money(each)} each.` : 'Tick everyone who worked for this guest.';
    };
    form.addEventListener('change', sum);
    sum();
    panel.onclick = (e) => { if (e.target.closest('[data-svc-later]')) { if (done) done(); else if (h.closeModal) h.closeModal(); } };
    panel.onsubmit = async (e) => {
      e.preventDefault();
      const btn = form.querySelector('[type="submit"]');
      btn.disabled = true;
      try {
        const r = await h.api(`/pos/bills/${bill.id}/service`, { method: 'POST', body: { employeeIds: picked() } });
        h.toast(r.shares.length ? `Service charge shared: ${r.shares.map((x) => `${x.name} ${money(x.amount)}`).join(', ')}` : 'Service charge not shared');
        bill.serviceShares = r.shares;
        if (done) done(); else if (h.closeModal) h.closeModal();
      } catch (ex) { h.toast(ex.message, true); btn.disabled = false; }
    };
  }

  /** The bill window after checkout, or from the bill history (a bill as the API returns it). o: {sub, extra} */
  function billDialog(h, bill, money, o = {}) {
    const g = (bill.snapshot && bill.snapshot.guest) || {};
    docDialog(h, {
      title: `Bill ${bill.billNo}`,
      sub: o.sub || `${bill.guestName} · ${money(bill.total)}${bill.status === 'void' ? ' · VOID' : ''}`,
      url: bill.billUrl, phone: g.phone, email: bill.status === 'issued' ? g.email : '', emailPath: `/pos/bills/${bill.id}/email`,
      waText: `Thank you for staying with us! Here is your bill ${bill.billNo} (${money(bill.total)}).`,
      extra: o.extra || '',
    });
  }

  /** The invoice dialog for a booking (folio.invoiceUrl). h.me(): the signed-in staff member, whose name it prints. */
  function invoiceDialog(h, b, url, money) {
    const me = (h.me && h.me()) || {};
    const missing = [];
    if (b.status === 'pending' && me.bankReady === false) missing.push('your bank details aren\'t set, so it asks the guest to call you to pay');
    if (b.status === 'pending' && me.advancePct === 0) missing.push('no advance % is set, so it asks for the full amount');
    docDialog(h, {
      note: missing.length ? `This invoice is missing details: ${esc(missing.join('; '))}. Add them in <b>Booking setup → Advance payment</b>.` : '',
      issuedBy: me.name || '',
      title: `${b.status === 'pending' ? 'Reservation invoice' : 'Invoice'} ${b.ref}`,
      sub: `${b.guestName} · ${money(b.totalAmount)}${b.holdUntil && b.status === 'pending' ? ` · held until ${new Date(`${b.holdUntil}T00:00:00Z`).toLocaleDateString('en-GB', { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short' })}` : ''}`,
      url, phone: b.guestPhone, email: b.guestEmail, emailPath: `/bookings/${b.id}/invoice/email`,
      waText: `Hello ${String(b.guestName || '').split(' ')[0]}, here is the invoice for your booking ${b.ref} at Top Mountain Resort. It shows the advance and our bank details:`,
    });
  }

  return { esc, printUrl, printDocFrame, onPrintTip, markDoc, billDialog, serviceDialog, sendPdf, pdfName, rateFieldsHtml, syncRates, rateBody, rateParams, priceLine, payFieldsHtml, readPayFields, bindPayFields, docDialog, receiptDialog, invoiceDialog };
})();
