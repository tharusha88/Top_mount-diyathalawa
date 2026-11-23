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

    /**
     * Process an uploaded image into graded WebP sizes (plus a 1200 px JPEG for readers without
     * WebP, such as link previews); returns the new media key. $grade = false keeps the colours as
     * they are: a video's cover frame, which has to match the video it fades into.
     */
    public static function addUpload(string $bytes, string $label = 'photo', bool $grade = true): string
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
        if ($grade) self::grade($graded);

        $sizes = [];
        foreach (self::WIDTHS as $target) {
            $target = min($target, $W);
            if (in_array($target, $sizes, true)) continue;
            $out = $target === imagesx($graded) ? $graded : self::resize($graded, $target, max(1, (int) round($H * $target / $W)));
            imagewebp($out, self::dir() . "/$key-$target.webp", 80);
            if ($out !== $graded) imagedestroy($out);
            $sizes[] = $target;
        }

        // a JPEG on a white ground (transparent PNGs), the size the website's own photos have
        $jpegW = min(1200, $W);
        $jpeg = imagecreatetruecolor($jpegW, max(1, (int) round($H * $jpegW / $W)));
        imagefill($jpeg, 0, 0, imagecolorallocate($jpeg, 255, 255, 255));
        imagecopyresampled($jpeg, $graded, 0, 0, 0, 0, imagesx($jpeg), imagesy($jpeg), imagesx($graded), imagesy($graded));
        imagejpeg($jpeg, self::dir() . "/$key-$jpegW.jpg", 82);
        imagedestroy($jpeg);

        // the photo's average colour: the home page fills the frame with it while the photo loads
        $dot = self::resize($graded, 1, 1);
        $c = imagecolorat($dot, 0, 0);
        imagedestroy($dot);
        $color = sprintf('#%02x%02x%02x', ($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF);

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
            'jpeg' => $jpegW,
            'color' => $color,
            'placeholder' => 'data:image/webp;base64,' . base64_encode($tinyBytes),
            'uploaded' => Js::isoNow(),
        ];
        self::update(function ($m) use ($key, $entry) { $m[$key] = $entry; return $m; });
        return $key;
    }

    public static function removeUpload(string $key): bool
    {
        if (!str_starts_with($key, 'upload-') || !self::has($key)) return false;
        $m = self::get()[$key];
        foreach ($m['sizes'] as $w) @unlink(self::dir() . "/$key-$w.webp");
        if (!empty($m['jpeg'])) @unlink(self::dir() . "/$key-" . (int) $m['jpeg'] . '.jpg');
        self::update(function ($m) use ($key) { unset($m[$key]); return $m; });
        return true;
    }

    /**
     * An uploaded photo in the shape of js/data/media.js (window.TMR_MEDIA.images), so the home page
     * can show it like its own photos: files "<base>-<width>.webp", and a JPEG (older uploads: the
     * WebP nearest 1200 px) for the plain <img>. The website's own photos are in media.js already: null.
     */
    public static function pageImage(string $key, string $alt = ''): ?array
    {
        $m = self::get()[$key] ?? null;
        if (!is_array($m) || !empty($m['site']) || empty($m['sizes'])) return null;
        $sizes = array_values(array_map('intval', $m['sizes']));
        sort($sizes);
        $mid = array_reduce($sizes, fn ($best, $w) => abs($w - 1200) < abs($best - 1200) ? $w : $best, $sizes[0]);
        return [
            'base' => "/media/$key",
            'widths' => $sizes,
            'fallback' => !empty($m['jpeg']) ? "/media/$key-" . (int) $m['jpeg'] . '.jpg' : "/media/$key-$mid.webp",
            'w' => (int) ($m['width'] ?? 0),
            'h' => (int) ($m['height'] ?? 0),
            'alt' => $alt,
            'color' => (string) ($m['color'] ?? '#4b5242'),
        ];
    }

    // ---------- videos (Admin → Photos & videos) ----------

    public const VIDEO_MAX = 200 * 1048576; // 200 MB

    /** A video on its way in, in the private data folder until it is complete and checked. */
    private static function partFile(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{16,64}$/', $id)) self::fail('Invalid upload.');
        return Config::get()['dataDir'] . '/uploads/' . $id . '.part';
    }

    /**
     * Add the next piece of a video being uploaded. Videos come in pieces of a few MB, so each
     * request stays under the host's upload limit (post_max_size). Returns the bytes received so far.
     */
    public static function addVideoPart(string $id, int $offset, string $bytes): int
    {
        $file = self::partFile($id);
        if ($bytes === '') self::fail('No video arrived. The host\'s upload limit (post_max_size) may be below 4 MB: ask your host to raise it.');
        if ($offset === 0) {
            if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) self::fail('Could not save the video: the data folder is not writable.');
            self::sweepParts();
            if (strlen($bytes) < 12 || substr($bytes, 4, 4) !== 'ftyp') self::fail('Please upload an MP4 video (H.264).');
            @unlink($file);
        }
        clearstatcache(true, $file);
        $have = is_file($file) ? (int) filesize($file) : 0;
        if ($have !== $offset) self::fail('The upload was interrupted. Please try again.');
        if ($offset + strlen($bytes) > self::VIDEO_MAX) {
            @unlink($file);
            self::fail('That video is too large: please keep it under ' . (self::VIDEO_MAX >> 20) . ' MB.');
        }
        if (file_put_contents($file, $bytes, FILE_APPEND | LOCK_EX) !== strlen($bytes)) self::fail('Could not save the video: is the disk full?');
        return $offset + strlen($bytes);
    }

    /** Uploads that were never finished (a closed tab), after a day. */
    private static function sweepParts(): void
    {
        foreach (glob(Config::get()['dataDir'] . '/uploads/*.part') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) @unlink($f);
        }
    }

    /**
     * Check a finished upload and move it into the website (public/media/video/<slot>-<random>.mp4).
     * @return array{src:string, w:int, h:int, duration:float, bytes:int}
     */
    public static function finishVideo(string $id, string $slot): array
    {
        $part = self::partFile($id);
        if (!is_file($part)) self::fail('The video did not arrive. Please upload it again.');
        try {
            $info = self::videoInfo($part);
        } catch (\RuntimeException $e) {
            @unlink($part);
            throw $e;
        }
        $dir = self::dir() . '/video';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) self::fail('Could not save the video: the media folder is not writable.');
        $slug = substr((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($slot)), 0, 20);
        $name = $slug . '-' . bin2hex(random_bytes(4)) . '.mp4';
        $bytes = (int) filesize($part);
        if (!@rename($part, "$dir/$name")) {
            if (!@copy($part, "$dir/$name")) self::fail('Could not save the video: the media folder is not writable.');
            @unlink($part);
        }
        @chmod("$dir/$name", 0644);
        return ['src' => "/media/video/$name", 'bytes' => $bytes] + $info;
    }

    /** Delete a video uploaded in the admin panel (never one of the website's own). */
    public static function removeVideo(mixed $src): void
    {
        if (is_string($src) && preg_match('#^/media/video/([a-z0-9-]+\.mp4)$#', $src, $m)) @unlink(self::dir() . '/video/' . $m[1]);
    }

    /**
     * What an MP4 holds, read from its boxes (no ffmpeg on shared hosting): the picture's size as
     * it plays (a phone video's rotation applied) and its length. Only H.264 is accepted: every
     * browser plays it. HEVC (H.265, the iPhone's default) and AV1 don't play in many of them.
     * @return array{w:int, h:int, duration:float}
     */
    public static function videoInfo(string $file): array
    {
        $f = @fopen($file, 'rb');
        if (!$f) self::fail('Could not read the video.');
        try {
            $size = (int) filesize($file);
            fseek($f, 4);
            if (fread($f, 4) !== 'ftyp') self::fail('Please upload an MP4 video (H.264).');
            $moov = self::child($f, 0, $size, 'moov');
            if (!$moov) self::fail('That video file is incomplete. Please export it again as MP4 (H.264).');
            $duration = 0.0;
            $mvhd = self::child($f, $moov[0], $moov[1], 'mvhd');
            if ($mvhd) {
                fseek($f, $mvhd[0]);
                $b = (string) fread($f, 32);
                if (strlen($b) >= 20 && ord($b[0]) === 0) {
                    $u = unpack('Nscale/Nlen', substr($b, 12, 8));
                    $duration = $u['scale'] ? $u['len'] / $u['scale'] : 0.0;
                } elseif (strlen($b) >= 32) {
                    $u = unpack('Nscale/Jlen', substr($b, 20, 12));
                    $duration = $u['scale'] ? $u['len'] / $u['scale'] : 0.0;
                }
            }
            foreach (self::boxes($f, $moov[0], $moov[1]) as [$type, $s, $e]) {
                if ($type !== 'trak') continue;
                $mdia = self::child($f, $s, $e, 'mdia');
                $hdlr = $mdia ? self::child($f, $mdia[0], $mdia[1], 'hdlr') : null;
                if (!$hdlr) continue;
                fseek($f, $hdlr[0] + 8);
                if (fread($f, 4) !== 'vide') continue;
                // the codec: the first sample description (mdia/minf/stbl/stsd)
                $codec = '';
                $minf = self::child($f, $mdia[0], $mdia[1], 'minf');
                $stbl = $minf ? self::child($f, $minf[0], $minf[1], 'stbl') : null;
                $stsd = $stbl ? self::child($f, $stbl[0], $stbl[1], 'stsd') : null;
                if ($stsd) {
                    fseek($f, $stsd[0] + 12);
                    $codec = (string) fread($f, 4);
                }
                if (!in_array($codec, ['avc1', 'avc3'], true)) {
                    $name = ['hvc1' => 'HEVC (H.265)', 'hev1' => 'HEVC (H.265)', 'av01' => 'AV1', 'vp09' => 'VP9', 'mp4v' => 'MPEG-4 Part 2', 'encv' => 'protected video'][$codec] ?? 'a format';
                    self::fail("This video is saved as $name, which some browsers can't play. Please export it as MP4 with H.264 (in most video apps: Export → MP4 / H.264; on an iPhone: Settings → Camera → Formats → Most Compatible).");
                }
                // the size: the end of the track header, 16.16 fixed point, after its rotation matrix
                $tkhd = self::child($f, $s, $e, 'tkhd');
                if (!$tkhd || $tkhd[1] - $tkhd[0] < 84) self::fail('That video file is incomplete. Please export it again as MP4 (H.264).');
                fseek($f, $tkhd[1] - 44);
                $m = unpack('Na/Nb/Nu/Nc/Nd/Nv/Nx/Ny/Nw/Nwidth/Nheight', (string) fread($f, 44));
                $w = (int) round($m['width'] / 65536);
                $h = (int) round($m['height'] / 65536);
                if ($m['a'] === 0 && $m['d'] === 0) [$w, $h] = [$h, $w]; // turned a quarter: a phone held upright
                if (min($w, $h) < 240) self::fail('That video is too small: please use one at least 1280 px wide.');
                return ['w' => $w, 'h' => $h, 'duration' => round($duration, 2)];
            }
            self::fail('That file has no video in it.');
        } finally {
            fclose($f);
        }
    }

    /** @return list<array{0:string, 1:int, 2:int}> [type, where its contents start, where it ends] for the boxes in [$from, $to) */
    private static function boxes($f, int $from, int $to): array
    {
        $out = [];
        for ($at = $from; $at + 8 <= $to && count($out) < 4096;) {
            fseek($f, $at);
            $h = (string) fread($f, 8);
            if (strlen($h) < 8) break;
            $len = unpack('N', $h)[1];
            $type = substr($h, 4, 4);
            $head = 8;
            if ($len === 1) {
                $big = (string) fread($f, 8);
                if (strlen($big) < 8) break;
                $len = unpack('J', $big)[1];
                $head = 16;
            } elseif ($len === 0) {
                $len = $to - $at; // to the end of the file
            }
            if ($len < $head || $at + $len > $to) break;
            $out[] = [$type, $at + $head, $at + $len];
            $at += $len;
        }
        return $out;
    }

    /** The first box of a type inside [$from, $to): [contents start, end], or null. */
    private static function child($f, int $from, int $to, string $type): ?array
    {
        foreach (self::boxes($f, $from, $to) as [$t, $s, $e]) if ($t === $type) return [$s, $e];
        return null;
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
