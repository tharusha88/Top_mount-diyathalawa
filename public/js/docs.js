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
  if (/[?&]print=1\b/.test(location.search)) {
    window.addEventListener('load', () => {
      const go = () => setTimeout(() => { marks(); window.print(); }, 150);
      if (document.fonts && document.fonts.ready) document.fonts.ready.then(go); else go();
    });
  }
})();
