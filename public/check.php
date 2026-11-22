<?php
// Top Mountain Resort — hosting check. Open https://your-domain/check.php after uploading.
// Visible to anyone only until the admin account exists; after that, sign in to
// /admin/ first. It shows settings status only — never passwords or secrets.
declare(strict_types=1);

define('YDR_PUBLIC', __DIR__);
$rows = [];
$problems = 0;
$warnings = 0;
function row(string $level, string $msg, string $fix = ''): void
{
    global $rows, $problems, $warnings;
    if ($level === 'fail') $problems++;
    if ($level === 'warn') $warnings++;
    $rows[] = [$level, $msg, $fix];
}

// ---------- 1. PHP itself (checked before loading the app) ----------
$okPhp = PHP_VERSION_ID >= 80100;
row($okPhp ? 'ok' : 'fail', 'PHP ' . PHP_VERSION, $okPhp ? '' : 'cPanel → Select PHP Version (or MultiPHP Manager) → choose PHP 8.1, 8.2, 8.3 or newer.');
$ext = [
    ['mbstring', false, 'Recommended for text in every language (the site still works without it).'],
    ['openssl', false, 'Needed to send email over a secure connection.'],
    ['gd', false, 'Needed for photo uploads in Admin → Rooms.'],
    ['exif', false, 'Turns phone photos the right way up when uploading.'],
];
foreach ($ext as [$name, $required, $why]) {
    if (extension_loaded($name)) row('ok', "PHP extension \"$name\" enabled");
    else row($required ? 'fail' : 'warn', "PHP extension \"$name\" is missing — $why", 'cPanel → Select PHP Version → Extensions → tick "' . $name . '" (or ask your host).');
}
if (extension_loaded('gd') && !function_exists('imagewebp')) row('warn', 'PHP GD has no WebP support — photo uploads will fail', 'Ask your host to enable WebP in PHP GD.');

$bytes = function (string $v): int {
    $v = trim($v); $n = (int) $v; $u = strtolower(substr($v, -1));
    return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
};
$post = $bytes((string) ini_get('post_max_size'));
if ($post > 0 && $post < 16 << 20) row('warn', 'post_max_size is ' . ini_get('post_max_size') . ' — large photo uploads may be refused', 'Select PHP Version → Options → post_max_size = 20M (and upload_max_filesize = 20M).');
$mem = $bytes((string) ini_get('memory_limit'));
if ($mem > 0 && $mem < 128 << 20) row('warn', 'memory_limit is ' . ini_get('memory_limit') . ' — big photos may fail to process', 'Select PHP Version → Options → memory_limit = 256M.');

if (!$okPhp || !extension_loaded('pdo')) {
    if ($okPhp) row('fail', 'PHP extension "pdo" is missing — needed for the database', 'PHP Configuration → Extensions → tick "pdo" (or ask your host).');
    render($rows, $problems, $warnings, false);
    exit;
}

// ---------- 2. Load the app ----------
require (is_file(__DIR__ . '/app/bootstrap.php') ? __DIR__ : dirname(__DIR__)) . '/app/bootstrap.php';

use Ydr\Config;
use Ydr\Db;

$cfg = Config::get();
$isMysql = $cfg['db']['connection'] === 'mysql';
$need = $isMysql ? 'pdo_mysql' : 'pdo_sqlite';
if (extension_loaded($need)) row('ok', "PHP extension \"$need\" enabled");
try {
    Db::pdo();
} catch (Throwable $e) {
    $fix = $isMysql
        ? 'Hosting panel → Databases: create the database and user, then copy the exact names into DB_DATABASE / DB_USERNAME / DB_PASSWORD in .env (DB_HOST is usually "localhost").'
        : 'Make sure the "data" folder exists and has permission 755 (File Manager → right-click → Change Permissions).';
    row('fail', ($isMysql ? 'MySQL: ' : 'Database: ') . ($e instanceof Ydr\DbUnavailable ? $e->getMessage() : 'could not open the database.'), $fix);
    render($rows, $problems, $warnings, false);
    exit;
}

// Who may see this page: anyone before the admin exists, afterwards only a signed-in admin.
$hasAdmin = (int) Db::one('SELECT COUNT(*) n FROM admins')['n'] > 0;
if ($hasAdmin && PHP_SAPI !== 'cli' && !Ydr\Auth::current()) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hosting check</title><body style="font-family:system-ui;max-width:560px;margin:60px auto;padding:0 16px;color:#18241c"><h1>Hosting check</h1><p>Please <a href="/admin/">sign in to the admin panel</a> first, then reload this page.</p></body>';
    exit;
}

