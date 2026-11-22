<?php
// WhatsApp booking alerts to the resort's own phones, through CallMeBot (free for personal use:
// each phone sends CallMeBot one WhatsApp message and gets its own API key). An alert goes to every
// number on the list when a guest books on the website, and when a booking is confirmed.
// Set up in Admin → Settings → WhatsApp alerts. Like emails, alerts go out after the response
// has been sent; a number without an API key gets its alerts written to data/outbox/ instead.
declare(strict_types=1);

namespace Ydr;

final class Whatsapp
{
    private const API = 'https://api.callmebot.com/whatsapp.php';
    public const MAX_NUMBERS = 10;
    private static array $queue = [];

    /**
     * The numbers alerts go to: [{id, phone (digits with the country code), name, apiKey}].
     * Until a list is saved in Admin → Settings, the single number of earlier versions.
     */
    public static function numbers(): array
    {
        $saved = Settings::get('wa_alert_numbers');
        if ($saved === '') {
            $phone = Site::waDigits(Settings::get('wa_alert_phone'));
            return $phone === '' ? [] : [['id' => 'main', 'phone' => $phone, 'name' => '', 'apiKey' => trim(Settings::get('wa_alert_apikey'))]];
        }
        $out = [];
        $list = json_decode($saved, true);
        foreach (is_array($list) ? $list : [] as $n) {
            if (!is_array($n) || !is_string($n['phone'] ?? null) || $n['phone'] === '') continue;
            $out[] = ['id' => (string) ($n['id'] ?? ''), 'phone' => $n['phone'], 'name' => (string) ($n['name'] ?? ''), 'apiKey' => (string) ($n['apiKey'] ?? '')];
        }
        return $out;
    }

    /** numbers, on (alerts at all), onNew (a guest books on the website), onConfirmed (a booking is confirmed) */
    public static function config(): array
    {
        return [
            'numbers' => self::numbers(),
            'on' => Settings::get('wa_alert_on') !== '0',
            'onNew' => Settings::get('wa_alert_new') !== '0',
            'onConfirmed' => Settings::get('wa_alert_confirmed') !== '0',
        ];
    }

    /** Alerts are on and at least one number has its API key. */
    public static function enabled(): bool
    {
        $c = self::config();
        if (!$c['on']) return false;
        foreach ($c['numbers'] as $n) if ($n['apiKey'] !== '') return true;
        return false;
    }

    /** For Admin → Settings (API keys are never sent back in full). */
    public static function admin(): array
    {
        $c = self::config();
        $numbers = array_map(fn ($n) => ['id' => $n['id'], 'phone' => $n['phone'], 'name' => $n['name'], 'hasKey' => $n['apiKey'] !== '',
            'keyHint' => $n['apiKey'] === '' ? '' : '••••' . substr($n['apiKey'], -3)], $c['numbers']);
        return ['numbers' => $numbers, 'on' => $c['on'], 'onNew' => $c['onNew'], 'onConfirmed' => $c['onConfirmed'],
            'enabled' => self::enabled(), 'max' => self::MAX_NUMBERS];
    }

    /**
     * {numbers?: [{id?, phone, name?, apiKey? (empty = keep this number's key), removeKey?}], on?, onNew?, onConfirmed?}
     * The list replaces the saved one: a number left out is removed. A key belongs to its phone, so
     * changing a number drops its old key unless a new one is given.
     */
    public static function save(array $in): array
    {
        $set = [];
        if (array_key_exists('numbers', $in)) {
            if (!is_array($in['numbers'])) throw new BookingError('Send the list of WhatsApp numbers.');
            $old = [];
            foreach (self::numbers() as $n) $old[$n['id']] = $n;
            $out = [];
            foreach ($in['numbers'] as $n) {
                if (!is_array($n)) throw new BookingError('Send each WhatsApp number with its details.');
                $typed = trim(Js::str($n['phone'] ?? ''));
                $key = trim(Js::str($n['apiKey'] ?? ''));
                $name = trim((string) preg_replace('/\s+/', ' ', Js::str($n['name'] ?? '')));
                if ($typed === '' && $key === '' && $name === '') continue; // a row left empty
                $phone = Site::waDigits($typed);
                if (strlen($phone) < 8 || strlen($phone) > 15) {
                    throw new BookingError('Please enter ' . ($name !== '' ? Js::slice($name, 40) . "'s" : 'each') . ' WhatsApp number with the country code, e.g. 94777368264.');
                }
                if (isset($out[$phone])) throw new BookingError("+$phone is on the list twice.");
                $prev = $old[Js::str($n['id'] ?? '')] ?? null;
                $keep = $prev && $prev['phone'] === $phone && empty($n['removeKey']) ? $prev['apiKey'] : '';
                if ($key !== '' && !preg_match('/^[A-Za-z0-9_-]{4,64}$/', $key)) {
                    throw new BookingError("The API key for +$phone does not look right — copy it exactly from the CallMeBot reply.");
                }
                $out[$phone] = ['id' => $prev ? $prev['id'] : bin2hex(random_bytes(4)), 'phone' => $phone, 'name' => Js::slice($name, 40), 'apiKey' => $key !== '' ? $key : $keep];
            }
            if (count($out) > self::MAX_NUMBERS) throw new BookingError('Up to ' . self::MAX_NUMBERS . ' WhatsApp numbers.');
            $set['wa_alert_numbers'] = json_encode(array_values($out), JSON_UNESCAPED_UNICODE);
        }
        foreach (['on' => 'wa_alert_on', 'onNew' => 'wa_alert_new', 'onConfirmed' => 'wa_alert_confirmed'] as $k => $name) {
            if (array_key_exists($k, $in)) $set[$name] = Js::truthy($in[$k]) ? '1' : '0';
        }
        Settings::set($set);
        return self::admin();
    }

