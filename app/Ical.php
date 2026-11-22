<?php
// The bookings as a calendar feed (iCalendar / .ics) at a secret link, for Google Calendar,
// Apple Calendar or Outlook to subscribe to ("Add calendar → From URL"). The calendar app
// re-reads the link on its own schedule, so new, changed and cancelled bookings follow by
// themselves. Set up in Admin → Settings → Google Calendar.
declare(strict_types=1);

namespace Ydr;

final class Ical
{
    private const PAST_DAYS = 365; // stays that ended longer ago than this drop off the calendar
    private const STATUS = ['pending' => 'Pending — waiting for payment', 'confirmed' => 'Confirmed', 'checked_in' => 'Checked in', 'checked_out' => 'Checked out'];

    /** The secret part of the link (made on first use). */
    private static function token(): string
    {
        $t = Settings::get('ical_token');
        if ($t === '') {
            $t = bin2hex(random_bytes(16));
            Settings::set(['ical_token' => $t]);
        }
        return $t;
    }

    public static function url(): string
    {
        return Config::get()['baseUrl'] . '/calendar/' . self::token() . '.ics';
    }

    /** The calendar's name in Google Calendar. */
    private static function name(): string
    {
        return Site::info()['name'] . ' bookings';
    }

    public static function matches(string $token): bool
    {
        $t = Settings::get('ical_token');
        return $t !== '' && hash_equals($t, $token);
    }

    /** For Admin → Settings. */
    public static function admin(): array
    {
        return ['url' => self::url(), 'name' => self::name(), 'events' => count(self::bookings())];
    }

    /** A new secret link: the old one stops working. */
    public static function reset(): array
    {
        Settings::set(['ical_token' => bin2hex(random_bytes(16))]);
        return self::admin();
    }

    /** Every booking and blocked date from a year ago onwards, except cancelled ones. */
    private static function bookings(): array
    {
        $from = Bookings::addDays(Bookings::today(), -self::PAST_DAYS);
        return array_values(array_filter(Bookings::listBookings(['from' => $from, 'limit' => -1]), fn ($b) => $b['status'] !== 'cancelled'));
    }

    /** The whole calendar file. */
    public static function feed(): string
    {
        $cfg = Config::get();
        $site = Site::info();
        $host = (string) (parse_url($cfg['baseUrl'], PHP_URL_HOST) ?: 'topmountain');
        $now = gmdate('Ymd\THis\Z');
        $out = self::line('BEGIN:VCALENDAR') . self::line('VERSION:2.0') . self::line('PRODID:-//Top Mountain Resort//Bookings//EN')
            . self::line('CALSCALE:GREGORIAN') . self::line('METHOD:PUBLISH')
            . self::line('X-WR-CALNAME:' . self::text(self::name()))
            . self::line('X-WR-CALDESC:' . self::text('Bookings from the ' . $site['name'] . ' booking system'))
            . self::line('X-WR-TIMEZONE:' . self::text($cfg['timezone']))
            // how often to re-read the link (Apple and Outlook follow this; Google uses its own schedule)
            . self::line('REFRESH-INTERVAL;VALUE=DURATION:PT1H') . self::line('X-PUBLISHED-TTL:PT1H');
        foreach (self::bookings() as $b) $out .= self::event($b, $host, $now, $cfg);
        foreach (Closures::upcoming(Bookings::addDays(Bookings::today(), -60)) as $c) {
            $out .= self::line('BEGIN:VEVENT') . self::line('UID:closed-' . $c['id'] . '@' . $host) . self::line('DTSTAMP:' . $now)
                . self::line('DTSTART;VALUE=DATE:' . str_replace('-', '', $c['from']))
                . self::line('DTEND;VALUE=DATE:' . str_replace('-', '', Bookings::addDays($c['to'], 1)))
                . self::line('SUMMARY:' . self::text('Resort closed · ' . $c['label']))
                . self::line('DESCRIPTION:' . self::text(trim('No bookings on the website on these days. ' . $c['note'])))
                . self::line('TRANSP:TRANSPARENT') . self::line('END:VEVENT');
        }
        return $out . self::line('END:VCALENDAR');
    }

