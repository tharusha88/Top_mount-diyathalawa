<?php
// WhatsApp alerts to the resort's own phone when a booking is confirmed, through CallMeBot
// (free for personal use: the phone sends CallMeBot one WhatsApp message and gets an API key).
// Set up in Admin → Settings → WhatsApp alerts. Like emails, alerts go out after the response
// has been sent; until an API key is saved they are written to data/outbox/ instead.
declare(strict_types=1);

namespace Ydr;

final class Whatsapp
{
    private const API = 'https://api.callmebot.com/whatsapp.php';
    private static array $queue = [];

    /** phone (digits with the country code), apiKey, on */
    public static function config(): array
    {
        return [
            'phone' => Site::waDigits(Settings::get('wa_alert_phone')),
            'apiKey' => trim(Settings::get('wa_alert_apikey')),
            'on' => Settings::get('wa_alert_on') !== '0',
        ];
    }

    public static function enabled(): bool
    {
        $c = self::config();
        return $c['on'] && $c['phone'] !== '' && $c['apiKey'] !== '';
    }

    /** For Admin → Settings (the API key is never sent back in full). */
    public static function admin(): array
    {
        $c = self::config();
        return ['phone' => $c['phone'], 'on' => $c['on'], 'hasKey' => $c['apiKey'] !== '',
            'keyHint' => $c['apiKey'] === '' ? '' : '••••' . substr($c['apiKey'], -3), 'enabled' => self::enabled()];
    }

    /** {phone?, apiKey? (empty = keep), removeKey?, on?} */
    public static function save(array $in): array
    {
        $set = [];
        if (array_key_exists('phone', $in)) {
            $p = Site::waDigits($in['phone']);
            if ($p === '' || strlen($p) < 8 || strlen($p) > 15) throw new BookingError('Please enter the WhatsApp number with the country code, e.g. 94777368264.');
            $set['wa_alert_phone'] = $p;
        }
        if (!empty($in['removeKey'])) $set['wa_alert_apikey'] = '';
        elseif (isset($in['apiKey']) && trim(Js::str($in['apiKey'])) !== '') {
            $k = trim(Js::str($in['apiKey']));
            if (!preg_match('/^[A-Za-z0-9_-]{4,64}$/', $k)) throw new BookingError('That API key does not look right — copy it exactly from the CallMeBot reply.');
            $set['wa_alert_apikey'] = $k;
        }
        if (array_key_exists('on', $in)) $set['wa_alert_on'] = Js::truthy($in['on']) ? '1' : '0';
        Settings::set($set);
        return self::admin();
    }

    /** Send now. ['sent' => true] | ['outbox' => file] | ['off' => true]; throws when CallMeBot refuses. */
    public static function send(string $text, bool $force = false): array
    {
        $c = self::config();
        if (!$c['on'] && !$force) return ['off' => true];
        if ($c['apiKey'] === '' || $c['phone'] === '') {
            $dir = Config::get()['dataDir'] . '/outbox';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $file = $dir . '/' . str_replace([':', '.'], '-', Js::isoNow()) . '-whatsapp.txt';
            file_put_contents($file, "To WhatsApp +{$c['phone']}\n\n$text\n");
            error_log('[whatsapp] no CallMeBot API key yet — saved the message to data/outbox/');
            return ['outbox' => $file];
        }
        $url = self::API . '?' . http_build_query(['phone' => '+' . $c['phone'], 'text' => $text, 'apikey' => $c['apiKey']], '', '&', PHP_QUERY_RFC3986);
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
            try {
                self::send($text);
            } catch (\Throwable $e) {
                error_log('[whatsapp] failed: ' . $e->getMessage());
            }
        }
    }

    /** The alert for a booking that has just been confirmed. */
    public static function bookingConfirmed(array $b): void
    {
        if ($b['source'] === 'block') return;
        $cur = Config::get()['site']['currency'];
        $money = fn (int $n) => $cur . ' ' . number_format($n);
        $date = fn (string $s) => (new \DateTimeImmutable($s . ' 00:00:00', new \DateTimeZone('UTC')))->format('D j M Y');
        $what = $b['kind'] === 'room' ? (string) $b['roomName']
            : $b['packageLabel'] . ($b['kind'] === 'per_person' && $b['roomName'] ? ' · rooms: ' . $b['roomName'] : '');
        $when = $b['kind'] === 'day_out' ? $date($b['checkIn']) . ' (day visit)'
            : $date($b['checkIn']) . ' → ' . $date($b['checkOut']) . ' (' . $b['nights'] . ' night' . ($b['nights'] === 1 ? '' : 's') . ')';
        $guests = $b['adults'] || $b['children']
            ? $b['adults'] . ' adult' . ($b['adults'] === 1 ? '' : 's') . ($b['children'] ? ', ' . $b['children'] . ' child' . ($b['children'] === 1 ? '' : 'ren') : '')
            : '';
        $paid = 0;
        try { $paid = Pos::folio($b['id'])['totals']['paid']; } catch (\Throwable $e) { /* no room bill yet */ }
        $lines = [
            '✅ *Booking confirmed* — ' . $b['ref'],
            '👤 ' . $b['guestName'] . ($b['guestPhone'] !== '' ? ' · ' . $b['guestPhone'] : ''),
            $b['guestIdNo'] !== '' ? '🪪 NIC / passport: ' . $b['guestIdNo'] : '',
            '🏡 ' . $what,
            '📅 ' . $when,
            $guests !== '' ? '👥 ' . $guests : '',
            '💰 Total ' . $money($b['totalAmount']) . ($paid ? ' · paid ' . $money($paid) : ''),
            $b['sourceLabel'] !== 'Website' ? 'Source: ' . $b['sourceLabel'] : '',
        ];
        self::queue(implode("\n", array_values(array_filter($lines, fn ($l) => $l !== ''))));
    }
}
