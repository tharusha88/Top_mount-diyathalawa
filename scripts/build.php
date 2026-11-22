<?php
// Builds the upload package for shared hosting:
//   php scripts/build.php
//   → dist/topmountain-php-hosting.zip   (upload into public_html and extract)
//   → dist/DEPLOY.md                  (step-by-step guide)
// The site is packed unchanged, plus a production .env with fresh secrets.
// Never includes data/ (your bookings database) — a fresh one is created on the server.
declare(strict_types=1);

$root = dirname(__DIR__);
$dist = "$root/dist";
$zipFile = "$dist/topmountain-php-hosting.zip";

if (!class_exists('PharData')) { fwrite(STDERR, "PHP's phar extension is needed to build the zip.\n"); exit(1); }

// fresh dist/
if (is_dir($dist)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dist, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
} else {
    mkdir($dist, 0755, true);
}

/** [local path => path inside the zip] */
$files = [];
$add = function (string $dir, string $prefix, ?callable $keep = null) use (&$files, $root) {
    $base = "$root/$dir";
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
        if (preg_match('/\.(lock|tmp)$/', $rel)) continue;
        if ($keep && !$keep($rel)) continue;
        $files[$f->getPathname()] = ltrim("$prefix/$rel", '/');
    }
};
$add('public', '');                                                    // web root → top level of public_html
$add('app', 'app');                                                    // application code (private)
$add('views', 'views', fn ($r) => str_ends_with($r, '.html') || $r === '.htaccess');
$files["$root/hosting/DEPLOY.md"] = 'DEPLOY.md';
$files["$root/scripts/send-reminders.php"] = 'scripts/send-reminders.php';   // the daily cron job (command line only)
$files["$root/scripts/indexnow.php"] = 'scripts/indexnow.php';               // tells Bing the site changed (command line only)

// production .env: fresh secrets, resort details carried over from .env.example
$b64url = fn (string $b) => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
$overrides = [
    'APP_ENV' => 'production',
    'BASE_URL' => 'https://www.yourdomain.lk',
    'TRUST_PROXY' => 'false',
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_DATABASE' => '',
    'DB_USERNAME' => '',
    'DB_PASSWORD' => '',
    'ADMIN_PASSWORD' => $b64url(random_bytes(12)),
    // Hostinger mail: the resort's mailbox and its password are left to fill in
    'SMTP_HOST' => 'smtp.hostinger.com',
    'SMTP_PORT' => '465',
    'SMTP_SECURE' => 'true',
    'SMTP_USER' => '',
    'SMTP_PASS' => '',
];
$notes = [
    'BASE_URL' => 'CHANGE: your real website address, starting with https://',
    'DB_DATABASE' => 'CHANGE: the MySQL database, user and password you created in your hosting panel (Hostinger: Databases → Management). Names usually start with your account prefix, e.g. u123456789_topmountain.',
    'ADMIN_PASSWORD' => 'Generated for you. Only used to create the admin account the first time the site opens — change it later in Admin → Settings.',
    'ADMIN_NOTIFY_EMAILS' => 'CHANGE: who receives an email for every new booking (comma-separated)',
    'SMTP_USER' => 'CHANGE: the mailbox the site sends from, e.g. bookings@yourdomain.lk (Hostinger hPanel → Emails → create the mailbox), and the same address in MAIL_FROM below.',
    'SMTP_PASS' => 'CHANGE: the password of that mailbox. While empty, emails are saved in data/outbox/ instead of being sent.',
    'MAIL_FROM' => 'CHANGE: the same address as SMTP_USER.',
    'RESORT_EMAIL' => 'CHANGE: the email address guests see (empty = not shown).',
    'RESORT_PHONE' => 'Contact details shown on the website. Easier to change in Admin → Website, which overrides these.',
];
// Real production values (database login etc.) live in hosting/production.env — a private,
// git-ignored file — and win over the placeholders above.
$prodFile = "$root/hosting/production.env";
$fromProd = [];
if (is_file($prodFile)) {
    foreach (preg_split('/\r?\n/', (string) file_get_contents($prodFile)) as $line) {
        if (preg_match('/^\s*([A-Z_]+)\s*=(.*)$/', $line, $m)) $fromProd[$m[1]] = trim($m[2]);
    }
    $overrides = $fromProd + $overrides;
    // a filled-in database login needs no "CHANGE" reminder
    if (isset($fromProd['ADMIN_PASSWORD'])) {
        $notes['ADMIN_PASSWORD'] = 'Admin login password (from hosting/production.env). Only used to create the admin account if the database has none yet.';
    }
    if (($fromProd['DB_DATABASE'] ?? '') !== '' && ($fromProd['DB_USERNAME'] ?? '') !== '') {
        $notes['DB_DATABASE'] = 'Your hosting MySQL database (filled in from hosting/production.env).';
    }
    if (($fromProd['ADMIN_NOTIFY_EMAILS'] ?? '') !== '') {
        $notes['ADMIN_NOTIFY_EMAILS'] = 'Who receives an email for every new booking (comma-separated).';
    }
}

