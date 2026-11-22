/* Invoice and receipt pages: the "Print / save as PDF" button, and ?print=1 prints on open.
   What prints in the page margins (Docs::pageCss): the date and time of printing on the left and
   who issued it on the right — no page title, link or page numbers from the browser.
   The admin panel and the POS call YDDoc.marks(name) to put the staff member's name on an invoice. */
(function () {
  'use strict';
  const body = document.body;
  const style = document.getElementById('doc-page');
  const cssString = (s) => `"${String(s).replace(/[\x00-\x1f\x7f]+/g, ' ').replace(/[\\"]/g, '\\$&').replace(/</g, '\\3c ').replace(/>/g, '\\3e ')}"`;
  const now = () => {
    const opts = { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true };
    try { return new Date().toLocaleString('en-GB', { ...opts, timeZone: body.dataset.tz || undefined }); } catch { return new Date().toLocaleString('en-GB', opts); }
  };
  let issuedBy = body.dataset.issuedBy || '';

  function marks(name) {
    if (typeof name === 'string' && name.trim()) issuedBy = name.trim();
    if (!style) return;
    const paper = body.dataset.paper === 'A4' ? 'A4' : 'A5';
    const box = 'font: 7.5pt/1.2 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #555;';
    style.textContent = `@page { size: ${paper}; margin: ${paper === 'A5' ? '9mm' : '14mm'};`
      + ` @top-left { content: ${cssString(now())}; ${box} }`
      + ` @top-right { content: ${cssString(issuedBy ? `Issued by ${issuedBy}` : '')}; ${box} }`
      + ' @bottom-center { content: ""; } }';
  }
  window.YDDoc = { marks };
  window.addEventListener('beforeprint', () => marks());

  document.addEventListener('click', (e) => { if (e.target.closest('[data-print]')) { marks(); window.print(); } });

  // ---------- the guest sends their payment slip (invoice page: Docs::slipBox) ----------
  const slipBox = document.querySelector('[data-slip-form]');
  if (slipBox) {
    const form = slipBox.querySelector('[data-slip-upload]');
    const msg = slipBox.querySelector('[data-slip-msg]');
    const list = slipBox.querySelector('[data-slip-list]');
    const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const when = (iso) => {
      const opts = { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false };
      try { return new Date(iso).toLocaleString('en-GB', { ...opts, timeZone: body.dataset.tz || undefined }); } catch { return new Date(iso).toLocaleString('en-GB', opts); }
    };
    const item = (s) => `<li><b>Sent ${esc(when(s.uploadedAt))}</b>${s.amount ? ` · ${esc(slipBox.dataset.currency)} ${Number(s.amount).toLocaleString('en-US')}` : ''}${s.note ? ` · ${esc(s.note)}` : ''}`
      + ` <span class="${s.recorded ? 'is-ok">Payment recorded ✓' : 'is-wait">We are checking it'}</span></li>`;
    const say = (text, bad = false) => { msg.textContent = text; msg.classList.toggle('is-bad', bad); };
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const file = form.slip.files && form.slip.files[0];
      if (!file) { say('Choose the photo or PDF of your payment slip first.', true); form.slip.focus(); return; }
      if (!TYPES.includes(file.type)) { say('The slip must be a photo (JPG, PNG, WebP) or a PDF.', true); return; }
      if (file.size > 10 * 1024 * 1024) { say('That file is too large (up to 10 MB). Please choose a smaller photo.', true); return; }
      const btn = form.querySelector('[type="submit"]');
      btn.disabled = true;
      say('Sending…');
      try {
        const res = await fetch(slipBox.dataset.url, {
          method: 'POST', credentials: 'same-origin', body: file,
          headers: { 'Content-Type': file.type, 'X-Slip-Amount': form.amount.value.trim(), 'X-Slip-Note': encodeURIComponent(form.note.value.trim()) },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || `Could not send the slip (${res.status}). Please try again.`);
        list.innerHTML = (data.slips || []).map(item).join('');
        form.reset();
        say('Thank you — we have your slip. We will check it and get back to you.');
      } catch (ex) {
        say(ex.message || 'Could not send the slip. Please check your connection and try again.', true);
      } finally {
        btn.disabled = false;
      }
    });
  }
  if (/[?&]print=1\b/.test(location.search)) {
    window.addEventListener('load', () => {
      const go = () => setTimeout(() => { marks(); window.print(); }, 150);
      if (document.fonts && document.fonts.ready) document.fonts.ready.then(go); else go();
    });
  }
})();
