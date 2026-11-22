<?php
// Staff sign-in: password_hash() + HMAC-signed session cookie.
// Sessions last 12 hours; changing a password signs out that login's sessions, and a login that is
// switched off or removed is signed out on its next request. Each login has a role (ROLES) that
// decides what it may use; the API checks it on every request (App::permFor).
declare(strict_types=1);

namespace Ydr;

final class Auth
{
    private const COOKIE = 'ydr_admin';
    private const TTL_MS = 1000 * 60 * 60 * 12; // 12 hours

    /**
     * What each role may use:
     *   bookings: overview, calendar, bookings, new booking, invoices
     *   pos: the POS screen (check-in, walk-ins, orders, payments, check-out) and bills
     *   menu: POS menu items and categories      void: void a recorded payment or an issued bill
     *   coupons    setup: booking setup, rooms, printing    site: website, photos, email, WhatsApp, Google Calendar
     *   users: staff logins    finance: expenses, staff pay, service charge shares, bank, stock, figures
     */
    public const ROLES = [
        'admin' => [
            'label' => 'Admin',
            'about' => 'Everything, including finance, the website, booking setup, settings and staff logins.',
            'perms' => ['bookings', 'pos', 'menu', 'void', 'coupons', 'setup', 'site', 'users', 'finance'],
        ],
        'manager' => [
            'label' => 'Manager',
            'about' => 'Bookings, the calendar, the POS, bills, the POS menu and coupons. Not finance, the website, booking setup, settings or staff logins.',
            'perms' => ['bookings', 'pos', 'menu', 'void', 'coupons'],
        ],
        'pos' => [
            'label' => 'Front desk (POS only)',
            'about' => 'Only the POS screen: check-in, walk-ins, orders, payments, check-out and printing bills. Cannot void a payment or an issued bill.',
            'perms' => ['pos'],
        ],
    ];

    public static function role(array $admin): string
    {
        $r = (string) ($admin['role'] ?? 'admin');
        return isset(self::ROLES[$r]) ? $r : 'pos'; // an unknown role gets the least
    }

    /** @return string[] */
    public static function perms(array $admin): array
    {
        return self::ROLES[self::role($admin)]['perms'];
    }

    public static function can(array $admin, string $perm): bool
    {
        return in_array($perm, self::perms($admin), true);
    }

    /** The name printed on bills and receipts (falls back to the username). */
    public static function displayName(array $admin): string
    {
        $n = trim((string) ($admin['name'] ?? ''));
        return $n !== '' ? $n : (string) $admin['username'];
    }

    /** @var array<string,string>|null username → display name */
    private static ?array $names = null;

    /** The display name for a username stored on a bill or payment ("guest", "website" and removed logins stay as they are). */
    public static function nameOf(string $username): string
    {
        if (self::$names === null) {
            self::$names = [];
            foreach (Db::all('SELECT username, name FROM admins') as $r) self::$names[(string) $r['username']] = self::displayName($r);
        }
        return self::$names[$username] ?? $username;
    }

    public static function forgetNames(): void
    {
        self::$names = null;
    }

    public static function hashPassword(string $pw): string
    {
        return password_hash($pw, PASSWORD_DEFAULT);
    }

    private static function verifyPassword(string $pw, string $stored): bool
    {
        return password_verify($pw, $stored);
    }

    // First run: create the admin from .env
    public static function ensureAdmin(): void
    {
        if ((int) Db::one('SELECT COUNT(*) n FROM admins')['n'] > 0) return;
        $cfg = Config::get()['admin'];
        $pw = $cfg['password'];
        if ($pw === '') {
            $pw = rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=');
            error_log('[auth] ADMIN_PASSWORD not set — created admin "' . $cfg['username'] . '" with a random password: ' . $pw . ' — change it in Admin → Settings.');
        }
        Db::run('INSERT INTO admins (username, password_hash, created_at) VALUES (?, ?, ?)', [$cfg['username'], self::hashPassword($pw), Db::toDbTime(Js::isoNow())]);
        error_log('[auth] admin user "' . $cfg['username'] . '" created');
    }

