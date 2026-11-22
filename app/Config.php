<?php
// Settings from .env. Real environment variables (e.g. SetEnv) take priority.
// APP_ENV=production turns on HTTPS-only cookies and HSTS.
declare(strict_types=1);

namespace Ydr;

final class Config
{
    private static ?array $env = null;
    private static ?array $cfg = null;

    private static function loadEnvFile(string $file): array
    {
        $out = [];
        if (!is_file($file)) return $out;
        $text = (string) file_get_contents($file);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        foreach (preg_split('/\r\n|\n|\r/', (string) $text) as $line) {
            if (!preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.-]*)\s*=\s*(.*)$/', $line, $m)) continue;
            $key = $m[1];
            $val = $m[2];
            $q = $val[0] ?? '';
            if (($q === '"' || $q === "'" || $q === '`') && ($end = strpos($val, $q, 1)) !== false) {
                $val = substr($val, 1, $end - 1);
                if ($q === '"') $val = str_replace('\n', "\n", $val);
            } else {
                $val = preg_replace('/\s+#.*$/', '', $val);
                $val = trim((string) $val);
            }
            $out[$key] = $val;
        }
        return $out;
    }

    public static function env(string $key, string $default = ''): string
    {
        $real = getenv($key);
        if ($real !== false) return trim($real);
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && !str_starts_with($key, 'HTTP_')) return trim($_SERVER[$key]);
        self::$env ??= self::loadEnvFile(YDR_ROOT . '/.env');
        return trim(self::$env[$key] ?? $default);
    }

    private static function bool(string $key, bool $default = false): bool
    {
        $v = strtolower(self::env($key, $default ? 'true' : 'false'));
        return $v === 'true' || $v === '1' || $v === 'yes';
    }

    private static function sessionSecret(string $dataDir): string
    {
        $s = self::env('SESSION_SECRET');
        if ($s !== '') return $s;
        $file = $dataDir . '/.session-secret';
        if (is_file($file)) return trim((string) file_get_contents($file));
        $s = bin2hex(random_bytes(48));
        file_put_contents($file, $s, LOCK_EX);
        @chmod($file, 0600);
        return $s;
    }

    /** Make sure the data folder exists and can never be downloaded. */
    private static function prepareDataDir(string $dir): void
    {
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ht = $dir . '/.htaccess';
        if (is_dir($dir) && !is_file($ht)) {
            @file_put_contents($ht, "# Private: bookings database and email outbox\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
        }
    }

    public static function get(): array
    {
        if (self::$cfg !== null) return self::$cfg;
        $dataDir = self::env('DATA_DIR', './data');
        $isAbs = str_starts_with($dataDir, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $dataDir);
        $dataDir = $isAbs ? $dataDir : YDR_ROOT . '/' . preg_replace('#^\./#', '', $dataDir);
        $dataDir = rtrim($dataDir, '/\\');
        self::prepareDataDir($dataDir);

        $appEnv = self::env('APP_ENV');

        return self::$cfg = [
            'root' => YDR_ROOT,
            'baseUrl' => rtrim(self::env('BASE_URL', 'http://localhost:3000'), '/'),
            'dataDir' => $dataDir,
            'isProd' => $appEnv === 'production',
            'trustProxy' => self::bool('TRUST_PROXY', false),
            'timezone' => self::env('RESORT_TIMEZONE', 'Asia/Colombo'),
            'sessionSecret' => self::sessionSecret($dataDir),
            // DB_CONNECTION=mysql (MySQL / MariaDB) or sqlite (a single file in data/)
            'db' => [
                'connection' => strtolower(self::env('DB_CONNECTION', 'sqlite')) === 'mysql' ? 'mysql' : 'sqlite',
                'host' => self::env('DB_HOST', 'localhost'),
                'port' => (int) self::env('DB_PORT', '3306'),
                'database' => self::env('DB_DATABASE'),
                'username' => self::env('DB_USERNAME'),
                'password' => self::env('DB_PASSWORD'),
            ],
            'admin' => [
                'username' => self::env('ADMIN_USERNAME', 'admin'),
                'password' => self::env('ADMIN_PASSWORD'),
                'notifyEmails' => array_values(array_filter(array_map('trim', explode(',', self::env('ADMIN_NOTIFY_EMAILS'))), fn ($s) => $s !== '')),
            ],
            'smtp' => [
                'host' => self::env('SMTP_HOST'),
                'port' => (int) self::env('SMTP_PORT', '587'),
                'secure' => self::bool('SMTP_SECURE', false),
                'user' => self::env('SMTP_USER'),
                'pass' => self::env('SMTP_PASS'),
                'from' => self::env('MAIL_FROM', 'Top Mountain Resort <bookings@example.com>'),
            ],
            // the website's chatbot on the Panora chatbot platform (Chatbot.php); empty URL or secret = off
            'chatbot' => [
                'url' => rtrim(self::env('CHATBOT_URL'), '/'),
                'secret' => self::env('CHATBOT_SECRET'),
                'tenant' => self::env('CHATBOT_TENANT', 'topmountain') ?: 'topmountain',
            ],
            'site' => [
                'name' => self::env('RESORT_NAME', 'Top Mountain Resort'),
                'phone' => self::env('RESORT_PHONE', '+94 77 736 8264'),
                'whatsapp' => self::env('RESORT_WHATSAPP', '94777368264'),
                'email' => self::env('RESORT_EMAIL', ''),
                'address' => self::env('RESORT_ADDRESS', '122/1A, Pahala Kadurugamuwa Road, Diyatalawa, Sri Lanka'),
                'mapQuery' => self::env('RESORT_MAP_QUERY', 'Top Mountain Resort, Diyatalawa, Sri Lanka'),
                // the resort's exact spot as "latitude,longitude" (empty = search for RESORT_MAP_QUERY)
                'mapCoords' => self::env('RESORT_MAP_COORDS', ''),
                'mapPlace' => self::env('RESORT_MAP_PLACE', 'https://www.google.com/maps/search/?api=1&query=Top+Mountain+Resort+Diyatalawa'),
                'plusCode' => self::env('RESORT_PLUS_CODE', ''),
                'facebook' => self::env('RESORT_FACEBOOK', ''),
                'instagram' => self::env('RESORT_INSTAGRAM', ''),
                'currency' => self::env('CURRENCY', 'LKR'),
                'checkInTime' => self::env('CHECKIN_TIME', '2:00 PM'),
                'checkOutTime' => self::env('CHECKOUT_TIME', '11:00 AM'),
                'heroVideo' => self::env('HERO_VIDEO', ''),
                // the "Powered by" credit in the footers (empty name = no credit; the link is optional)
                'poweredBy' => self::env('POWERED_BY', 'Panoralink Business Solutions'),
                'poweredByUrl' => self::env('POWERED_BY_URL', ''),
            ],
        ];
    }

    /**
     * Where the resort is: [lat, lng] (null if RESORT_MAP_COORDS isn't "lat,lng") and the Google
     * Maps links — the listing, directions to it, and the map embedded on the home page.
     */
    public static function location(): array
    {
        $s = Site::info(); // Admin → Website can move the pin
        $ll = preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $s['mapCoords'], $m) ? [(float) $m[1], (float) $m[2]] : null;
        $q = Js::encodeURIComponent($s['mapQuery']);
        $at = $ll ? $ll[0] . ',' . $ll[1] : '';
        return [
            'lat' => $ll ? $ll[0] : null,
            'lng' => $ll ? $ll[1] : null,
            // the coordinates as people read them, e.g. "6.81346° N, 81.11035° E"
            'coords' => $ll ? sprintf('%.5f° %s, %.5f° %s', abs($ll[0]), $ll[0] < 0 ? 'S' : 'N', abs($ll[1]), $ll[1] < 0 ? 'W' : 'E') : '',
            'plusCode' => $s['plusCode'],
            'place' => preg_match('#^https://#', $s['mapPlace']) ? $s['mapPlace'] : 'https://www.google.com/maps/search/?api=1&query=' . $q,
            'directions' => 'https://www.google.com/maps/dir/?api=1&destination=' . ($ll ? Js::encodeURIComponent($at) : $q),
            'embed' => 'https://www.google.com/maps?q=' . $q . ($ll ? '&ll=' . $at . '&z=15' : '') . '&output=embed',
        ];
    }
}
