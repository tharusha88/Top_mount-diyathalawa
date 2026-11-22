<?php
// Booking confirmations: staff fill in a booking (Admin → New booking, or any booking already
// made), then send the guest a private link (copy it, WhatsApp it or email it). The guest opens
// it, checks the stay and the price, fills in whatever details are still missing (as the website
// asks: name, NIC or passport, email, phone) and confirms. Staff see who has confirmed, and are
// emailed and sent a WhatsApp alert when a guest does.
// The guest confirming doesn't change the booking's status: a reservation still waits for its
// advance, and staff still confirm the booking as before.
// The link is made from the site's secret, like the invoice link. Its record (when it was sent,
// when the guest confirmed and what they filled in) is kept in booking_confirms, which this class
// creates the first time it is needed.
declare(strict_types=1);

namespace Ydr;

final class Confirmations
{
    /** What the guest gives: input => [column, longest, label, required]. The required ones are what the website's booking form asks for. */
    public const FIELDS = [
        'guestName' => ['guest_name', 120, 'Full name', true],
        'guestIdNo' => ['guest_id_no', 40, 'NIC / passport number', true],
        'guestEmail' => ['guest_email', 160, 'Email', true],
        'guestPhone' => ['guest_phone', 40, 'Phone / WhatsApp', true],
        'country' => ['country', 60, 'Country', false],
        'arrivalTime' => ['arrival_time', 40, 'Estimated arrival', false],
        'specialRequests' => ['special_requests', 1500, 'Special requests', false],
    ];

    /** How staff sent the link: copied, WhatsApp, email. */
    public const VIA = ['link' => 'Link copied', 'whatsapp' => 'WhatsApp', 'email' => 'Email'];

    private static bool $ready = false;

    /** The table, made when first needed (SQLite and MySQL). */
    private static function table(): void
    {
        if (self::$ready) return;
        if (Db::driver() === 'mysql') {
            Db::pdo()->exec("CREATE TABLE IF NOT EXISTS booking_confirms (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                booking_id   INT UNSIGNED NOT NULL,
                issued_at    DATETIME(3) NOT NULL COMMENT 'UTC: when the link was first sent',
                issued_by    VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'staff username',
                sent_at      DATETIME(3) NOT NULL COMMENT 'UTC: when it was last sent',
                sent_via     VARCHAR(20) COLLATE utf8mb4_bin NOT NULL DEFAULT 'link' COMMENT 'link|whatsapp|email',
                confirmed_at DATETIME(3) NULL COMMENT 'UTC: when the guest confirmed',
                changes      TEXT NOT NULL COMMENT 'JSON: what the guest filled in or changed',
                UNIQUE KEY uq_booking_confirms (booking_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            Db::pdo()->exec("CREATE TABLE IF NOT EXISTS booking_confirms (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                booking_id   INTEGER NOT NULL UNIQUE,
                issued_at    TEXT NOT NULL,
                issued_by    TEXT NOT NULL DEFAULT '',
                sent_at      TEXT NOT NULL,
                sent_via     TEXT NOT NULL DEFAULT 'link',
                confirmed_at TEXT,
                changes      TEXT NOT NULL DEFAULT '[]'
            )");
        }
        self::$ready = true;
    }

    // ---------- the private link ----------
    private static function code(array $b): string
    {
        return substr(hash_hmac('sha256', "confirm|{$b['id']}|{$b['ref']}", Config::get()['sessionSecret']), 0, 24);
    }

    public static function url(array $b): string
    {
        return Config::get()['baseUrl'] . self::path($b);
    }

    /** "/confirm/TMR-7K3M9Q/<code>" */
    public static function path(array $b): string
    {
        return '/confirm/' . rawurlencode($b['ref']) . '/' . self::code($b);
    }

    /** The booking of a confirmation link, or null when the link is wrong. */
    public static function byLink(string $ref, string $code): ?array
    {
        $b = Bookings::getByRef($ref);
        if (!$b || $b['source'] === 'block') return null;
        return hash_equals(self::code($b), $code) ? $b : null;
    }

    // ---------- what is missing, and the record ----------
    /** The required details the booking still lacks (input names), e.g. ['guestIdNo', 'guestEmail']. */
    public static function missing(array $b): array
    {
        $out = [];
        if (Js::trim((string) $b['guestName']) === '') $out[] = 'guestName';
        if ((string) $b['guestIdNo'] === '') $out[] = 'guestIdNo';
        if (!Bookings::isEmail((string) $b['guestEmail'])) $out[] = 'guestEmail';
        if (strlen((string) preg_replace('/\D/', '', (string) $b['guestPhone'])) < 7) $out[] = 'guestPhone';
        return $out;
    }

    /** The details a guest can fill in for this booking (a party's time is its time, not an arrival). */
    private static function fieldsFor(array $b): array
    {
        $f = self::FIELDS;
        if ($b['kind'] === 'event') unset($f['arrivalTime']);
        return $f;
    }

    private static function row(int $bookingId): ?array
    {
        self::table();
        return Db::one('SELECT * FROM booking_confirms WHERE booking_id = ?', [$bookingId]);
    }

    private static function mapRow(?array $r): ?array
    {
        if (!$r) return null;
        $changes = json_decode((string) $r['changes'], true);
        return [
            'issuedAt' => Db::fromDbTime($r['issued_at']),
            'issuedBy' => (string) $r['issued_by'] !== '' ? Auth::nameOf((string) $r['issued_by']) : '',
            'sentAt' => Db::fromDbTime($r['sent_at']),
            'sentVia' => (string) $r['sent_via'],
            'sentViaLabel' => self::VIA[$r['sent_via']] ?? (string) $r['sent_via'],
            'confirmedAt' => Db::fromDbTime($r['confirmed_at'] ?? null),
            'changes' => is_array($changes) ? $changes : [],
        ];
    }

    /** Where it stands: 'open' (the guest can confirm), 'confirmed' (they have), 'closed' (cancelled, or the stay has begun). */
    public static function state(array $b, ?array $rec = null): string
    {
        $rec ??= self::mapRow(self::row($b['id']));
        if ($rec && $rec['confirmedAt']) return 'confirmed';
        return in_array($b['status'], ['pending', 'confirmed'], true) ? 'open' : 'closed';
    }

    /** For a list of bookings: id => 'sent' | 'confirmed' (bookings with no link sent are left out). */
    public static function statuses(array $bookingIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $bookingIds)));
        if (!$ids) return [];
        self::table();
        $out = [];
        foreach (Db::all('SELECT booking_id, confirmed_at FROM booking_confirms WHERE booking_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $r) {
            $out[(int) $r['booking_id']] = $r['confirmed_at'] !== null ? 'confirmed' : 'sent';
        }
        return $out;
    }

