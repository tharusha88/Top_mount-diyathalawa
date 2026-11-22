<?php
// The home page's words and photos, read on the server: js/data/content.js (window.TMR_CONTENT)
// and js/data/media.js (window.TMR_MEDIA). render.js draws the rooms, experiences, dining, places
// nearby and contact details in the browser; this draws the same markup into the page first, so
// readers that don't run JavaScript (Bing much of the time, and AI answer engines such as
// GPTBot, ClaudeBot and PerplexityBot) see the whole page, not just its headings.
//
// render.js replaces every part drawn here with its own markup as soon as it runs, so keep the
// two in step: same elements, same classes. Each photo is a plain <img> inside <noscript>, so it
// costs nothing with JavaScript on (render.js then draws the held-back photo, as before).
declare(strict_types=1);

namespace Ydr;

final class Content
{
    private static ?array $content = null;
    private static ?array $media = null;

    /** window.TMR_CONTENT from js/data/content.js, as an array ([] if it can't be read). */
    public static function get(): array
    {
        if (self::$content !== null) return self::$content;
        $v = null;
        try {
            $v = self::literalAfter(self::file('content.js'), 'TMR_CONTENT');
        } catch (\Throwable $e) {
            error_log('[content] could not read js/data/content.js: ' . $e->getMessage());
        }
        return self::$content = is_array($v) ? $v : [];
    }

    /** window.TMR_MEDIA from js/data/media.js (JSON written by tools/build_media.py): images and videos by key. */
    public static function media(): array
    {
        if (self::$media !== null) return self::$media;
        $src = self::file('media.js');
        $at = strpos($src, '{');
        $end = strrpos($src, '}');
        $v = $at !== false && $end !== false ? json_decode(substr($src, $at, $end - $at + 1), true) : null;
        if (!is_array($v)) {
            try { $v = self::literalAfter($src, 'TMR_MEDIA'); } catch (\Throwable $e) { $v = null; }
        }
        return self::$media = (is_array($v) ? $v : []) + ['images' => [], 'videos' => []];
    }

    public static function image(string $key): ?array
    {
        $m = self::media()['images'][$key] ?? null;
        return is_array($m) ? $m : null;
    }

    /** A photo's JPEG (the widest browsers without WebP get, 1200px), as a full address. */
    public static function imageUrl(string $key): ?string
    {
        $m = self::image($key);
        return $m && !empty($m['fallback']) ? Config::get()['baseUrl'] . '/' . ltrim((string) $m['fallback'], '/') : null;
    }

    /** The JPEG's own width and height (from the file, else from the photo's shape). */
    public static function imageSize(string $key): array
    {
        $m = self::image($key);
        if (!$m) return [0, 0];
        $file = YDR_PUBLIC . '/' . ltrim((string) ($m['fallback'] ?? ''), '/');
        $info = is_file($file) ? @getimagesize($file) : false;
        if ($info) return [(int) $info[0], (int) $info[1]];
        $w = (int) $m['w'];
        $fw = preg_match('/-(\d+)\.\w+$/', (string) ($m['fallback'] ?? ''), $mm) ? (int) $mm[1] : $w;
        return [$fw, $w > 0 ? (int) round($fw * (int) $m['h'] / $w) : 0];
    }

    /**
     * A photo for readers without JavaScript: media.js picture() markup with a plain <img> (the
     * 1200px JPEG), inside <noscript>. With JavaScript on, the browser never parses or downloads
     * it, and render.js draws the real, held-back <picture> in its place.
     */
    public static function picture(string $key, array $opts = []): string
    {
        $m = self::image($key);
        if (!$m || empty($m['fallback'])) return '';
        $e = [self::class, 'esc'];
        $alt = array_key_exists('alt', $opts) ? (string) $opts['alt'] : (string) ($m['alt'] ?? '');
        $pos = !empty($m['pos']) ? ' style="object-position:' . $e($m['pos']) . '"' : '';
        return '<noscript><picture class="' . $e(trim('pic ' . ($opts['cls'] ?? ''))) . '" style="--ph:' . $e($m['color'] ?? '') . '">'
            . '<img src="/' . $e(ltrim((string) $m['fallback'], '/')) . '" width="' . (int) $m['w'] . '" height="' . (int) $m['h'] . '" alt="' . $e($alt) . '"' . $pos . ' decoding="async" loading="lazy">'
            . '</picture></noscript>';
    }

