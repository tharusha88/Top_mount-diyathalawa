<?php
// Media library: the processed photo manifest + admin photo uploads (GD).
declare(strict_types=1);

namespace Ydr;

final class Media
{
    private const WIDTHS = [640, 1280, 1920];
    private const MAX_EDGE = 2400;

    private static function dir(): string { return YDR_PUBLIC . '/media'; }
    private static function manifestFile(): string { return self::dir() . '/manifest.json'; }

    private static ?array $cache = null;
    private static ?array $siteCache = null;

    /** Uploaded photos (public/media) plus the website's own photos, which can be used but not deleted. */
    public static function get(): array
    {
        if (self::$cache === null) {
            $raw = @file_get_contents(self::manifestFile());
            self::$cache = $raw ? (json_decode($raw, true) ?: []) : [];
        }
        return self::$cache + self::site();
    }

    /**
     * The website's photos from public/js/data/media.js (written by tools/build_media.py), in this
     * library's shape. `base` is where the files are: "<base>-<width>.webp".
     */
    public static function site(): array
    {
        if (self::$siteCache !== null) return self::$siteCache;
        self::$siteCache = [];
        $raw = (string) @file_get_contents(YDR_PUBLIC . '/js/data/media.js');
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        $data = $start === false || $end === false ? null : json_decode(substr($raw, $start, $end - $start + 1), true);
        foreach ((is_array($data) ? $data['images'] ?? [] : []) as $key => $m) {
            if (!is_array($m) || empty($m['base']) || empty($m['widths'])) continue;
            self::$siteCache[(string) $key] = [
                'width' => (int) ($m['w'] ?? 0), 'height' => (int) ($m['h'] ?? 0),
                'sizes' => array_values(array_map('intval', $m['widths'])),
                'base' => '/' . ltrim((string) $m['base'], '/'),
                'alt' => (string) ($m['alt'] ?? ''),
                'site' => true,
            ];
        }
        return self::$siteCache;
    }