    /** Bookings as the API lists them, each with guestConfirm: null | 'sent' | 'confirmed'. */
    public static function decorate(array $bookings): array
    {
        $st = self::statuses(array_column($bookings, 'id'));
        return array_map(fn ($b) => $b + ['guestConfirm' => $st[$b['id']] ?? null], $bookings);
    }

    /** For the admin panel: the link, what the guest will be asked for, and when it was sent and confirmed. */
    public static function forAdmin(array $b): array
    {
        $rec = self::mapRow(self::row($b['id']));
        $labels = array_map(fn ($k) => self::FIELDS[$k][2], self::missing($b));
        return [
            'url' => self::url($b),
            'state' => self::state($b, $rec),
            'missing' => self::missing($b),
            'missingLabels' => $labels,
            'record' => $rec,
            'canEmail' => Bookings::isEmail((string) $b['guestEmail']),
            'waText' => self::waText($b),
        ];
    }

    /** The WhatsApp message staff send with the link. */
    public static function waText(array $b): string
    {
        $first = explode(' ', Js::trim((string) $b['guestName']))[0] ?? '';
        $site = Site::info()['name'];
        $ask = self::missing($b) ? ', fill in the few details we still need' : '';
        return 'Hello' . ($first !== '' ? " $first" : '') . ", thank you for booking with $site. Please check your booking {$b['ref']}$ask and confirm it here:";
    }

    /** Note that staff sent the link ($via: link | whatsapp | email). The first send is kept as when it was issued. */
    public static function markSent(array $b, string $by, string $via): array
    {
        if (!isset(self::VIA[$via])) throw new BookingError('Choose how the link was sent.');
        if ($b['source'] === 'block') throw new BookingError('Blocked dates have no guest to confirm them.');
        self::table();
        $now = Db::toDbTime(Js::isoNow());
        if (self::row($b['id'])) {
            Db::run('UPDATE booking_confirms SET sent_at = ?, sent_via = ? WHERE booking_id = ?', [$now, $via, $b['id']]);
        } else {
            Db::run("INSERT INTO booking_confirms (booking_id, issued_at, issued_by, sent_at, sent_via, changes) VALUES (?, ?, ?, ?, ?, '[]')", [$b['id'], $now, $by, $now, $via]);
        }
        return self::forAdmin($b);
    }

