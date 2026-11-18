/* Renders the data-driven parts of the page from window.TMR_CONTENT. */
(() => {
  const C = window.TMR_CONTENT;
  const { picture, esc, hydrate } = window.TMR;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const pad = n => String(n).padStart(2, '0');
  const into = (name, html) => $$(`[data-render="${name}"]`).forEach(el => { el.innerHTML = html; });

  const arrow = '<svg class="i-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h15m-5.5-6 6 6-6 6"/></svg>';
  const ext = '<svg class="i-ext" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 16 16.5 7.5M9 7h8v8"/></svg>';
  window.TMR.icons = { arrow, ext };

  const ICONS = {
    wifi: '<path d="M2.5 9.2a14 14 0 0 1 19 0M5.6 12.6a9.4 9.4 0 0 1 12.8 0M8.8 16a4.7 4.7 0 0 1 6.4 0"/><circle cx="12" cy="19.2" r=".9"/>',
    shower: '<path d="M6 21V9.5a5 5 0 0 1 10 0"/><path d="M12.5 10h7"/><path d="M14 14v.4M17 14v.4M20 14v.4M15.5 17.2v.4M18.5 17.2v.4"/>',
    bath: '<path d="M3 12h18v2.5a5.5 5.5 0 0 1-5.5 5.5h-7A5.5 5.5 0 0 1 3 14.5V12Z"/><path d="M6 12V5.6a2.1 2.1 0 0 1 4-.9"/><path d="m7 20-1 1.8M17 20l1 1.8"/>',
    kettle: '<path d="M6.2 9.5h10.6L15.7 20H7.3L6.2 9.5Z"/><path d="M9 9.5V7.8a2.5 2.5 0 0 1 5 0v1.7"/><path d="M16.7 11.5h1.6a2 2 0 0 1 0 4h-2.1"/>',
    tv: '<rect x="3" y="5.5" width="18" height="11.5" rx="1"/><path d="M8.5 20.5h7M12 17v3.5"/>',
    parking: '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M10 16.5v-9h3.2a2.6 2.6 0 0 1 0 5.2H10"/>'
  };

  /* ---------- contact ---------- */
  const ct = C.contact;
  const primary = ct.phones.find(p => p.primary) || ct.phones[0];
  $$('[data-contact="tel"]').forEach(a => { a.href = primary.href; if (!a.children.length && !a.textContent.trim()) a.textContent = primary.label; });
  $$('[data-contact="tel-label"]').forEach(el => { el.textContent = primary.label; });
  $$('[data-contact="phones"]').forEach(el => {
    el.innerHTML = ct.phones.map(p => `<a href="${p.href}">${esc(p.label)}</a>`).join('');
  });
  $$('[data-contact="address"]').forEach(el => { el.innerHTML = ct.address.map(esc).join('<br>'); });
  $$('[data-contact="maps"]').forEach(a => { a.href = ct.mapsUrl; });
  $$('[data-contact="whatsapp"]').forEach(a => {
    if (!ct.whatsapp) { a.remove(); return; }
    a.href = `https://wa.me/${ct.whatsapp}`;
  });
  $$('[data-contact="socials"]').forEach(el => {
    if (!ct.socials || !ct.socials.length) { (el.closest('[data-optional]') || el).remove(); return; }
    el.innerHTML = ct.socials.map(s => `<a href="${esc(s.href)}" target="_blank" rel="noopener">${esc(s.label)} ${ext}</a>`).join('');
  });

  /* ---------- stays ---------- */
  // rates at a glance
  into('rates', C.stays.map(s => `
    <li><a href="#stay-${s.id}"><span class="rates__name">${esc(s.name)}</span><i aria-hidden="true"></i><b>${esc(s.from)}</b></a></li>`).join(''));
  $$('[data-render="rates-note"]').forEach(el => { el.textContent = C.ratesNote || ''; });

  // floating stays: two staggered columns on desktop (each stay spans two rows,
  // starting one row after the previous one), a single column on phones
  into('stays', C.stays.map((s, i) => {
    const side = i % 2 ? 'right' : 'left';
    return `
    <li class="room room--${side}" id="stay-${s.id}" style="--r:${i + 1}" aria-labelledby="stay-${s.id}-t">
      <div class="room__visual" data-float-in>
        <figure class="room__photo" data-drift="${side === 'left' ? 26 : 40}">
          <div class="room__frame">${picture(s.photo, { sizes: `(min-width: 900px) ${side === 'left' ? 46 : 38}vw, 92vw` })}</div>
          <p class="room__price"><span>From</span>${esc(s.from)}</p>
        </figure>
        ${s.detail ? `<figure class="room__detail" data-drift="${side === 'left' ? 70 : 84}">${picture(s.detail, { sizes: '(min-width: 900px) 16vw, 40vw' })}</figure>` : ''}
      </div>
      <div class="room__body">
        <p class="room__kind"><span>${pad(i + 1)}</span>${esc(s.kind)}</p>
        <h3 class="room__name" id="stay-${s.id}-t">${esc(s.name)}</h3>
        <p class="room__tag">${esc(s.tagline)}</p>
        ${s.desc ? `<p class="room__desc">${esc(s.desc)}</p>` : ''}
        <ul class="room__specs">${[s.size, s.guests, s.view].filter(Boolean).map(x => `<li>${esc(x)}</li>`).join('')}</ul>
        <a class="link-arrow" href="#book" data-stay="${esc(s.name)}">Enquire about this stay ${arrow}</a>
      </div>
    </li>`;
  }).join(''));

  into('amenities', C.amenities.map(a => `
    <li><svg viewBox="0 0 24 24" aria-hidden="true">${ICONS[a.icon] || ''}</svg>${esc(a.label)}</li>`).join(''));

  // booking select
  $$('[data-render="stay-options"]').forEach(sel => {
    const names = C.stays.map(s => s.name);
    sel.insertAdjacentHTML('beforeend', names.map(n => `<option value="${esc(n)}">${esc(n)}</option>`).join(''));
  });

  /* ---------- experiences ---------- */
  into('experiences', C.experiences.map((e, i) => `
    <li class="exp-item${i === 0 ? ' is-active' : ''}" data-i="${i}">
      <figure class="exp-item__img">${picture(e.image, { sizes: '100vw' })}</figure>
      <div class="exp-item__row">
        <span class="exp-item__num">${pad(i + 1)}</span>
        <div>
          <h3 class="exp-item__name">${esc(e.name)}</h3>
          <p class="exp-item__text">${esc(e.text)}</p>
        </div>
        <span class="exp-item__when">${esc(e.when)}</span>
      </div>
    </li>`).join(''));
  into('exp-stage', C.experiences.map((e, i) => `
    <figure class="exp-stage__fig${i === 0 ? ' is-active' : ''}" data-i="${i}">${picture(e.image, { sizes: '(min-width: 900px) 42vw, 100vw', alt: '' })}</figure>`).join(''));

  /* ---------- craft ---------- */
  into('craft', C.craft.map((c, i) => `
    <article class="craft-item craft-item--${i + 1}">
      <figure class="craft-item__fig" data-reveal="clip"><div class="parallax" data-parallax="0.08">${picture(c.image, { sizes: i === 0 ? '(min-width: 900px) 55vw, 100vw' : '(min-width: 900px) 40vw, 100vw' })}</div></figure>
      <div class="craft-item__text" data-fade>
        <span class="craft-item__num">${pad(i + 1)}</span>
        <h3 class="craft-item__title">${esc(c.title)}</h3>
        <p>${esc(c.text)}</p>
      </div>
    </article>`).join(''));

  into('dining', C.dining.map((d, i) => `<li data-fade><span>${pad(i + 1)}</span>${esc(d)}</li>`).join(''));

  /* ---------- gallery ---------- */
  const galleryItems = C.gallery.map(g => {
    const m = window.TMR.image(g.image) || {};
    return { ...g, alt: g.caption || m.alt || '' };
  });
  window.TMR.galleryItems = galleryItems;
  into('gallery', galleryItems.map((g, i) => `
    <li class="g-item" style="--c:${g.c};--r:${g.r};--mc:${g.mc};--mr:${g.mr}" data-reveal="clip">
      <button type="button" class="g-btn" data-gallery="${i}" aria-label="${g.video ? 'Play video' : 'Open photo'}: ${esc(g.alt)}">
        ${picture(g.image, { sizes: `(min-width: 900px) ${Math.round(g.c / 12 * 100)}vw, ${g.mc === 2 ? 100 : 50}vw`, alt: '' })}
        ${g.video ? '<span class="g-play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5-11-6.5Z"/></svg></span>' : ''}
      </button>
    </li>`).join(''));

  /* ---------- location & nearby ---------- */
  into('location', C.location.map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`).join(''));

  const near = [...C.nearby].sort((a, b) => a.km - b.km);
  window.TMR.nearbyItems = near;
  const kms = near.map(n => n.km);
  $$('[data-count="nearby"]').forEach(el => { el.textContent = near.length; });
  $$('[data-km="min"]').forEach(el => { el.textContent = Math.min(...kms); });
  $$('[data-km="max"]').forEach(el => { el.textContent = Math.max(...kms); });
  const mapsLink = q => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(q)}`;
  into('nearby', near.map((n, i) => `
    <li class="place" data-km="${n.km}">
      <button type="button" class="place__media" data-place="${i}" aria-label="View photo: ${esc(n.name)}">
        ${picture(n.image, { sizes: '(min-width: 900px) 26vw, 72vw', alt: '' })}
        <span class="place__km">${n.km} km</span>
      </button>
      <div class="place__body">
        <p class="place__type">${esc(n.type)}</p>
        <h3 class="place__name">${esc(n.name)}</h3>
        <p class="place__desc">${esc(n.desc)}</p>
        <a class="link-arrow" href="${mapsLink(n.map)}" target="_blank" rel="noopener">Directions<span class="sr-only"> to ${esc(n.name)} (opens Google Maps)</span> ${ext}</a>
      </div>
    </li>`).join(''));
  into('also', C.alsoNearby.map(([name, km]) => `<li><span>${esc(name)}</span><i>${km} km</i></li>`).join(''));

  /* ---------- reviews (verified only; hidden when empty) ---------- */
  $$('[data-optional="reviews"]').forEach(section => {
    if (!C.reviews || !C.reviews.length) {
      section.remove();
      $$('a[href="#reviews"]').forEach(a => (a.closest('li') || a).remove());
      return;
    }
    $('[data-render="reviews"]', section).innerHTML = C.reviews.map((r, i) => `
      <figure class="review${i === 0 ? ' is-active' : ''}" aria-hidden="${i === 0 ? 'false' : 'true'}">
        <blockquote><p>“${esc(r.quote)}”</p></blockquote>
        <figcaption><b>${esc(r.guest)}</b><span>${[r.stay, r.source, r.date, r.rating].filter(Boolean).map(esc).join(' · ')}</span></figcaption>
      </figure>`).join('');
    const link = $('[data-render="reviews-link"]', section);
    if (link) {
      if (C.reviewsLink) { link.href = C.reviewsLink.href; link.firstChild.textContent = C.reviewsLink.label + ' '; }
      else link.remove();
    }
    if (C.reviews.length < 2) $$('.reviews__nav', section).forEach(n => n.remove());
  });

  /* ---------- year ---------- */
  $$('[data-year]').forEach(el => { el.textContent = new Date().getFullYear(); });

  hydrate();
})();