    /** Only these photos, for a page that needs just a few. */
    public static function pick(array $keys): array
    {
        return array_intersect_key(self::get(), array_flip(array_filter($keys, 'is_string')));
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::get());
    }

    /** Read-modify-write the manifest under a lock (several PHP requests may run at once). */
    private static function update(callable $fn): void
    {
        $lock = fopen(self::manifestFile() . '.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $raw = @file_get_contents(self::manifestFile());
            $m = $raw ? (json_decode($raw, true) ?: []) : [];
            $m = $fn($m);
            $tmp = self::manifestFile() . '.tmp';
            file_put_contents($tmp, (string) json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            rename($tmp, self::manifestFile());
            self::$cache = $m; // the uploads only: get() adds the website's photos
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function fail(string $msg): never
    {
        throw new \RuntimeException($msg);
    }

    /** Process an uploaded image into graded WebP sizes; returns the new media key. */
    public static function addUpload(string $bytes, string $label = 'photo'): string
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);
        if (!function_exists('imagecreatefromstring')) self::fail('Photo uploads need the PHP "gd" extension — ask your host to enable it.');
        if (!function_exists('imagewebp')) self::fail('Photo uploads need WebP support in PHP GD — ask your host to enable it.');

        $info = @getimagesizefromstring($bytes);
        if (!$info) self::fail('That file is not a supported image.');
        $format = [IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null;
        if (defined('IMAGETYPE_AVIF') && $info[2] === IMAGETYPE_AVIF && function_exists('imagecreatefromavif')) $format = 'avif';
        if (!$format) self::fail('Please upload a JPG, PNG or WebP photo.');
        if ($info[0] < 400 || $info[1] < 300) self::fail('That photo is too small — please use one at least 800 px wide.');

        $label = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
        $slug = (string) preg_replace('/\.[a-z0-9]+$/', '', $label);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = substr(trim($slug, '-'), 0, 30);
        if ($slug === '') $slug = 'photo';
        $key = 'upload-' . $slug . '-' . bin2hex(random_bytes(3));

        $img = @imagecreatefromstring($bytes);
        if (!$img) self::fail('That file is not a supported image.');
        $img = self::autoOrient($img, $bytes, $format);

        // fit inside 2400×2400 without enlarging (these are the dimensions recorded)
        [$w, $h] = [imagesx($img), imagesy($img)];
        $scale = min(1, self::MAX_EDGE / max($w, $h));
        $W = max(1, (int) round($w * $scale));
        $H = max(1, (int) round($h * $scale));

        // grade once at the largest output size, then derive the smaller ones from it
        $topW = min(max(self::WIDTHS), $W);
        $graded = self::resize($img, $topW, max(1, (int) round($H * $topW / $W)));
        imagedestroy($img);
        self::grade($graded);

        $sizes = [];
        foreach (self::WIDTHS as $target) {
            $target = min($target, $W);
            if (in_array($target, $sizes, true)) continue;
            $out = $target === imagesx($graded) ? $graded : self::resize($graded, $target, max(1, (int) round($H * $target / $W)));
            imagewebp($out, self::dir() . "/$key-$target.webp", 80);
            if ($out !== $graded) imagedestroy($out);
            $sizes[] = $target;
        }

        // a small blurred placeholder (inlined as data URI by the front end)
        $tiny = self::resize($graded, 24, max(1, (int) round($H * 24 / $W)));
        imagefilter($tiny, IMG_FILTER_GAUSSIAN_BLUR);
        ob_start();
        imagewebp($tiny, null, 40);
        $tinyBytes = (string) ob_get_clean();
        imagedestroy($tiny);
        imagedestroy($graded);

        $entry = [
            'width' => $W,
            'height' => $H,
            'sizes' => $sizes,
            'placeholder' => 'data:image/webp;base64,' . base64_encode($tinyBytes),
            'uploaded' => Js::isoNow(),
        ];
        self::update(function ($m) use ($key, $entry) { $m[$key] = $entry; return $m; });
        return $key;
    }

    public static function removeUpload(string $key): bool
    {
        if (!str_starts_with($key, 'upload-') || !self::has($key)) return false;
        foreach (self::get()[$key]['sizes'] as $w) @unlink(self::dir() . "/$key-$w.webp");
        self::update(function ($m) use ($key) { unset($m[$key]); return $m; });
        return true;
    }

    private static function resize(\GdImage $src, int $w, int $h): \GdImage
    {
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
        return $dst;
    }

    /** Same gentle grade as before: linear(1.04, -5) and +6 % saturation. */
    private static function grade(\GdImage $im): void
    {
        // linear a·x + b per channel, done natively by GD
        imageconvolution($im, [[0, 0, 0], [0, 1.04, 0], [0, 0, 0]], 1, -5);
        // saturation: push each channel away from the pixel's luminance
        $s = 1.06;
        $w = imagesx($im);
        $h = imagesy($im);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
                $l = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                $r = (int) round($l + ($r - $l) * $s); $g = (int) round($l + ($g - $l) * $s); $b = (int) round($l + ($b - $l) * $s);
                $r = $r < 0 ? 0 : ($r > 255 ? 255 : $r);
                $g = $g < 0 ? 0 : ($g > 255 ? 255 : $g);
                $b = $b < 0 ? 0 : ($b > 255 ? 255 : $b);
                imagesetpixel($im, $x, $y, ($c & 0x7F000000) | ($r << 16) | ($g << 8) | $b);
            }
        }
    }

    /** Rotate phone photos the right way up (EXIF orientation). */
    private static function autoOrient(\GdImage $img, string $bytes, string $format): \GdImage
    {
        if ($format !== 'jpeg' || !function_exists('exif_read_data')) return $img;
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        $o = (int) ($exif['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => -90, 8 => 90, 5 => -90, 7 => 90][$o] ?? 0;
        if ($rot) {
            $r = imagerotate($img, $rot, 0);
            if ($r) { imagedestroy($img); $img = $r; }
        }
        if (in_array($o, [2, 4, 5, 7], true)) imageflip($img, $o === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        return $img;
    }
}