    // ---------- the guest confirms ----------
    /**
     * Save what the guest filled in and mark the booking confirmed by them. $in: the FIELDS, agree.
     * Returns ['booking' => the booking as it is now, 'changes' => [{field, label, from, to}]].
     */
    public static function confirm(array $b, array $in): array
    {
        if (!Js::truthy($in['agree'] ?? null)) throw new BookingError('Please tick the box to confirm the booking details.');
        $fields = self::fieldsFor($b);
        $vals = [];
        foreach ($fields as $k => [, $max, $label, $required]) {
            if ($k === 'guestIdNo') {
                $v = Bookings::idNo($in[$k] ?? '');
                if ($v === null) throw new BookingError('Please check the NIC or passport number.');
                if ($v === '') throw new BookingError('Please enter your NIC or passport number.');
            } else {
                $v = Bookings::str($in[$k] ?? '', $max);
                if ($v === null) throw new BookingError("Please check the $label.");
            }
            $vals[$k] = $v;
        }
        if ($vals['guestName'] === '') throw new BookingError('Please enter your full name.');
        if (!Bookings::isEmail($vals['guestEmail'])) throw new BookingError('Please enter a valid email address.');
        if (strlen((string) preg_replace('/\D/', '', $vals['guestPhone'])) < 7) throw new BookingError('Please enter a valid phone number.');

        return Db::transaction(function () use ($b, $fields, $vals) {
            Db::lockBooking($b['id']);
            $cur = Bookings::getBooking($b['id']); // as it is now, inside the lock
            if (!$cur) throw new BookingError('Booking not found', 404, 'not_found');
            $rec = self::mapRow(self::row($cur['id']));
            if ($rec && $rec['confirmedAt']) throw new BookingError('This booking has already been confirmed. To change anything, please call us.', 409, 'already_confirmed');
            if (!in_array($cur['status'], ['pending', 'confirmed'], true)) {
                throw new BookingError($cur['status'] === 'cancelled' ? 'This booking has been cancelled. Please contact us to book again.' : 'This booking can no longer be changed here. Please contact us.', 409, 'closed');
            }
            $sets = [];
            $changes = [];
            foreach ($fields as $k => [$col, , $label]) {
                $was = (string) ($cur[$k] ?? '');
                if ($vals[$k] === $was) continue;
                $sets[$col] = $vals[$k];
                $changes[] = ['field' => $k, 'label' => $label, 'from' => $was, 'to' => $vals[$k]];
            }
            $now = Db::toDbTime(Js::isoNow());
            if ($sets) {
                $sets['updated_at'] = $now;
                $cols = array_keys($sets);
                Db::run('UPDATE bookings SET ' . implode(', ', array_map(fn ($c) => "$c = ?", $cols)) . ' WHERE id = ?', array_merge(array_values($sets), [$cur['id']]));
            }
            $json = json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($rec) {
                Db::run('UPDATE booking_confirms SET confirmed_at = ?, changes = ? WHERE booking_id = ?', [$now, $json, $cur['id']]);
            } else {
                // a link opened without staff noting they sent it
                Db::run('INSERT INTO booking_confirms (booking_id, issued_at, issued_by, sent_at, sent_via, confirmed_at, changes) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$cur['id'], $now, '', $now, 'link', $now, $json]);
            }
            return ['booking' => Bookings::getBooking($cur['id']), 'changes' => $changes];
        });
    }

    /** "added Email, NIC / passport number · changed Phone / WhatsApp" for alerts. */
    public static function changesText(array $changes): string
    {
        $added = [];
        $changed = [];
        foreach ($changes as $c) {
            if (($c['from'] ?? '') === '') $added[] = $c['label']; else $changed[] = $c['label'];
        }
        return implode(' · ', array_filter([$added ? 'added ' . implode(', ', $added) : '', $changed ? 'changed ' . implode(', ', $changed) : '']));
    }

    /** Tell staff a guest has confirmed: a WhatsApp alert (with the new-booking alerts) and an email to each admin address. */
    public static function notify(array $b, array $changes): void
    {
        Mailer::notifyGuestConfirmed($b, $changes);
        if (Settings::get('wa_alert_new') === '0') return;
        $what = $b['kind'] === 'room' ? (string) ($b['place'] ?: $b['roomName']) : $b['packageLabel'];
        $when = $b['nights'] > 0 ? "{$b['checkIn']} → {$b['checkOut']} ({$b['nights']} night" . ($b['nights'] === 1 ? '' : 's') . ')' : $b['checkIn'];
        $did = self::changesText($changes);
        Whatsapp::queue(implode("\n", array_values(array_filter([
            '☑️ *Guest confirmed the booking* — ' . $b['ref'],
            '👤 ' . $b['guestName'] . ($b['guestPhone'] !== '' ? ' · ' . $b['guestPhone'] : ''),
            '🏡 ' . $what,
            '📅 ' . $when,
            $did !== '' ? '📝 ' . ucfirst($did) : 'No details changed',
            $b['status'] === 'pending' ? ($b['advance']['required'] ? '⏳ Waiting for the advance' : '⏳ Confirm it when you are ready') : '',
            Config::get()['baseUrl'] . '/admin/#/bookings/' . $b['id'],
        ]))));
    }

    // ---------- the guest's page ----------
    /** What the confirmation page shows (window.BOOT.confirm). */
    public static function page(array $b): array
    {
        $rec = self::mapRow(self::row($b['id']));
        $state = self::state($b, $rec);
        $a = Docs::amounts($b);
        $pay = Bookings::paymentInfo();
        $lines = array_map(fn ($l) => ['label' => $l['label'], 'detail' => $l['unit'] ? Site::info()['currency'] . ' ' . number_format($l['unit']) . ' × ' . $l['detail'] : $l['detail'], 'amount' => $l['amount']], Bookings::priceLines($b));
        $guest = [];
        foreach (self::fieldsFor($b) as $k => $f) $guest[$k] = (string) ($b[$k] ?? '');
        $room = $b['roomId'] ? Bookings::getRoom($b['roomId']) : null;
        return [
            'ref' => $b['ref'],
            'state' => $state,
            'status' => $b['status'],
            'kind' => $b['kind'],
            'what' => $b['kind'] === 'room' ? (string) ($b['roomName'] ?? '') : $b['packageLabel'],
            'place' => (string) ($b['place'] ?? ''),
            'roomCount' => count($b['rooms']),
            'mealPlan' => $b['mealPlan'],
            'mealPlanLabel' => $b['mealPlanLabel'],
            'wholeCategory' => $b['wholeCategory'] ? $b['wholeCategory']['name'] . ' — ' . $b['wholeCategory']['label'] : '',
            'image' => $room['images'][0] ?? '',
            'checkIn' => $b['checkIn'],
            'checkOut' => $b['checkOut'],
            'nights' => $b['nights'],
            'adults' => $b['adults'],
            'children' => $b['children'],
            'capacity' => $b['capacity'],
            'time' => $b['kind'] === 'event' ? (string) $b['arrivalTime'] : '',
            'extras' => array_values(array_filter([$b['partyArea'] && $b['kind'] !== 'event' ? 'Party area' : '', $b['jblSpeaker'] ? 'JBL speaker' : ''])),
            'lines' => $lines,
            'couponCode' => $b['couponCode'],
            'couponDiscount' => $b['couponDiscount'],
            'total' => $b['totalAmount'],
            'paid' => $a['paid'],
            'due' => $a['due'],
            'dueLabel' => $a['dueLabel'],
            'advance' => $a['advance'],
            'atResort' => $a['atResort'],
            'holdUntil' => $b['status'] === 'pending' ? $b['holdUntil'] : null,
            'pay' => ['bank' => $pay['bank'], 'accountName' => $pay['accountName'], 'accountNo' => $pay['accountNo'], 'branch' => $pay['branch'], 'phone' => $pay['phone'], 'note' => $pay['note']],
            'guest' => $guest,
            'fields' => array_keys(self::fieldsFor($b)),
            'missing' => self::missing($b),
            'confirmedAt' => $rec['confirmedAt'] ?? null,
            'invoiceUrl' => Docs::invoiceUrl($b),
            'policyHtml' => App::policySection(Bookings::cancellationPolicy()),
            'servicePct' => Pos::servicePct(),
        ];
    }
}
