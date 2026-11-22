<?php
// Top Mountain Resort — every page and API request goes through here.
define('YDR_PUBLIC', __DIR__);

// PHP's built-in development server: let it serve real files (css, js, media) directly.
if (PHP_SAPI === 'cli-server') {
    $path = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    $file = realpath(__DIR__ . $path);
    $rel = $file ? substr($file, strlen(__DIR__)) : '';
    if ($path !== '/' && $file && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)
        && !preg_match('#(^|[\\\\/])\.|\.php$|^[\\\\/](app|views|data|database|tests)([\\\\/]|$)#i', $rel)) {
        return false;
    }
    if (strtolower($path) === '/check.php' && is_file(__DIR__ . '/check.php')) return false; // run the hosting check page
}

require (is_file(__DIR__ . '/app/bootstrap.php') ? __DIR__ : dirname(__DIR__)) . '/app/bootstrap.php';
Ydr\App::run();
