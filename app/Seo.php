<?php
// What search engines (Google, Bing) and AI answer engines (ChatGPT, Copilot, Gemini, Claude,
// Perplexity) read about the resort:
//   - head tags: the canonical address, Open Graph / X card, verification codes, the hero preload
//   - structured data (JSON-LD): the resort as a schema.org Resort (address, phones, map, check-in
//     times, facilities, stays, starting rate, booking action), the website, the page, the film,
//     the places nearby and the questions and answers; the booking page's breadcrumb
//   - the questions and answers shown on the home page (the same text as the FAQPage data)
//   - robots.txt, sitemap.xml (with the photos), llms.txt and the IndexNow key file
// Every fact comes from content.js, the media manifest, .env / Admin → Website or the booking
// system (rates, meal plans, advance, cancellation policy). Nothing here invents a price, a
// rating or a review: add verified reviews to content.js, never here.
declare(strict_types=1);

namespace Ydr;

final class Seo
{
    /** The resort in one sentence: the home page's description (keep in step with views/index.html). */
    private const SUMMARY = 'A 3-acre estate in the cool, pine-covered hills of Diyatalawa, Sri Lanka, with wooden cabanas, a clay-walled mud house, villas and a whole bungalow.';

    /** Photos for the resort's structured data and its social card, best first. */
    private const PHOTOS = ['hero-poster', 'estate-golden', 'cabana-hillside', 'bungalow-aerial', 'dining-deck', 'cabin-bedroom'];

    private static ?array $facts = null;

    private static function base(): string
    {
        return Config::get()['baseUrl'];
    }

    // ---------- the facts every part below is built from ----------

    /** The resort's details, its rates and booking rules (each booking-system part null when the database can't be read). */
    public static function facts(): array
    {
        if (self::$facts !== null) return self::$facts;
        $s = Site::info();
        $loc = Config::location();
        $ct = Content::contact();
        $parts = array_values(array_filter(array_map('trim', explode(',', (string) $s['address'])), fn ($p) => $p !== ''));
        $country = $parts && preg_match('/^sri\s*lanka$/i', (string) end($parts)) ? 'LK' : '';
        if ($country) array_pop($parts);
        $locality = count($parts) > 1 ? (string) array_pop($parts) : '';

        $f = [
            'name' => (string) $s['name'],
            'phones' => array_values(array_filter(array_map(fn ($p) => (string) ($p['label'] ?? ''), $ct['phones']))),
            'whatsapp' => $ct['whatsapp'],
            'email' => (string) $s['email'],
            'address' => trim((string) $s['address']),
            'street' => implode(', ', $parts),
            'locality' => $locality,
            'country' => $country,
            'lat' => $loc['lat'], 'lng' => $loc['lng'], 'coords' => $loc['coords'], 'plusCode' => (string) $loc['plusCode'],
            'map' => $loc['place'],
            'checkIn' => (string) $s['checkInTime'], 'checkOut' => (string) $s['checkOutTime'],
            'currency' => (string) $s['currency'],
            'socials' => array_values(array_unique(array_filter([
                ...array_map(fn ($x) => (string) $x['href'], $ct['socials']), (string) $s['facebook'], (string) $s['instagram'],
            ], fn ($u) => preg_match('#^https?://#', $u) === 1))),
            'fromPrice' => null, 'plans' => [], 'advanceOn' => null, 'policy' => '',
        ];
        try {
            // the lowest nightly rate of any room guests can book, and the meal plans some room is priced for (as book.js shows them)
            $rooms = Bookings::listRooms();
            $ro = array_filter(array_map(fn ($r) => (int) ($r['prices']['RO'] ?? $r['price'] ?? 0), $rooms), fn ($p) => $p > 0);
            $f['fromPrice'] = $ro ? min($ro) : null;
            foreach (Bookings::mealPlans() as $p) {
                foreach ($rooms as $r) if ((int) ($r['prices'][$p['code']] ?? 0) > 0) { $f['plans'][] = $p; break; }
            }
            $f['advanceOn'] = Bookings::advanceRule()['on'];
            $f['policy'] = Bookings::cancellationPolicy();
        } catch (\Throwable $e) {
            // no database: the page still has everything that comes from content.js and .env
        }
        return self::$facts = $f;
    }

    /** "2:00 PM" → "14:00" (schema.org times), null if it can't be read. */
    private static function time24(string $t): ?string
    {
        $ts = $t === '' ? false : strtotime('2000-01-01 ' . $t);
        return $ts === false ? null : date('H:i', $ts);
    }

    private static function money(int $n, string $cur): string
    {
        return trim($cur . ' ' . number_format($n));
    }

