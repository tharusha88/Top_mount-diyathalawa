/* Admin → Rate sheet: the resort's room rate sheet as an editable table, laid out like the printed
   one. Each room category is a table: its rooms (room number, room type, how many it sleeps) with a
   price per night for each meal plan (RO room only, BB bed & breakfast, HB half board, FB full
   board), and the bold whole-villa row (every room of the category together, at one price). The
   website's booking page, home page rates and every new booking use these prices. Save writes the
   whole sheet at once. admin.js creates this with its helpers. */
window.YDRATES = function (h) {
  'use strict';
  const { $, $$, esc, icon, api, toast, setHead, view, currency, askDialog, confirmBox } = h;
  const PLAN_HEAD = { RO: 'RO (Room Only)', BB: 'BB (Bed & Breakfast)', HB: 'HB (Half Board)', FB: 'FB (Full Board)' };
  let SHEET = null; // {categories, uncategorised, mealPlans}
  let dirty = false;

  const plans = () => (SHEET && SHEET.mealPlans.length ? SHEET.mealPlans : [{ code: 'RO', name: 'Room only' }, { code: 'BB', name: 'Bed & breakfast' }, { code: 'HB', name: 'Half board' }, { code: 'FB', name: 'Full board' }]);
  const priceInputs = (prices, name) => plans().map((p) => `<td class="rs-num"><input class="rs-in rs-in--price" type="number" min="0" step="100" inputmode="numeric" name="${name}-${p.code}" data-plan="${p.code}" value="${Number((prices || {})[p.code]) || 0}" aria-label="${esc(p.name)} price"></td>`).join('');
  const catOptions = (sel) => SHEET.categories.map((c) => `<option value="${c.id}" ${c.id === sel ? 'selected' : ''}>${esc(c.name)}</option>`).join('') + `<option value="" ${sel === null ? 'selected' : ''}>— no category —</option>`;

  function roomRow(r) {
    return `<tr data-room="${r.id}">
      <td><input class="rs-in rs-in--name" name="name" value="${esc(r.name)}" maxlength="80" required aria-label="Room">${r.active ? '' : '<span class="rs-tag" title="Not bookable on the website (Rooms page)">hidden</span>'}</td>
      <td class="rs-type"><input class="rs-in" name="label" value="${esc(r.label)}" maxlength="80" placeholder="e.g. Double Room" aria-label="Room type"><label class="rs-pax"><input class="rs-in rs-in--pax" type="number" name="capacity" min="1" max="50" value="${r.capacity}" required aria-label="Guests (pax)"><span>PAX</span></label></td>
      ${priceInputs(r.prices, 'p')}
      <td class="rs-move no-print"><select class="rs-in rs-in--move" name="categoryId" aria-label="Category of ${esc(r.name)}" title="Move to another category">${catOptions(r.categoryId)}</select></td>
    </tr>`;
  }

  function wholeRow(c) {
    const on = !!c.whole;
    return `<tr class="rs-whole" data-whole ${on ? '' : 'hidden'}>
      <td><input class="rs-in rs-in--name" name="wholeCode" value="${esc(c.wholeCode || 'W/VILLA')}" maxlength="20" aria-label="Short name, e.g. W/VILLA"></td>
      <td class="rs-type"><input class="rs-in" name="wholeLabel" value="${esc(c.wholeLabel || 'Whole Villa')}" maxlength="80" aria-label="Whole option, e.g. Whole Villa"><label class="rs-pax"><input class="rs-in rs-in--pax" type="number" name="wholeCapacity" min="0" max="500" value="${c.wholeCapacity || c.rooms.reduce((s, r) => s + r.capacity, 0)}" aria-label="Guests (pax)"><span>PAX</span></label></td>
      ${priceInputs(c.wholePrices, 'w')}
      <td class="rs-move no-print"><button type="button" class="abtn abtn--sm abtn--ghost" data-whole-off title="Stop offering the whole ${esc(c.name)} at one price">${icon('a-x', 14)}</button></td>
    </tr>`;
  }

  function table(c, i) {
    const loose = c.id === null;
    return `<section class="rs-cat rs-cat--${i % 6}" ${loose ? 'data-loose' : `data-cat="${c.id}"`}>
      <table class="rs-table">
        <thead><tr>
          <th class="rs-cat__name">${loose ? '<span>Rooms without a category</span>' : `<input class="rs-in rs-in--cat" name="catName" value="${esc(c.name)}" maxlength="80" required aria-label="Category name">`}</th>
          <th>Room Type</th>
          ${plans().map((p) => `<th class="rs-num">${esc(PLAN_HEAD[p.code] || `${p.code} (${p.name})`)}</th>`).join('')}
          <th class="rs-move no-print">${loose ? '' : `<button type="button" class="abtn abtn--sm abtn--ghost" data-cat-del title="Delete this category (only when it has no rooms)">${icon('a-x', 14)}</button>`}</th>
        </tr></thead>
        <tbody>
          ${c.rooms.map(roomRow).join('')}
          ${loose ? '' : wholeRow(c)}
        </tbody>
      </table>
      ${loose ? '' : `<button type="button" class="linkbtn rs-addwhole no-print" data-whole-on ${c.whole ? 'hidden' : ''}>+ Add a whole-villa price (every room of ${esc(c.name)} together)</button>`}
    </section>`;
  }

  function render() {
    const cats = SHEET.categories;
    const loose = SHEET.uncategorised.length ? [{ id: null, name: '', rooms: SHEET.uncategorised }] : [];
    view.innerHTML = `<div class="ratesheet">
      <div class="rs-bar no-print">
        <span class="src" data-rs-state>All prices saved. They are what the website and new bookings use.</span>
        <div class="rs-bar__btns">
          <button type="button" class="abtn" data-rs-add>${icon('a-plus', 16)}Add category</button>
          <button type="button" class="abtn" data-rs-print>${icon('a-print', 16)}Print</button>
          <button type="button" class="abtn abtn--primary" data-rs-save>Save rate sheet</button>
        </div>
      </div>
      <div class="rs-sheet">
        <header class="rs-banner"><h2>${esc((h.me() && h.me().siteName) || 'Top Mountain Resort')}</h2><p>Room rate sheet</p></header>
        <p class="rs-tariff">Tariff effective until further notice. Rates are per room, per night, in ${esc(currency())}.</p>
        <form data-rs-form novalidate>${[...cats, ...loose].map(table).join('')}</form>
        <div class="rs-notes">
          <b>Notes:</b>
          <ul>
            <li>${plans().map((p) => `${esc(p.code)}: ${esc(p.name)}`).join(' | ')}</li>
            <li>Rates are per room, per night, inclusive of all applicable taxes.</li>
            <li>Bold rows are group / whole-villa bookings: every room of that category together, at one price.</li>
            <li class="no-print">A price of 0 means that meal plan isn't offered for that room. Photos, descriptions and whether a room can be booked on the website are on the <a href="#/rooms">Rooms</a> page.</li>
          </ul>
        </div>
      </div>
    </div>`;
    setDirty(false);
  }

  function setDirty(on) {
    dirty = on;
    const s = $('[data-rs-state]');
    if (!s) return;
    s.textContent = on ? 'Unsaved changes — press Save rate sheet.' : 'All prices saved. They are what the website and new bookings use.';
    s.classList.toggle('is-due', on);
  }

  /** The sheet as it stands on screen: categories in order, and every room with its category. */
  function collect() {
    const num = (el) => Number(el.value === '' ? NaN : el.value);
    const prices = (row, name) => Object.fromEntries(plans().map((p) => [p.code, num($(`[name="${name}-${p.code}"]`, row))]));
    const categories = $$('[data-cat]', view).map((sec) => {
      const w = $('[data-whole]', sec);
      const on = !w.hidden;
      return {
        id: Number(sec.dataset.cat), name: $('[name="catName"]', sec).value,
        wholeCode: on ? $('[name="wholeCode"]', w).value : '', wholeLabel: on ? $('[name="wholeLabel"]', w).value : '',
        wholeCapacity: on ? num($('[name="wholeCapacity"]', w)) : 0, wholePrices: prices(w, 'w'),
      };
    });
    const rooms = $$('[data-room]', view).map((row) => ({
      id: Number(row.dataset.room), name: $('[name="name"]', row).value, label: $('[name="label"]', row).value,
      capacity: num($('[name="capacity"]', row)), prices: prices(row, 'p'),
      categoryId: $('[name="categoryId"]', row).value === '' ? null : Number($('[name="categoryId"]', row).value),
    }));
    return { categories, rooms };
  }

  async function save() {
    const body = collect();
    const bad = body.rooms.find((r) => !r.name.trim()) || body.categories.find((c) => !c.name.trim());
    if (bad) { toast('Every room and category needs a name.', true); return false; }
    const btn = $('[data-rs-save]');
    btn.disabled = true;
    try {
      SHEET = await api('/rates', { method: 'PUT', body });
      render();
      toast('Rate sheet saved — the website shows the new prices');
      return true;
    } catch (ex) { toast(ex.message, true); btn.disabled = false; return false; }
  }

  async function viewRates() {
    setHead('Rate sheet', 'Room categories, room types and the price per night for each meal plan. The website and new bookings use these prices.');
    SHEET = await api('/rates');
    render();
    view.oninput = (e) => { if (e.target.closest('[data-rs-form]')) setDirty(true); };
    view.onchange = (e) => {
      // moving a room to another category moves its row there
      if (e.target.name !== 'categoryId') return;
      const row = e.target.closest('[data-room]');
      const to = e.target.value === '' ? null : $(`[data-cat="${e.target.value}"] tbody`, view);
      if (to) to.insertBefore(row, $('[data-whole]', to));
      else toast('Saved without a category, the room is listed under “Rooms without a category”.');
      setDirty(true);
    };
    view.onclick = async (e) => {
      if (e.target.closest('[data-rs-save]')) { save(); return; }
      if (e.target.closest('[data-rs-print]')) { window.print(); return; }
      const on = e.target.closest('[data-whole-on]');
      if (on) {
        const sec = on.closest('[data-cat]');
        $('[data-whole]', sec).hidden = false;
        on.hidden = true;
        $('[name="wholeCode"]', sec).focus();
        setDirty(true);
        return;
      }
      const off = e.target.closest('[data-whole-off]');
      if (off) {
        const sec = off.closest('[data-cat]');
        $('[data-whole]', sec).hidden = true;
        $('[data-whole-on]', sec).hidden = false;
        setDirty(true);
        return;
      }
      if (e.target.closest('[data-rs-add]')) {
        // names already on the sheet (as typed now, saved or not)
        const taken = new Set([...SHEET.categories.map((c) => c.name), ...$$('[name="catName"]', view).map((i) => i.value)].map((n) => n.trim().toLowerCase()));
        const name = await askDialog({
          title: 'New room category',
          sub: 'A new table on the rate sheet, with its own prices for each meal plan.',
          label: 'Category name',
          placeholder: 'e.g. Garden Villa',
          hint: 'It starts empty. Move rooms into it with the menu at the end of each room’s row.',
          button: 'Add category',
          max: 80,
          validate: (v) => (taken.has(v.toLowerCase()) ? `There is already a category called “${v}”.` : ''),
        });
        if (!name) return;
        if (dirty && !(await save())) return; // keep what was typed
        try {
          await api('/categories', { method: 'POST', body: { name } });
          SHEET = await api('/rates');
          render();
          toast(`${name} added — move rooms into it with the menu at the end of each row`);
        } catch (ex) { toast(ex.message, true); }
        return;
      }
      const del = e.target.closest('[data-cat-del]');
      if (del) {
        const sec = del.closest('[data-cat]');
        const name = $('[name="catName"]', sec).value;
        if ($$('[data-room]', sec).length) { toast(`Move the rooms of ${name} to another category first.`, true); return; }
        const ok = await confirmBox({
          title: 'Delete this category?',
          text: `“${name}” has no rooms, so nothing else changes. Its table is removed from the rate sheet.`,
          button: 'Delete category',
          danger: true,
        });
        if (!ok) return;
        if (dirty && !(await save())) return;
        try {
          await api(`/categories/${sec.dataset.cat}`, { method: 'DELETE' });
          SHEET = await api('/rates');
          render();
          toast(`${name} deleted`);
        } catch (ex) { toast(ex.message, true); }
      }
    };
  }

  // leaving with unsaved prices: ask first
  window.addEventListener('hashchange', () => { if (dirty && !location.hash.startsWith('#/rates')) { dirty = false; toast('The rate sheet changes were not saved.', true); } });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  return { rates: viewRates };
};
