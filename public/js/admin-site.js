/* Admin → Website: the home page's contact details, map spot and photos.
   admin.js creates this with its helpers. */
window.YDSITE = function (h) {
  'use strict';
  const { $, $$, esc, icon, api, toast, setHead, view, openLibrary, img, hasPhoto } = h;
  let S = null; // the last /site answer
  let hero = []; // slideshow being edited: [{key, caption}]
  let gallery = [];

  const moveBtns = (i, n) => `<span class="row-move">
    <button type="button" class="abtn abtn--sm abtn--ghost" data-move="-1" ${i === 0 ? 'disabled' : ''} aria-label="Move up">${icon('a-chevup', 15)}</button>
    <button type="button" class="abtn abtn--sm abtn--ghost" data-move="1" ${i === n - 1 ? 'disabled' : ''} aria-label="Move down">${icon('a-chevdown', 15)}</button></span>`;
  const thumb = (key) => (hasPhoto(key) ? `<img src="${img(key, 640)}" alt="" loading="lazy">` : '<span class="ph-missing">Photo missing</span>');

  async function viewWebsite() {
    setHead('Website', 'The contact details, map and photos on the home page. Changes show on the website straight away.');
    load((await api('/site')).site);
    // Top Mountain: the home page's photos come from the media build (tools/media.config.json), so only
    // the cards that change something on the website are shown
    view.innerHTML = `<div class="site-admin">${contactCard()}${mapCard()}${S.slots.length ? photosCard() : ''}${S.max.hero && S.hero.length ? listCard('hero') : ''}${S.gallery.length ? listCard('gallery') : ''}</div>`;
    bind();
  }

  function load(site) {
    S = site;
    hero = S.hero.map((x) => ({ ...x }));
    gallery = S.gallery.map((x) => ({ ...x }));
  }

  // ---------- contact details ----------
  // each box shows what the website shows now: the value set here, or else the .env default
  const field = (name, label, { type = 'text', hint = '', wide = false, max = 255 } = {}) => {
    const value = S.contact[name] !== '' ? S.contact[name] : S.defaults[name] || '';
    return `<label class="fld${wide ? ' span2' : ''}"><span>${label}</span>
      ${type === 'textarea'
        ? `<textarea name="${name}" rows="2" maxlength="${max}">${esc(value)}</textarea>`
        : `<input type="${type}" name="${name}" value="${esc(value)}" maxlength="${max}">`}
      ${hint ? `<small>${hint}</small>` : ''}</label>`;
  };
  /** what to save: '' when a box still holds the .env default, so .env stays in charge of it */
  const valueOf = (f, k) => {
    const v = f[k].value.trim();
    return v === (S.defaults[k] || '') ? '' : v;
  };
  const contactCard = () => `<section class="card" data-card="contact">
    <div class="card__head"><h2>Contact details</h2><span class="src">Header, footer, “Find us”, booking page and emails</span></div>
    <form class="card__body grid2" data-form="contact">
      ${field('phone', 'Phone', { hint: 'guests are asked to call this number to confirm a booking', max: 40 })}
      ${field('whatsapp', 'WhatsApp number', { hint: 'with the country code, e.g. 94777368264', max: 20 })}
      ${field('email', 'Email', { type: 'email', max: 160 })}
      ${field('facebook', 'Facebook page', { type: 'url', hint: 'https://…' })}
      ${field('address', 'Address', { type: 'textarea', wide: true })}
      ${field('instagram', 'Instagram page', { type: 'url', hint: 'https://… (empty = no Instagram link)' })}
      <p class="setup__note full">These are shown on the website and in the emails to guests. (If you fill in “Phone to call” in Booking setup → Advance payment, that number is used for confirming bookings instead.)</p>
      <div class="full setup__save"><button class="abtn abtn--primary" type="submit">Save contact details</button></div>
    </form>
  </section>`;

  const mapCard = () => `<section class="card" data-card="map">
    <div class="card__head"><h2>Map</h2><span class="src">The pin on the “Find us” map and the directions link</span></div>
    <form class="card__body grid2" data-form="map">
      ${field('mapCoords', 'GPS location <small>latitude, longitude</small>', { hint: 'in Google Maps, right-click the spot and click the numbers to copy them', max: 60 })}
      ${field('plusCode', 'Plus code', { max: 40 })}
      ${field('mapPlace', 'Google Maps listing link', { type: 'url', wide: true, hint: 'the “Share” link of your place on Google Maps' })}
      <div class="full setup__save"><button class="abtn abtn--primary" type="submit">Save map</button></div>
    </form>
  </section>`;

  // ---------- single photos ----------
  const photosCard = () => `<section class="card" data-card="photos">
    <div class="card__head"><h2>Photos on the home page</h2><span class="src">Tap “Change” to pick from the library or upload a new photo</span></div>
    <div class="card__body ph-grid">${S.slots.map((s) => `<div class="ph-card" data-slot="${esc(s.slot)}">
        <div class="ph-card__img">${thumb(s.key)}</div>
        <b>${esc(s.label)}</b>
        <div class="ph-card__btns">
          <button type="button" class="abtn abtn--sm" data-change>${icon('a-up', 14)}Change</button>
          ${s.key !== s.default ? '<button type="button" class="abtn abtn--sm abtn--ghost" data-reset>Use the original</button>' : ''}
        </div>
      </div>`).join('')}</div>
  </section>`;

  async function saveSlot(slot, key) {
    try {
      load((await api('/site', { method: 'PATCH', body: { images: { [slot]: key } } })).site);
      redraw('photos');
      toast('Photo saved — the home page is updated');
    } catch (ex) { toast(ex.message, true); }
  }

  // ---------- slideshow and gallery ----------
  const LISTS = {
    hero: { title: 'Slideshow at the top', sub: 'The big photos that change every few seconds, with their captions', max: () => S.max.hero, rows: () => hero, set: (v) => { hero = v; } },
    gallery: { title: 'Gallery', sub: 'The “Moments from the forest” photos — tap one on the website to see it large', max: () => S.max.gallery, rows: () => gallery, set: (v) => { gallery = v; } },
  };
  const listRows = (name) => {
    const rows = LISTS[name].rows();
    return rows.map((x, i) => `<li class="ph-row" data-i="${i}">
      ${moveBtns(i, rows.length)}
      <span class="ph-row__img">${thumb(x.key)}</span>
      <input class="inp" value="${esc(x.caption)}" maxlength="120" placeholder="Caption" data-caption aria-label="Caption">
      <button type="button" class="abtn abtn--sm abtn--ghost" data-remove aria-label="Remove this photo">${icon('a-x', 15)}</button>
    </li>`).join('');
  };
  const listCard = (name) => {
    const L = LISTS[name];
    return `<section class="card" data-card="${name}" data-list="${name}">
      <div class="card__head"><h2>${L.title}</h2><span class="src">${L.sub}</span></div>
      <div class="card__body">
        <ol class="ph-list" data-rows>${listRows(name)}</ol>
        <div class="ph-list__foot">
          <button type="button" class="abtn" data-add>${icon('a-plus', 15)}Add photos</button>
          <span class="src" data-count>${L.rows().length} of up to ${L.max()}</span>
          <button type="button" class="abtn abtn--primary" data-save-list>Save ${name === 'hero' ? 'slideshow' : 'gallery'}</button>
        </div>
      </div>
    </section>`;
  };
  const redrawList = (name) => {
    const card = $(`[data-list="${name}"]`, view);
    $('[data-rows]', card).innerHTML = listRows(name);
    $('[data-count]', card).textContent = `${LISTS[name].rows().length} of up to ${LISTS[name].max()}`;
  };
  function redraw(card) {
    const el = $(`[data-card="${card}"]`, view);
    const html = { contact: contactCard, map: mapCard, photos: photosCard, hero: () => listCard('hero'), gallery: () => listCard('gallery') }[card]();
    if (el) el.outerHTML = html;
  }

  function bind() {
    view.onsubmit = async (e) => {
      e.preventDefault();
      const f = e.target;
      const keys = f.dataset.form === 'contact' ? ['phone', 'whatsapp', 'email', 'facebook', 'address', 'instagram'] : ['mapCoords', 'plusCode', 'mapPlace'];
      const contact = Object.fromEntries(keys.map((k) => [k, valueOf(f, k)]));
      try {
        load((await api('/site', { method: 'PATCH', body: { contact } })).site);
        redraw(f.dataset.form);
        toast(f.dataset.form === 'contact' ? 'Contact details saved — the website is updated' : 'Map saved');
      } catch (ex) { toast(ex.message, true); }
    };
    view.oninput = (e) => {
      const cap = e.target.closest('[data-caption]');
      if (!cap) return;
      const name = cap.closest('[data-list]').dataset.list;
      LISTS[name].rows()[Number(cap.closest('[data-i]').dataset.i)].caption = cap.value;
    };
    view.onclick = async (e) => {
      const t = e.target;
      const slot = t.closest('[data-slot]');
      if (slot && t.closest('[data-change]')) {
        const s = S.slots.find((x) => x.slot === slot.dataset.slot);
        openLibrary([s.key], (keys) => saveSlot(s.slot, keys[0]), { max: 1, hint: `Pick the photo for: ${s.label}`, saveHint: '' });
        return;
      }
      if (slot && t.closest('[data-reset]')) { saveSlot(slot.dataset.slot, ''); return; }
      const card = t.closest('[data-list]');
      if (!card) return;
      const name = card.dataset.list;
      const L = LISTS[name];
      const rows = L.rows();
      const row = t.closest('[data-i]');
      const i = row ? Number(row.dataset.i) : -1;
      const mv = t.closest('[data-move]');
      if (mv && row) {
        const j = i + Number(mv.dataset.move);
        if (j < 0 || j >= rows.length) return;
        [rows[i], rows[j]] = [rows[j], rows[i]];
        redrawList(name);
        return;
      }
      if (t.closest('[data-remove]') && row) {
        if (rows.length === 1) { toast('Keep at least one photo.', true); return; }
        rows.splice(i, 1);
        redrawList(name);
        return;
      }
      if (t.closest('[data-add]')) {
        const room = L.max() - rows.length;
        if (room < 1) { toast(`There can be up to ${L.max()} photos — remove one first.`, true); return; }
        openLibrary([], (keys) => {
          L.set(rows.concat(keys.map((key) => ({ key, caption: '' }))));
          redrawList(name);
        }, { max: room, hint: `Tap the photos to add (up to ${room}), then add their captions.`, saveHint: 'Added — add captions, then save' });
        return;
      }
      if (t.closest('[data-save-list]')) {
        try {
          load((await api('/site', { method: 'PATCH', body: { [name]: rows } })).site);
          redraw(name);
          toast(`${name === 'hero' ? 'Slideshow' : 'Gallery'} saved — the home page is updated`);
        } catch (ex) { toast(ex.message, true); }
      }
    };
  }

  return { website: viewWebsite };
};
