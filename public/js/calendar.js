/* RangeCalendar — a dependency-free check-in / check-out picker.
   `unavailable` is a Set of YYYY-MM-DD *nights* that are taken. A night that is
   taken can still be chosen as a check-out date (guests leave in the morning).
   With `single: true` it picks one day instead (checkIn only, e.g. a day out). */
(function () {
  'use strict';
  const DOW = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
  const MAX_NIGHTS = 30;

  const pad = (n) => String(n).padStart(2, '0');
  const iso = (y, m, d) => `${y}-${pad(m + 1)}-${pad(d)}`;
  const toUTC = (s) => { const [y, m, d] = s.split('-').map(Number); return Date.UTC(y, m - 1, d); };
  const addDays = (s, n) => new Date(toUTC(s) + n * 86400000).toISOString().slice(0, 10);
  const diff = (a, b) => Math.round((toUTC(b) - toUTC(a)) / 86400000);

  class RangeCalendar {
    constructor(el, opts = {}) {
      this.el = el;
      this.months = opts.months || 2;
      this.minDate = opts.minDate || new Date().toISOString().slice(0, 10);
      this.maxDate = opts.maxDate || addDays(this.minDate, 540);
      this.unavailable = opts.unavailable || new Set();
      this.onChange = opts.onChange || (() => {});
      this.onMonthChange = opts.onMonthChange || null;
      this.single = !!opts.single;
      this.checkIn = opts.checkIn || null;
      this.checkOut = this.single ? null : opts.checkOut || null;
      this.hover = null;
      const start = this.checkIn || this.minDate;
      this.viewY = Number(start.slice(0, 4));
      this.viewM = Number(start.slice(5, 7)) - 1;
      this.el.classList.add('cal');
      this.el.addEventListener('click', (e) => this.handleClick(e));
      this.el.addEventListener('mouseover', (e) => {
        const b = e.target.closest('[data-date]');
        const h = b ? b.dataset.date : null;
        if (h !== this.hover && this.selectingCheckout()) { this.hover = h; this.paint(); }
      });
      this.el.addEventListener('mouseleave', () => { if (this.hover) { this.hover = null; this.paint(); } });
      this.render();
    }

    setMonths(n) { if (n !== this.months) { this.months = n; this.render(); } }
    setUnavailable(set) { this.unavailable = set; this.render(); }
    setRange(a, b) { this.checkIn = a || null; this.checkOut = this.single ? null : b || null; this.render(); }
    setSingle(on) { this.single = !!on; if (this.single) this.checkOut = null; this.hover = null; this.render(); }
    clear() { this.setRange(null, null); this.onChange(this.value()); }
    value() { return { checkIn: this.checkIn, checkOut: this.checkOut, nights: this.checkIn && this.checkOut ? diff(this.checkIn, this.checkOut) : 0 }; }

    /** First taken night on/after `from` (exclusive upper bound for a checkout). */
    firstBlockedAfter(from) {
      let best = null;
      for (const n of this.unavailable) if (n >= from && (!best || n < best)) best = n;
      return best;
    }

    selectingCheckout() { return !this.single && this.checkIn && !this.checkOut; }

    dayState(d) {
      const taken = this.unavailable.has(d);
      let disabled = d < this.minDate || d > this.maxDate;
      if (!disabled && this.selectingCheckout()) {
        if (d > this.checkIn) {
          const block = this.firstBlockedAfter(this.checkIn);
          disabled = (block && d > block) || diff(this.checkIn, d) > MAX_NIGHTS;
          // dates beyond reach can still be clicked to restart — if their own night is free
          if (disabled && !taken) disabled = false;
        } else if (taken) disabled = true;
      } else if (!disabled && taken) disabled = true;
      return { taken, disabled };
    }

    handleClick(e) {
      const nav = e.target.closest('[data-nav]');
      if (nav) {
        const dir = Number(nav.dataset.nav);
        this.viewM += dir;
        if (this.viewM < 0) { this.viewM = 11; this.viewY--; }
        if (this.viewM > 11) { this.viewM = 0; this.viewY++; }
        this.render();
        if (this.onMonthChange) this.onMonthChange(this.visibleRange());
        return;
      }
      const btn = e.target.closest('[data-date]');
      if (!btn || btn.disabled) return;
      const d = btn.dataset.date;
      if (this.selectingCheckout() && d > this.checkIn) {
        const block = this.firstBlockedAfter(this.checkIn);
        const ok = (!block || d <= block) && diff(this.checkIn, d) <= MAX_NIGHTS;
        if (ok) { this.checkOut = d; this.hover = null; this.render(); this.onChange(this.value()); return; }
      }
      if (this.unavailable.has(d)) { btn.classList.remove('shake'); void btn.offsetWidth; btn.classList.add('shake'); return; }
      this.checkIn = d; this.checkOut = null; this.hover = null;
      this.render();
      this.onChange(this.value());
    }

    visibleRange() {
      const from = iso(this.viewY, this.viewM, 1);
      const end = new Date(Date.UTC(this.viewY, this.viewM + this.months, 1)).toISOString().slice(0, 10);
      return { from, to: end };
    }

    render() {
      const minY = Number(this.minDate.slice(0, 4)), minM = Number(this.minDate.slice(5, 7)) - 1;
      const canPrev = this.viewY > minY || (this.viewY === minY && this.viewM > minM);
      const lastVisible = new Date(Date.UTC(this.viewY, this.viewM + this.months, 1)).toISOString().slice(0, 10);
      const canNext = lastVisible <= this.maxDate;
      let html = `<div class="cal__head">
        <button type="button" class="cal__nav" data-nav="-1" aria-label="Previous month" ${canPrev ? '' : 'disabled'}><svg width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="m15 18-6-6 6-6"/></svg></button>
        <button type="button" class="cal__nav" data-nav="1" aria-label="Next month" ${canNext ? '' : 'disabled'}><svg width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="m9 18 6-6-6-6"/></svg></button>
      </div><div class="cal__months" style="--months:${this.months}">`;
      const today = this.minDate;
      for (let k = 0; k < this.months; k++) {
        const dt = new Date(Date.UTC(this.viewY, this.viewM + k, 1));
        const y = dt.getUTCFullYear(), m = dt.getUTCMonth();
        const title = dt.toLocaleDateString('en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' });
        const first = (dt.getUTCDay() + 6) % 7; // Monday-first
        const days = new Date(Date.UTC(y, m + 1, 0)).getUTCDate();
        html += `<div><p class="cal__title">${title}</p><div class="cal__grid" role="grid">`;
        html += DOW.map((d) => `<span class="cal__dow">${d}</span>`).join('');
        for (let i = 0; i < first; i++) html += '<span class="cal__day is-blank"></span>';
        for (let d = 1; d <= days; d++) {
          const s = iso(y, m, d);
          html += `<button type="button" class="cal__day${s === today ? ' is-today' : ''}" data-date="${s}" aria-label="${new Date(toUTC(s)).toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' })}">${d}</button>`;
        }
        html += '</div></div>';
      }
      html += '</div><div class="cal__legend"><span><i style="background:var(--forest-800)"></i>Selected</span><span><i style="background:rgba(143,191,90,.35)"></i>Your stay</span><span><i style="background:transparent;border:1px solid rgba(217,130,43,.7)"></i><s>12</s>&nbsp;Booked</span></div>';
      this.el.innerHTML = html;
      this.paint();
    }

    paint() {
      let end = this.checkOut;
      if (!end && this.selectingCheckout() && this.hover > this.checkIn) {
        const block = this.firstBlockedAfter(this.checkIn);
        if ((!block || this.hover <= block) && diff(this.checkIn, this.hover) <= MAX_NIGHTS) end = this.hover;
      }
      this.el.querySelectorAll('[data-date]').forEach((b) => {
        const d = b.dataset.date;
        const { taken, disabled } = this.dayState(d);
        b.disabled = disabled;
        b.classList.toggle('is-taken', taken);
        b.classList.toggle('is-start', d === this.checkIn);
        b.classList.toggle('has-end', d === this.checkIn && !!end);
        b.classList.toggle('is-end', !!end && d === end);
        b.classList.toggle('in-range', !!(this.checkIn && end && d > this.checkIn && d < end));
        b.setAttribute('aria-pressed', String(d === this.checkIn || d === this.checkOut));
      });
    }
  }

  window.RangeCalendar = RangeCalendar;
  window.RangeCalendar.addDays = addDays;
  window.RangeCalendar.diff = diff;
})();