    /**
     * The contact details as render.js shows them: content.js's numbers, with the booking system's
     * phone (Admin → Website) first when it differs; its WhatsApp number; the map listing.
     */
    public static function contact(): array
    {
        $ct = self::get()['contact'] ?? [];
        $s = Site::info();
        $phones = array_values(array_filter($ct['phones'] ?? [], 'is_array'));
        $tel = (string) preg_replace('/[^\d+]/', '', (string) $s['phone']);
        $first = 0;
        foreach ($phones as $i => $p) if (!empty($p['primary'])) { $first = $i; break; }
        if ($tel !== '' && $phones && ($phones[$first]['href'] ?? '') !== "tel:$tel") {
            $rest = array_filter($phones, fn ($p, $i) => $i !== $first && ($p['href'] ?? '') !== "tel:$tel", ARRAY_FILTER_USE_BOTH);
            $phones = [['label' => (string) $s['phone'], 'href' => "tel:$tel", 'primary' => true], ...array_values($rest)];
        } elseif (!$phones && $tel !== '') {
            $phones = [['label' => (string) $s['phone'], 'href' => "tel:$tel", 'primary' => true]];
        }
        $primary = null;
        foreach ($phones as $p) if (!empty($p['primary'])) { $primary = $p; break; }
        return [
            'phones' => $phones,
            'primary' => $primary ?? ($phones[0] ?? ['label' => '', 'href' => '#']),
            'whatsapp' => Site::waDigits($s['whatsapp']),
            'address' => array_values(array_filter(array_map('strval', (array) ($ct['address'] ?? [])), fn ($l) => $l !== '')),
            'maps' => Config::location()['place'],
            'socials' => array_values(array_filter($ct['socials'] ?? [], fn ($x) => is_array($x) && !empty($x['href']))),
        ];
    }

    /** Places nearby, nearest first (as render.js sorts them). */
    public static function nearby(): array
    {
        $near = array_values(array_filter(self::get()['nearby'] ?? [], 'is_array'));
        usort($near, fn ($a, $b) => ($a['km'] ?? 0) <=> ($b['km'] ?? 0));
        return $near;
    }

    /** The rooms that have photos (the accommodation gallery). */
    public static function rooms(): array
    {
        return array_values(array_filter(self::get()['rooms'] ?? [], fn ($r) => is_array($r) && !empty($r['photos'])));
    }

