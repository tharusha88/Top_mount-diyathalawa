/* The guest's confirmation page (/confirm/<ref>/<code>, app/Confirmations.php): a booking staff
   made, sent to the guest to check, complete and confirm. It shows the stay and its price, the
   guest's details (what staff filled in already, with what is still needed marked), and after
   confirming, how to pay for a reservation and the invoice. */
(function () {
  'use strict';
  window.YD.boot();
  const { $, $$, esc, BOOT, site, money, fmtDate, imgTag, guestsLabel } = window.YD;
  let C = BOOT.confirm;
  if (!C) return;

  const long = { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' };
  const tel = (s) => String(s || '').replace(/[^\d+]/g, '');
  const wa = String(site.whatsapp || '').replace(/\D/g, '');
  const waUrl = (text) => (wa ? `https://wa.me/${wa}?text=${encodeURIComponent(text)}` : '');
  const plural = (n, one, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;
  const isDay = () => C.kind === 'day_out' || C.kind === 'event';
  const alertBox = $('[data-alert]');
  const showAlert = (msg, info = false) => {
    if (!msg) { alertBox.hidden = true; return; }
    alertBox.textContent = msg; alertBox.hidden = false; alertBox.classList.toggle('is-info', info);
    alertBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };
  const LABELS = { guestName: 'your name', guestIdNo: 'your NIC or passport number', guestEmail: 'your email', guestPhone: 'your phone number' };

  // ---------- the summary ----------
  function renderSummary() {
    $('[data-cf-eyebrow]').textContent = `Booking ${C.ref}`;
    $('[data-sum-eyebrow]').textContent = C.kind === 'day_out' ? 'Your visit' : C.kind === 'event' ? 'Your party' : 'Your stay';
    $('[data-sum-room]').textContent = C.kind !== 'room' ? C.what : C.wholeCategory || (C.roomCount > 1 ? plural(C.roomCount, 'room') : C.what);
    const media = $('[data-sum-media]');
    if (C.image && BOOT.media && BOOT.media[C.image]) media.innerHTML = imgTag(C.image, { sizes: '360px', alt: C.what, eager: true });
    const rows = [];
    if (C.kind === 'room') {
      if (C.wholeCategory || C.roomCount > 1) rows.push([C.roomCount > 1 ? 'Rooms' : 'Room', esc(C.what)]);
      if (C.mealPlanLabel) rows.push(['Meals', esc(C.mealPlanLabel)]);
    } else if (C.kind === 'per_person') {
      rows.push(['Booking', esc(C.what)]);
    }
    if (isDay()) {
      rows.push(['Date', esc(fmtDate(C.checkIn, long))]);
      if (C.time) rows.push(['Time', esc(C.time)]);
    } else {
      rows.push(['Check-in', esc(fmtDate(C.checkIn, long))]);
      rows.push(['Check-out', esc(fmtDate(C.checkOut, long))]);
      rows.push(['Nights', String(C.nights)]);
    }
    rows.push(['Guests', C.adults + C.children > 0 ? esc(guestsLabel(C.adults, C.children)) : `Sleeps ${C.capacity}`]);
    if (C.extras.length) rows.push(['Extras', esc(C.extras.join(', '))]);
    $('[data-sum-dl]').innerHTML = rows.map(([k, v]) => `<div><dt>${k}</dt><dd>${v}</dd></div>`).join('');
    $('[data-sum-lines]').innerHTML = C.lines.map((l) => `<div class="sum__line"><span>${esc(l.label)}${l.detail ? `<small>${esc(l.detail)}</small>` : ''}</span><span>${money(l.amount)}</span></div>`).join('')
      + (C.couponDiscount ? `<div class="sum__line sum__line--off"><span>Coupon ${esc(C.couponCode)}</span><span>− ${money(C.couponDiscount)}</span></div>` : '');
    $('[data-sum-total]').textContent = money(C.total);
    const due = $('[data-sum-due]');
    const open = ['pending', 'confirmed'].includes(C.status);
    due.hidden = !open;
    if (open) {
      due.innerHTML = C.atResort
        ? '<span>Nothing to pay now</span><b>Pay at the resort</b>'
        : `${C.paid ? `<span>Paid so far</span><b>${money(C.paid)}</b>` : ''}<span>${esc(C.dueLabel)}</span><b>${money(C.due)}</b>`;
    }
    const sv = $('[data-sum-service]');
    sv.hidden = !C.servicePct;
    if (C.servicePct) sv.textContent = `A ${C.servicePct}% service charge is added to the final bill at the resort. Food and drinks during the stay are billed separately.`;
    $('[data-sum-times]').hidden = isDay();
  }

  // ---------- the form ----------
  const form = $('[data-form]');
  function renderForm() {
    $$('[data-f]', form).forEach((box) => {
      const k = box.dataset.f;
      box.hidden = !C.fields.includes(k);
      const input = form.elements[k];
      if (!input || box.hidden) return;
      input.value = C.guest[k] || '';
      // what staff couldn't fill in: marked, so the guest sees what is asked of them
      const need = C.missing.includes(k);
      box.classList.toggle('is-needed', need);
      const label = $('label', box);
      if (need && !$('.cf-need', label)) label.insertAdjacentHTML('beforeend', ' <span class="cf-need">Needed</span>');
    });
    const n = C.missing.length;
    const asks = C.missing.map((k) => LABELS[k]).filter(Boolean);
    const list = asks.length > 1 ? `${asks.slice(0, -1).join(', ')} and ${asks[asks.length - 1]}` : asks[0] || '';
    $('[data-cf-intro]').innerHTML = n
      ? `We've prepared your booking. Please check it, add ${esc(list)} (marked <span class="cf-need">Needed</span> below), and confirm.`
      : 'We\'ve prepared your booking. Please check your details below and the stay on the right, then confirm.';
    $('[data-cf-agree]').textContent = 'I have checked the booking and the details are correct'
      + (C.status !== 'pending' ? '.' : C.atResort ? `. There is nothing to pay now: ${site.name} calls me to confirm it, and I pay at the resort.`
        : `. I understand it is confirmed once ${site.name} receives the advance payment.`);
    const fix = $('[data-cf-wa]');
    if (wa) fix.href = waUrl(`Hello ${site.name}! About my booking ${C.ref}: `); else fix.remove();
  }

  function validate() {
    const v = (k) => (form.elements[k] ? form.elements[k].value.trim() : '');
    const bad = [];
    if (!v('guestName')) bad.push(['guestName', 'Please enter your full name.']);
    if (v('guestIdNo').replace(/[^a-z0-9]/gi, '').length < 5) bad.push(['guestIdNo', 'Please enter your NIC or passport number.']);
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v('guestEmail'))) bad.push(['guestEmail', 'Please enter a valid email address.']);
    if (v('guestPhone').replace(/\D/g, '').length < 7) bad.push(['guestPhone', 'Please enter a valid phone number.']);
    $$('.field', form).forEach((f) => f.classList.remove('has-error'));
    bad.forEach(([k]) => { const f = form.elements[k] && form.elements[k].closest('.field'); if (f) f.classList.add('has-error'); });
    const agree = form.elements.agree;
    agree.closest('.check').classList.toggle('has-error', !agree.checked);
    if (bad.length) { form.elements[bad[0][0]].focus(); return bad[0][1]; }
    if (!agree.checked) return 'Please tick the box to confirm the booking details.';
    return '';
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = validate();
    if (err) { showAlert(err); return; }
    showAlert('');
    const body = { agree: true, website: form.elements.website.value };
    C.fields.forEach((k) => { if (form.elements[k]) body[k] = form.elements[k].value; });
    const btn = $('[data-submit]');
    btn.disabled = true;
    btn.classList.add('is-busy');
    try {
      const res = await fetch(C.api, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), credentials: 'same-origin' });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || 'Something went wrong — please try again, or call us.');
      C = Object.assign(data.confirm, { api: C.api });
      show(true);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (ex) {
      showAlert(ex.message || 'Could not reach us — please check your connection and try again.');
      btn.disabled = false;
      btn.classList.remove('is-busy');
    }
  });

  // ---------- after confirming ----------
  /** The "next step" card (as on the booking page's done step): how to pay, or that there is nothing
   *  to pay now, with Call and WhatsApp buttons. Empty once there is nothing left to do before arrival. */
  function payHtml() {
    const P = C.pay || {};
    if (C.status !== 'pending' || (!C.atResort && !(C.due > 0))) return '';
    const phoneNo = P.phone || site.phone || '';
    const reach = wa ? 'call or WhatsApp us' : 'call us';
    const hasBank = !C.atResort && !!P.accountNo;
    const isAdv = !C.atResort && C.advance > 0 && C.paid < C.advance; // an advance, not the whole amount
    const what = isAdv ? 'the advance' : 'the payment';
    const by = C.holdUntil ? ` by <b>${esc(fmtDate(C.holdUntil, long))}</b>` : '';
    const rows = C.atResort
      ? [['Total, paid at the resort', `<b>${money(C.total)}</b>`, 'is-amount']]
      : [
        [`${esc(C.dueLabel)}${isAdv ? `<small>of ${money(C.total)}</small>` : ''}`, `<b>${money(C.due)}</b>`, 'is-amount'],
        hasBank && P.bank ? ['Bank', esc(P.bank)] : null,
        hasBank && P.accountName ? ['Account name', esc(P.accountName)] : null,
        hasBank ? ['Account number', `<b class="bk-pay__no">${esc(P.accountNo)}</b> <button type="button" class="linkish" data-copy="${esc(P.accountNo)}">Copy</button>`] : null,
        hasBank && P.branch ? ['Branch', esc(P.branch)] : null,
        hasBank ? ['Payment reference', `<b>${esc(C.ref)}</b>`] : null, // to write on the transfer
      ];
    const title = C.atResort ? 'Nothing to pay now' : isAdv ? 'Pay the advance to confirm' : 'Pay to confirm your booking';
    const text = C.atResort ? 'You pay at the resort. We will call you to finalise your booking.'
      : hasBank ? `Pay ${what}${by} to the account below, then ${reach}. We confirm your booking as soon as it is received.`
        : `${reach[0].toUpperCase()}${reach.slice(1)}${by} to arrange ${what}. We confirm your booking as soon as it is received.`;
    const buttons = [
      phoneNo ? `<a class="btn btn--sm" href="tel:${esc(tel(phoneNo))}">Call ${esc(phoneNo)}</a>` : '',
      wa ? `<a class="btn btn--sm btn--ghost" href="${esc(waUrl(`Hello ${site.name}! My booking reference is ${C.ref}.`))}" target="_blank" rel="noopener"><svg width="16" height="16" aria-hidden="true"><use href="#i-wa"/></svg>WhatsApp us</a>` : '',
    ].join('');
    return `<p class="eyebrow">${C.atResort ? 'Payment' : 'Next step'}</p>
      <h3 class="bk-pay__title">${title}</h3>
      <p>${text}</p>
      <dl class="bk-paylist">${rows.filter(Boolean).map(([k, v, cls]) => `<div${cls ? ` class="${cls}"` : ''}><dt>${k}</dt><dd>${v}</dd></div>`).join('')}</dl>
      ${P.note ? `<p class="bk-pay__note">${esc(P.note)}</p>` : ''}
      ${buttons ? `<div class="bk-pay__actions">${buttons}</div>` : ''}`;
  }

  function renderDone(justNow) {
    const first = String(C.guest.guestName || '').trim().split(/\s+/)[0];
    $('[data-done-title]').textContent = `Thank you${first ? `, ${first}` : ''}!`;
    const when = C.confirmedAt ? new Date(C.confirmedAt).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) : '';
    const earlier = !justNow && when ? `You confirmed this booking on ${when}. ` : '';
    const text = C.status === 'confirmed' || C.status === 'checked_in' ? 'Your booking is confirmed. We can\'t wait to welcome you to the hills.'
      : C.atResort ? 'Thank you for confirming your booking.'
        : `Thank you for confirming. ${C.holdUntil ? `We're holding your booking until ${fmtDate(C.holdUntil, long)}: it` : 'It'} is confirmed once the advance is received.`;
    $('[data-done-text]').textContent = earlier + text + (justNow && C.guest.guestEmail ? ` We've emailed the details to ${C.guest.guestEmail}.` : '');
    $('[data-done-ref]').textContent = C.ref;
    $('[data-done-ref-copy]').dataset.copy = C.ref;
    const pay = payHtml();
    $('[data-done-pay]').innerHTML = pay;
    $('[data-done-invoice]').href = C.invoiceUrl;
    // WhatsApp sits in the card while there is something to pay; otherwise it joins the links
    const w = $('[data-done-wa]');
    w.hidden = !wa || !!pay;
    if (wa) w.href = waUrl(`Hello ${site.name}! My booking reference is ${C.ref}.`);
  }

  function renderClosed() {
    const cancelled = C.status === 'cancelled';
    $('[data-closed-title]').textContent = cancelled ? 'This booking has been cancelled' : C.status === 'checked_in' ? 'Welcome — your stay has begun' : 'Thank you for staying with us';
    $('[data-closed-text]').textContent = cancelled
      ? `Booking ${C.ref} is no longer held. If this is unexpected, or you'd like to choose new dates, please call or WhatsApp us.`
      : `Booking ${C.ref} can't be changed here any more. For anything you need, please call or WhatsApp us.`;
  }

  function show(justNow = false) {
    renderSummary();
    $$('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== C.state; });
    if (C.state === 'open') renderForm();
    else if (C.state === 'confirmed') renderDone(justNow);
    else renderClosed();
  }

  document.addEventListener('click', (e) => {
    const c = e.target.closest('[data-copy]');
    if (!c) return;
    navigator.clipboard?.writeText(c.dataset.copy).then(() => { c.textContent = 'Copied'; setTimeout(() => { c.textContent = 'Copy'; }, 1500); }).catch(() => {});
  });
  $('[data-cf-policy]').innerHTML = C.policyHtml || ''; // escaped on the server (App::policySection)
  show();
})();