    /** "a, b and c" (or "a, b or c") */
    private static function andList(array $items, string $last = 'and'): string
    {
        $items = array_values(array_filter(array_map('strval', $items), fn ($x) => $x !== ''));
        if (count($items) < 2) return $items[0] ?? '';
        return implode(', ', array_slice($items, 0, -1)) . " $last " . end($items);
    }

    /** The WhatsApp number as people read it: the phone number it matches, else +digits. */
    private static function waLabel(): string
    {
        $f = self::facts();
        foreach ($f['phones'] as $p) if (preg_replace('/\D/', '', $p) === $f['whatsapp']) return $p;
        return '+' . $f['whatsapp'];
    }

    /** Lower-case a label's first letter for use mid-sentence, except names and acronyms ("Sri Lankan", "TV"). */
    private static function lc(string $s): string
    {
        if (preg_match('/^(Sri|Ceylon|Ella|Uva|Horton|Lipton|Ravana|Adam)\b/', $s) || preg_match('/^.[A-Z]/', $s)) return $s;
        return strtolower(substr($s, 0, 1)) . substr($s, 1);
    }

    /** "2 – 4 adults, 1 child" → the most guests (5). */
    private static function maxGuests(string $g): ?int
    {
        $n = 0;
        if (preg_match('/(\d+)\s*adults?/i', $g, $m)) $n += (int) $m[1];
        if (preg_match('/(\d+)\s*child(?:ren)?/i', $g, $m)) $n += (int) $m[1];
        return $n ?: null;
    }

    /** "25 – 35 m²" → a schema.org size in square metres. */
    private static function floorSize(string $s): ?array
    {
        if (!preg_match_all('/\d+(?:\.\d+)?/', $s, $m)) return null;
        $n = array_map(fn ($x) => $x + 0, $m[0]);
        return count($n) > 1
            ? ['@type' => 'QuantitativeValue', 'minValue' => min($n), 'maxValue' => max($n), 'unitCode' => 'MTK']
            : ['@type' => 'QuantitativeValue', 'value' => $n[0], 'unitCode' => 'MTK'];
    }

    private static function locationFacts(): array
    {
        $out = [];
        foreach (Content::get()['location'] ?? [] as $kv) if (is_array($kv) && isset($kv[0], $kv[1])) $out[strtolower((string) $kv[0])] = (string) $kv[1];
        return $out;
    }

    // ---------- questions and answers ----------