    /**
     * Send $text to every number, or only to the number with id $only. ['off' => true] when alerts
     * are off (and not $force); otherwise ['results' => one per number: {id, phone, name} plus
     * 'sent' => true | 'outbox' => file (no API key yet) | 'error' => what CallMeBot said].
     */
    public static function send(string $text, bool $force = false, ?string $only = null): array
    {
        $c = self::config();
        if (!$c['on'] && !$force) return ['off' => true];
        $results = [];
        foreach ($c['numbers'] as $n) {
            if ($only !== null && $n['id'] !== $only) continue;
            $r = ['id' => $n['id'], 'phone' => $n['phone'], 'name' => $n['name']];
            try {
                $results[] = $r + self::sendTo($n, $text);
            } catch (\Throwable $e) {
                $results[] = $r + ['error' => $e->getMessage()];
            }
        }
        return ['results' => $results];
    }

    /** One number: ['sent' => true] | ['outbox' => file]; throws when CallMeBot refuses. */
    private static function sendTo(array $n, string $text): array
    {
        if ($n['apiKey'] === '') {
            $dir = Config::get()['dataDir'] . '/outbox';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $base = $dir . '/' . str_replace([':', '.'], '-', Js::isoNow()) . '-whatsapp-' . $n['phone'];
            $file = "$base.txt";
            for ($i = 2; file_exists($file); $i++) $file = "$base-$i.txt";
            file_put_contents($file, "To WhatsApp +{$n['phone']}" . ($n['name'] !== '' ? " ({$n['name']})" : '') . "\n\n$text\n");
            error_log("[whatsapp] no CallMeBot API key for +{$n['phone']} yet — saved the message to data/outbox/");
            return ['outbox' => $file];
        }
        $url = self::API . '?' . http_build_query(['phone' => '+' . $n['phone'], 'text' => $text, 'apikey' => $n['apiKey']], '', '&', PHP_QUERY_RFC3986);
        [$status, $body] = self::get($url);
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
        if ($status < 200 || $status >= 300 || preg_match('/\b(error|invalid|not activated|wrong)\b/i', $plain)) {
            throw new \RuntimeException('CallMeBot answered ' . ($status ?: 'nothing') . ($plain !== '' ? ': ' . Js::slice($plain, 160) : ''));
        }
        return ['sent' => true];
    }