$dataDir = $cfg['dataDir'];
$probe = $dataDir . '/.write-test';
if (@file_put_contents($probe, 'ok') !== false) { @unlink($probe); row('ok', 'Data folder is writable (' . ($isMysql ? 'email outbox and login key' : 'bookings database: ' . basename($dataDir) . '/topmountain.db') . ')'); }
else row('fail', 'Cannot write to the data folder', 'File Manager → "data" folder → Change Permissions → 755.');
$dbName = $isMysql ? 'MySQL ' . Db::pdo()->query('SELECT VERSION()')->fetchColumn() . ', database "' . htmlspecialchars($cfg['db']['database']) . '"' : 'SQLite file data/topmountain.db';
row('ok', $dbName . ' — ' . Db::one('SELECT COUNT(*) n FROM rooms')['n'] . ' rooms, ' . Db::one('SELECT COUNT(*) n FROM bookings')['n'] . ' bookings');

// ---------- 3. Settings (.env) ----------
$envFile = YDR_ROOT . '/.env';
if (!is_file($envFile)) {
    row('fail', 'The settings file .env is missing', 'Upload .env from the package (File Manager → Settings → tick "Show Hidden Files" to see it).');
} else {
    $ph = '/yourdomain|example\.com|000 0000|94770000000/i';
    $env = fn (string $k) => Config::env($k);
    if ($cfg['isProd']) row('ok', 'Production mode is on'); else row('warn', 'Production mode is off', 'Set APP_ENV=production in .env.');
    if (preg_match($ph, $cfg['baseUrl']) || $cfg['baseUrl'] === 'http://localhost:3000') row('fail', 'BASE_URL is not your real web address (it is used in emails)', 'Set BASE_URL=https://www.your-domain.lk in .env.');
    elseif (!str_starts_with($cfg['baseUrl'], 'https://')) row('warn', 'BASE_URL does not start with https://', 'Turn on SSL (cPanel → SSL/TLS Status → AutoSSL) and use https:// in BASE_URL.');
    else row('ok', 'BASE_URL = ' . htmlspecialchars($cfg['baseUrl']));
    if (!$cfg['admin']['notifyEmails'] || preg_match($ph, implode(',', $cfg['admin']['notifyEmails']))) row('warn', 'ADMIN_NOTIFY_EMAILS is not set — nobody will get new-booking emails', 'Put the owner / manager email address(es) in .env.');
    else row('ok', 'New-booking emails go to: ' . htmlspecialchars(implode(', ', $cfg['admin']['notifyEmails'])));
    if (preg_match($ph, $env('RESORT_PHONE') . ' ' . $env('RESORT_WHATSAPP') . ' ' . $env('RESORT_EMAIL'))) row('warn', 'Resort phone / WhatsApp / email are still placeholders', 'Edit RESORT_PHONE, RESORT_WHATSAPP and RESORT_EMAIL in .env.');
}

// ---------- 4. Email ----------
$smtp = $cfg['smtp'];
if ($smtp['host'] === '') {
    row('warn', 'Email sending is not set up — emails are being saved in data/outbox/ instead', 'Fill in SMTP_HOST, SMTP_USER and SMTP_PASS in .env (see DEPLOY.md step 2).');
} elseif (!extension_loaded('openssl') && ($smtp['secure'] || $smtp['port'] !== 25)) {
    row('fail', 'Email needs the PHP "openssl" extension', 'cPanel → Select PHP Version → Extensions → tick "openssl".');
} else {
    try { Ydr\Smtp::verify($smtp); row('ok', 'Email server login works (' . htmlspecialchars($smtp['host']) . ')'); }
    catch (Throwable $e) { row('fail', 'Email server login failed: ' . htmlspecialchars($e->getMessage()), 'Check SMTP_HOST / SMTP_PORT / SMTP_SECURE / SMTP_USER / SMTP_PASS. Usually: mail.your-domain, port 465, SMTP_SECURE=true.'); }
}