    /**
     * The home page's questions and answers, for guests and for answer engines: short, factual,
     * each answer complete on its own. The same list feeds the FAQPage data and llms.txt.
     * @return list<array{q:string, a:string, links:array<string,string>}>  links: text in the answer => where it links
     */
    public static function faq(): array
    {
        $C = Content::get();
        $f = self::facts();
        $name = $f['name'];
        $base = self::base();
        $out = [];
        $add = function (string $q, string $a, array $links = []) use (&$out) {
            $a = trim((string) preg_replace('/\s+/', ' ', $a));
            if ($a !== '') $out[] = ['q' => $q, 'a' => $a, 'links' => $links];
        };
        $guests = fn (string $g) => str_replace(', ', ' and ', $g);
        $stays = array_values(array_filter($C['stays'] ?? [], fn ($x) => is_array($x) && !empty($x['name'])));
        $near = Content::nearby();
        $also = array_values(array_filter($C['alsoNearby'] ?? [], fn ($x) => is_array($x) && isset($x[0], $x[1])));
        $loc = self::locationFacts();

        // where
        $a = "$name is a 3-acre estate in the cool, pine-covered hills of Diyatalawa, Sri Lanka. The address is {$f['address']}.";
        $trips = isset($loc['day trips']) ? self::andList(preg_split('/\s*·\s*/u', $loc['day trips'])) : '';
        if (isset($loc['close by'])) $a .= " {$loc['close by']} is close by" . ($trips !== '' ? ", and $trips are within a day trip." : '.');
        elseif ($trips !== '') $a .= " $trips are within a day trip.";
        if ($f['coords'] !== '') $a .= " Map location: {$f['coords']}" . ($f['plusCode'] !== '' ? " (plus code {$f['plusCode']})" : '') . '.';
        $add("Where is $name?", $a, ['the map' => $f['map']]);

        // the stays
        $rooms = count(Content::rooms());
        if ($stays) {
            $list = self::andList(array_map(fn ($st) => $st['name'] . (($d = implode(', ', array_filter([
                (string) ($st['size'] ?? ''), !empty($st['guests']) ? 'sleeps ' . $guests((string) $st['guests']) : '',
            ]))) !== '' ? " ($d)" : ''), $stays));
            $add("What kind of rooms does $name have?", ($rooms ? "There are $rooms rooms, in " . count($stays) . ' kinds of stay: ' : 'The stays are: ') . $list . '.');
        }

        // rates (from the booking system: what the booking page charges)
        if ($f['fromPrice']) {
            $add("How much does a room at $name cost?", 'Rooms start from ' . self::money($f['fromPrice'], $f['currency']) . ' a night, room only. '
                . 'The booking page shows the exact price for your dates, rooms and meal plan before you book.', ['The booking page' => "$base/book"]);
        }

        // families and groups
        $big = array_values(array_filter($stays, fn ($st) => preg_match('/famil|group/i', (string) ($st['kind'] ?? '')) || (self::maxGuests((string) ($st['guests'] ?? '')) ?? 0) >= 6));
        if ($big) {
            $add("Is $name good for families and groups?", 'Yes. Stays for families and groups: '
                . self::andList(array_map(fn ($st) => $st['name'] . (!empty($st['guests']) ? ' (' . $guests((string) $st['guests']) . ')' : ''), $big))
                . '. A family or group can also book up to ' . Bookings::MAX_ROOMS . ' rooms together in one booking.');
        }

        // check-in and check-out
        if ($f['checkIn'] !== '' || $f['checkOut'] !== '') {
            $add('What are the check-in and check-out times?', implode(' ', array_filter([
                $f['checkIn'] !== '' ? "Check-in is at {$f['checkIn']}." : '', $f['checkOut'] !== '' ? "Check-out is at {$f['checkOut']}." : '',
            ])));
        }

        // facilities
        $amen = array_values(array_filter(array_map(fn ($x) => (string) ($x['label'] ?? ''), $C['amenities'] ?? [])));
        if ($amen) $add("What facilities does $name have?", ucfirst(self::andList(array_map([self::class, 'lc'], $amen))) . '.');

        // food
        $menu = array_values(array_map('strval', (array) ($C['dining'] ?? [])));
        if ($menu) {
            $a = 'Yes: ' . self::andList(array_map([self::class, 'lc'], $menu)) . '.';
            $deck = array_values(array_filter($C['diningPlaces'] ?? [], 'is_array'))[0] ?? null;
            if ($deck && preg_match('/^(An?|The)\s/', (string) ($deck['text'] ?? ''))) $a .= ' ' . $deck['name'] . ' is ' . self::lc(rtrim((string) $deck['text'], '.')) . '.';
            $plans = array_values(array_filter($f['plans'], fn ($p) => $p['code'] !== 'RO'));
            if ($plans) {
                $a .= ' When you book a room you can add ' . self::andList(array_map(fn ($p) => self::lc($p['name']) . (stripos($p['name'], $p['includes']) === false ? ' (' . self::lc($p['includes']) . ')' : ''), $plans), 'or') . '.';
            }
            $add("Is food served at $name?", $a);
        }

        // on the estate
        $xps = array_values(array_filter(array_map(fn ($x) => (string) ($x['name'] ?? ''), $C['experiences'] ?? [])));
        if ($xps) $add("What is there to do at $name?", 'On the estate: ' . self::andList(array_map([self::class, 'lc'], $xps)) . '.');

        // places nearby
        if ($near) {
            $kms = array_map(fn ($n) => $n['km'], $near);
            $a = count($near) . ' places within a day trip, from ' . min($kms) . ' to ' . max($kms) . ' km away: '
                . self::andList(array_map(fn ($n) => "{$n['name']} ({$n['km']} km)", $near)) . '.';
            if ($also) $a .= ' Also close by: ' . self::andList(array_map(fn ($x) => "{$x[0]} ({$x[1]} km)", $also)) . '.';
            $add("What is there to see near $name?", $a);
        }

        // Ella and Horton Plains, the two day trips people ask about most
        $ella = array_values(array_filter($near, fn ($n) => preg_match('/\bElla\b/', (string) ($n['map'] ?? '') . ' ' . $n['name'])));
        if ($ella) {
            $k = array_map(fn ($n) => $n['km'], $ella);
            $add("How far is $name from Ella?", "The sights around Ella are " . (min($k) === max($k) ? min($k) : min($k) . ' to ' . max($k)) . ' km from the resort: '
                . self::andList(array_map(fn ($n) => "{$n['name']} ({$n['km']} km)", $ella)) . '.');
        }
        foreach ([...$also, ...array_map(fn ($n) => [$n['name'], $n['km']], $near)] as $x) {
            if (preg_match('/Horton Plains/i', (string) $x[0])) { $add('How far is it to Horton Plains?', "{$x[0]} is {$x[1]} km from $name, a day trip from the resort."); break; }
        }

        // booking and paying
        $a = "Book online on the booking page: choose your dates, see which rooms are free and send your details in a few minutes. No payment is taken online";
        if ($f['advanceOn'] === true) $a .= "; the booking is confirmed once $name receives the advance payment.";
        elseif ($f['advanceOn'] === false) $a .= ': there is nothing to pay before you arrive, and you pay at the resort.';
        else $a .= '.';
        if ($f['phones']) $a .= ' You can also book by phone on ' . $f['phones'][0] . ($f['whatsapp'] !== '' ? ' or on WhatsApp' : '') . '.';
        $add("How do I book $name, and do I pay online?", $a, ['the booking page' => "$base/book"] + ($f['phones'] ? [$f['phones'][0] => 'tel:' . preg_replace('/[^\d+]/', '', $f['phones'][0])] : []));

        // cancellation (Admin → Booking setup)
        if ($f['policy'] !== '') {
            $p = trim((string) preg_replace('/\s+/', ' ', preg_replace('/^\s*[-*•]\s*/mu', '', $f['policy'])));
            if (strlen($p) > 600) $p = rtrim(substr($p, 0, (int) (strrpos(substr($p, 0, 600), '. ') ?: 597) + 1)) . ' The full policy is on the booking page.';
            $add('What is the cancellation policy?', $p, ['the booking page' => "$base/book#cancellation-policy"]);
        }

        // contact
        $ways = [];
        if ($f['phones']) $ways[] = 'call ' . self::andList($f['phones'], 'or');
        if ($f['whatsapp'] !== '') $ways[] = 'send a WhatsApp message to ' . self::waLabel() . (in_array(self::waLabel(), $f['phones'], true) ? ' (the same number)' : '');
        if ($f['email'] !== '') $ways[] = 'email ' . $f['email'];
        $links = [];
        foreach ($f['phones'] as $p) $links[$p] = 'tel:' . preg_replace('/[^\d+]/', '', $p);
        if ($f['whatsapp'] !== '') $links['a WhatsApp message'] = 'https://wa.me/' . $f['whatsapp'];
        if ($f['email'] !== '') $links[$f['email']] = 'mailto:' . $f['email'];
        if ($ways) $add("How can I contact $name?", ucfirst(implode(', ', array_slice($ways, 0, -1)) . (count($ways) > 1 ? ', or ' : '') . end($ways)) . '.', $links);

        return $out;
    }

