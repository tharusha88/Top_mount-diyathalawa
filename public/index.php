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
        // The built-in server ignores Range requests, which leaves videos unseekable (the hero's
        // play-then-reverse needs seeking). Real hosts (Apache) support Range, so this is dev-only.
        if (preg_match('/\.(mp4|webm)$/i', $file)) {
            $size = filesize($file);
            $start = 0; $end = $size - 1; $partial = false;
            if (preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $m) && ($m[1] !== '' || $m[2] !== '')) {
                if ($m[1] === '') { $start = max(0, $size - (int) $m[2]); }
                else { $start = (int) $m[1]; if ($m[2] !== '') $end = min($end, (int) $m[2]); }
                $partial = $start <= $end && $start < $size;
                if (!$partial) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
            }
            http_response_code($partial ? 206 : 200);
            header('Content-Type: ' . (str_ends_with(strtolower($file), '.webm') ? 'video/webm' : 'video/mp4'));
            header('Accept-Ranges: bytes');
            if ($partial) header("Content-Range: bytes $start-$end/$size");
            header('Content-Length: ' . ($end - $start + 1));
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
                $fh = fopen($file, 'rb'); fseek($fh, $start);
                for ($left = $end - $start + 1; $left > 0 && !feof($fh); $left -= $n) {
                    $chunk = fread($fh, min(262144, $left)); $n = strlen($chunk);
                    if ($n === 0) break;
                    echo $chunk; flush();
                    if (connection_aborted()) break;
                }
                fclose($fh);
            }
            exit;
        }
        return false;
    }
    if (strtolower($path) === '/check.php' && is_file(__DIR__ . '/check.php')) return false; // run the hosting check page
}

require (is_file(__DIR__ . '/app/bootstrap.php') ? __DIR__ : dirname(__DIR__)) . '/app/bootstrap.php';
Ydr\App::run();
