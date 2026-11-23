/* "Check your booking" (home page hero): a guest types the reference from their confirmation and the
   phone number or email they booked with, and is taken to their private invoice page, where they can see
   the booking, download it as a PDF, and send the payment slip. Server side: POST /api/booking-lookup (App.php). */
(function () {
  'use strict';
  const openers = document.querySelectorAll('[data-lookup-open]');
  if (!openers.length) return;

  const dlg = document.createElement('dialog');
  dlg.className = 'lookup';
  dlg.setAttribute('aria-labelledby', 'lookup-title');
  dlg.innerHTML = `
    <form class="lookup__form" novalidate>
      <button type="button" class="lookup__x" data-lookup-close aria-label="Close">&times;</button>
      <p class="lookup__kicker">Your reservation</p>
      <h2 id="lookup-title">Check your booking</h2>
      <p class="lookup__lede">Enter your booking reference, or the mobile number you booked with.</p>
      <label>Booking reference or mobile number
        <input name="query" autocomplete="off" spellcheck="false" placeholder="TMR-XXXXXX or 07x xxx xxxx" required>
      </label>
      <input name="website" tabindex="-1" autocomplete="off" aria-hidden="true" class="lookup__hp">
      <p class="lookup__msg" role="alert" data-lookup-msg></p>
      <ul class="lookup__list" data-lookup-list hidden></ul>
      <button class="btn btn--solid lookup__go" type="submit">Find my booking</button>
    </form>`;
  document.body.appendChild(dlg);

  const form = dlg.querySelector('form');
  const msg = dlg.querySelector('[data-lookup-msg]');
  const go = dlg.querySelector('.lookup__go');

  openers.forEach((o) => o.addEventListener('click', () => {
    msg.textContent = '';
    dlg.showModal();
    form.ref.focus();
  }));
  dlg.addEventListener('click', (e) => { if (e.target === dlg || e.target.closest('[data-lookup-close]')) dlg.close(); });

  const list = dlg.querySelector('[data-lookup-list]');
  const esc = (v) => String(v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const day = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  const path = (u) => new URL(u, location.href).pathname;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.textContent = '';
    list.hidden = true; list.innerHTML = '';
    const query = form.query.value.trim();
    if (!query) { msg.textContent = 'Please enter your booking reference or mobile number.'; form.query.focus(); return; }
    go.disabled = true; go.textContent = 'Looking…';
    try {
      const res = await fetch('/api/booking-lookup', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ query, website: form.website.value }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.bookings) throw new Error(data.error || 'Something went wrong. Please try again or call us.');
      if (data.bookings.length === 1) { location.href = path(data.bookings[0].invoiceUrl); return; }
      list.innerHTML = data.bookings.map((b) => `<li><a href="${esc(path(b.invoiceUrl))}"><b>${esc(b.ref)}</b><span>${esc(b.what)} · ${day(b.checkIn)}${b.checkOut !== b.checkIn ? ` → ${day(b.checkOut)}` : ''}</span><i>${esc(b.status.replace('_', ' '))}</i></a></li>`).join('');
      list.hidden = false;
      msg.textContent = 'These bookings use that number. Choose yours:';
      msg.className = 'lookup__msg lookup__msg--info';
    } catch (ex) {
      msg.className = 'lookup__msg';
      msg.textContent = ex.message;
    }
    go.disabled = false; go.textContent = 'Find my booking';
  });
})();