    /** The questions and answers as the home page shows them (views/index.html, section #faq). */
    public static function faqHtml(): string
    {
        $e = [Content::class, 'esc'];
        $html = '';
        foreach (self::faq() as $i => $x) {
            $a = $e($x['a']);
            foreach ($x['links'] as $text => $href) {
                $t = $e($text);
                $at = strpos($a, $t);
                if ($at === false) continue;
                $ext = preg_match('#^https?://#', $href) && !str_starts_with($href, self::base());
                $a = substr_replace($a, '<a href="' . $e(str_starts_with($href, self::base()) ? (substr($href, strlen(self::base())) ?: '/') : $href) . '"' . ($ext ? ' target="_blank" rel="noopener"' : '') . '>' . $t . '</a>', $at, strlen($t));
            }
            $html .= '<details class="faq__item"' . ($i === 0 ? ' open' : '') . '>'
                . '<summary class="faq__q"><h3>' . $e($x['q']) . '</h3><span class="faq__icon" aria-hidden="true"></span></summary>'
                . '<div class="faq__a"><p>' . $a . '</p></div>'
                . '</details>';
        }
        return $html;
    }

    // ---------- head tags ----------

    /**
     * The tags for a page's <head> that depend on the site's address and settings: canonical,
     * robots, Open Graph image and address, X card, Search Console / Bing verification, place,
     * and (home) an early start on the hero photo, the largest thing on the first screen.
     */
    public static function headTags(string $page): string
    {
        $e = [Content::class, 'esc'];
        $base = self::base();
        $f = self::facts();
        $cfg = Config::get()['seo'];
        $url = $page === 'book' ? "$base/book" : "$base/";
        $tags = [
            '<link rel="canonical" href="' . $e($url) . '">',
            '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">',
            '<meta property="og:url" content="' . $e($url) . '">',
            '<meta property="og:site_name" content="' . $e($f['name']) . '">',
        ];
        // the booking section's photo and the hero's cover, as Admin → Photos & videos has them
        $img = $page === 'book' ? HomeMedia::book() : (HomeMedia::poster('hero') ?: 'hero-poster');
        if (($src = Content::imageUrl($img)) !== null) {
            [$w, $h] = Content::imageSize($img);
            $tags[] = '<meta property="og:image" content="' . $e($src) . '">';
            if ($w && $h) $tags[] = '<meta property="og:image:width" content="' . $w . '"><meta property="og:image:height" content="' . $h . '">';
            $tags[] = '<meta property="og:image:alt" content="' . $e((Content::image($img)['alt'] ?? '') ?: $f['name']) . '">';
        }
        $tags[] = '<meta name="twitter:card" content="summary_large_image">';
        if ($cfg['googleVerification'] !== '') $tags[] = '<meta name="google-site-verification" content="' . $e($cfg['googleVerification']) . '">';
        if ($cfg['bingVerification'] !== '') $tags[] = '<meta name="msvalidate.01" content="' . $e($cfg['bingVerification']) . '">';
        if ($f['country'] !== '') $tags[] = '<meta name="geo.region" content="' . $e($f['country']) . '">';
        if ($f['locality'] !== '') $tags[] = '<meta name="geo.placename" content="' . $e($f['locality']) . '">';
        if ($f['lat'] !== null) $tags[] = '<meta name="geo.position" content="' . $f['lat'] . ';' . $f['lng'] . '"><meta name="ICBM" content="' . $f['lat'] . ', ' . $f['lng'] . '">';
        if ($page === 'home') $tags[] = self::heroPreload();
        return implode("\n", array_filter($tags));
    }