    /** [HTTP status, body] — curl when available, otherwise PHP's own HTTP client. */
    private static function get(string $url): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Top Mountain booking system']);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false) throw new \RuntimeException('Could not reach CallMeBot: ' . $err);
            return [$status, (string) $body];
        }
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true, 'header' => "User-Agent: Top Mountain booking system\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) throw new \RuntimeException('Could not reach CallMeBot (the server may block outgoing connections).');
        $status = preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m) ? (int) $m[1] : 0;
        return [$status, $body];
    }

    public static function queue(string $text): void
    {
        self::$queue[] = $text;
    }

    public static function hasQueue(): bool
    {
        return (bool) self::$queue;
    }

    public static function flushQueue(): void
    {
        while (($text = array_shift(self::$queue)) !== null) {
            $r = self::send($text);
            foreach ($r['results'] ?? [] as $x) {
                if (isset($x['error'])) error_log("[whatsapp] failed for +{$x['phone']}: {$x['error']}");
            }
        }
    }

    /** The lines both alerts share: guest, ID, what, when, guests. */
    private static function details(array $b): array
    {
        $date = fn (string $s) => (new \DateTimeImmutable($s . ' 00:00:00', new \DateTimeZone('UTC')))->format('D j M Y');
        $what = $b['kind'] === 'room' ? (string) ($b['place'] ?: $b['roomName'])
            : $b['packageLabel'] . ($b['kind'] === 'per_person' && $b['roomName'] ? ' · rooms: ' . $b['roomName'] : '');
        $when = match (true) {
            $b['kind'] === 'event' => $date($b['checkIn']) . (($b['arrivalTime'] ?? '') !== '' ? ', ' . $b['arrivalTime'] : ''),
            $b['kind'] === 'day_out' || ($b['package'] ?? '') === 'day_out' => $date($b['checkIn']) . ' (day visit)',
            default => $date($b['checkIn']) . ' → ' . $date($b['checkOut']) . ' (' . $b['nights'] . ' night' . ($b['nights'] === 1 ? '' : 's') . ')',
        };
        $guests = $b['adults'] || $b['children']
            ? $b['adults'] . ' adult' . ($b['adults'] === 1 ? '' : 's') . ($b['children'] ? ', ' . $b['children'] . ' child' . ($b['children'] === 1 ? '' : 'ren') : '')
            : '';
        return [
            '👤 ' . $b['guestName'] . ($b['guestPhone'] !== '' ? ' · ' . $b['guestPhone'] : ''),
            $b['guestIdNo'] !== '' ? '🪪 NIC / passport: ' . $b['guestIdNo'] : '',
            '🏡 ' . $what,
            '📅 ' . $when,
            $guests !== '' ? '👥 ' . $guests : '',
        ];
    }

    private static function money(int|float $n): string
    {
        return Config::get()['site']['currency'] . ' ' . number_format((float) $n);
    }

    private static function text(array $lines): string
    {
        return implode("\n", array_values(array_filter($lines, fn ($l) => $l !== '')));
    }

    /** The alert for a booking a guest has just made on the website (pending until staff confirm it). */
    public static function newBooking(array $b): void
    {
        if ($b['source'] === 'block' || Settings::get('wa_alert_new') === '0') return;
        $adv = $b['advance'] ?? [];
        $advance = !empty($adv['required']) && empty($adv['full']) && !empty($adv['amount']) ? ' · advance ' . self::money($adv['amount']) : '';
        $requests = trim((string) preg_replace('/\s+/', ' ', $b['specialRequests'] ?? ''));
        self::queue(self::text([
            '🆕 *New booking request* — ' . $b['ref'],
            ...self::details($b),
            ($b['guestEmail'] ?? '') !== '' ? '✉️ ' . $b['guestEmail'] : '',
            '💰 Total ' . self::money($b['totalAmount']) . $advance,
            $requests !== '' ? '📝 ' . Js::slice($requests, 200) : '',
            '⏳ Waiting for your confirmation',
            Config::get()['baseUrl'] . '/admin/#/bookings/' . $b['id'],
        ]));
    }

    /** The alert for a payment slip the guest has just sent from their invoice link (Pos::addGuestSlip). */
    public static function guestSlip(array $b, array $slip): void
    {
        if ($b['source'] === 'block' || Settings::get('wa_alert_new') === '0') return;
        self::queue(self::text([
            '🧾 *Payment slip received* — ' . $b['ref'],
            ...self::details($b),
            '💰 ' . ($slip['amount'] ? 'The guest says they paid ' . self::money($slip['amount']) : 'No amount given') . ' · total ' . self::money($b['totalAmount']),
            $slip['note'] !== '' ? '📝 ' . Js::slice($slip['note'], 200) : '',
            $b['status'] === 'pending' ? '⏳ Check the slip, then confirm the booking' : '⏳ Check the slip, then record the payment',
            Config::get()['baseUrl'] . '/admin/#/bookings/' . $b['id'],
        ]));
    }

    /** The alert for a booking that has just been confirmed. */
    public static function bookingConfirmed(array $b): void
    {
        if ($b['source'] === 'block' || Settings::get('wa_alert_confirmed') === '0') return;
        $paid = 0;
        try { $paid = Pos::folio($b['id'])['totals']['paid']; } catch (\Throwable $e) { /* no room bill yet */ }
        self::queue(self::text([
            '✅ *Booking confirmed* — ' . $b['ref'],
            ...self::details($b),
            '💰 Total ' . self::money($b['totalAmount']) . ($paid ? ' · paid ' . self::money($paid) : ''),
            $b['sourceLabel'] !== 'Website' ? 'Source: ' . $b['sourceLabel'] : '',
        ]));
    }
}
