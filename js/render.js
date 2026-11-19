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
    if (!ct.whatsapp) { (a.closest('.foot__actions > li') || a).remove(); return; }
    a.href = `https://wa.me/${ct.whatsapp}`;
  });
  $$('[data-contact="socials"]').forEach(el => {
    if (!ct.socials || !ct.socials.length) { (el.closest('[data-optional]') || el).remove(); return; }
    el.innerHTML = ct.socials.map(s => `<a href="${esc(s.href)}" target="_blank" rel="noopener">${esc(s.label)} ${ext}</a>`).join('');
  });

  /* ---------- highlights: expanding photo panels at the end of the estate intro ---------- */
  const hls = C.highlights || [];
  window.TMR.highlightItems = hls;
  if (!hls.length) $$('[data-hl]').forEach(el => el.remove());
  $$('[data-render="highlights"]').forEach(el => el.style.setProperty('--n', hls.length));
  into('highlights', hls.map((h, i) => `
    <li class="hl-item${i === 0 ? ' is-active' : ''}" data-i="${i}">
      <button type="button" class="hl-item__btn" data-highlight="${i}" aria-label="${esc(h.title)}: open the photo full screen">
        <span class="hl-item__img">${picture(h.image, { sizes: '(min-width: 900px) 54vw, (min-width: 600px) 60vw, 84vw', alt: '' })}</span>
        <span class="hl-item__shade" aria-hidden="true"></span>
        <span class="hl-item__tab" aria-hidden="true"><b>${pad(i + 1)}</b><span>${esc(h.title)}</span></span>
      </button>
      <div class="hl-item__cap">
        <p class="hl-item__num"><span class="hl-reveal"><span>No. ${pad(i + 1)}</span></span></p>
        <h4 class="hl-item__title"><span class="hl-reveal"><span>${esc(h.title)}</span></span></h4>
        <p class="hl-item__text"><span class="hl-reveal"><span>${esc(h.text)}</span></span></p>
      </div>
      <span class="hl-item__bar" aria-hidden="true"><i></i></span>
    </li>`).join(''));

  /* ---------- accommodation: one row of five floating rooms; "Show more" adds five at a time ---------- */
  const rooms = (C.rooms || []).filter(r => r.photos && r.photos.length);
  window.TMR.roomItems = rooms;
  const ROOMS_STEP = 5;
  window.TMR.roomsFirst = ROOMS_STEP;
  const gridIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 4.5h6v6h-6zM13.5 4.5h6v6h-6zM4.5 13.5h6v6h-6zM13.5 13.5h6v6h-6z"/></svg>';
  into('rooms', rooms.map((r, i) => {
    const n = r.photos.length;
    const [kicker, label] = /^\d+$/.test(r.id) ? ['Room', r.id] : [r.kicker || '', r.label || r.name];
    // li: slides in · .rcard__float: bobs gently (--i offsets each card's rhythm) · button: lifts on hover
    return `
    <li class="rcard" style="--i:${i}"${i >= ROOMS_STEP ? ' hidden' : ''}>
      <div class="rcard__float">
        <button type="button" class="rcard__btn" data-room="${i}" aria-label="${esc(r.name)}: view all ${n} photos">
          <span class="rcard__img">${picture(r.photos[0], { sizes: '(min-width: 900px) 20vw, (min-width: 600px) 36vw, 64vw', alt: '' })}</span>
          <span class="rcard__shade" aria-hidden="true"></span>
          <span class="rcard__label" aria-hidden="true"><span class="rcard__kicker">${esc(kicker)}</span><span class="rcard__num${/^\d+$/.test(label) ? '' : ' rcard__num--name'}">${esc(label)}</span></span>
          <span class="rcard__more" aria-hidden="true">${gridIcon}<span class="rcard__count">${n}</span><span class="rcard__cta">View photos</span></span>
        </button>
      </div>
    </li>`;
  }).join(''));
  $$('[data-render="rooms-total"]').forEach(el => { el.textContent = rooms.length; });

  into('foot-stays', C.stays.map(s => `<li><a href="#stay">${esc(s.name)}</a></li>`).join(''));

  // booking select
  $$('[data-render="stay-options"]').forEach(sel => {
    const names = C.stays.map(s => s.name);
    sel.insertAdjacentHTML('beforeend', names.map(n => `<option value="${esc(n)}">${esc(n)}</option>`).join(''));
  });

  /* ---------- experiences & dining: photo cards, a 3 × 2 grid beside the intro from 1100px, a swipeable rail below that.
     The two tabs (Experiences, Dining) each fill the same grid, so switching never changes the section's height ---------- */
  // reveal: scroll-in effects for the cards shown first; the Dining cards animate in when their tab opens
  const xpCard = (e, i, reveal = true) => `
    <li class="xp">
      <figure class="xp__fig"${reveal ? ' data-reveal="clip"' : ''}>
        <div class="parallax"${reveal ? ' data-parallax="0.06"' : ''}>${picture(e.image, { sizes: '(min-width: 1100px) 22vw, 340px' })}</div>
      </figure>
      <div class="xp__body"${reveal ? ' data-fade' : ''}>
        <p class="xp__meta"><span class="xp__num">${pad(i + 1)}</span><span class="xp__when">${esc(e.when)}</span></p>
        <h3 class="xp__name">${esc(e.name)}</h3>
        <p class="xp__text">${esc(e.text)}</p>
      </div>
    </li>`;
  const xps = C.experiences || [];
  into('experiences', xps.map((e, i) => xpCard(e, i)).join(''));
  $$('[data-render="experiences-total"]').forEach(el => { el.textContent = pad(xps.length); });

  const places = C.diningPlaces || [], menu = C.dining || [];
  into('dining-cards', places.map((e, i) => xpCard(e, i, false)).join('') + (menu.length ? `
    <li class="xp xp--menu">
      <div class="xp__menu">
        <p class="xp__meta"><span class="xp__num">${pad(places.length + 1)}</span><span class="xp__when">On the table</span></p>
        <ol class="xp__menulist">${menu.map((d, i) => `<li><span>${pad(i + 1)}</span>${esc(d)}</li>`).join('')}</ol>
      </div>
    </li>` : ''));
  $$('[data-render="dining-total"]').forEach(el => { el.textContent = pad(places.length + (menu.length ? 1 : 0)); });

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
