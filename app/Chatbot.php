<?php
// The website's chatbot: Top Mountain's bot is a "hotel" brand on the Panora chatbot platform
// (D:\Projects\Panora\Chatbot), reached through that platform's bridge API with a shared secret.
// The browser never talks to the chatbot: the website's chat window (/api/chat) and the admin
// panel's Chatbot page (/api/admin/chatbot) both go through this class.
//
// .env: CHATBOT_URL (the chatbot server, e.g. http://127.0.0.1:5000), CHATBOT_SECRET (its
// CRM_BRIDGE_SHARED_SECRET), CHATBOT_TENANT (this site's id on the platform, default "topmountain").
// The same brand can later answer WhatsApp through a CRM: the knowledge base edited here is the bot's.
declare(strict_types=1);

namespace Ydr;

final class Chatbot
{
    public const BUSINESS_TYPE = 'hotel';
    public const HISTORY_MAX = 20;    // earlier messages sent with each question (the chatbot caps it too)
    public const MESSAGE_MAX = 1000;  // characters a guest can type in one message

    public static function config(): array
    {
        return Config::get()['chatbot'];
    }

    /** CHATBOT_URL and CHATBOT_SECRET are set. */
    public static function configured(): bool
    {
        $c = self::config();
        return $c['url'] !== '' && $c['secret'] !== '';
    }

    /** Is the chat window shown on the website? (set up, a bot made, and switched on in the admin panel) */
    public static function webOn(): bool
    {
        return self::configured() && Settings::get('chat_web_on') === '1' && Settings::get('chat_brand_id') !== '';
    }

    /** What the website's chat window needs to know (nothing when it is off). */
    public static function webBoot(): array
    {
        if (!self::webOn()) return ['on' => false];
        return ['on' => true, 'botName' => Settings::get('chat_bot_name'), 'greeting' => Settings::get('chat_greeting')];
    }