    /**
     * Start the hero's poster photo with the page instead of after the scripts (media.js draws it):
     * it is the page's largest content, so this brings Largest Contentful Paint forward. Same files
     * and sizes as media.js picks (the tall poster on portrait screens), so nothing loads twice.
     */
    private static function heroPreload(): string
    {
        $v = Content::media()['videos']['hero'] ?? null;
        if (!is_array($v)) return '';
        $out = [];
        $posters = ['(min-aspect-ratio: 10/11)' => $v['poster'] ?? null, '(max-aspect-ratio: 10/11)' => $v['posterPortrait'] ?? null];
        if (!$posters['(max-aspect-ratio: 10/11)']) $posters = ['' => $v['poster'] ?? null];
        foreach ($posters as $media => $key) {
            $m = $key ? Content::image((string) $key) : null;
            if (!$m || empty($m['widths'])) continue;
            $set = implode(', ', array_map(fn ($w) => $m['base'] . '-' . (int) $w . '.webp ' . (int) $w . 'w', $m['widths']));
            $out[] = '<link rel="preload" as="image" type="image/webp" imagesrcset="' . Content::esc($set) . '" imagesizes="100vw" fetchpriority="high"' . ($media !== '' ? ' media="' . $media . '"' : '') . '>';
        }
        return implode("\n", $out);
    }

    // ---------- structured data ----------

