/* The website's chat window: a bubble that opens a small chat with Top Mountain's bot.
   Questions go to /api/chat (app/Chatbot.php → the Panora chatbot); the browser never talks to the
   chatbot itself. The conversation is kept for this visit only (sessionStorage), so it follows the
   guest from the home page to the booking page. Shown only when Admin → Chatbot switches it on. */
(function () {
  'use strict';
  const BOOT = window.BOOT || {};
  const CHAT = BOOT.chat || {};
  if (!CHAT.on) return;
  const site = BOOT.site || {};
  const KEY = 'tmr-chat';
  const MAX_KEEP = 30; // messages kept for the visit (the server sends the last 20 to the bot)
  const name = CHAT.botName || '';
  const greeting = CHAT.greeting || `Hello! I'm ${name ? `${name}, ` : ''}the virtual assistant of ${site.name || 'the resort'}. Ask me about rooms, rates, food, things to do or getting here.`;
  const tel = String(site.phone || '').replace(/[^\d+]/g, '');
  const wa = String(site.whatsapp || '').replace(/\D/g, '');
  const SUGGEST = ['What are your room rates?', 'What time is check-in?', 'How do I get there?', 'What is there to do nearby?'];

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  /** The bot writes WhatsApp-style (*bold*, _italic_, plain links): show it as HTML, safely. */
  function format(text) {
    let h = esc(text);
    h = h.replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/g, (url) => {
      let same = false;
      try { same = new URL(url.replace(/&amp;/g, '&')).origin === location.origin; } catch (_) { /* not a URL */ }
      return `<a href="${url}"${same ? '' : ' target="_blank" rel="noopener"'}>${url.replace(/^https?:\/\//, '')}</a>`;
    });
    h = h.replace(/(^|[\s(])\*([^*\n]+)\*(?=[\s.,;:!?)]|$)/g, '$1<b>$2</b>').replace(/(^|[\s(])_([^_\n]+)_(?=[\s.,;:!?)]|$)/g, '$1<i>$2</i>');
    return h.replace(/\n/g, '<br>');
  }

  let log = [];
  try { log = JSON.parse(sessionStorage.getItem(KEY) || '[]').filter((m) => m && (m.role === 'user' || m.role === 'assistant') && typeof m.content === 'string'); } catch (_) { log = []; }
  const keep = () => { try { sessionStorage.setItem(KEY, JSON.stringify(log.slice(-MAX_KEEP))); } catch (_) { /* private mode */ } };

  // ---------- markup ----------
  const root = document.createElement('div');
  root.className = 'tchat';
  root.innerHTML = `
    <button type="button" class="tchat__btn" aria-expanded="false" aria-controls="tchat-panel" aria-label="Chat with us">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v9a1.5 1.5 0 0 1-1.5 1.5H10l-4.5 4v-4A1.5 1.5 0 0 1 4 14.5z"/><path d="M8.5 9.5h7M8.5 12.5h4"/></svg>
      <span class="tchat__btnlabel">Ask us</span>
    </button>
    <section class="tchat__panel" id="tchat-panel" role="dialog" aria-label="Chat with ${esc(site.name || 'us')}" hidden>
      <header class="tchat__head">
        <div><b>${esc(name || site.name || 'Chat with us')}</b><small>Virtual assistant · AI</small></div>
        <button type="button" class="tchat__close" aria-label="Close the chat"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
      </header>
      <div class="tchat__log" aria-live="polite"></div>
      <div class="tchat__suggest"></div>
      <form class="tchat__form">
        <label class="sr-only" for="tchat-input">Your message</label>
        <input id="tchat-input" name="message" maxlength="1000" autocomplete="off" placeholder="Type your question…">
        <input name="website" tabindex="-1" autocomplete="off" class="tchat__hp" aria-hidden="true">
        <button type="submit" aria-label="Send"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h15m-6-6 6 6-6 6"/></svg></button>
      </form>
      <footer class="tchat__foot">
        <span>Book on the <a href="/book">booking page</a></span>
        ${tel ? `<a href="tel:${esc(tel)}">Call</a>` : ''}${wa ? `<a href="https://wa.me/${esc(wa)}" target="_blank" rel="noopener">WhatsApp</a>` : ''}
      </footer>
    </section>`;
  document.body.appendChild(root);
  const btn = root.querySelector('.tchat__btn');
  const panel = root.querySelector('.tchat__panel');
  const box = root.querySelector('.tchat__log');
  const form = root.querySelector('.tchat__form');
  const input = form.message;
  const suggest = root.querySelector('.tchat__suggest');

  function draw(typing = false) {
    const msgs = [{ role: 'assistant', content: greeting }, ...log];
    box.innerHTML = msgs.map((m) => `<div class="tchat__msg tchat__msg--${m.role === 'user' ? 'me' : 'bot'}${m.error ? ' is-error' : ''}">${m.role === 'user' ? esc(m.content) : format(m.content)}</div>`).join('')
      + (typing ? '<div class="tchat__msg tchat__msg--bot is-typing" aria-label="Typing"><i></i><i></i><i></i></div>' : '');
    suggest.innerHTML = log.length ? '' : SUGGEST.map((s) => `<button type="button">${esc(s)}</button>`).join('');
    box.scrollTop = box.scrollHeight;
  }

  function open(on) {
    panel.hidden = !on;
    btn.setAttribute('aria-expanded', String(on));
    root.classList.toggle('is-open', on);
    if (on) { draw(); setTimeout(() => input.focus(), 60); } else btn.focus();
  }
  btn.addEventListener('click', () => open(panel.hidden));
  root.querySelector('.tchat__close').addEventListener('click', () => open(false));
  panel.addEventListener('keydown', (e) => { if (e.key === 'Escape') open(false); });
  suggest.addEventListener('click', (e) => { const b = e.target.closest('button'); if (b) send(b.textContent); });

  let busy = false;
  async function send(text) {
    const message = String(text || '').trim();
    if (!message || busy) return;
    busy = true;
    const history = log.filter((m) => !m.error).map(({ role, content }) => ({ role, content }));
    log.push({ role: 'user', content: message });
    keep();
    draw(true);
    try {
      const r = await fetch('/api/chat', {
        method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ message, history, website: form.website.value }),
      });
      const data = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(data.error || 'error');
      if (data.reply) (data.parts && data.parts.length ? data.parts : [data.reply]).forEach((p) => log.push({ role: 'assistant', content: p }));
    } catch (err) {
      const reach = [tel ? `call ${site.phone}` : '', wa ? 'message us on WhatsApp' : ''].filter(Boolean).join(' or ');
      log.push({ role: 'assistant', error: true, content: /too many/i.test(err.message) ? err.message
        : `Sorry, I can't answer just now.${reach ? ` Please ${reach}, and the team will help.` : ''}` });
    }
    keep();
    busy = false;
    draw();
  }
  form.addEventListener('submit', (e) => { e.preventDefault(); const t = input.value; input.value = ''; send(t); });

  // a guest coming back in the same visit finds the chat as they left it (closed)
  if (log.length) root.classList.add('has-chat');
})();
