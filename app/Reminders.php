<?php
// Booking reminders, by email to the guest:
//   arrival: a confirmed booking, a few days before check-in (Settings: reminder_arrival_days)
//   payment: a pending reservation, a few days before its hold date, while no payment is
//            recorded (Settings: reminder_payment_days)
// Each reminder is sent once per booking (table booking_reminders). Run it from a daily cron
// job (scripts/send-reminders.php); as a fallback the website also runs it, at most once an
// hour, after answering a visitor, so reminders still go out on hosting without cron.
declare(strict_types=1);

namespace Ydr;

final class Reminders
{
    public static function enabled(): bool
    {
        return Settings::get('reminders_on') !== '0';
    }

    /**
     * Bookings that need a reminder today: [['kind' => 'arrival'|'payment', 'booking' => array, 'daysLeft' => int], ...].
     * Nothing is sent or recorded here.
     */
    public static function due(): array
    {
        $today = Bookings::today();
        $out = [];
        $arrivalDays = max(0, Settings::int('reminder_arrival_days'));
        if ($arrivalDays > 0) {
            $rows = Db::all("SELECT b.id, b.check_in FROM bookings b
                WHERE b.status = 'confirmed' AND b.source <> 'block' AND b.guest_email <> ''
                AND b.check_in >= ? AND b.check_in <= ?
                AND NOT EXISTS (SELECT 1 FROM booking_reminders r WHERE r.booking_id = b.id AND r.kind = 'arrival')
                ORDER BY b.check_in, b.id", [$today, Bookings::addDays($today, $arrivalDays)]);
            foreach ($rows as $r) $out[] = ['kind' => 'arrival', 'id' => (int) $r['id'], 'daysLeft' => self::daysBetween($today, (string) $r['check_in'])];
        }
        $payDays = max(0, Settings::int('reminder_payment_days'));
        if ($payDays > 0) {
            $rows = Db::all("SELECT b.id, b.hold_until FROM bookings b
                WHERE b.status = 'pending' AND b.source <> 'block' AND b.guest_email <> ''
                AND b.hold_until IS NOT NULL AND b.hold_until >= ? AND b.hold_until <= ?
                AND " . Bookings::advanceRequiredSql() . "
                AND NOT EXISTS (SELECT 1 FROM pos_payments p WHERE p.booking_id = b.id AND p.voided_at IS NULL)
                AND NOT EXISTS (SELECT 1 FROM booking_reminders r WHERE r.booking_id = b.id AND r.kind = 'payment')
                ORDER BY b.hold_until, b.id", [$today, Bookings::addDays($today, $payDays)]);
            foreach ($rows as $r) $out[] = ['kind' => 'payment', 'id' => (int) $r['id'], 'daysLeft' => self::daysBetween($today, (string) $r['hold_until'])];
        }
        return $out;
    }

    /**
     * Queue the emails that are due (the caller flushes the mail queue) and record them.
     * Returns the number sent per kind: ['arrival' => n, 'payment' => n].
     */
    public static function run(bool $force = false): array
    {
        $sent = ['arrival' => 0, 'payment' => 0];
        if (!$force && !self::enabled()) return $sent;
        foreach (self::due() as $d) {
            // claim it first: the unique key means two runs at once can't both send it
            try {
                Db::run('INSERT INTO booking_reminders (booking_id, kind, sent_at) VALUES (?, ?, ?)', [$d['id'], $d['kind'], Db::toDbTime(Js::isoNow())]);
            } catch (\Throwable $e) {
                continue;
            }
            $b = Bookings::getBooking($d['id']);
            $ok = $b && ($d['kind'] === 'arrival' ? Mailer::sendArrivalReminder($b, $d['daysLeft']) : Mailer::sendPaymentReminder($b, $d['daysLeft']));
            if ($ok) {
                $sent[$d['kind']]++;
            } else {
                Db::run('DELETE FROM booking_reminders WHERE booking_id = ? AND kind = ?', [$d['id'], $d['kind']]); // nothing was sent
            }
        }
        if ($sent['arrival'] || $sent['payment']) error_log('[reminders] queued ' . $sent['arrival'] . ' arrival and ' . $sent['payment'] . ' payment reminder(s)');
        return $sent;
    }

    /** For Admin → Settings: the settings, what is due now, and the reminders sent lately. */
    public static function admin(): array
    {
        $due = [];
        foreach (self::due() as $d) {
            $b = Bookings::getBooking($d['id']);
            if ($b) $due[] = ['kind' => $d['kind'], 'daysLeft' => $d['daysLeft'], 'id' => $b['id'], 'ref' => $b['ref'], 'guestName' => $b['guestName'],
                'guestEmail' => $b['guestEmail'], 'checkIn' => $b['checkIn'], 'holdUntil' => $b['holdUntil']];
        }
        $recent = [];
        foreach (Db::all('SELECT r.kind, r.sent_at, b.id, b.ref, b.guest_name FROM booking_reminders r JOIN bookings b ON b.id = r.booking_id ORDER BY r.sent_at DESC, r.id DESC LIMIT 8') as $r) {
            $recent[] = ['kind' => $r['kind'], 'sentAt' => Db::fromDbTime((string) $r['sent_at']), 'id' => (int) $r['id'], 'ref' => $r['ref'], 'guestName' => $r['guest_name']];
        }
        return ['on' => self::enabled(), 'arrivalDays' => max(0, Settings::int('reminder_arrival_days')), 'paymentDays' => max(0, Settings::int('reminder_payment_days')),
            'emailEnabled' => Mailer::enabled(), 'due' => $due, 'recent' => $recent];
    }

    /** {on?, arrivalDays?, paymentDays?}: days are 0 (off) to 30. */
    public static function save(array $in): array
    {
        $set = [];
        if (array_key_exists('on', $in)) $set['reminders_on'] = Js::truthy($in['on']) ? '1' : '0';
        foreach (['arrivalDays' => 'reminder_arrival_days', 'paymentDays' => 'reminder_payment_days'] as $key => $name) {
            if (!array_key_exists($key, $in)) continue;
            $n = Js::number($in[$key]);
            if (is_nan($n) || floor($n) != $n || $n < 0 || $n > 30) throw new BookingError('Days before must be a whole number from 0 to 30 (0 turns that reminder off).');
            $set[$name] = (string) (int) $n;
        }
        if ($set) Settings::set($set);
        return self::admin();
    }

    /**
     * Send one booking's reminder now, whatever the settings and whether or not it was sent before:
     * a confirmed booking gets the arrival reminder, a pending one the payment reminder.
     * Returns 'arrival' | 'payment'. The email goes out after the response (Mailer queue).
     */
    public static function sendFor(array $b): string
    {
        if ($b['source'] === 'block') throw new BookingError('Blocked dates have no guest to remind.', 404, 'not_found');
        if ($b['guestEmail'] === '') throw new BookingError('This guest has no email address. Add one to the booking, or message them on WhatsApp.');
        $today = Bookings::today();
        if ($b['status'] === 'confirmed') {
            if (strcmp($b['checkIn'], $today) < 0) throw new BookingError('This stay has already started, so an arrival reminder would not make sense.');
            $kind = 'arrival';
            $ok = Mailer::sendArrivalReminder($b, self::daysBetween($today, $b['checkIn']));
        } elseif ($b['status'] === 'pending') {
            if (!$b['advance']['required']) throw new BookingError('This reservation asks for no advance, so there is no payment to remind the guest about. Once you confirm it, they get an arrival reminder before check-in.');
            $kind = 'payment';
            $ok = Mailer::sendPaymentReminder($b, $b['holdUntil'] ? self::daysBetween($today, $b['holdUntil']) : 0);
        } else {
            throw new BookingError('Reminders go to confirmed bookings (arrival) and pending reservations (payment) only.');
        }
        if (!$ok) throw new BookingError('The reminder could not be prepared.');
        self::record((int) $b['id'], $kind);
        return $kind;
    }

    /** Send every reminder that is due now (the "Send now" button). Returns the counts per kind. */
    public static function sendDueNow(): array
    {
        return self::run(true); // the switch is for the automatic sending; staff asked for this one
    }

    /** Note that a reminder was sent (again): the latest send time is kept. */
    private static function record(int $bookingId, string $kind): void
    {
        $now = Db::toDbTime(Js::isoNow());
        if (Db::one('SELECT 1 FROM booking_reminders WHERE booking_id = ? AND kind = ?', [$bookingId, $kind])) {
            Db::run('UPDATE booking_reminders SET sent_at = ? WHERE booking_id = ? AND kind = ?', [$now, $bookingId, $kind]);
        } else {
            Db::run('INSERT INTO booking_reminders (booking_id, kind, sent_at) VALUES (?, ?, ?)', [$bookingId, $kind, $now]);
        }
    }

    /** The website's own fallback: run at most once an hour. Never throws. */
    public static function runHourly(): void
    {
        try {
            if (!self::enabled()) return;
            $hour = gmdate('Y-m-d H');
            if (Settings::get('reminders_last_run') === $hour) return;
            Settings::set(['reminders_last_run' => $hour]);
            self::run();
        } catch (\Throwable $e) {
            error_log('[reminders] failed: ' . $e->getMessage());
        }
    }

    private static function daysBetween(string $from, string $to): int
    {
        $a = new \DateTimeImmutable($from . ' 00:00:00', new \DateTimeZone('UTC'));
        $b = new \DateTimeImmutable($to . ' 00:00:00', new \DateTimeZone('UTC'));
        return (int) $a->diff($b)->format('%r%a');
    }
}