    /** The page's JSON-LD (a <script type="application/ld+json">). $html: the page, for its title, description and film length. */
    public static function jsonLd(string $page, string $html): string
    {
        $base = self::base();
        $f = self::facts();
        $title = preg_match('#<title>(.*?)</title>#s', $html, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8') : $f['name'];
        $desc = preg_match('#<meta name="description" content="([^"]*)"#', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : self::SUMMARY;
        $resort = ['@id' => "$base/#resort"];
        $website = ['@type' => 'WebSite', '@id' => "$base/#website", 'url' => "$base/", 'name' => $f['name'], 'inLanguage' => 'en', 'publisher' => $resort];

        if ($page === 'book') {
            $graph = [
                $website,
                [
                    '@type' => 'WebPage', '@id' => "$base/book#webpage", 'url' => "$base/book", 'name' => $title, 'description' => $desc,
                    'isPartOf' => ['@id' => "$base/#website"], 'about' => $resort, 'breadcrumb' => ['@id' => "$base/book#breadcrumb"], 'inLanguage' => 'en',
                    'potentialAction' => self::reserveAction(),
                ],
                [
                    '@type' => 'BreadcrumbList', '@id' => "$base/book#breadcrumb", 'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => $f['name'], 'item' => "$base/"],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Book your stay', 'item' => "$base/book"],
                    ],
                ],
                self::resort(),
            ];
        } else {
            $modified = max(array_map(fn ($p) => (int) @filemtime($p), [YDR_ROOT . '/views/index.html', YDR_PUBLIC . '/js/data/content.js', YDR_PUBLIC . '/js/data/media.js']));
            $hp = HomeMedia::poster('hero') ?: 'hero-poster';
            $graph = [
                $website,
                array_filter([
                    '@type' => 'WebPage', '@id' => "$base/#webpage", 'url' => "$base/", 'name' => $title, 'description' => $desc,
                    'isPartOf' => ['@id' => "$base/#website"], 'about' => $resort, 'mainEntity' => $resort,
                    'primaryImageOfPage' => Content::imageUrl($hp) ? ['@id' => "$base/#primaryimage"] : null,
                    'inLanguage' => 'en', 'dateModified' => $modified ? date('c', $modified) : null,
                ], fn ($v) => $v !== null),
                self::resort(),
            ];
            if (($src = Content::imageUrl($hp)) !== null) {
                [$w, $h] = Content::imageSize($hp);
                $graph[] = ['@type' => 'ImageObject', '@id' => "$base/#primaryimage", 'url' => $src, 'contentUrl' => $src, 'width' => $w, 'height' => $h, 'caption' => (Content::image($hp)['alt'] ?? '') ?: self::facts()['name']];
            }
            if ($film = self::film($html)) $graph[] = $film;
            $near = Content::nearby();
            if ($near) {
                $graph[] = [
                    '@type' => 'ItemList', '@id' => "$base/#nearby", 'name' => 'Places to visit near ' . $f['name'], 'numberOfItems' => count($near),
                    'itemListElement' => array_map(fn ($n, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'item' => array_filter([
                        '@type' => 'TouristAttraction', 'name' => $n['name'],
                        'description' => "{$n['km']} km from {$f['name']}. " . ($n['desc'] ?? ''),
                        'image' => Content::imageUrl((string) ($n['image'] ?? '')),
                        'hasMap' => 'https://www.google.com/maps/search/?api=1&query=' . Js::encodeURIComponent((string) ($n['map'] ?? $n['name'])),
                    ])], $near, array_keys($near)),
                ];
            }
            $faq = self::faq();
            if ($faq) {
                $graph[] = [
                    '@type' => 'FAQPage', '@id' => "$base/#faq", 'url' => "$base/#faq", 'isPartOf' => ['@id' => "$base/#webpage"], 'inLanguage' => 'en',
                    'mainEntity' => array_map(fn ($x) => ['@type' => 'Question', 'name' => $x['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x['a']]], $faq),
                ];
            }
        }
        $json = json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE);
        return '<script type="application/ld+json">' . $json . '</script>';
    }

    private static function reserveAction(): array
    {
        $f = self::facts();
        return [
            '@type' => 'ReserveAction', 'name' => 'Book your stay',
            'target' => ['@type' => 'EntryPoint', 'urlTemplate' => self::base() . '/book', 'inLanguage' => 'en', 'actionPlatform' => ['https://schema.org/DesktopWebPlatform', 'https://schema.org/MobileWebPlatform']],
            'result' => ['@type' => 'LodgingReservation', 'name' => 'A stay at ' . $f['name']],
        ];
    }

    /** The resort: a schema.org Resort (a LodgingBusiness and LocalBusiness). */
    private static function resort(): array
    {
        $base = self::base();
        $f = self::facts();
        $C = Content::get();
        $stays = array_values(array_filter($C['stays'] ?? [], fn ($x) => is_array($x) && !empty($x['name'])));
        $features = array_merge(
            array_map(fn ($x) => (string) ($x['label'] ?? ''), $C['amenities'] ?? []),
            array_map(fn ($x) => (string) ($x['name'] ?? ''), $C['experiences'] ?? []),
            !empty($C['diningPlaces']) ? ['Open-air dining deck'] : [],
        );
        $logo = is_file(YDR_PUBLIC . '/assets/brand/logo.png') ? "$base/assets/brand/logo.png" : null;
        $r = [
            '@type' => 'Resort',
            '@id' => "$base/#resort",
            'name' => $f['name'],
            'description' => self::SUMMARY,
            'url' => "$base/",
            'image' => array_values(array_filter(array_map([Content::class, 'imageUrl'], self::PHOTOS))),
            'logo' => $logo,
            'telephone' => $f['phones'][0] ?? null,
            'email' => $f['email'] !== '' ? $f['email'] : null,
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $f['street'] !== '' ? $f['street'] : $f['address'],
                'addressLocality' => $f['locality'] ?: null,
                'addressCountry' => $f['country'] ?: null,
            ]),
            'geo' => $f['lat'] !== null ? ['@type' => 'GeoCoordinates', 'latitude' => $f['lat'], 'longitude' => $f['lng']] : null,
            'hasMap' => $f['map'],
            'checkinTime' => self::time24($f['checkIn']),
            'checkoutTime' => self::time24($f['checkOut']),
            'numberOfRooms' => count(Content::rooms()) ?: null,
            'priceRange' => $f['fromPrice'] ? 'From ' . self::money($f['fromPrice'], $f['currency']) . ' a night' : null,
            'currenciesAccepted' => $f['currency'] ?: null,
            'amenityFeature' => array_values(array_map(fn ($n) => ['@type' => 'LocationFeatureSpecification', 'name' => $n, 'value' => true], array_values(array_unique(array_filter($features))))),
            'containsPlace' => array_map(fn ($st) => array_filter([
                '@type' => 'Accommodation',
                'name' => $st['name'],
                'description' => (string) ($st['desc'] ?? '') !== '' ? $st['desc'] : (($st['tagline'] ?? '') !== '' ? $st['tagline'] . '.' : null),
                'floorSize' => !empty($st['size']) ? self::floorSize((string) $st['size']) : null,
                'occupancy' => ($g = self::maxGuests((string) ($st['guests'] ?? ''))) ? ['@type' => 'QuantitativeValue', 'maxValue' => $g] : null,
                'amenityFeature' => !empty($st['view']) ? [['@type' => 'LocationFeatureSpecification', 'name' => $st['view'], 'value' => true]] : null,
            ]), $stays),
            'contactPoint' => array_map(fn ($p) => ['@type' => 'ContactPoint', 'telephone' => $p, 'contactType' => 'reservations'], $f['phones']),
            'potentialAction' => self::reserveAction(),
            'sameAs' => $f['socials'] ?: null,
        ];
        return array_filter($r, fn ($v) => $v !== null && $v !== []);
    }

