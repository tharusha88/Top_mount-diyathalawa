/* PDFs of invoices, receipts, bills and the finance summary, made in the browser with no add-ons:
   the document page (Docs.php) is drawn onto a canvas as a picture of its HTML, cut into A5 (or A4)
   pages at row boundaries, with the date and time and who issued it in the top margin as when
   printed, and each page is saved as a JPEG inside a small PDF file.
   YDPdf.fromUrl(url) → Promise<Blob>  (url: an /invoice, /receipt, /bill or /finance/summary page) */
window.YDPdf = (function () {
  'use strict';
  const MM = 96 / 25.4;                                   // CSS px in a millimetre
  const PAPER = { A5: [148, 210, 9], A4: [210, 297, 14] };  // width, height, margin (mm) — as Docs::pageCss
  const SCALE = 2.6;                                      // picture pixels per CSS px (about 250 dpi)

  const dataUrl = (blob) => new Promise((ok, no) => { const r = new FileReader(); r.onload = () => ok(r.result); r.onerror = no; r.readAsDataURL(blob); });
  // the next frame — or a moment, in a tab that isn't showing (it gets no frames)
  const nextFrame = () => new Promise((r) => {
    let done = false;
    const go = () => { if (!done) { done = true; r(); } };
    requestAnimationFrame(go);
    setTimeout(go, 100);
  });

  /** The document page: its article, the styles (with the print rules on) and its paper. */
  async function load(url) {
    const page = new URL(url, location.href);
    page.searchParams.set('embed', '1');
    const res = await fetch(page, { credentials: 'same-origin' });
    if (!res.ok) throw new Error('The document could not be loaded.');
    const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
    const art = doc.querySelector('article.doc');
    if (!art) throw new Error('The document could not be loaded.');
    const link = doc.querySelector('link[rel="stylesheet"][href*="docs.css"]');
    const css = link ? (await (await fetch(new URL(link.getAttribute('href'), page))).text()).replace(/@media\s+print/g, '@media all') : '';
    for (const img of art.querySelectorAll('img')) { // pictures go inside the file
      const src = img.getAttribute('src');
      if (!src || src.startsWith('data:')) continue;
      try { img.setAttribute('src', await dataUrl(await (await fetch(new URL(src, page))).blob())); } catch { img.remove(); }
    }
    const b = doc.body.dataset;
    return { art, css, paper: b.paper === 'A4' ? 'A4' : 'A5', issuedBy: b.issuedBy || '', tz: b.tz || '' };
  }

  /** Lay the document out at the paper's width and draw it as one tall picture. */
  async function render(d) {
    const [pw, , pm] = PAPER[d.paper];
    const W = Math.round((pw - 2 * pm) * MM);
    const frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = `position:fixed;left:-10000px;top:0;width:${W}px;height:1600px;border:0;opacity:0;pointer-events:none`;
    document.body.appendChild(frame);
    try {
      const fd = frame.contentDocument;
      fd.open();
      fd.write(`<!doctype html><html><head><meta charset="utf-8"><style>${d.css}\nhtml,body{margin:0;background:#fff}body{width:${W}px}.doc{box-shadow:none}</style></head>`
        + `<body class="doc-body is-embed paper-${d.paper.toLowerCase()}"></body></html>`);
      fd.close();
      fd.body.appendChild(fd.importNode(d.art, true));
      await Promise.all(Array.from(fd.images).map((i) => (i.complete ? 0 : new Promise((r) => { i.onload = i.onerror = r; }))));
      if (fd.fonts && fd.fonts.ready) await fd.fonts.ready;
      await nextFrame();
      const art = fd.querySelector('article');
      const top = art.getBoundingClientRect().top;
      const H = Math.ceil(art.getBoundingClientRect().bottom - top);
      // where a page may end: below a table row or a block — never through a line of text
      const breaks = Array.from(art.querySelectorAll('tr, p, h3, dl, section, .doc__head, .doc__to, .doc__totals > div, .doc__amount, .doc__sign, .doc__foot, .sum-kpis'))
        .map((el) => Math.round(el.getBoundingClientRect().bottom - top)).filter((y) => y > 0 && y < H).sort((a, b) => a - b);
      const xhtml = new XMLSerializer().serializeToString(fd.documentElement);
      const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${Math.round(W * SCALE)}" height="${Math.round(H * SCALE)}" viewBox="0 0 ${W} ${H}">`
        + `<foreignObject x="0" y="0" width="${W}" height="${H}">${xhtml}</foreignObject></svg>`;
      const img = new Image();
      img.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
      await img.decode();
      return { img, W, H, breaks };
    } finally {
      frame.remove();
    }
  }

  /** "17 Nov 2026, 2:43 pm", as printed (docs.js). */
  function printedAt(tz) {
    const o = { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true };
    try { return new Date().toLocaleString('en-GB', { ...o, timeZone: tz || undefined }); } catch { return new Date().toLocaleString('en-GB', o); }
  }

  /** One canvas per page: white paper, the document slice inside the margins, the date and name on top. */
  function pages(r, d) {
    const [pw, ph, pm] = PAPER[d.paper];
    const m = pm * MM;
    const contentH = ph * MM - 2 * m;
    const out = [];
    const when = printedAt(d.tz);
    let y = 0;
    while (y < r.H - 1) {
      let end = Math.min(r.H, y + contentH);
      if (end < r.H) {
        const fit = r.breaks.filter((b) => b > y + 60 && b <= end);
        if (fit.length) end = fit[fit.length - 1];
      }
      const c = document.createElement('canvas');
      c.width = Math.round(pw * MM * SCALE);
      c.height = Math.round(ph * MM * SCALE);
      const ctx = c.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, c.width, c.height);
      ctx.drawImage(r.img, 0, y * SCALE, r.W * SCALE, (end - y) * SCALE, m * SCALE, m * SCALE, r.W * SCALE, (end - y) * SCALE);
      ctx.fillStyle = '#555';
      ctx.font = `${10 * SCALE}px system-ui, "Segoe UI", Roboto, Arial, sans-serif`;
      ctx.textBaseline = 'bottom';
      const base = (m - 3 * MM) * SCALE;
      ctx.fillText(when, m * SCALE, base);
      if (d.issuedBy) { ctx.textAlign = 'right'; ctx.fillText(`Issued by ${d.issuedBy}`, (pw * MM - m) * SCALE, base); }
      out.push(c);
      y = end;
    }
    return out;
  }

  const jpeg = (c) => new Promise((ok, no) => {
    try { c.toBlob((b) => (b ? b.arrayBuffer().then(ok, no) : no(new Error('The PDF could not be made.'))), 'image/jpeg', 0.9); } catch (e) { no(e); }
  });

  /** A PDF with one picture per page. */
  async function pdf(canvases, paper) {
    const [pw, ph] = PAPER[paper];
    const w = (pw * 72 / 25.4).toFixed(2);
    const h = (ph * 72 / 25.4).toFixed(2);
    const images = await Promise.all(canvases.map(jpeg));
    const enc = new TextEncoder();
    const parts = [];
    const offsets = [];
    let len = 0;
    const add = (x) => { const b = typeof x === 'string' ? enc.encode(x) : x; parts.push(b); len += b.length; };
    const obj = (n, write) => { offsets[n] = len; add(`${n} 0 obj\n`); write(); add('\nendobj\n'); };
    add('%PDF-1.4\n%âãÏÓ\n');
    obj(1, () => add('<< /Type /Catalog /Pages 2 0 R >>'));
    obj(2, () => add(`<< /Type /Pages /Count ${canvases.length} /Kids [${canvases.map((_, i) => `${3 + 3 * i} 0 R`).join(' ')}] >>`));
    canvases.forEach((c, i) => {
      const n = 3 + 3 * i;
      const draw = `q ${w} 0 0 ${h} 0 0 cm /Im${i} Do Q`;
      obj(n, () => add(`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${w} ${h}] /Resources << /XObject << /Im${i} ${n + 2} 0 R >> >> /Contents ${n + 1} 0 R >>`));
      obj(n + 1, () => { add(`<< /Length ${draw.length} >>\nstream\n${draw}\nendstream`); });
      obj(n + 2, () => {
        add(`<< /Type /XObject /Subtype /Image /Width ${c.width} /Height ${c.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${images[i].byteLength} >>\nstream\n`);
        add(new Uint8Array(images[i]));
        add('\nendstream');
      });
    });
    const size = 3 + 3 * canvases.length;
    const xref = len;
    let table = `xref\n0 ${size}\n0000000000 65535 f \n`;
    for (let k = 1; k < size; k++) table += `${String(offsets[k]).padStart(10, '0')} 00000 n \n`;
    add(`${table}trailer\n<< /Size ${size} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`);
    return new Blob(parts, { type: 'application/pdf' });
  }

  async function fromUrl(url) {
    const d = await load(url);
    const r = await render(d);
    return pdf(pages(r, d), d.paper);
  }

  /** Save a PDF to the computer. */
  function download(blob, filename) {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 4000);
  }

  return { fromUrl, download };
})();