    /**
     * The home page's server-drawn parts, as {{ssr.*}} tokens for views/index.html. Each mirrors a
     * block of render.js (named in the comment), which redraws it in the browser.
     */
    public static function homeTokens(): array
    {
        $C = self::get();
        $e = [self::class, 'esc'];
        $pad = fn (int $n) => str_pad((string) $n, 2, '0', STR_PAD_LEFT);
        $ext = '<svg class="i-ext" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 16 16.5 7.5M9 7h8v8"/></svg>';

        // contact
        $ct = self::contact();
        $t = [
            'ssr.tel' => $e($ct['primary']['href'] ?? '#'),
            'ssr.telLabel' => $e($ct['primary']['label'] ?? ''),
            'ssr.phones' => implode('', array_map(fn ($p) => '<a href="' . $e($p['href'] ?? '') . '">' . $e($p['label'] ?? '') . '</a>', $ct['phones'])),
            'ssr.address' => implode('<br>', array_map($e, $ct['address'])),
            'ssr.maps' => $e($ct['maps']),
            'ssr.wa' => $ct['whatsapp'] !== '' ? 'https://wa.me/' . $e($ct['whatsapp']) : '#',
            'ssr.socials' => implode('', array_map(fn ($x) => '<a href="' . $e($x['href']) . '" target="_blank" rel="noopener">' . $e($x['label'] ?? '') . ' ' . $ext . '</a>', $ct['socials'])),
        ];

        // highlights
        $hls = array_values(array_filter($C['highlights'] ?? [], 'is_array'));
        $t['ssr.hlCount'] = (string) count($hls);
        $t['ssr.highlights'] = implode('', array_map(fn ($h, $i) => '<li class="hl-item' . ($i === 0 ? ' is-active' : '') . '" data-i="' . $i . '">'
            . '<button type="button" class="hl-item__btn" data-highlight="' . $i . '" aria-label="' . $e($h['title'] ?? '') . ': open the photo full screen">'
            . '<span class="hl-item__img">' . self::picture((string) ($h['image'] ?? ''), ['sizes' => '(min-width: 900px) 54vw, (min-width: 600px) 60vw, 84vw']) . '</span>'
            . '<span class="hl-item__shade" aria-hidden="true"></span>'
            . '<span class="hl-item__tab" aria-hidden="true"><b>' . $pad($i + 1) . '</b><span>' . $e($h['title'] ?? '') . '</span></span>'
            . '</button>'
            . '<div class="hl-item__cap">'
            . '<p class="hl-item__num"><span class="hl-reveal"><span>No. ' . $pad($i + 1) . '</span></span></p>'
            . '<h4 class="hl-item__title"><span class="hl-reveal"><span>' . $e($h['title'] ?? '') . '</span></span></h4>'
            . '<p class="hl-item__text"><span class="hl-reveal"><span>' . $e($h['text'] ?? '') . '</span></span></p>'
            . '</div>'
            . '<span class="hl-item__bar" aria-hidden="true"><i></i></span>'
            . '</li>', $hls, array_keys($hls)));

        // accommodation: five rooms shown, the rest wait for "Show more rooms"
        $rooms = self::rooms();
        $grid = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 4.5h6v6h-6zM13.5 4.5h6v6h-6zM4.5 13.5h6v6h-6zM13.5 13.5h6v6h-6z"/></svg>';
        $t['ssr.roomsTotal'] = (string) count($rooms);
        $t['ssr.rooms'] = implode('', array_map(function ($r, $i) use ($e, $grid) {
            $n = count($r['photos']);
            $numbered = preg_match('/^\d+$/', (string) ($r['id'] ?? '')) === 1;
            [$kicker, $label] = $numbered ? ['Room', (string) $r['id']] : [(string) ($r['kicker'] ?? ''), (string) ($r['label'] ?? $r['name'] ?? '')];
            return '<li class="rcard" style="--i:' . $i . '"' . ($i >= 5 ? ' hidden' : '') . '>'
                . '<div class="rcard__float">'
                . '<button type="button" class="rcard__btn" data-room="' . $i . '" aria-label="' . $e($r['name'] ?? '') . ': view all ' . $n . ' photos">'
                . '<span class="rcard__img">' . self::picture((string) $r['photos'][0], ['sizes' => '(min-width: 900px) 20vw, (min-width: 600px) 36vw, 64vw']) . '</span>'
                . '<span class="rcard__shade" aria-hidden="true"></span>'
                . '<span class="rcard__label" aria-hidden="true"><span class="rcard__kicker">' . $e($kicker) . '</span><span class="rcard__num' . (preg_match('/^\d+$/', $label) ? '' : ' rcard__num--name') . '">' . $e($label) . '</span></span>'
                . '<span class="rcard__more" aria-hidden="true">' . $grid . '<span class="rcard__count">' . $n . '</span><span class="rcard__cta">View photos</span></span>'
                . '</button>'
                . '</div>'
                . '</li>';
        }, $rooms, array_keys($rooms)));

        // experiences & dining (xpCard)
        $card = fn (array $x, int $i, bool $reveal = true) => '<li class="xp">'
            . '<figure class="xp__fig"' . ($reveal ? ' data-reveal="clip"' : '') . '>'
            . '<div class="parallax"' . ($reveal ? ' data-parallax="0.06"' : '') . '>' . self::picture((string) ($x['image'] ?? ''), ['sizes' => '(min-width: 1100px) 22vw, 340px']) . '</div>'
            . '</figure>'
            . '<div class="xp__body"' . ($reveal ? ' data-fade' : '') . '>'
            . '<p class="xp__meta"><span class="xp__num">' . $pad($i + 1) . '</span><span class="xp__when">' . $e($x['when'] ?? '') . '</span></p>'
            . '<h3 class="xp__name">' . $e($x['name'] ?? '') . '</h3>'
            . '<p class="xp__text">' . $e($x['text'] ?? '') . '</p>'
            . '</div>'
            . '</li>';
        $xps = array_values(array_filter($C['experiences'] ?? [], 'is_array'));
        $t['ssr.experiences'] = implode('', array_map(fn ($x, $i) => $card($x, $i), $xps, array_keys($xps)));
        $t['ssr.xpTotal'] = $pad(count($xps));
        $places = array_values(array_filter($C['diningPlaces'] ?? [], 'is_array'));
        $menu = array_values(array_map('strval', (array) ($C['dining'] ?? [])));
        $t['ssr.dining'] = implode('', array_map(fn ($x, $i) => $card($x, $i, false), $places, array_keys($places)))
            . ($menu ? '<li class="xp xp--menu"><div class="xp__menu">'
                . '<p class="xp__meta"><span class="xp__num">' . $pad(count($places) + 1) . '</span><span class="xp__when">On the table</span></p>'
                . '<ol class="xp__menulist">' . implode('', array_map(fn ($d, $i) => '<li><span>' . $pad($i + 1) . '</span>' . $e($d) . '</li>', $menu, array_keys($menu))) . '</ol>'
                . '</div></li>' : '');
        $t['ssr.diningTotal'] = $pad(count($places) + ($menu ? 1 : 0));

        // location & nearby
        $t['ssr.location'] = implode('', array_map(fn ($kv) => '<div><dt>' . $e($kv[0] ?? '') . '</dt><dd>' . $e($kv[1] ?? '') . '</dd></div>', array_filter($C['location'] ?? [], 'is_array')));
        $near = self::nearby();
        $kms = array_map(fn ($n) => $n['km'] ?? 0, $near);
        $t['ssr.nearbyCount'] = (string) count($near);
        $t['ssr.kmMin'] = $kms ? (string) min($kms) : '';
        $t['ssr.kmMax'] = $kms ? (string) max($kms) : '';
        $t['ssr.nearby'] = implode('', array_map(fn ($n, $i) => '<li class="place" data-km="' . $e($n['km'] ?? '') . '">'
            . '<button type="button" class="place__media" data-place="' . $i . '" aria-label="View photo: ' . $e($n['name'] ?? '') . '">'
            . self::picture((string) ($n['image'] ?? ''), ['sizes' => '(min-width: 900px) 26vw, 72vw'])
            . '<span class="place__km">' . $e($n['km'] ?? '') . ' km</span>'
            . '</button>'
            . '<div class="place__body">'
            . '<p class="place__type">' . $e($n['type'] ?? '') . '</p>'
            . '<h3 class="place__name">' . $e($n['name'] ?? '') . '</h3>'
            . '<p class="place__desc">' . $e($n['desc'] ?? '') . '</p>'
            . '<a class="link-arrow" href="https://www.google.com/maps/search/?api=1&amp;query=' . $e(Js::encodeURIComponent((string) ($n['map'] ?? $n['name'] ?? ''))) . '" target="_blank" rel="noopener">Directions<span class="sr-only"> to ' . $e($n['name'] ?? '') . ' (opens Google Maps)</span> ' . $ext . '</a>'
            . '</div>'
            . '</li>', $near, array_keys($near)));
        $t['ssr.also'] = implode('', array_map(fn ($a) => '<li><span>' . $e($a[0] ?? '') . '</span><i>' . $e($a[1] ?? '') . ' km</i></li>', array_filter($C['alsoNearby'] ?? [], 'is_array')));

        // reviews (verified only): the section is left out of the page while there are none
        $reviews = array_values(array_filter($C['reviews'] ?? [], 'is_array'));
        $t['ssr.reviews'] = implode('', array_map(fn ($r, $i) => '<figure class="review' . ($i === 0 ? ' is-active' : '') . '" aria-hidden="' . ($i === 0 ? 'false' : 'true') . '">'
            . '<blockquote><p>“' . $e($r['quote'] ?? '') . '”</p></blockquote>'
            . '<figcaption><b>' . $e($r['guest'] ?? '') . '</b><span>' . implode(' · ', array_map($e, array_filter([$r['stay'] ?? '', $r['source'] ?? '', $r['date'] ?? '', $r['rating'] ?? '']))) . '</span></figcaption>'
            . '</figure>', $reviews, array_keys($reviews)));
        return $t;
    }

