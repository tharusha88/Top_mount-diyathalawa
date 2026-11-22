/* Admin → Chatbot: the website's chat window and the knowledge base of Top Mountain's bot.
   The bot lives on the Panora chatbot platform (a "hotel" brand); this page edits it through the
   site's own API (/api/admin/chatbot → app/Chatbot.php → the chatbot's bridge). admin.js creates it. */
window.YDCHAT = function (h) {
  'use strict';
  const { $, $$, esc, icon, api, toast, setHead, view } = h;
  let C = null;       // the last /chatbot answer
  let draft = null;   // { botName, instructions, entries: [{title, content}] } being edited
  let dirty = false;
  let testLog = [];   // the test chat: [{role: user|assistant, content}]

  const LIMITS_DEFAULT = { instructions_max: 6000, entry_title_max: 120, entry_max: 4000, entries_max: 40, total_max: 30000 };
  const limits = () => (C && C.brand && C.brand.limits) || LIMITS_DEFAULT;
  const total = () => draft.instructions.length + draft.entries.reduce((s, e) => s + e.title.length + e.content.length, 0);
  const num = (n) => Number(n).toLocaleString('en-US');

  // ---------- the starter knowledge base: the website's own content (content.js) + the booking system ----------
  function seed(f) {
    const T = window.TMR_CONTENT || {};
    const ct = T.contact || {};
    const book = `${f.baseUrl}/book`;
    const wa = f.whatsapp ? `https://wa.me/${f.whatsapp}` : '';
    const lines = (xs) => xs.filter(Boolean).join('\n');
    const entries = [];
    const add = (title, content) => { if (content && content.trim()) entries.push({ title, content: content.trim() }); };

    add('About the resort', lines([
      `${f.name} is a 3-acre estate in the cool, pine-covered hills of Diyatalawa, Sri Lanka, with wooden cabanas, a clay-walled mud house, villas and a whole bungalow.`,
      ...(T.highlights || []).map((x) => `- ${x.title}: ${x.text}`),
    ]));
    add('Stays and starting rates', lines([
      ...(T.stays || []).map((s) => `- ${s.name} (${s.kind}): ${s.tagline}.${s.desc ? ` ${s.desc}` : ''} Size ${s.size}. Sleeps ${s.guests}.${s.view ? ` ${s.view}.` : ''} Starting rate ${s.from}.`),
      `These are starting rates. The exact price for the chosen dates and rooms is on the booking page: ${book}`,
    ]));
    if ((T.rooms || []).length) {
      add('Rooms', lines([
        `There are ${T.rooms.length} rooms: ${T.rooms.map((r) => r.name).join(', ')}.`,
        `Photos of every room are on the website. Live prices and availability for the guest's dates are on the booking page: ${book}`,
      ]));
    }
    add('In the rooms', (T.amenities || []).length ? `Every stay has: ${T.amenities.map((a) => a.label).join(', ')}.` : '');
    add('Things to do', lines((T.experiences || []).map((x) => `- ${x.name}: ${x.text}`)));
    add('Food and dining', lines([
      (T.dining || []).length ? `On the table: ${T.dining.join(', ')}.` : '',
      ...(T.diningPlaces || []).map((x) => `- ${x.name}: ${x.text}`),
    ]));
    add('Location and getting here', lines([
      `Address: ${(ct.address || []).join(', ') || f.address}.`,
      f.mapLink ? `Google Maps: ${f.mapLink}` : '',
      ...(T.location || []).map(([k, v]) => `${k}: ${v}.`),
    ]));
    add('Places nearby', lines([
      ...(T.nearby || []).map((x) => `- ${x.name} (${x.km} km, ${x.type}): ${x.desc}`),
      (T.alsoNearby || []).length ? `Also nearby: ${T.alsoNearby.map(([n, km]) => `${n} (${km} km)`).join(', ')}.` : '',
    ]));
    add('Contact', lines([
      `Phone: ${(ct.phones || []).map((p) => p.label).join(' or ') || f.phone}.`,
      wa ? `WhatsApp: ${wa}` : '',
      f.email ? `Email: ${f.email}` : '',
    ]));
    add('Check-in and check-out', `Check-in is from ${f.checkIn}. Check-out is by ${f.checkOut}.`);
    add('How to book', lines([
      `Guests book online at ${book}: choose the dates, then one room or several (up to ${f.maxRooms} rooms in one booking), then enter their details. No payment is taken online.`,
      ...(f.advanceOn === false
        ? ['Nothing is paid before arrival: the stay is paid at the resort. A booking is pending until the resort calls to confirm it.',
          `For help with a booking, guests call ${f.phone}${wa ? ' or message on WhatsApp' : ''}.`]
        : [`A booking is pending until the advance is paid${f.advanceType === 'amount' && f.advanceAmount ? ` (${f.currency} ${Number(f.advanceAmount).toLocaleString('en-US')})`
          : f.advancePct ? ` (${f.advancePct}% of the total)` : ''}. The resort holds a pending booking for ${f.holdDays} days, then the dates open again.`,
        `To arrange the advance, or for help with a booking, guests call ${f.phone}${wa ? ' or message on WhatsApp' : ''}.`]),
    ]));

    const instructions = lines([
      `You are the virtual assistant of ${f.name} in Diyatalawa, Sri Lanka, answering guests in the chat window on the resort's website.`,
      '- Be warm and brief: two to four short sentences, or a short list.',
      "- Reply in the guest's language (English, Sinhala or Tamil).",
      '- Use only the knowledge base. Starting rates are "from" prices; the exact price and live availability are on the booking page.',
      `- To book, send guests to ${book}, where they choose dates and one or more rooms.`,
      `- If you can't answer, give the phone number ${f.phone}${wa ? ` and WhatsApp ${wa}` : ''}.`,
    ]);
    return { botName: '', instructions, entries };
  }

  // ---------- page ----------
  async function viewChatbot() {
    setHead('Chatbot', 'The chat window on the website, and what Top Mountain\'s bot knows. Changes reach the bot straight away.');
    C = (await api('/chatbot')).chatbot;
    draft = C.brand ? { botName: C.brand.bot_name || '', instructions: C.brand.instructions || '', entries: (C.brand.entries || []).map((e) => ({ ...e })) } : null;
    dirty = false;
    render();
  }

  function render() {
    if (!C.configured) { view.innerHTML = setupCard(); return; }
    if (C.error) { view.innerHTML = `<div class="card"><div class="card__body"><div class="note note--warn">${esc(C.error)}</div>${setupHelp()}</div></div>`; return; }
    if (!C.brand) { view.innerHTML = createCard(); bind(); return; }
    view.innerHTML = `<div class="chat-admin">${webCard()}${botCard()}${testCard()}</div>`;
    bind();
  }

  const setupHelp = () => `<p class="src" style="margin-top:12px">In the website's <code>.env</code>: <code>CHATBOT_URL</code> (the chatbot server, e.g. <code>http://127.0.0.1:5000</code>),
    <code>CHATBOT_SECRET</code> (the same value as the chatbot's <code>CRM_BRIDGE_SHARED_SECRET</code>) and, optionally, <code>CHATBOT_TENANT</code> (default <code>topmountain</code>).</p>`;
  const setupCard = () => `<div class="card"><div class="card__head"><h2>Connect the chatbot</h2></div><div class="card__body">
    <p style="margin:0">The website's chat window uses the Panora chatbot. It isn't connected yet.</p>${setupHelp()}</div></div>`;

  const createCard = () => `<div class="card"><div class="card__head"><h2>Make Top Mountain's bot</h2></div><div class="card__body">
    <p style="margin:0 0 12px">The chatbot is connected (${esc(C.url)}). Make the bot, and it starts with what the website says: stays and starting rates, rooms, facilities, food, things to do, places nearby, contact details, check-in times and how to book. You can change all of it afterwards.</p>
    <button class="abtn abtn--primary" type="button" data-create>${icon('a-plus', 16)}Make the bot</button></div></div>`;

  function webCard() {
    return `<section class="card" data-card="web">
      <div class="card__head"><h2>On the website</h2><span class="src">A chat bubble on the home and booking pages</span></div>
      <form class="card__body grid2" data-form="web">
        <label class="switch full"><input type="checkbox" name="webOn" ${C.webOn ? 'checked' : ''}> Show the chat window on the website</label>
        <label class="fld full"><span>First message <small>(optional)</small></span>
          <input name="greeting" maxlength="300" value="${esc(C.greeting)}" placeholder="Hello! I'm the resort's virtual assistant. Ask me about rooms, rates, food or getting here."></label>
        <div class="full setup__save"><button class="abtn abtn--primary" type="submit">Save</button></div>
      </form>
    </section>`;
  }

  function botCard() {
    const L = limits();
    const used = C.brand.used_this_month;
    const cap = C.brand.monthly_reply_cap;
    return `<section class="card" data-card="bot">
      <div class="card__head"><h2>What the bot knows</h2><span class="src">${used === undefined ? '' : `${num(used)} answers this month${cap === null || cap === undefined ? '' : ` of ${num(cap)}`}`}</span></div>
      <div class="card__body chat-kb">
        <div class="grid2">
          <label class="fld"><span>The bot's name <small>(optional — e.g. “Mila”)</small></span><input name="botName" maxlength="40" value="${esc(draft.botName)}" data-k="botName"></label>
          <p class="src" style="align-self:end;margin:0">With a name, the bot introduces itself by it. It always says it is an AI assistant.</p>
        </div>
        <label class="fld"><span>Instructions <small>how the bot talks and what it does · <b data-count="instructions"></b></small></span>
          <textarea rows="8" data-k="instructions">${esc(draft.instructions)}</textarea></label>
        <div class="chat-kb__head"><b>Knowledge</b> <small class="src" data-count="entries"></small>
          <span class="chat-kb__tools"><button type="button" class="abtn abtn--sm" data-reseed>Fill in again from the website</button></span></div>
        <div data-entries>${draft.entries.map(entryRow).join('')}</div>
        <button type="button" class="abtn abtn--sm" data-add>${icon('a-plus', 15)}Add an entry</button>
        <p class="note note--info" style="margin:14px 0 0">The bot answers only from these instructions and entries. It never sees bookings or live availability, so it sends guests to the booking page for prices on their dates. Safety rules for hotels (no card numbers, no promises of refunds or rooms) always apply.</p>
      </div>
      <div class="card__foot chat-kb__foot"><span class="src" data-count="total"></span><button class="abtn abtn--primary" type="button" data-save>Save what the bot knows</button></div>
    </section>`;
  }

  function entryRow(e, i) {
    const n = draft.entries.length;
    return `<div class="chat-entry" data-i="${i}">
      <div class="chat-entry__top">
        <input class="inp" maxlength="${limits().entry_title_max}" value="${esc(e.title)}" placeholder="Title, e.g. Parking" data-f="title">
        <span class="row-move">
          <button type="button" class="abtn abtn--sm abtn--ghost" data-move="-1" ${i === 0 ? 'disabled' : ''} aria-label="Move up">${icon('a-chevup', 15)}</button>
          <button type="button" class="abtn abtn--sm abtn--ghost" data-move="1" ${i === n - 1 ? 'disabled' : ''} aria-label="Move down">${icon('a-chevdown', 15)}</button>
          <button type="button" class="abtn abtn--sm abtn--ghost" data-del aria-label="Remove this entry">${icon('a-x', 15)}</button></span>
      </div>
      <textarea class="inp" rows="4" maxlength="${limits().entry_max}" placeholder="What the bot should know, in plain sentences." data-f="content">${esc(e.content)}</textarea>
    </div>`;
  }

  function testCard() {
    return `<section class="card" data-card="test">
      <div class="card__head"><h2>Try the bot</h2><span class="src">Answers use what is saved above</span></div>
      <div class="card__body">
        <div class="chat-test" data-log>${testLog.map(bubble).join('') || '<p class="src" style="margin:0">Ask something a guest might ask, e.g. “Do you have parking?” or “How far is Ella?”</p>'}</div>
        <form class="chat-test__form" data-form="test">
          <input class="inp" name="message" maxlength="1000" placeholder="Type a question…" autocomplete="off">
          <button class="abtn abtn--primary" type="submit">Ask</button>
          <button class="abtn" type="button" data-clear-test>Clear</button>
        </form>
      </div>
    </section>`;
  }
  const bubble = (m) => `<div class="chat-test__msg chat-test__msg--${m.role === 'user' ? 'me' : 'bot'}">${esc(m.content).replace(/\n/g, '<br>')}${m.note ? `<small>${esc(m.note)}</small>` : ''}</div>`;

  function counts() {
    const L = limits();
    const t = total();
    const set = (k, text, bad) => { const el = $(`[data-count="${k}"]`, view); if (el) { el.textContent = text; el.classList.toggle('is-over', !!bad); } };
    set('instructions', `${num(draft.instructions.length)} / ${num(L.instructions_max)}`, draft.instructions.length > L.instructions_max);
    set('entries', `${draft.entries.length} of ${L.entries_max} entries`, draft.entries.length > L.entries_max);
    set('total', `${num(t)} of ${num(L.total_max)} characters${dirty ? ' · not saved yet' : ''}`, t > L.total_max);
  }
  const redrawEntries = () => { $('[data-entries]', view).innerHTML = draft.entries.map(entryRow).join(''); counts(); };
  const touch = () => { dirty = true; counts(); };

  function bind() {
    const create = $('[data-create]', view);
    if (create) {
      create.onclick = async () => {
        create.disabled = true;
        try {
          C = (await api('/chatbot/create', { method: 'POST', body: { seed: seed(C.facts) } })).chatbot;
          draft = { botName: C.brand.bot_name || '', instructions: C.brand.instructions || '', entries: (C.brand.entries || []).map((e) => ({ ...e })) };
          toast('The bot is made. Switch the chat window on when you are happy with its answers.');
          render();
        } catch (ex) { toast(ex.message, true); create.disabled = false; }
      };
      return;
    }
    counts();
    // website switch and greeting
    $('[data-form="web"]', view).onsubmit = async (e) => {
      e.preventDefault();
      const f = e.target;
      try {
        C = (await api('/chatbot/web', { method: 'PATCH', body: { webOn: f.webOn.checked, greeting: f.greeting.value } })).chatbot;
        toast(C.webOn ? 'Saved — the chat window is on the website' : 'Saved — the chat window is off');
      } catch (ex) { toast(ex.message, true); }
    };
    // the knowledge base
    const card = $('[data-card="bot"]', view);
    card.addEventListener('input', (e) => {
      const k = e.target.dataset.k;
      if (k) { draft[k] = e.target.value; touch(); return; }
      const row = e.target.closest('[data-i]');
      if (row && e.target.dataset.f) { draft.entries[Number(row.dataset.i)][e.target.dataset.f] = e.target.value; touch(); }
    });
    card.addEventListener('click', (e) => {
      const row = e.target.closest('[data-i]');
      const i = row ? Number(row.dataset.i) : -1;
      if (e.target.closest('[data-add]')) {
        if (draft.entries.length >= limits().entries_max) { toast(`The bot can have up to ${limits().entries_max} entries.`, true); return; }
        draft.entries.push({ title: '', content: '' }); redrawEntries(); touch();
        const rows = $$('[data-i]', view); if (rows.length) $('[data-f="title"]', rows[rows.length - 1]).focus();
      } else if (e.target.closest('[data-del]') && i >= 0) {
        const en = draft.entries[i];
        if ((en.title || en.content) && !confirm(`Remove “${en.title || 'this entry'}”?`)) return;
        draft.entries.splice(i, 1); redrawEntries(); touch();
      } else if (e.target.closest('[data-move]') && i >= 0) {
        const j = i + Number(e.target.closest('[data-move]').dataset.move);
        if (j < 0 || j >= draft.entries.length) return;
        [draft.entries[i], draft.entries[j]] = [draft.entries[j], draft.entries[i]]; redrawEntries(); touch();
      } else if (e.target.closest('[data-reseed]')) {
        if (!confirm('Replace every entry with what the website says now? The instructions and the bot\'s name stay as they are. Nothing is saved until you press Save.')) return;
        draft.entries = seed(C.facts).entries; redrawEntries(); touch();
      } else if (e.target.closest('[data-save]')) {
        save(e.target.closest('[data-save]'));
      }
    });
    // try the bot
    const log = $('[data-log]', view);
    $('[data-form="test"]', view).onsubmit = async (e) => {
      e.preventDefault();
      const input = e.target.message;
      const message = input.value.trim();
      if (!message) return;
      if (dirty) toast('The bot answers from what is saved — save your changes to try them.');
      const history = testLog.map(({ role, content }) => ({ role, content }));
      testLog.push({ role: 'user', content: message });
      input.value = '';
      log.innerHTML = testLog.map(bubble).join('') + '<div class="chat-test__msg chat-test__msg--bot is-typing">…</div>';
      log.scrollTop = log.scrollHeight;
      try {
        const r = await api('/chatbot/test', { method: 'POST', body: { message, history } });
        const note = r.escalate ? `Would hand over to the team: ${r.escalate}` : r.capped ? 'The bot\'s monthly allowance is used up.' : '';
        if (r.reply === null) testLog.push({ role: 'assistant', content: '(no reply needed)', note });
        else (r.parts || [r.reply]).forEach((p, k, all) => testLog.push({ role: 'assistant', content: p, note: k === all.length - 1 ? note : '' }));
      } catch (ex) {
        testLog.push({ role: 'assistant', content: `⚠ ${ex.message}` });
      }
      log.innerHTML = testLog.map(bubble).join('');
      log.scrollTop = log.scrollHeight;
    };
    $('[data-clear-test]', view).onclick = () => { testLog = []; log.innerHTML = '<p class="src" style="margin:0">Cleared.</p>'; };
  }

  async function save(btn) {
    const L = limits();
    const blank = draft.entries.findIndex((e) => !e.title.trim() !== !e.content.trim());
    if (blank >= 0) { toast('Every entry needs both a title and some content (or remove it).', true); return; }
    if (total() > L.total_max) { toast(`That is ${num(total())} characters — the bot can hold ${num(L.total_max)}. Shorten it a little.`, true); return; }
    btn.disabled = true;
    try {
      C = (await api('/chatbot/kb', { method: 'PUT', body: { botName: draft.botName, instructions: draft.instructions, entries: draft.entries.filter((e) => e.title.trim() || e.content.trim()) } })).chatbot;
      draft = { botName: C.brand.bot_name || '', instructions: C.brand.instructions || '', entries: (C.brand.entries || []).map((e) => ({ ...e })) };
      dirty = false;
      redrawEntries();
      const nameIn = $('[data-k="botName"]', view); if (nameIn) nameIn.value = draft.botName;
      toast('Saved — the bot uses it straight away');
    } catch (ex) { toast(ex.message, true); }
    btn.disabled = false;
  }

  // leaving with unsaved changes
  window.addEventListener('hashchange', () => { if (dirty && !location.hash.startsWith('#/chatbot')) { toast('The chatbot changes were not saved.', true); dirty = false; } });

  return { chatbot: viewChatbot };
};