    private static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }

    private static function sign(string $data): string
    {
        return self::b64url(hash_hmac('sha256', $data, Config::get()['sessionSecret'], true));
    }

    private static function fingerprint(string $hash): string
    {
        return substr(self::b64url(hash('sha256', $hash, true)), 0, 16);
    }

    // The token embeds a fingerprint of the password hash, so changing the
    // password invalidates every existing session.
    public static function issueToken(array $admin): string
    {
        $payload = self::b64url(Js::json([
            'u' => $admin['username'],
            'exp' => (int) round(microtime(true) * 1000) + self::TTL_MS,
            'f' => self::fingerprint($admin['password_hash']),
        ]));
        return $payload . '.' . self::sign($payload);
    }

    public static function readToken(?string $token): ?array
    {
        if (!$token || !str_contains($token, '.')) return null;
        $parts = explode('.', $token);
        [$payload, $sig] = [$parts[0], $parts[1]];
        if (!hash_equals(self::sign($payload), $sig)) return null;
        $json = self::b64urlDecode($payload);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data) || empty($data['exp']) || $data['exp'] < microtime(true) * 1000) return null;
        $admin = Db::one('SELECT * FROM admins WHERE username = ?', [Js::str($data['u'] ?? '')]);
        if (!$admin || !(int) ($admin['active'] ?? 1)) return null; // removed or switched off: signed out
        if (self::fingerprint($admin['password_hash']) !== ($data['f'] ?? null)) return null;
        return $admin;
    }

    public static function setSessionCookie(Response $res, string $token): void
    {
        $parts = [self::COOKIE . '=' . $token, 'Path=/', 'HttpOnly', 'SameSite=Strict', 'Max-Age=' . (self::TTL_MS / 1000)];
        if (Config::get()['isProd']) $parts[] = 'Secure';
        $res->header('Set-Cookie', implode('; ', $parts));
    }

    public static function clearSessionCookie(Response $res): void
    {
        $res->header('Set-Cookie', self::COOKIE . '=; Path=/; HttpOnly; SameSite=Strict; Max-Age=0');
    }

    /** The login for these details, or null. Check 'active' before letting it in (App does). */
    public static function login(mixed $username, mixed $password): ?array
    {
        $username = Js::truthy($username) ? Js::str($username) : '';
        $password = Js::str($password ?? 'undefined');
        $admin = Db::one('SELECT * FROM admins WHERE username = ?', [$username])
            ?? ($username !== strtolower($username) ? Db::one('SELECT * FROM admins WHERE username = ?', [strtolower($username)]) : null);
        // constant-ish time even for unknown users
        if (!$admin) { password_verify($password, password_hash('x', PASSWORD_DEFAULT)); return null; }
        if (!self::verifyPassword($password, $admin['password_hash'])) return null;
        if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
            Db::run('UPDATE admins SET password_hash = ? WHERE id = ?', [self::hashPassword($password), $admin['id']]);
            $admin = Db::one('SELECT * FROM admins WHERE id = ?', [$admin['id']]);
        }
        return $admin;
    }

    public static function changePassword(string $username, mixed $current, mixed $next): array
    {
        $admin = self::login($username, $current);
        if (!$admin) return ['ok' => false, 'error' => 'Current password is incorrect.'];
        if (!is_string($next) || Js::length($next) < 10) return ['ok' => false, 'error' => 'New password must be at least 10 characters.'];
        $hash = self::hashPassword($next);
        Db::run('UPDATE admins SET password_hash = ? WHERE id = ?', [$hash, $admin['id']]);
        return ['ok' => true, 'admin' => ['password_hash' => $hash] + $admin];
    }

    public static function touchLogin(array $admin): void
    {
        Db::run('UPDATE admins SET last_login = ? WHERE id = ?', [Db::toDbTime(Js::isoNow()), $admin['id']]);
    }

    /** Returns the signed-in admin or null. */
    public static function current(): ?array
    {
        return self::readToken(isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE]) ? $_COOKIE[self::COOKIE] : null);
    }
}