// ---------- 5. Site files + web server rules ----------
$manifest = YDR_PUBLIC . '/media/manifest.json';
if (is_file($manifest)) row('ok', 'Site files present (' . count(json_decode((string) file_get_contents($manifest), true) ?: []) . ' photos)');
else row('fail', 'media/manifest.json is missing', 'Re-upload the full package.');
if (!is_file(YDR_PUBLIC . '/.htaccess')) row('fail', '.htaccess is missing — pages and the booking system will not load', 'Upload .htaccess from the package (it is a hidden file).');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$here = isset($_SERVER['HTTP_HOST']) ? "$scheme://{$_SERVER['HTTP_HOST']}" : null;
if ($here && ini_get('allow_url_fopen')) {
    $get = function (string $url): array {
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true, 'follow_location' => 0], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s?/', $http_response_header[0], $m) ? (int) $m[1] : 0;
        return [$code, (string) $body];
    };
    [$c1, $b1] = $get("$here/api/rooms");
    if ($c1 === 200 && str_contains($b1, '"rooms"')) row('ok', 'Booking API reachable (URL rewriting works)');
    elseif ($c1 === 0) row('warn', 'Could not test the site over the web from the server itself — open the website to check it loads');
    else row('fail', "The booking API answered $c1 — URL rewriting is not working", 'Make sure .htaccess was uploaded and that your host has mod_rewrite enabled.');
    [$c2] = $get("$here/data/topmountain.db");
    [$c3] = $get("$here/.env");
    if ($c2 && $c3) {
        if ($c2 >= 400 && $c3 >= 400) row('ok', 'Private files are protected (database and .env cannot be downloaded)');
        else row('fail', 'Private files can be downloaded from the web!', 'Make sure .htaccess, app/.htaccess and data/.htaccess were uploaded. Contact support if the server ignores .htaccess files.');
    }
}

render($rows, $problems, $warnings, true);

function render(array $rows, int $problems, int $warnings, bool $loaded): void
{
    if (PHP_SAPI === 'cli') {
        foreach ($rows as [$l, $m, $f]) echo str_pad('[' . strtoupper($l) . ']', 7) . strip_tags(html_entity_decode($m)) . ($f ? "\n       → $f" : '') . "\n";
        echo "\n" . ($problems ? "$problems problem(s) to fix" : 'No blocking problems') . ($warnings ? ", $warnings warning(s)." : '.') . "\n";
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $icon = ['ok' => '✔', 'warn' => '!', 'fail' => '✖'];
    $color = ['ok' => '#3f7a3a', 'warn' => '#a8650c', 'fail' => '#b6431b'];
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Hosting check · Top Mountain Resort</title>';
    echo '<style>body{margin:0;background:#f4f1e8;font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;color:#18241c}main{max-width:760px;margin:40px auto;padding:0 16px}h1{font-family:Georgia,serif;font-weight:500}.sum{padding:14px 18px;border-radius:12px;margin:18px 0;font-weight:600}ul{list-style:none;padding:0;margin:0;background:#fff;border:1px solid #e6e1d3;border-radius:14px;overflow:hidden}li{display:grid;grid-template-columns:28px 1fr;gap:4px 10px;padding:12px 16px;border-top:1px solid #efebe0}li:first-child{border-top:0}.i{width:22px;height:22px;border-radius:50%;color:#fff;display:grid;place-items:center;font-size:12px;font-weight:800}.f{grid-column:2;color:#56645a;font-size:.88rem}</style></head><body><main>';
    echo '<h1>Hosting check</h1>';
    $bg = $problems ? '#fdf0ea;color:#8e3412' : ($warnings ? '#fff7ea;color:#7a5a10' : '#eaf3e1;color:#2b4c22');
    echo '<div class="sum" style="background:' . $bg . '">' . ($problems ? "$problems problem(s) to fix" : 'No blocking problems') . ($warnings ? " · $warnings warning(s)" : '') . ($problems ? '' : ' — your site is ready.') . '</div><ul>';
    foreach ($rows as [$l, $m, $f]) {
        echo '<li><span class="i" style="background:' . $color[$l] . '">' . $icon[$l] . '</span><span>' . $m . '</span>' . ($f ? '<span class="f">→ ' . htmlspecialchars($f) . '</span>' : '') . '</li>';
    }
    echo '</ul><p style="color:#56645a;font-size:.88rem">After fixing anything, reload this page. ' . ($loaded ? '<a href="/">Open the website</a> · <a href="/admin/">Admin panel</a>' : '') . '</p></main></body></html>';
}