    /** The drone film behind "Watch the film" (its length is read from the page's label, e.g. 1:40). */
    private static function film(string $html): ?array
    {
        $v = Content::media()['videos']['film'] ?? null;
        $src = is_array($v) ? ($v['sources'][0]['src'] ?? null) : null;
        if (!$src) return null;
        $file = YDR_PUBLIC . '/' . ltrim((string) $src, '/');
        $at = is_file($file) ? (int) filemtime($file) : 0;
        $thumb = Content::imageUrl((string) ($v['poster'] ?? ''));
        if (!$at || !$thumb) return null;
        $dur = preg_match('#class="dfilm__dur">(?:<span[^>]*>[^<]*</span>)?\s*(\d+):(\d{2})#', $html, $m) ? 'PT' . (int) $m[1] . 'M' . (int) $m[2] . 'S' : null;
        $base = self::base();
        return array_filter([
            '@type' => 'VideoObject', '@id' => "$base/#film",
            'name' => self::facts()['name'] . ' from above',
            'description' => (string) ($v['label'] ?? 'A drone film of the resort') . ': the estate, its cabanas and villas, and the hills of Diyatalawa.',
            'thumbnailUrl' => $thumb,
            'contentUrl' => $base . '/' . ltrim((string) $src, '/'),
            'uploadDate' => date('c', $at),
            'duration' => $dur,
            'publisher' => ['@id' => "$base/#resort"],
        ]);
    }

    // ---------- robots.txt, sitemap.xml, llms.txt, IndexNow ----------

    public static function robots(): string
    {
        $base = self::base();
        // One group for every crawler, AI assistants included (GPTBot, OAI-SearchBot, ClaudeBot,
        // PerplexityBot, Google-Extended, Bingbot): a named group would replace these rules for
        // that crawler, not add to them. Private links (/confirm, /invoice, /receipt, /bill) stay
        // crawlable on purpose: they answer "noindex", which a crawler must be able to read.
        return "# $base\n"
            . "User-agent: *\n"
            . "Disallow: /admin/\n"
            . "Disallow: /pos\n"
            . "Disallow: /api/\n"
            . "Disallow: /finance/\n"
            . "Disallow: /check.php\n"
            . "\n"
            . "Sitemap: $base/sitemap.xml\n"
            . "# A plain-text guide to the resort for AI assistants: $base/llms.txt\n";
    }

    /** The two public pages, each with its photos (Google Images reads <image:image>). */
    public static function sitemap(): string
    {
        $base = self::base();
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $C = Content::get();
        $keys = [HomeMedia::poster('hero'), HomeMedia::poster('film'), HomeMedia::poster('aerial'), HomeMedia::book()];
        foreach ($C['highlights'] ?? [] as $x) $keys[] = (string) ($x['image'] ?? '');
        foreach (Content::rooms() as $r) $keys[] = (string) $r['photos'][0];
        foreach ([...($C['experiences'] ?? []), ...($C['diningPlaces'] ?? [])] as $x) $keys[] = (string) ($x['image'] ?? '');
        foreach (Content::nearby() as $x) $keys[] = (string) ($x['image'] ?? '');
        $images = array_values(array_unique(array_filter(array_map([Content::class, 'imageUrl'], array_unique($keys)))));
        $day = fn (array $files) => date('Y-m-d', max(array_map(fn ($p) => (int) @filemtime($p), $files)) ?: time());
        $url = fn (string $loc, string $mod, array $imgs = []) => '<url><loc>' . $e($loc) . '</loc><lastmod>' . $mod . '</lastmod>'
            . implode('', array_map(fn ($i) => '<image:image><image:loc>' . $e($i) . '</image:loc></image:image>', $imgs)) . '</url>';
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'
            . $url("$base/", $day([YDR_ROOT . '/views/index.html', YDR_PUBLIC . '/js/data/content.js', YDR_PUBLIC . '/js/data/media.js']), $images)
            . $url("$base/book", $day([YDR_ROOT . '/views/book.html']))
            . '</urlset>' . "\n";
    }

