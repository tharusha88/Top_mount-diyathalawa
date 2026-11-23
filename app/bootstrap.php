<?php
// Top Mountain Resort — PHP backend bootstrap (the booking system first built for Yodhaara).
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'This website needs PHP 8.1 or newer (this server runs PHP ' . PHP_VERSION . '). Choose a newer PHP version in your hosting control panel.';
    exit;
}

define('YDR_APP', __DIR__);
define('YDR_ROOT', dirname(__DIR__));
// The web root (where css/, js/, media/ live): set by index.php; repo layout uses /public.
if (!defined('YDR_PUBLIC')) define('YDR_PUBLIC', is_dir(YDR_ROOT . '/public/media') ? YDR_ROOT . '/public' : YDR_ROOT);

if (!extension_loaded('pdo')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'This website needs the PHP extension "pdo" (database access). Enable it in your hosting control panel: PHP Configuration → Extensions.';
    exit;
}
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');

foreach (['Js', 'Config', 'RoomsSeed', 'Db', 'Settings', 'Media', 'Site', 'HomeMedia', 'Content', 'Seo', 'Bookings', 'Closures', 'Coupons', 'Pos', 'Docs', 'Finance', 'Smtp', 'Mailer', 'Whatsapp', 'Reminders', 'Confirmations','Chatbot', 'Ical', 'Auth', 'Users', 'Http', 'RateLimit', 'App'] as $f) {
    require YDR_APP . "/$f.php";
}