$lines = [];
foreach (preg_split('/\r?\n/', (string) file_get_contents("$root/.env.example")) as $line) {
    if (!preg_match('/^([A-Z_]+)=(.*)$/', $line, $m)) { $lines[] = $line; continue; }
    [, $key, $value] = $m;
    if (isset($notes[$key])) $lines[] = "# <-- {$notes[$key]}";
    $lines[] = $key . '=' . ($overrides[$key] ?? $value);
    if ($key === 'ADMIN_PASSWORD') {
        $lines[] = '# Signs admin login sessions. Generated for you — keep it secret, no need to change.';
        $lines[] = 'SESSION_SECRET=' . bin2hex(random_bytes(32));
    }
}
$env = str_replace('# Copy to .env and fill in. Changes apply on the next page load.',
    '# Production settings. Edit the lines marked CHANGE — changes apply immediately.', implode("\n", $lines));
$env = rtrim($env, "\n") . "\n";

$denyData = "# Private: bookings database and email outbox\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";

// build the zip (PharData writes forward-slash paths, so it extracts correctly on Linux servers)
$zip = new PharData($zipFile, 0, null, Phar::ZIP);
foreach ($files as $local => $inZip) $zip->addFile($local, $inZip);
$zip->addFromString('.env', $env);
$zip->addFromString('data/.htaccess', $denyData);
if (extension_loaded('zlib')) $zip->compressFiles(Phar::GZ);
unset($zip);

copy("$root/hosting/DEPLOY.md", "$dist/DEPLOY.md");

// the existing data for MySQL — kept OUT of the zip so it can never end up on the web
$sql = "$root/database/topmountain-mysql.sql";
if (is_file($sql)) copy($sql, "$dist/topmountain-database.sql");
// for a site that is already live: brings its database up to date (phpMyAdmin → SQL)
if (is_file("$root/database/upgrade.sql")) copy("$root/database/upgrade.sql", "$dist/topmountain-upgrade.sql");

// for a site whose database was ALREADY imported: sets the admin password from production.env
if (isset($fromProd['ADMIN_PASSWORD']) && $fromProd['ADMIN_PASSWORD'] !== '') {
    $h = password_hash($fromProd['ADMIN_PASSWORD'], PASSWORD_DEFAULT);
    file_put_contents("$dist/set-admin-password.sql", "-- Run in phpMyAdmin (SQL tab) only if topmountain-database.sql was ALREADY imported earlier.
-- Sets the admin password to the one in hosting/production.env.
UPDATE admins SET password_hash = '$h' WHERE username = 'admin';
");
}

$bytes = array_sum(array_map('filesize', array_keys($files)));
printf("Package ready: %d files, %.1f MB\n", count($files) + 2, $bytes / 1048576);
printf("  zip:   dist/topmountain-php-hosting.zip (%.1f MB)  ← upload this into public_html and extract\n", filesize($zipFile) / 1048576);
echo "  guide: dist/DEPLOY.md\n";
if (is_file("$dist/topmountain-upgrade.sql")) echo "  upgrade: dist/topmountain-upgrade.sql  ← for a site already live: run in phpMyAdmin → SQL to update its database\n";
if (is_file("$dist/topmountain-database.sql")) {
    echo "  data:  dist/topmountain-database.sql  ← import in phpMyAdmin (your rooms, bookings and admin account)\n";
    echo "  Admin login after importing: your existing admin password. (ADMIN_PASSWORD in the zip's .env\n";
    echo "  is only used if the database has no admin yet.)\n";
} else {
    echo "  The admin password is in the zip's .env (ADMIN_PASSWORD).\n";
}
