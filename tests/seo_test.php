<?php
// What search engines and AI assistants read (app/Content.php, app/Seo.php): the content.js
// reader, the home page as served before any JavaScript runs, the structured data, robots.txt,
// sitemap.xml, llms.txt, IndexNow and the canonical redirect. Runs on a THROWAWAY SQLite
// database, like bookings_test.php:
//   php tests/seo_test.php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/topmountain-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
putenv("DATA_DIR=$tmp");
putenv('YDR_TEST_SEED=' . __DIR__ . '/seed.php');
putenv('SMTP_HOST=');
putenv('DB_CONNECTION=sqlite');
putenv('BASE_URL=https://resort.test');
putenv('INDEXNOW_KEY=0123abcd-test-key');
putenv('CANONICAL_REDIRECT=true');
putenv('GOOGLE_SITE_VERIFICATION=google-code-1');
putenv('BING_SITE_VERIFICATION=BING-CODE-2');
require dirname(__DIR__) . '/app/bootstrap.php';

use Ydr\App;
use Ydr\Bookings;
use Ydr\Content;
use Ydr\Request;
use Ydr\Response;
use Ydr\Seo;

$pass = 0;
$fail = 0;
function test(string $name, callable $fn): void
{
    global $pass, $fail;
    try { $fn(); $pass++; echo "✔ $name\n"; }
    catch (Throwable $e) { $fail++; echo "✖ $name\n    " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n"; }
}
function eq($a, $b, string $msg = ''): void
{
    if ($a !== $b) throw new Exception(($msg ? "$msg: " : '') . 'expected ' . var_export($b, true) . ', got ' . var_export($a, true));
}
function ok(bool $v, string $msg): void
{
    if (!$v) throw new Exception($msg);
}

/** A GET request through the app, as a crawler makes it: [status, body, headers (lower-case name => value)]. */
function get(string $path, string $host = 'resort.test'): array
{
    $req = new Request();
    $req->method = 'GET';
    $req->path = (string) parse_url($path, PHP_URL_PATH);
    $req->query = [];
    $req->headers = ['host' => $host];
    $_SERVER['REQUEST_URI'] = $path;
    $res = new Response();
    (new ReflectionMethod(App::class, 'handle'))->invoke(null, $req, $res);
    $h = [];
    foreach ((new ReflectionProperty(Response::class, 'headers'))->getValue($res) as $k => [, $v]) $h[$k] = implode(', ', $v);
    return [$res->status, $res->body, $h];
}

/** The page's text as a reader without JavaScript gets it (scripts, styles and tags removed). */
function text(string $html): string
{
    $html = (string) preg_replace('#<(script|style|svg)\b[^>]*>.*?</\1>#si', ' ', $html);
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<noscript>', '</noscript>'], '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function jsonLd(string $html): array
{
    ok((bool) preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m), 'no JSON-LD on the page');
    $d = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    $by = [];
    foreach ($d['@graph'] as $n) $by[is_array($n['@type']) ? $n['@type'][0] : $n['@type']] = $n;
    return $by;
}

$C = Content::get();
[$homeStatus, $home] = get('/');
$homeText = text($home);

test('content.js is read on the server, as the browser reads it', function () use ($C) {
    foreach (['contact', 'highlights', 'stays', 'rooms', 'experiences', 'dining', 'diningPlaces', 'location', 'nearby', 'alsoNearby', 'reviews'] as $k) {
        ok(array_key_exists($k, $C), "content.js has no '$k'");
    }
    ok(count($C['rooms']) > 0 && count($C['nearby']) > 0, 'rooms and nearby places are read');
    ok(count(Content::media()['images']) > 0, 'the media manifest is read');
});

test('the JavaScript literal reader: quotes, escapes, comments, trailing commas; nothing else', function () {
    eq(Content::parseLiteral("{a: 'it\\'s', \"b\": [1, -2.5, true, null,], /* c */ d: {}, // e\n f: '\\u00e9\\u{1F600}\\n'};"),
        ['a' => "it's", 'b' => [1, -2.5, true, null], 'd' => [], 'f' => "é😀\n"]);
    foreach (['{a: foo}', '{a: f()}', '{a: 1} + 2', '{a: `x`}', "{a: 'open"] as $bad) {
        try { Content::parseLiteral($bad); throw new Exception("accepted $bad"); } catch (UnexpectedValueException $e) { /* refused */ }
    }
});

test('the home page is complete without JavaScript: no tokens left, every part drawn', function () use ($homeStatus, $home, $homeText, $C) {
    eq($homeStatus, 200);
    ok(!preg_match('/\{\{\s*[\w.]+\s*\}\}/', $home), 'a {{token}} was left in the page');
    foreach ($C['highlights'] as $x) ok(str_contains($homeText, $x['title']) && str_contains($homeText, $x['text']), "highlight '{$x['title']}' missing");
    foreach ($C['experiences'] as $x) ok(str_contains($homeText, $x['name']) && str_contains($homeText, $x['text']), "experience '{$x['name']}' missing");
    foreach ($C['diningPlaces'] as $x) ok(str_contains($homeText, $x['name']), "dining '{$x['name']}' missing");
    foreach ($C['nearby'] as $x) ok(str_contains($homeText, $x['name']) && str_contains($homeText, $x['desc']), "nearby '{$x['name']}' missing");
    foreach ($C['alsoNearby'] as $x) ok(str_contains($homeText, $x[0]), "'{$x[0]}' missing");
    foreach ($C['location'] as $x) ok(str_contains($homeText, $x[1]), "location fact '{$x[1]}' missing");
    eq(substr_count($home, 'class="rcard"'), count(Content::rooms()), 'room tiles');
    ok(str_contains($homeText, $C['contact']['address'][0]), 'address missing');
    ok(str_contains($home, 'href="tel:+'), 'no phone link');
    ok(str_contains($home, 'href="https://wa.me/'), 'no WhatsApp link');
    ok(!str_contains($home, 'data-contact="tel" href="#"'), 'a phone link was left empty');
});

test('photos: plain <img> with descriptions inside <noscript>, nothing held back in the raw page', function () use ($home) {
    preg_match_all('#<noscript><picture class="pic"[^>]*><img src="(/assets/media/img/[^"]+\.jpg)"[^>]* alt="([^"]*)"#', $home, $m);
    ok(count($m[1]) >= 40, 'expected the room, highlight, experience and nearby photos, got ' . count($m[1]));
    foreach ($m[1] as $src) ok(is_file(YDR_PUBLIC . $src), "$src does not exist");
    ok(count(array_filter($m[2], fn ($a) => $a !== '')) === count($m[2]), 'a photo has no description');
    ok(!preg_match('#<picture[^>]*data-defer#', $home), 'held-back photos belong to render.js only');
});

test('the reviews section is left out while content.js has no verified reviews', function () use ($home, $C) {
    if ($C['reviews']) { ok(str_contains($home, 'id="reviews"'), 'reviews section missing'); return; }
    ok(!str_contains($home, 'id="reviews"'), 'an empty reviews section is in the page');
});

test('head: one canonical, social card, verification codes, place, hero preload', function () use ($home) {
    eq(substr_count($home, 'rel="canonical"'), 1, 'canonical links');
    ok(str_contains($home, '<link rel="canonical" href="https://resort.test/">'), 'canonical is BASE_URL/');
    ok(str_contains($home, '<meta property="og:url" content="https://resort.test/">'), 'og:url');
    ok((bool) preg_match('#<meta property="og:image" content="https://resort.test/assets/media/img/[\w-]+\.jpg">#', $home), 'og:image must be a full address');
    ok(str_contains($home, '<meta name="google-site-verification" content="google-code-1">'), 'Google verification');
    ok(str_contains($home, '<meta name="msvalidate.01" content="BING-CODE-2">'), 'Bing verification');
    ok(str_contains($home, '<meta name="geo.region" content="LK">'), 'geo.region');
    ok(str_contains($home, 'rel="preload" as="image"') && str_contains($home, 'fetchpriority="high"'), 'hero preload');
    ok((bool) preg_match('#<title>[^<]{20,70}</title>#', $home), 'title length');
    ok((bool) preg_match('#<meta name="description" content="[^"]{70,160}">#', $home), 'description length');
});

test('structured data: the resort, the page, the questions (matching the page), the places nearby', function () use ($home, $homeText) {
    ok(!preg_match('#<script type="application/ld\+json">[^<]*<[^/]#', $home), 'raw < inside the JSON-LD');
    $ld = jsonLd($home);
    foreach (['WebSite', 'WebPage', 'Resort', 'FAQPage', 'ItemList'] as $t) ok(isset($ld[$t]), "no $t");
    $r = $ld['Resort'];
    eq($r['@id'], 'https://resort.test/#resort');
    foreach (['name', 'address', 'telephone', 'url', 'image', 'checkinTime', 'checkoutTime', 'amenityFeature', 'containsPlace', 'potentialAction'] as $k) ok(!empty($r[$k]), "Resort has no $k");
    eq($r['address']['addressCountry'], 'LK');
    eq($r['address']['addressLocality'], 'Diyatalawa');
    ok((bool) preg_match('/^\d{2}:\d{2}$/', $r['checkinTime']), 'checkinTime is HH:MM');
    eq($r['potentialAction']['target']['urlTemplate'], 'https://resort.test/book');
    eq($ld['WebPage']['about']['@id'], $r['@id'], 'the page is about the resort');
    $faq = Seo::faq();
    eq(count($ld['FAQPage']['mainEntity']), count($faq), 'one Question per answer on the page');
    foreach ($ld['FAQPage']['mainEntity'] as $q) {
        ok(str_contains($homeText, $q['name']), "question not on the page: {$q['name']}");
        ok(str_contains($homeText, $q['acceptedAnswer']['text']), "answer not on the page: {$q['name']}");
    }
    eq($ld['ItemList']['numberOfItems'], count(Content::nearby()));
    if (isset($ld['VideoObject'])) {
        foreach (['name', 'description', 'thumbnailUrl', 'uploadDate', 'contentUrl'] as $k) ok(!empty($ld['VideoObject'][$k]), "VideoObject has no $k");
        ok(is_file(YDR_PUBLIC . substr($ld['VideoObject']['contentUrl'], strlen('https://resort.test'))), 'the film file exists');
    }
});

test('questions and answers: built from the booking system and content.js, never made up', function () use ($C) {
    $faq = Seo::faq();
    ok(count($faq) >= 10, 'expected at least 10 questions');
    $all = implode("\n", array_map(fn ($x) => $x['q'] . ' ' . $x['a'], $faq));
    $prices = array_filter(array_map(fn ($r) => (int) ($r['prices']['RO'] ?? 0), Bookings::listRooms()));
    ok(str_contains($all, 'from LKR ' . number_format(min($prices)) . ' a night'), 'the starting rate is the lowest room rate');
    ok(str_contains($all, 'up to ' . Bookings::MAX_ROOMS . ' rooms'), 'the rooms-per-booking limit');
    foreach ($C['stays'] as $st) ok(str_contains($all, $st['name']), "stay '{$st['name']}' not answered");
    ok(!preg_match('/\b(rating|stars?|reviewed|award|best|cheapest)\b/i', $all), 'an answer claims something the site does not say');
});

test('the booking page: canonical, breadcrumb, booking action', function () {
    [$st, $html] = get('/book');
    eq($st, 200);
    eq(substr_count($html, 'rel="canonical"'), 1, 'canonical links');
    ok(str_contains($html, '<link rel="canonical" href="https://resort.test/book">'), 'canonical');
    $ld = jsonLd($html);
    eq(array_column($ld['BreadcrumbList']['itemListElement'], 'item'), ['https://resort.test/', 'https://resort.test/book']);
    eq($ld['WebPage']['potentialAction']['@type'], 'ReserveAction');
});

test('robots.txt: private screens closed, private links left readable (they answer noindex), sitemap', function () {
    [$st, $txt] = get('/robots.txt');
    eq($st, 200);
    foreach (['Disallow: /admin/', 'Disallow: /pos', 'Disallow: /api/', 'Sitemap: https://resort.test/sitemap.xml'] as $l) ok(str_contains($txt, $l), "missing: $l");
    foreach (['/confirm', '/invoice', '/receipt', '/bill'] as $p) ok(!str_contains($txt, "Disallow: $p"), "$p must stay crawlable so its noindex is seen");
    eq(substr_count($txt, 'User-agent:'), 1, 'a single group for every crawler');
});

test('sitemap.xml: valid, both pages, their photos', function () {
    [$st, $xml, $h] = get('/sitemap.xml');
    eq($st, 200);
    ok(str_starts_with($h['content-type'], 'application/xml'), 'content type');
    $doc = simplexml_load_string($xml);
    ok($doc !== false, 'not valid XML');
    $locs = array_map('strval', $doc->xpath('//*[local-name()="url"]/*[local-name()="loc"]'));
    eq($locs, ['https://resort.test/', 'https://resort.test/book']);
    $imgs = array_map('strval', $doc->xpath('//*[local-name()="image"]/*[local-name()="loc"]'));
    ok(count($imgs) >= 30, 'expected the photos, got ' . count($imgs));
    foreach ($imgs as $i) ok(is_file(YDR_PUBLIC . substr($i, strlen('https://resort.test'))), "$i does not exist");
    foreach ($doc->xpath('//*[local-name()="lastmod"]') as $d) ok((bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d), 'lastmod format');
});

test('llms.txt: Markdown summary with the facts and the same answers, kept out of search results', function () {
    [$st, $md, $h] = get('/llms.txt');
    eq($st, 200);
    ok(str_starts_with($md, '# '), 'starts with the name as a heading');
    ok(str_contains($md, "\n> "), 'has the summary');
    eq($h['x-robots-tag'] ?? '', 'noindex');
    foreach (Seo::faq() as $x) ok(str_contains($md, "### {$x['q']}\n{$x['a']}"), "missing answer: {$x['q']}");
});

test('IndexNow: the key file answers with the key; nothing else does', function () {
    [$st, $body] = get('/0123abcd-test-key.txt');
    eq([$st, $body], [200, '0123abcd-test-key']);
    eq(get('/other-key-0000.txt')[0], 404);
});

test('canonical redirect: another host moves to BASE_URL for good; the API and the right host do not', function () {
    [$st, , $h] = get('/book?go=rooms', 'temporary.hostingersite.test');
    eq($st, 301);
    eq($h['location'], 'https://resort.test/book?go=rooms');
    eq(get('/', 'resort.test')[0], 200);
    ok(get('/api/rooms', 'temporary.hostingersite.test')[0] !== 301, 'the API must not redirect');
});

echo "\n$pass passed, $fail failed  (test database: $tmp)\n";
exit($fail ? 1 : 0);
