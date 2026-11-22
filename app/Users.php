<?php
// Staff logins (Admin → Staff logins): who can sign in, the name printed on their bills and
// receipts, and their role (Auth::ROLES). There is always at least one active admin.
declare(strict_types=1);

namespace Ydr;

final class Users
{
    public const MIN_PASSWORD = 10;

    public static function map(array $r): array
    {
        $role = Auth::role($r);
        return [
            'id' => (int) $r['id'],
            'username' => (string) $r['username'],
            'name' => (string) ($r['name'] ?? ''),
            'displayName' => Auth::displayName($r),
            'role' => $role,
            'roleLabel' => Auth::ROLES[$role]['label'],
            'active' => (bool) (int) ($r['active'] ?? 1),
            'lastLogin' => Db::fromDbTime($r['last_login'] ?? null),
            'createdAt' => Db::fromDbTime($r['created_at'] ?? null),
        ];
    }

    public static function list(): array
    {
        return array_map([self::class, 'map'], Db::all('SELECT * FROM admins ORDER BY active DESC, username'));
    }

    public static function roles(): array
    {
        $out = [];
        foreach (Auth::ROLES as $k => $r) $out[] = ['value' => $k, 'label' => $r['label'], 'about' => $r['about']];
        return $out;
    }

    private static function row(int $id): ?array
    {
        return Db::one('SELECT * FROM admins WHERE id = ?', [$id]);
    }

    private static function fail(string $msg, int $status = 400): never
    {
        throw new BookingError($msg, $status);
    }

    private static function name(mixed $v): string
    {
        $n = Js::trim(Js::str($v ?? ''));
        if (Js::length($n) > 60) self::fail('The name can be up to 60 characters.');
        return $n;
    }

    private static function role(mixed $v): string
    {
        $r = is_string($v) ? $v : '';
        if (!isset(Auth::ROLES[$r])) self::fail('Choose what this login can use.');
        return $r;
    }

    private static function password(mixed $v): string
    {
        if (!is_string($v) || Js::length($v) < self::MIN_PASSWORD) self::fail('The password must be at least ' . self::MIN_PASSWORD . ' characters.');
        if (Js::length($v) > 200) self::fail('The password is too long.');
        return $v;
    }

    /** Active admins other than $exceptId. */
    private static function adminsLeft(int $exceptId): int
    {
        return (int) Db::one("SELECT COUNT(*) n FROM admins WHERE role = 'admin' AND active = 1 AND id <> ?", [$exceptId])['n'];
    }

    /** {username, name, role, password} */
    public static function create(array $in): array
    {
        $username = strtolower(Js::trim(Js::str($in['username'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,39}$/', $username)) {
            self::fail('The username needs 3 to 40 letters or numbers (dots, dashes and underscores are fine, no spaces).');
        }
        $name = self::name($in['name'] ?? '');
        $role = self::role($in['role'] ?? null);
        $hash = Auth::hashPassword(self::password($in['password'] ?? null));
        return Db::transaction(function () use ($username, $name, $role, $hash) {
            if (Db::one('SELECT id FROM admins WHERE LOWER(username) = ?', [$username])) self::fail('That username is taken. Choose another.', 409);
            $id = Db::run('INSERT INTO admins (username, password_hash, name, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)',
                [$username, $hash, $name, $role, Db::toDbTime(Js::isoNow())]);
            Auth::forgetNames();
            return self::map(self::row($id));
        });
    }

    /**
     * {name?, role?, active?, password?} — $me is the signed-in admin. A new password signs that
     * login out everywhere; switching a login off signs it out on its next request.
     */
    public static function update(int $id, array $in, array $me): array
    {
        return Db::transaction(function () use ($id, $in, $me) {
            $u = self::row($id);
            if (!$u) self::fail('Login not found.', 404);
            $set = [];
            if (array_key_exists('name', $in)) $set['name'] = self::name($in['name']);
            if (array_key_exists('role', $in)) $set['role'] = self::role($in['role']);
            if (array_key_exists('active', $in)) $set['active'] = Js::truthy($in['active']) ? 1 : 0;
            if (Js::truthy($in['password'] ?? null)) $set['password_hash'] = Auth::hashPassword(self::password($in['password']));
            $self = (int) $u['id'] === (int) $me['id'];
            $wasAdmin = Auth::role($u) === 'admin' && (int) $u['active'];
            $staysAdmin = ($set['role'] ?? Auth::role($u)) === 'admin' && ($set['active'] ?? (int) $u['active']);
            if ($self && isset($set['password_hash'])) self::fail('Change your own password in Settings → Change password.');
            if ($self && isset($set['active']) && !$set['active']) self::fail('You can\'t switch off your own login.');
            if ($self && isset($set['role']) && $set['role'] !== 'admin') self::fail('You can\'t take admin away from your own login. Ask another admin.');
            if ($wasAdmin && !$staysAdmin && self::adminsLeft($id) === 0) self::fail('There must always be at least one active admin.');
            if ($set) {
                $cols = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($set)));
                Db::run("UPDATE admins SET $cols WHERE id = ?", [...array_values($set), $id]);
            }
            Auth::forgetNames();
            return self::map(self::row($id));
        });
    }

    /**
     * Only a login that has never signed in can be removed: bills and receipts store the username,
     * so a used login is switched off instead and old bills keep the right name.
     */
    public static function delete(int $id, array $me): void
    {
        Db::transaction(function () use ($id, $me) {
            $u = self::row($id);
            if (!$u) self::fail('Login not found.', 404);
            if ((int) $u['id'] === (int) $me['id']) self::fail('You can\'t remove your own login.');
            if (!empty($u['last_login'])) self::fail('This login has been used, so it can only be switched off. That keeps the right name on old bills and receipts.', 409);
            if (Auth::role($u) === 'admin' && (int) $u['active'] && self::adminsLeft($id) === 0) self::fail('There must always be at least one active admin.');
            Db::run('DELETE FROM admins WHERE id = ?', [$id]);
            Auth::forgetNames();
        });
    }
}
