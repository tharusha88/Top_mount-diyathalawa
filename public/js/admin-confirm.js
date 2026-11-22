/* Booking confirmations (app/Confirmations.php): staff fill in a booking, then send the guest a
   private link to check it, fill in what is still missing (name, NIC or passport, email, phone)
   and confirm it. In a booking's drawer this shows where that stands (not sent, sent and waiting,
   confirmed by the guest) and opens the window that sends the link: WhatsApp, email or copy.
   admin.js creates this with its helpers. */
window.YDCONFIRM = function (h) {
  'use strict';
  const { $, esc, icon, api, toast, openModal, modalHead, fdt } = h;
  const waDigits = (p) => String(p || '').replace(/\D/g, '').replace(/^0/, '94');
  const canWa = (b) => waDigits(b.guestPhone).length >= 9;
  const list = (a) => (a.length > 1 ? `${a.slice(0, -1).join(', ')} and ${a[a.length - 1]}` : a[0] || '');
  const changesText = (changes) => {
    const added = changes.filter((c) => !c.from).map((c) => c.label);
    const changed = changes.filter((c) => c.from).map((c) => c.label);
    return [added.length ? `added ${list(added)}` : '', changed.length ? `changed ${list(changed)}` : ''].filter(Boolean).join(' · ');
  };

  /** The line in a booking's drawer: where the confirmation stands, and the button that sends it. */
  function slotHtml(b, L) {
    const r = L.record;
    if (L.state === 'confirmed') {
      const did = changesText(r.changes || []);
      return `<div class="note note--ok gc-note"><b>${icon('a-check', 15)}Guest confirmed</b> ${fdt(r.confirmedAt)}${did ? ` — ${esc(did)}` : ' — no details changed'}.
        <button type="button" class="linkbtn" data-gc-open>See the link</button></div>`;
    }
    if (L.state !== 'open') return '';
    const need = L.missingLabels.length ? `The guest will be asked for: <b>${esc(list(L.missingLabels))}</b>.` : 'Everything is filled in: the guest only checks the booking and confirms.';
    if (r) {
      return `<div class="note note--info gc-note"><b>Confirmation link sent</b> ${fdt(r.sentAt)} · ${esc(r.sentViaLabel)}${r.issuedBy ? ` · by ${esc(r.issuedBy)}` : ''} — waiting for the guest to confirm. ${need}
        <button type="button" class="linkbtn" data-gc-open>Send again</button></div>`;
    }
    return `<div class="dr-actions gc-actions">
      <button type="button" class="abtn${b.status === 'pending' ? '' : ' abtn--amber'}" data-gc-open>${icon('a-check', 16)}Send booking confirmation</button>
      <span class="src">${need}</span></div>`;
  }

  /**
   * Fill a booking drawer's slot (slot: the element; b: the booking). onChange runs after the
   * link was sent, so the drawer can show it.
   */
  async function mount(slot, b, onChange) {
    if (!slot) return;
    let L;
    try { L = (await api(`/bookings/${b.id}/confirm-link`)).link; } catch { slot.innerHTML = ''; return; }
    if (!slot.isConnected) return;
    slot.innerHTML = slotHtml(b, L);
    slot.onclick = (e) => { if (e.target.closest('[data-gc-open]')) dialog(b, onChange, L); };
  }

  /** The window that sends the link: what the guest will be asked for, the link, WhatsApp, email and copy. */
  async function dialog(b, onChange, L = null) {
    if (!L) {
      try { L = (await api(`/bookings/${b.id}/confirm-link`)).link; } catch (ex) { toast(ex.message, true); return; }
    }
    const r = L.record;
    const done = L.state === 'confirmed';
    const notes = [];
    if (done) notes.push(`<div class="note note--ok"><b>The guest confirmed this booking</b> ${fdt(r.confirmedAt)}${changesText(r.changes || []) ? ` and ${esc(changesText(r.changes))}` : ''}. The link now shows them their booking and how to pay.</div>`);
    else if (L.state !== 'open') notes.push('<div class="note note--warn">This booking is no longer pending or confirmed, so the guest can\'t confirm it. The link shows them that.</div>');
    else {
      notes.push(L.missingLabels.length
        ? `<div class="note note--warn">The guest will be asked for <b>${esc(list(L.missingLabels))}</b> — everything else you entered is filled in for them to check.</div>`
        : '<div class="note note--info">Every detail is filled in: the guest checks the booking and confirms (they can correct their own details).</div>');
      if (b.status === 'pending') notes.push(`<div class="note note--info">After confirming, the guest sees ${b.advance && !b.advance.required ? 'that nothing is paid before they arrive' : 'the advance to pay and your bank details'}, and gets the invoice by email. You still confirm the booking yourself, as now.</div>`);
    }
    if (r && !done) notes.push(`<p class="src">Sent ${fdt(r.sentAt)} · ${esc(r.sentViaLabel)}${r.issuedBy ? ` · first by ${esc(r.issuedBy)} ${fdt(r.issuedAt)}` : ''}</p>`);
    openModal(`${modalHead('Booking confirmation', `${b.guestName} · ${b.ref}`)}
      <div class="dr-body gc-dlg">
        ${notes.join('')}
        <label class="fld"><span>Link for the guest</span>
          <div class="gc-link"><input class="inp" value="${esc(L.url)}" readonly data-gc-url aria-label="Confirmation link">
          <a class="abtn abtn--ghost" href="${esc(L.url)}" target="_blank" rel="noopener" title="See the page the guest sees">${icon('a-ext', 16)}Open</a></div>
        </label>
        <div class="dlg-foot">
          ${canWa(b) ? `<button type="button" class="abtn abtn--primary" data-gc-via="whatsapp">${icon('a-wa', 16)}Send on WhatsApp</button>` : ''}
          ${L.canEmail ? `<button type="button" class="abtn" data-gc-via="email">${icon('a-mail', 16)}Email to ${esc(b.guestEmail)}</button>` : ''}
          <button type="button" class="abtn${canWa(b) || L.canEmail ? '' : ' abtn--primary'}" data-gc-via="link">${icon('a-check', 16)}Copy link</button>
        </div>
        ${!canWa(b) && !L.canEmail ? '<p class="src">Add the guest\'s phone or email to the booking to send it from here, or copy the link and send it yourself.</p>' : ''}
      </div>`);
    const panel = document.querySelector('[data-modal-panel]');
    const input = $('[data-gc-url]', panel);
    input.onfocus = () => input.select();
    const note = async (via, btn) => {
      if (btn) btn.disabled = true;
      try {
        const res = await api(`/bookings/${b.id}/confirm-link`, { method: 'POST', body: { via } });
        if (via === 'email') toast(res.outbox ? `Email isn't set up yet — saved to data/outbox for ${res.to}` : `Emailed to ${res.to}`);
        if (onChange) onChange(res.link);
      } catch (ex) { toast(ex.message, true); }
      if (btn) btn.disabled = false;
    };
    panel.onclick = async (e) => {
      const btn = e.target.closest('[data-gc-via]');
      if (!btn) return;
      const via = btn.dataset.gcVia;
      if (via === 'whatsapp') {
        // open the chat straight away (a browser only allows it right after the click), then note it
        window.open(`https://wa.me/${waDigits(b.guestPhone)}?text=${encodeURIComponent(`${L.waText} ${L.url}`)}`, '_blank', 'noopener');
        note('whatsapp', btn);
        return;
      }
      if (via === 'link') {
        try { await navigator.clipboard.writeText(L.url); } catch { input.select(); document.execCommand('copy'); }
        toast('Link copied — paste it in WhatsApp, SMS or email');
        note('link', btn);
        return;
      }
      note('email', btn);
    };
  }

  /** A small mark for booking lists: sent and waiting, or confirmed by the guest. */
  function chip(b) {
    if (b.guestConfirm === 'confirmed') return `<span class="gc-chip gc-chip--ok" title="The guest checked and confirmed this booking">${icon('a-check', 12)}Guest confirmed</span>`;
    if (b.guestConfirm === 'sent' && ['pending', 'confirmed'].includes(b.status)) return '<span class="gc-chip" title="Confirmation link sent — the guest hasn\'t confirmed yet">Awaiting guest</span>';
    return '';
  }

  return { mount, dialog, chip };
};