    /** Leave the guest reviews section out of the page while content.js has no verified reviews. */
    public static function dropEmptyReviews(string $html): string
    {
        if (!empty(self::get()['reviews'])) return $html;
        return (string) preg_replace('#<!-- reviews -->.*?<!-- /reviews -->#s', '', $html);
    }

    public static function esc(mixed $s): string
    {
        return htmlspecialchars(is_scalar($s) ? (string) $s : '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    // ---------- reading content.js: a JavaScript object literal ----------

    private static function file(string $name): string
    {
        $f = YDR_PUBLIC . '/js/data/' . $name;
        return is_file($f) ? (string) file_get_contents($f) : '';
    }

    /** The literal assigned to window.<name> in a script. */
    private static function literalAfter(string $src, string $name): mixed
    {
        $at = strpos($src, $name);
        $eq = $at === false ? false : strpos($src, '=', $at);
        if ($eq === false) throw new \UnexpectedValueException("window.$name not found");
        return self::parseLiteral(substr($src, $eq + 1));
    }

    /**
     * Parse a JavaScript object or array literal: comments, 'single' or "double" quoted strings,
     * bare keys, numbers, true / false / null and trailing commas (what content.js uses). Throws
     * on anything else, such as a function or a variable.
     */
    public static function parseLiteral(string $src): mixed
    {
        $re = '~\G(?:(\s+|//[^\n]*|/\*.*?\*/)|("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')|(-?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)|([A-Za-z_$][\w$]*)|([{}\[\]:,;]))~s';
        $tokens = [];
        $len = strlen($src);
        for ($pos = 0; $pos < $len;) {
            if (!preg_match($re, $src, $m, 0, $pos)) throw new \UnexpectedValueException('Unexpected character at offset ' . $pos);
            $pos += strlen($m[0]);
            if (($m[1] ?? '') !== '') continue;
            if (($m[2] ?? '') !== '') $tokens[] = ['s', self::unquote($m[2])];
            elseif (($m[3] ?? '') !== '') $tokens[] = ['n', $m[3]];
            elseif (($m[4] ?? '') !== '') $tokens[] = ['w', $m[4]];
            else $tokens[] = ['p', $m[5]];
        }
        $i = 0;
        $v = self::value($tokens, $i);
        if (($tokens[$i] ?? null) === ['p', ';']) $i++;
        if ($i !== count($tokens)) throw new \UnexpectedValueException('Unexpected text after the literal');
        return $v;
    }

