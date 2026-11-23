/* Admin → Photos & videos: every photo and video on the home page. Each can be changed (a photo from
   the library, a new upload, a new video) and put back to the original from the media build.
   The server keeps the choices and checks every file (app/HomeMedia.php, app/Media.php).
   admin.js creates this with its helpers. */
window.YDMEDIA = function (h) {
  'use strict';
  const { $, esc, icon, api, toast, setHead, view, openLibrary, img, hasPhoto, addMedia, openModal, modalHead, confirmBox, askDialog } = h;
  let S = null; // the last /home-media answer
  const busy = {}; // slot → {pct, text} while a video uploads

  const CHUNK = 4 * 1048576; // each request stays under the host's upload limit (App::VIDEO_PART_LIMIT)
  const mb = (b) => `${(b / 1048576).toFixed(b < 10485760 ? 1 : 0)} MB`;
  const len = (s) => { const t = Math.round(s || 0); return `${Math.floor(t / 60)}:${String(t % 60).padStart(2, '0')}`; };
  const thumb = (key) => (key && hasPhoto(key) ? `<img src="${img(key, 640)}" alt="" loading="lazy">` : '<span class="ph-missing">No photo</span>');
  const places = () => S.groups.flatMap((g) => g.places);
  const videoOf = (slot) => S.videos.find((v) => v.slot === slot);

  addEventListener('beforeunload', (e) => {
    if (Object.keys(busy).length) { e.preventDefault(); e.returnValue = ''; } // a video is still uploading
  });

  async function show() {
    setHead('Photos & videos', 'Every photo and video on the home page. Changes show on the website straight away; “Use the original” puts one back.');
    S = (await api('/home-media')).media;
    view.innerHTML = `<div class="media-admin">${videosCard()}${S.groups.map(groupCard).join('')}</div>`;
    bind();
  }

  // ---------- videos ----------
  const fileLine = (f) => `${f.custom ? 'Your video' : 'The original'} · ${f.w}×${f.h}${f.duration ? ` · ${len(f.duration)}` : ''}${f.bytes ? ` · ${mb(f.bytes)}` : ''}`;
  const uploadBtn = (role, label, off) => `<label class="abtn abtn--sm${role === 'main' ? ' abtn--primary' : ''}${off ? ' is-disabled' : ''}">${icon('a-up', 14)}${label}
    <input type="file" accept="video/mp4,video/quicktime,.mp4,.m4v,.mov" hidden data-upload="${role}"${off ? ' disabled' : ''}></label>`;
  const phoneBlock = (v, off) => {
    const p = v.phoneVideo;
    return `<div class="vid__phone">
      <div class="vid__phone-img">${p ? thumb(v.posterPhone || v.poster) : ''}${p ? `<button type="button" class="vid__play" data-preview="phone" aria-label="Play the phone version">${icon('a-right', 16)}</button>` : ''}</div>
      <div class="vid__phone-body">
        <b>Phone version</b> <span class="src">optional: an upright video for phones held upright</span>
        <p class="vid__file">${p ? fileLine(p) : 'None: phones show the main video, cropped to fit the screen.'}</p>
        <div class="ph-card__btns">
          ${uploadBtn('phone', p && p.custom ? 'Replace' : 'Upload a phone version', off)}
          ${p ? '<button type="button" class="abtn abtn--sm" data-cover="posterPhone">Change its cover photo</button>' : ''}
          ${p && p.custom ? '<button type="button" class="abtn abtn--sm abtn--ghost" data-rm-phone>Remove</button>' : ''}
        </div>
      </div>
    </div>`;
  };
  const videoRow = (v) => {
    const up = busy[v.slot];
    return `<li class="vid" data-slot="${v.slot}">
      <div class="vid__poster">${thumb(v.poster)}${v.main ? `<button type="button" class="vid__play" data-preview="main" aria-label="Play: ${esc(v.label)}">${icon('a-right', 20)}</button>` : ''}</div>
      <div class="vid__body">
        <h3>${esc(v.label)}${v.changed ? ' <span class="st st--confirmed">Changed</span>' : ''}</h3>
        <p class="src">${esc(v.where)}</p>
        ${v.main ? `<p class="vid__file">${fileLine(v.main)}</p>` : ''}
        ${up ? `<div class="vid__progress" role="status"><i style="width:${up.pct}%"></i><span>${esc(up.text)}</span></div>` : ''}
        <div class="ph-card__btns">
          ${uploadBtn('main', 'Upload a new video', !!up)}
          <button type="button" class="abtn abtn--sm" data-cover="poster">Change cover photo</button>
          <button type="button" class="abtn abtn--sm abtn--ghost" data-describe>Description</button>
          ${v.changed && !up ? '<button type="button" class="abtn abtn--sm abtn--ghost" data-original>Use the original</button>' : ''}
        </div>
        ${v.phone ? phoneBlock(v, !!up) : ''}
      </div>
    </li>`;
  };
  const videosCard = () => `<section class="card" data-card="videos">
    <div class="card__head"><h2>Videos</h2><span class="src">MP4 (H.264), 1920×1080 or 1280×720. Background videos play muted, so they need no sound; under about 15 MB keeps the page quick on phones.</span></div>
    <ol class="card__body vid-list">${S.videos.map(videoRow).join('')}</ol>
  </section>`;
  const redrawVideo = (slot) => {
    const el = $(`.vid[data-slot="${slot}"]`, view);
    const v = videoOf(slot);
    if (el && v) el.outerHTML = videoRow(v);
  };

  async function saveVideo(slot, change, msg) {
    try {
      S = (await api('/home-media', { method: 'PATCH', body: { videos: { [slot]: change } } })).media;
      redrawVideo(slot);
      toast(msg);
    } catch (ex) { toast(ex.message, true); }
  }

  const fourcc = (d) => String.fromCharCode(d.getUint8(0), d.getUint8(1), d.getUint8(2), d.getUint8(3));
  /**
   * The video codec inside an MP4 (avc1 = H.264), read from its boxes the way the server reads it,
   * so a phone's HEVC video is turned away before a long upload. '' when it can't be read.
   */
  async function codecOf(file) {
    const read = async (at, n) => new DataView(await file.slice(at, at + n).arrayBuffer());
    const boxes = async (from, to) => {
      const out = [];
      for (let at = from; at + 8 <= to && out.length < 4096;) {
        const d = await read(at, 16);
        if (d.byteLength < 8) break;
        let size = d.getUint32(0);
        let head = 8;
        if (size === 1) { if (d.byteLength < 16) break; size = Number(d.getBigUint64(8)); head = 16; } else if (size === 0) size = to - at;
        if (size < head || at + size > to) break;
        out.push([fourcc(new DataView(d.buffer, 4, 4)), at + head, at + size]);
        at += size;
      }
      return out;
    };
    const child = async (box, type) => (box ? (await boxes(box[1], box[2])).find((b) => b[0] === type) : undefined);
    try {
      const moov = await child(['', 0, file.size], 'moov');
      for (const trak of moov ? await boxes(moov[1], moov[2]) : []) {
        if (trak[0] !== 'trak') continue;
        const mdia = await child(trak, 'mdia');
        const hdlr = await child(mdia, 'hdlr');
        if (!hdlr || fourcc(await read(hdlr[1] + 8, 4)) !== 'vide') continue;
        const stsd = await child(await child(await child(mdia, 'minf'), 'stbl'), 'stsd');
        return stsd ? fourcc(await read(stsd[1] + 12, 4)) : '';
      }
    } catch { /* unreadable here: the server checks it */ }
    return '';
  }

  /**
   * Play the chosen file here, unseen: it must play, and gives its size, its length and a frame for
   * its cover photo (`at`: how far in, 0 = the first frame, which a looping background starts on).
   */
  function probe(file, at) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const v = document.createElement('video');
      let done = false;
      const end = (fn, val) => {
        if (done) return;
        done = true;
        clearTimeout(timer);
        v.removeAttribute('src');
        v.load();
        URL.revokeObjectURL(url);
        fn(val);
      };
      const cant = () => end(reject, new Error('That video doesn’t play in this browser, so it may not play for guests either. Please export it as MP4 with H.264.'));
      const timer = setTimeout(cant, 30000);
      v.muted = true;
      v.playsInline = true;
      v.preload = 'auto';
      v.onerror = cant;
      v.onloadedmetadata = () => {
        if (!v.videoWidth) { cant(); return; }
        v.currentTime = Math.max(0.04, (v.duration || 0) * at);
      };
      v.onseeked = () => {
        const w = v.videoWidth;
        const ht = v.videoHeight;
        const scale = Math.min(1, 1920 / Math.max(w, ht));
        const c = document.createElement('canvas');
        c.width = Math.round(w * scale);
        c.height = Math.round(ht * scale);
        try { c.getContext('2d').drawImage(v, 0, 0, c.width, c.height); } catch { end(resolve, { w, h: ht, duration: v.duration, frame: null }); return; }
        c.toBlob((b) => end(resolve, { w, h: ht, duration: v.duration, frame: b }), 'image/jpeg', 0.9);
      };
      v.src = url;
    });
  }

  const CODECS = { hvc1: 'HEVC (H.265)', hev1: 'HEVC (H.265)', av01: 'AV1', vp09: 'VP9', mp4v: 'MPEG-4 Part 2' };

  async function uploadVideo(slot, role, file) {
    const v = videoOf(slot);
    if (!v || busy[slot]) return;
    if (!/\.(mp4|m4v|mov)$/i.test(file.name) && !/^video\/(mp4|quicktime)$/.test(file.type)) { toast('Please choose an MP4 video (H.264).', true); return; }
    if (file.size > S.videoMax) { toast(`That video is ${mb(file.size)}: please keep it under ${mb(S.videoMax)}.`, true); return; }
    const codec = await codecOf(file);
    if (codec && !['avc1', 'avc3'].includes(codec)) {
      toast(`This video is saved as ${CODECS[codec] || codec}, which some browsers can't play. Please export it as MP4 with H.264 (on an iPhone: Settings → Camera → Formats → Most Compatible).`, true);
      return;
    }
    let info;
    try { info = await probe(file, slot === 'film' ? 0.1 : 0); } catch (ex) { toast(ex.message, true); return; }
    const upright = info.h > info.w;
    if (role === 'main' && upright && v.phone && !(await confirmBox({ title: 'This video is upright', text: 'On computers and tablets an upright video is cropped to a wide strip. It may suit “Phone version” better. Use it as the main video anyway?', button: 'Use it anyway' }))) return;
    if (role === 'phone' && !upright && !(await confirmBox({ title: 'This video is not upright', text: 'The phone version is for phones held upright, so it should be taller than it is wide. Use it anyway?', button: 'Use it anyway' }))) return;
    const heavy = (slot === 'film' ? 80 : 25) * 1048576;
    if (file.size > heavy && !(await confirmBox({ title: 'Upload a large video?', text: `This video is ${mb(file.size)}. ${slot === 'film' ? 'Guests wait longer before the film starts.' : 'Guests on mobile data wait longer, and it uses up their data.'} A video app can make it smaller (HandBrake's “Fast 1080p30” preset, with no sound for a background video).`, button: 'Upload anyway' }))) return;

    const setBusy = (pct, text) => { busy[slot] = { pct, text }; redrawVideo(slot); };
    const id = Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');
    try {
      for (let at = 0; at < file.size; at += CHUNK) {
        const pct = Math.round((at / file.size) * 100);
        setBusy(pct, `Uploading… ${pct}% (${mb(at)} of ${mb(file.size)}). Keep this page open.`);
        const part = file.slice(at, at + CHUNK);
        const r = await api(`/media/video?upload=${id}&offset=${at}`, { method: 'POST', raw: { data: part, type: 'application/octet-stream', name: file.name } });
        if (r.size !== at + part.size) throw new Error('The upload was interrupted. Please try again.');
      }
      setBusy(100, 'Checking the video…');
      // its cover photo: the frame read above, kept as it is (not colour-graded) so it matches the video
      let poster = null;
      if (info.frame) {
        try {
          const p = await api('/media?grade=0', { method: 'POST', raw: { data: info.frame, type: 'image/jpeg', name: `${v.label}${role === 'phone' ? ' phone' : ''} cover.jpg` } });
          addMedia(p.key, p.item);
          poster = p.key;
        } catch (ex) { toast(`The cover photo could not be made (${ex.message}). Choose one with “Change cover photo”.`, true); }
      }
      S = (await api('/home-media/video', { method: 'POST', body: { upload: id, slot, role, poster } })).media;
      delete busy[slot];
      redrawVideo(slot);
      toast(role === 'phone' ? 'Phone version saved: the home page is updated' : 'Video saved: the home page is updated');
    } catch (ex) {
      delete busy[slot];
      redrawVideo(slot);
      toast(ex.message, true);
    }
  }

  function preview(v, role) {
    const f = role === 'phone' ? v.phoneVideo : v.main;
    if (!f) return;
    openModal(`${modalHead(v.label, role === 'phone' ? 'Phone version' : fileLine(f))}
      <div class="dr-body"><video class="vid-preview${role === 'phone' ? ' vid-preview--tall' : ''}" src="${esc(f.src)}" poster="${esc(img(role === 'phone' ? v.posterPhone || v.poster : v.poster, 1280))}" controls autoplay muted loop playsinline></video></div>`);
  }

  // ---------- photos ----------
  const placeCard = (p) => {
    const cover = p.multi ? p.key[0] : p.key;
    return `<div class="ph-card" data-place="${esc(p.id)}">
      <div class="ph-card__img">${thumb(cover)}${p.multi ? `<span class="ph-card__count">${p.key.length} photo${p.key.length === 1 ? '' : 's'}</span>` : ''}</div>
      <b>${esc(p.label)}${p.changed ? ' <span class="ph-card__changed">changed</span>' : ''}</b>
      <div class="ph-card__btns">
        <button type="button" class="abtn abtn--sm" data-change>${icon('a-up', 14)}${p.multi ? 'Change photos' : 'Change'}</button>
        ${p.changed ? '<button type="button" class="abtn abtn--sm abtn--ghost" data-reset>Use the original</button>' : ''}
      </div>
    </div>`;
  };
  const groupCard = (g) => `<section class="card" data-group="${esc(g.id)}">
    <div class="card__head"><h2>${esc(g.title)}</h2><span class="src">${esc(g.sub)}</span></div>
    <div class="card__body ph-grid">${g.places.map(placeCard).join('')}</div>
  </section>`;

  async function savePhoto(id, value, msg) {
    try {
      S = (await api('/home-media', { method: 'PATCH', body: { photos: { [id]: value } } })).media;
      const el = Array.from(view.querySelectorAll('[data-place]')).find((x) => x.dataset.place === id);
      const p = places().find((x) => x.id === id);
      if (el && p) el.outerHTML = placeCard(p);
      toast(msg);
    } catch (ex) { toast(ex.message, true); }
  }

  function bind() {
    view.onchange = (e) => {
      const input = e.target.closest('[data-upload]');
      if (!input || !input.files.length) return;
      const file = input.files[0];
      input.value = '';
      uploadVideo(input.closest('[data-slot]').dataset.slot, input.dataset.upload, file);
    };
    view.onclick = async (e) => {
      const t = e.target;
      const row = t.closest('[data-slot]');
      if (row) {
        const v = videoOf(row.dataset.slot);
        const cover = t.closest('[data-cover]');
        if (t.closest('[data-preview]')) { preview(v, t.closest('[data-preview]').dataset.preview); return; }
        if (cover) {
          const which = cover.dataset.cover;
          openLibrary([which === 'poster' ? v.poster : v.posterPhone || v.poster].filter(Boolean), (keys) => saveVideo(v.slot, { [which]: keys[0] }, 'Cover photo saved: the home page is updated'),
            { max: 1, hint: 'The cover photo shows while the video loads, and in its place where it can’t play (slow connections, “reduce motion”). The video’s own first frame matches it best.', saveHint: '' });
          return;
        }
        if (t.closest('[data-describe]')) {
          const label = await askDialog({ title: 'What the video shows', sub: v.label, label: 'Description', value: v.description, max: 200, button: 'Save', hint: 'Screen readers read it out, and search engines use it. One short sentence.' });
          if (label !== null) saveVideo(v.slot, { label }, 'Description saved');
          return;
        }
        if (t.closest('[data-rm-phone]')) {
          if (await confirmBox({ title: 'Remove the phone version?', text: 'Phones then show the main video, cropped to fit the screen.', button: 'Remove', danger: true })) saveVideo(v.slot, { phone: null }, 'Phone version removed');
          return;
        }
        if (t.closest('[data-original]')) {
          if (await confirmBox({ title: 'Put back the original video?', text: `Your video${v.phone ? ', its phone version' : ''}, cover photo and description for “${v.label}” are removed, and the website's original comes back.`, button: 'Use the original' })) {
            saveVideo(v.slot, null, 'The original video is back on the home page');
          }
        }
        return;
      }
      const card = t.closest('[data-place]');
      if (!card) return;
      const p = places().find((x) => x.id === card.dataset.place);
      if (!p) return;
      if (t.closest('[data-reset]')) { savePhoto(p.id, '', 'The original is back on the home page'); return; }
      if (!t.closest('[data-change]')) return;
      if (p.multi) {
        openLibrary(p.key, (keys) => savePhoto(p.id, keys, `${p.label}: photos saved, the home page is updated`),
          { max: S.maxRoomPhotos, hint: `${p.label}: tap the photos in the order you want them. The first is the tile's cover.`, saveHint: '' });
      } else {
        openLibrary([p.key], (keys) => savePhoto(p.id, keys[0], 'Photo saved: the home page is updated'), { max: 1, hint: `Pick the photo for: ${p.label}`, saveHint: '' });
      }
    };
  }

  return { show };
};