    /** One booking as an all-day event from arrival to departure (a day visit is that one day). */
    private static function event(array $b, string $host, string $now, array $cfg): string
    {
        $cur = $cfg['site']['currency'];
        $date = fn (string $s) => (new \DateTimeImmutable($s . ' 00:00:00', new \DateTimeZone('UTC')))->format('D j M Y');
        $plural = fn (int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);
        $block = $b['source'] === 'block';
        $guests = $b['adults'] + $b['children'];
        $what = $b['kind'] === 'room' ? (string) (($b['place'] ?? '') ?: ($b['roomName'] ?? 'Room')) : $b['packageLabel'];

        if ($block) {
            $summary = 'Blocked · ' . ($b['place'] !== '' ? $b['place'] : $what) . ($b['guestName'] !== 'Blocked' ? ' · ' . $b['guestName'] : '');
        } else {
            $summary = ($b['status'] === 'pending' ? 'Pending · ' : '') . $b['guestName'] . ' · ' . $what
                . ($guests ? ' · ' . $plural($guests, 'guest', 'guests') : '');
        }

        $extras = array_values(array_filter([$b['partyArea'] ? 'party area' : '', $b['jblSpeaker'] ? 'JBL speaker' : '']));
        $desc = [
            $b['ref'] . ' · ' . (self::STATUS[$b['status']] ?? $b['status']) . ' · ' . $b['sourceLabel'],
            $b['kind'] === 'day_out' ? 'Day visit: ' . $date($b['checkIn'])
                : $date($b['checkIn']) . ' → ' . $date($b['checkOut']) . ' · ' . $plural($b['nights'], 'night', 'nights'),
            $b['kind'] !== 'room' && $b['roomName'] ? 'Rooms: ' . $b['roomName'] : '',
            !$block && $guests ? 'Guests: ' . $plural($b['adults'], 'adult', 'adults') . ($b['children'] ? ', ' . $plural($b['children'], 'child', 'children') : '') : '',
            $extras ? 'Extras: ' . implode(', ', $extras) : '',
            $b['guestPhone'] !== '' ? 'Phone: ' . $b['guestPhone'] : '',
            $b['guestEmail'] !== '' ? 'Email: ' . $b['guestEmail'] : '',
            $b['arrivalTime'] !== '' ? 'Arrival time: ' . $b['arrivalTime'] : '',
            !$block ? 'Total: ' . $cur . ' ' . number_format($b['totalAmount']) : '',
            $b['specialRequests'] !== '' ? 'Requests: ' . $b['specialRequests'] : '',
            $b['adminNotes'] !== '' ? 'Notes: ' . $b['adminNotes'] : '',
            'Open in admin: ' . $cfg['baseUrl'] . '/admin/#/bookings/' . $b['id'],
        ];
        $changed = self::utc($b['updatedAt']) ?? $now;

        return self::line('BEGIN:VEVENT')
            . self::line('UID:booking-' . $b['id'] . '@' . $host)
            . self::line('DTSTAMP:' . $now)
            . self::line('LAST-MODIFIED:' . $changed)
            . self::line('CREATED:' . (self::utc($b['createdAt']) ?? $changed))
            . self::line('DTSTART;VALUE=DATE:' . str_replace('-', '', $b['checkIn']))
            . self::line('DTEND;VALUE=DATE:' . str_replace('-', '', $b['checkOut'])) // the departure day (not included)
            . self::line('SUMMARY:' . self::text($summary))
            . self::line('DESCRIPTION:' . self::text(implode("\n", array_values(array_filter($desc, fn ($l) => $l !== '')))))
            . self::line('URL:' . $cfg['baseUrl'] . '/admin/#/bookings/' . $b['id'])
            . self::line('STATUS:' . ($b['status'] === 'pending' ? 'TENTATIVE' : 'CONFIRMED'))
            . self::line('TRANSP:TRANSPARENT') // shows as free, so it doesn't fill your own diary
            . self::line('END:VEVENT');
    }

    /** "2026-11-07T10:20:30.000Z" → "20261107T102030Z" */
    private static function utc(?string $iso): ?string
    {
        if ($iso === null || $iso === '') return null;
        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Escape a text value (commas, semicolons, backslashes and new lines). */
    private static function text(string $s): string
    {
        $s = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', str_replace(["\r\n", "\r"], "\n", $s));
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $s);
    }

    /** One content line, folded at 75 bytes without splitting a character. */
    private static function line(string $s): string
    {
        $out = '';
        $max = 75;
        while (strlen($s) > $max) {
            $cut = $max;
            while ($cut > 1 && (ord($s[$cut]) & 0xC0) === 0x80) $cut--;
            $out .= substr($s, 0, $cut) . "\r\n ";
            $s = substr($s, $cut);
            $max = 74; // the leading space counts on continuation lines
        }
        return $out . $s . "\r\n";
    }
}