    /** [status, decoded JSON or null]: a call to the chatbot's bridge (never throws for an HTTP error status). */
    private static function call(string $method, string $path, ?array $body = null, int $timeout = 20): array
    {
        if (!self::configured()) throw new BookingError('The chatbot is not set up yet: add CHATBOT_URL and CHATBOT_SECRET to .env.', 503, 'chatbot_off');
        $c = self::config();
        $url = rtrim($c['url'], '/') . $path;
        $json = $body === null ? null : Js::json($body);
        $headers = ['X-Internal-Secret: ' . $c['secret'], 'Accept: application/json', 'User-Agent: Top Mountain booking system'];
        if ($json !== null) $headers[] = 'Content-Type: application/json';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_HTTPHEADER => $headers] + ($json !== null ? [CURLOPT_POSTFIELDS => $json] : []));
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) throw new BookingError('The chatbot could not be reached (' . $err . ').', 502, 'chatbot_down');
        } else {
            $ctx = stream_context_create(['http' => ['method' => $method, 'timeout' => $timeout, 'ignore_errors' => true,
                'header' => implode("\r\n", $headers) . "\r\n", 'content' => $json ?? '']]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false) throw new BookingError('The chatbot could not be reached (the server may block outgoing connections).', 502, 'chatbot_down');
            $status = preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m) ? (int) $m[1] : 0;
        }
        if ($status === 403) throw new BookingError('The chatbot refused the connection: CHATBOT_SECRET does not match its CRM_BRIDGE_SHARED_SECRET.', 502, 'chatbot_secret');
        $data = json_decode((string) $raw, true);
        return [$status, is_array($data) ? $data : null];
    }

    private static function tenant(): string
    {
        return rawurlencode(self::config()['tenant']);
    }

    /** The bot as the chatbot has it (name, instructions, entries, limits, usage), or null when none is made yet. */
    public static function brand(): ?array
    {
        [$status, $data] = self::call('GET', '/internal/tenant-brands/' . self::tenant());
        if ($status === 404) return null;
        if ($status !== 200 || !$data) throw new BookingError($data['error'] ?? 'The chatbot answered with an error (' . $status . ').', 502, 'chatbot_error');
        self::remember($data);
        return $data;
    }

    /** Keep what the website's chat window needs, so a guest's question costs one call. */
    private static function remember(array $b): void
    {
        $set = ['chat_brand_id' => (string) ($b['brand_id'] ?? ''), 'chat_bot_name' => (string) ($b['bot_name'] ?? '')];
        $changed = array_filter($set, fn ($v, $k) => Settings::get($k) !== $v, ARRAY_FILTER_USE_BOTH);
        if ($changed) Settings::set($changed);
    }

    /** Make Top Mountain's bot on the chatbot (a hotel brand), with the starter knowledge base from the admin panel. */
    public static function create(array $seed): array
    {
        [$status, $data] = self::call('POST', '/internal/tenant-brands', [
            'tenant_id' => self::config()['tenant'],
            'name' => Site::info()['name'],
            'business_type' => self::BUSINESS_TYPE,
        ]);
        if (!in_array($status, [200, 201], true) || !$data) throw new BookingError($data['error'] ?? 'The chatbot could not make the bot (' . $status . ').', 502, 'chatbot_error');
        self::remember($data);
        // a new bot gets the starter knowledge base; an existing one keeps what it has
        return $status === 201 ? self::save($seed) : $data;
    }

    /** Replace the bot's name, instructions and entries (what the admin edits). The chatbot checks the limits. */
    public static function save(array $in): array
    {
        $entries = [];
        foreach (is_array($in['entries'] ?? null) ? $in['entries'] : [] as $e) {
            if (!is_array($e)) continue;
            $entries[] = ['title' => Js::str($e['title'] ?? ''), 'content' => Js::str($e['content'] ?? '')];
        }
        $body = ['instructions' => Js::str($in['instructions'] ?? ''), 'entries' => $entries];
        if (array_key_exists('botName', $in)) $body['bot_name'] = Js::str($in['botName'] ?? '');
        [$status, $data] = self::call('PUT', '/internal/tenant-brands/' . self::tenant() . '/kb', $body);
        if ($status === 404) throw new BookingError('There is no bot yet: make it first.', 404, 'no_bot');
        if ($status !== 200 || !$data) throw new BookingError($data['error'] ?? 'The chatbot could not save that (' . $status . ').', 400);
        self::remember($data);
        return $data;
    }

    /**
     * The bot's answer to a guest: ['reply' => text or null, 'parts' => [...] or null, 'escalate' => reason or null].
     * $history: earlier messages, oldest first, [{role: user|assistant, content}]. A reply of null means
     * "nothing to say" (e.g. a bare "thanks"); a failure throws, and the chat window offers phone and WhatsApp.
     */
    public static function reply(string $message, array $history, string $channel = 'web', int $timeout = 30): array
    {
        $brandId = (int) Settings::get('chat_brand_id');
        if (!$brandId) throw new BookingError('There is no bot yet.', 503, 'no_bot');
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? '');
        if ($message === '') throw new BookingError('Please type a message.');
        if (mb_strlen($message) > self::MESSAGE_MAX) throw new BookingError('Please keep your message under ' . self::MESSAGE_MAX . ' characters.');
        $turns = [];
        foreach (array_slice(array_values(array_filter($history, 'is_array')), -self::HISTORY_MAX) as $h) {
            $text = trim(Js::str($h['content'] ?? ''));
            if ($text === '') continue;
            $turns[] = ['role' => ($h['role'] ?? '') === 'user' ? 'user' : 'assistant', 'content' => mb_substr($text, 0, 2000)];
        }
        [$status, $data] = self::call('POST', '/internal/generate-reply', [
            'brand_id' => $brandId, 'tenant_id' => self::config()['tenant'],
            'message_text' => $message, 'history' => $turns, 'channel' => $channel,
        ], $timeout);
        if ($status === 404) {
            Settings::set(['chat_brand_id' => '']); // the bot was removed on the chatbot: the chat window goes away
            throw new BookingError('The bot is not available.', 503, 'no_bot');
        }
        if ($status !== 200 || !$data) throw new BookingError('The bot could not answer just now.', 502, 'chatbot_error');
        $parts = isset($data['parts']) && is_array($data['parts']) ? array_values(array_filter(array_map([Js::class, 'str'], $data['parts']), fn ($p) => trim($p) !== '')) : null;
        return [
            'reply' => isset($data['reply']) && is_string($data['reply']) && trim($data['reply']) !== '' ? $data['reply'] : null,
            'parts' => $parts && count($parts) > 1 ? $parts : null,
            'escalate' => isset($data['escalate']) && is_string($data['escalate']) ? $data['escalate'] : null,
            'capped' => !empty($data['capped']),
        ];
    }

    /** What the admin page shows and saves besides the knowledge base. */
    public static function admin(): array
    {
        $out = ['configured' => self::configured(), 'tenant' => self::config()['tenant'], 'url' => self::config()['url'],
            'webOn' => Settings::get('chat_web_on') === '1', 'greeting' => Settings::get('chat_greeting'),
            'brand' => null, 'error' => null];
        if (!$out['configured']) return $out;
        try {
            $out['brand'] = self::brand();
        } catch (BookingError $e) {
            $out['error'] = $e->getMessage();
        }
        // the facts the starter knowledge base is built from (with the site's content.js, in the browser)
        $s = Site::info();
        $pay = Bookings::paymentInfo();
        $out['facts'] = [
            'name' => $s['name'], 'baseUrl' => Config::get()['baseUrl'], 'phone' => $pay['phone'] ?: $s['phone'],
            'whatsapp' => Site::waDigits($s['whatsapp']), 'email' => $s['email'], 'address' => $s['address'],
            'mapLink' => Config::location()['place'], 'checkIn' => $s['checkInTime'], 'checkOut' => $s['checkOutTime'],
            'currency' => $s['currency'], 'advanceOn' => $pay['advanceOn'], 'advanceType' => $pay['advanceType'],
            'advancePct' => $pay['advancePct'], 'advanceAmount' => $pay['advanceAmount'], 'bank' => $pay['bank'], 'holdDays' => Bookings::holdDays(),
            'maxRooms' => Bookings::MAX_ROOMS,
        ];
        return $out;
    }

    /** Switch the website's chat window on or off, and its first message. */
    public static function saveWeb(array $in): array
    {
        if (array_key_exists('webOn', $in)) Settings::set(['chat_web_on' => Js::truthy($in['webOn']) ? '1' : '0']);
        if (array_key_exists('greeting', $in)) {
            $g = trim(Js::str($in['greeting'] ?? ''));
            if (mb_strlen($g) > 300) throw new BookingError('Keep the greeting under 300 characters.');
            Settings::set(['chat_greeting' => $g]);
        }
        return ['webOn' => Settings::get('chat_web_on') === '1', 'greeting' => Settings::get('chat_greeting')];
    }
}