    private static function value(array $t, int &$i): mixed
    {
        [$type, $v] = $t[$i++] ?? throw new \UnexpectedValueException('Unexpected end');
        if ($type === 's') return $v;
        if ($type === 'n') return $v + 0;
        if ($type === 'w') {
            return match ($v) {
                'true' => true, 'false' => false, 'null', 'undefined' => null,
                default => throw new \UnexpectedValueException("Unexpected '$v'"),
            };
        }
        if ($v === '[' || $v === '{') {
            $close = $v === '[' ? ']' : '}';
            $out = [];
            while (true) {
                if (($t[$i] ?? null) === ['p', $close]) { $i++; return $out; }
                if ($close === ']') {
                    $out[] = self::value($t, $i);
                } else {
                    $k = $t[$i++] ?? null;
                    if (!$k || $k[0] === 'p') throw new \UnexpectedValueException('Expected a key');
                    if (($t[$i++] ?? null) !== ['p', ':']) throw new \UnexpectedValueException("Expected ':' after " . $k[1]);
                    $out[(string) $k[1]] = self::value($t, $i);
                }
                $sep = $t[$i++] ?? null;
                if ($sep === ['p', $close]) return $out;
                if ($sep !== ['p', ',']) throw new \UnexpectedValueException("Expected ',' or '$close'");
            }
        }
        throw new \UnexpectedValueException("Unexpected '$v'");
    }

    private static function unquote(string $tok): string
    {
        return (string) preg_replace_callback('/\\\\(?:u\{([0-9a-fA-F]{1,6})\}|u([0-9a-fA-F]{4})|x([0-9a-fA-F]{2})|(\r\n|[\s\S]))/', function ($m) {
            foreach ([1, 2, 3] as $g) if (($m[$g] ?? '') !== '') return self::utf8((int) hexdec($m[$g]));
            return match ($m[4]) {
                'n' => "\n", 't' => "\t", 'r' => "\r", 'b' => "\x08", 'f' => "\f", 'v' => "\v", '0' => "\0",
                "\n", "\r\n", "\r" => '', // a backslash at the end of a line continues the string
                default => $m[4],
            };
        }, substr($tok, 1, -1));
    }

    private static function utf8(int $cp): string
    {
        if ($cp < 0x80) return chr($cp);
        if ($cp < 0x800) return chr(0xC0 | $cp >> 6) . chr(0x80 | $cp & 0x3F);
        if ($cp < 0x10000) return chr(0xE0 | $cp >> 12) . chr(0x80 | $cp >> 6 & 0x3F) . chr(0x80 | $cp & 0x3F);
        return chr(0xF0 | $cp >> 18) . chr(0x80 | $cp >> 12 & 0x3F) . chr(0x80 | $cp >> 6 & 0x3F) . chr(0x80 | $cp & 0x3F);
    }
}
