<?php
// Tells Bing (and the other IndexNow search engines: Yandex, Seznam, Naver) that the website
// changed, so they read it again within minutes instead of days. Google doesn't use IndexNow:
// it reads sitemap.xml (submit BASE_URL/sitemap.xml once in Google Search Console).
//   php scripts/indexnow.php            → submit the home page and the booking page
//   php scripts/indexnow.php --dry-run  → only show what would be sent
// Needs INDEXNOW_KEY in .env: 8 to 128 letters, digits or dashes, e.g. from
//   php -r "echo bin2hex(random_bytes(16));"
// The site then answers BASE_URL/<key>.txt with the key, which shows the request comes from the site.
// Run it after uploading changes to the website, not on a schedule: unchanged pages shouldn't be resubmitted.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use Ydr\Config;
use Ydr\Seo;

$base = Config::get()['baseUrl'];
$key = Seo::indexNowKey();
if ($key === '') {
    fwrite(STDERR, "Set INDEXNOW_KEY in .env first: 8 to 128 letters, digits or dashes.\n");
    exit(1);
}
$host = (string) parse_url($base, PHP_URL_HOST);
if (!str_starts_with($base, 'https://') || $host === '' || preg_match('/^(localhost|127\.|\[?::1)/', $host)) {
    fwrite(STDERR, "BASE_URL must be the live https:// address of the website (it is $base).\n");
    exit(1);
}

$body = ['host' => $host, 'key' => $key, 'keyLocation' => "$base/$key.txt", 'urlList' => ["$base/", "$base/book"]];
$json = json_encode($body, JSON_UNESCAPED_SLASHES);
if (in_array('--dry-run', $argv, true)) {
    echo "Would send to https://api.indexnow.org/indexnow:\n$json\n";
    exit(0);
}

/** [HTTP status, body] of a request (curl when available, else PHP's own streams). */
function http(string $method, string $url, ?string $json = null): array
{
    $headers = ['User-Agent: Top Mountain website (IndexNow)'];
    if ($json !== null) $headers[] = 'Content-Type: application/json; charset=utf-8';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $headers] + ($json !== null ? [CURLOPT_POSTFIELDS => $json] : []));
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$raw === false ? 0 : $status, (string) $raw];
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'timeout' => 20, 'ignore_errors' => true,
        'header' => implode("\r\n", $headers) . "\r\n", 'content' => $json ?? '']]);
    $raw = @file_get_contents($url, false, $ctx);
    $head = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : [];
    return [preg_match('#^HTTP/\S+ (\d{3})#', $head[0] ?? '', $m) ? (int) $m[1] : ($raw === false ? 0 : 200), (string) $raw];
}

// the search engines fetch the key file to check the request: make sure the live site serves it
[$st, $served] = http('GET', "$base/$key.txt");
if ($st !== 200 || trim($served) !== $key) {
    fwrite(STDERR, "$base/$key.txt doesn't answer with the key (HTTP $st). Upload the .env with INDEXNOW_KEY first.\n");
    exit(1);
}

[$st, $answer] = http('POST', 'https://api.indexnow.org/indexnow', $json);
$meaning = [200 => 'accepted', 202 => 'accepted (the key is still being checked)', 400 => 'bad request', 403 => 'the key was not accepted',
    422 => 'the addresses don\'t belong to the host, or the key doesn\'t match', 429 => 'too many requests: try again later'][$st] ?? 'no answer';
echo "IndexNow: HTTP $st, $meaning.\n";
exit($st === 200 || $st === 202 ? 0 : 1);