    /**
     * /llms.txt (llmstxt.org): the resort in plain Markdown for AI assistants: what it is, where,
     * the stays, facilities, food, things to do, places nearby, how to book, and the same
     * questions and answers as the home page.
     */
    public static function llms(): string
    {
        $base = self::base();
        $f = self::facts();
        $C = Content::get();
        $md = fn ($s) => trim((string) preg_replace('/\s+/', ' ', (string) $s));
        $o = ["# {$f['name']}", '', '> ' . self::SUMMARY . ' ' . count(Content::rooms()) . ' rooms. Book direct online, with live availability; no payment is taken online.', ''];

        $o[] = '## Key facts';
        $o[] = "- Address: {$f['address']}";
        if ($f['coords'] !== '') $o[] = "- Map location: {$f['coords']}" . ($f['plusCode'] !== '' ? " (plus code {$f['plusCode']})" : '');
        $o[] = "- Map: {$f['map']}";
        if ($f['phones']) $o[] = '- Phone: ' . implode(', ', $f['phones']);
        if ($f['whatsapp'] !== '') $o[] = '- WhatsApp: ' . self::waLabel() . " (https://wa.me/{$f['whatsapp']})";
        if ($f['email'] !== '') $o[] = "- Email: {$f['email']}";
        if ($f['checkIn'] !== '') $o[] = "- Check-in: {$f['checkIn']}";
        if ($f['checkOut'] !== '') $o[] = "- Check-out: {$f['checkOut']}";
        if ($f['fromPrice']) $o[] = '- Rooms from: ' . self::money($f['fromPrice'], $f['currency']) . ' a night, room only (the booking page shows the price for your dates)';
        foreach (self::locationFacts() as $k => $v) $o[] = '- ' . ucfirst($k) . ": $v";
        foreach ($f['socials'] as $s) $o[] = "- Profile: $s";
        $o[] = '';

        $stays = array_values(array_filter($C['stays'] ?? [], fn ($x) => is_array($x) && !empty($x['name'])));
        if ($stays) {
            $o[] = '## Stays';
            foreach ($stays as $st) {
                $o[] = '- ' . $st['name'] . (!empty($st['kind']) ? " ({$st['kind']})" : '') . ': ' . $md(implode(' ', array_filter([
                    !empty($st['desc']) ? $st['desc'] : (!empty($st['tagline']) ? $st['tagline'] . '.' : ''),
                    !empty($st['size']) ? "Size {$st['size']}." : '', !empty($st['guests']) ? "Sleeps {$st['guests']}." : '', !empty($st['view']) ? "{$st['view']}." : '',
                ])));
            }
            $o[] = '';
        }
        $sections = [
            'Facilities' => array_map(fn ($x) => (string) ($x['label'] ?? ''), $C['amenities'] ?? []),
            'Things to do at the resort' => array_map(fn ($x) => trim(($x['name'] ?? '') . ': ' . ($x['text'] ?? ''), ': '), $C['experiences'] ?? []),
            'Food and dining' => [!empty($C['dining']) ? 'On the table: ' . self::andList(array_map([self::class, 'lc'], array_map('strval', (array) $C['dining']))) : '', ...array_map(fn ($x) => trim(($x['name'] ?? '') . ': ' . ($x['text'] ?? ''), ': '), $C['diningPlaces'] ?? [])],
            'Places nearby (distance from the resort)' => [
                ...array_map(fn ($n) => "{$n['name']} ({$n['km']} km, {$n['type']}): " . ($n['desc'] ?? ''), Content::nearby()),
                ...array_map(fn ($x) => "{$x[0]} ({$x[1]} km)", array_filter($C['alsoNearby'] ?? [], 'is_array')),
            ],
        ];
        foreach ($sections as $title => $items) {
            $items = array_values(array_filter(array_map($md, $items)));
            if (!$items) continue;
            $o[] = "## $title";
            foreach ($items as $x) $o[] = "- $x";
            $o[] = '';
        }
        $faq = self::faq();
        if ($faq) {
            $o[] = '## Questions and answers';
            foreach ($faq as $x) { $o[] = "### {$x['q']}"; $o[] = $x['a']; $o[] = ''; }
        }
        $o[] = '## Pages';
        $o[] = "- [Home]($base/): photos of every room, the estate, experiences, dining, location and places nearby";
        $o[] = "- [Book your stay]($base/book): live availability and prices for your dates; book online in a few minutes";
        return implode("\n", $o) . "\n";
    }

    /** The IndexNow key from .env (INDEXNOW_KEY), or '' when it is not set or not a valid key. */
    public static function indexNowKey(): string
    {
        $k = Config::get()['seo']['indexNowKey'];
        return preg_match('/^[A-Za-z0-9-]{8,128}$/', $k) ? $k : '';
    }
}
