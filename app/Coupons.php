<?php
// Coupon codes: a % or an amount off the room charge (the room or per-person stay price,
// never food, drinks or extras), with a usage limit and optional dates. Made one at a time
// or in batches (random or numbered codes) in Admin → Coupons, and used on the booking page
// or at POS checkout. A code is "used" by every booking made with it (until that booking is
// cancelled) and every issued bill it was used on, so the count can never drift.
declare(strict_types=1);

namespace Ydr;

final class Coupons
{
    public const MAX_BATCH = 500;
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O or 1/I

    /** "summer 10" → "SUMMER10" */
    public static function normalize(mixed $code): string
    {
        return is_string($code) ? strtoupper((string) preg_replace('/\s+/', '', $code)) : '';
    }

    private static function validCode(string $code): bool
    {
        return (bool) preg_match('/^[A-Z0-9][A-Z0-9-]{2,39}$/', $code);
    }

    public static function label(string $type, int $value): string
    {
        return $type === 'percent' ? "$value% off the room charge" : Config::get()['site']['currency'] . ' ' . number_format($value) . ' off the room charge';
    }

    /** The discount on a room charge of $amount (never more than the charge). */
    public static function discountOn(string $type, int $value, int $amount): int
    {
        if ($amount <= 0 || $value <= 0) return 0;
        return $type === 'percent' ? (int) round($amount * min(100, $value) / 100) : min($value, $amount);
    }

    /** Bookings made with the code (not cancelled) plus issued bills it was used on. */
    public static function usesOf(string $code): int
    {
        if ($code === '') return 0;
        return (int) Db::one("SELECT COUNT(*) AS n FROM bookings WHERE coupon_code = ? AND status != 'cancelled'", [$code])['n']
            + (int) Db::one("SELECT COUNT(*) AS n FROM bills WHERE coupon_code = ? AND status = 'issued'", [$code])['n'];
    }

    private static function niceDate(string $d): string
    {
        return (new \DateTimeImmutable($d . ' 00:00:00', new \DateTimeZone('UTC')))->format('j M Y');
    }

    /**
     * A coupon that can be used now on a room charge of $amount:
     * ['id', 'code', 'type', 'value', 'label', 'discount']. Throws a message the guest can read
     * when it can't be used. $lock (inside a transaction): lock it first, so the last use can
     * only be taken once.
     */
    public static function check(mixed $code, int $amount, bool $lock = false): array
    {
        $code = self::normalize($code);
        if ($code === '') throw new BookingError('Please enter the coupon code.', 400, 'coupon');
        $r = Db::one('SELECT * FROM coupons WHERE code = ?', [$code]);
        if (!$r) throw new BookingError("Sorry — $code is not a valid coupon code.", 400, 'coupon');
        if ($lock) {
            Db::lockCoupon((int) $r['id']);
            $r = Db::one('SELECT * FROM coupons WHERE id = ?', [(int) $r['id']]);
        }
        $t = Bookings::today();
        if (!(int) $r['active']) throw new BookingError("Sorry — coupon $code is no longer valid.", 400, 'coupon');
        if ($r['valid_from'] !== null && strcmp($t, (string) $r['valid_from']) < 0) throw new BookingError("Coupon $code can be used from " . self::niceDate((string) $r['valid_from']) . '.', 400, 'coupon');
        if ($r['valid_to'] !== null && strcmp($t, (string) $r['valid_to']) > 0) throw new BookingError("Sorry — coupon $code expired on " . self::niceDate((string) $r['valid_to']) . '.', 400, 'coupon');
        $max = (int) $r['max_uses'];
        if ($max && self::usesOf($code) >= $max) throw new BookingError("Sorry — coupon $code has already been used.", 400, 'coupon');
        return ['id' => (int) $r['id'], 'code' => $code, 'type' => $r['type'], 'value' => (int) $r['value'], 'label' => self::label($r['type'], (int) $r['value']),
            'discount' => self::discountOn($r['type'], (int) $r['value'], $amount)];
    }

    /** The coupon row as it is now (even if switched off), to re-price a booking that used it. */
    public static function find(string $code): ?array
    {
        return $code === '' ? null : Db::one('SELECT * FROM coupons WHERE code = ?', [$code]);
    }

    // ---------- admin ----------
    private const USES_SQL = "(SELECT COUNT(*) FROM bookings b WHERE b.coupon_code = c.code AND b.status != 'cancelled')
        + (SELECT COUNT(*) FROM bills bl WHERE bl.coupon_code = c.code AND bl.status = 'issued')";

    private static function map(array $r): array
    {
        $uses = (int) ($r['uses'] ?? self::usesOf($r['code']));
        $max = (int) $r['max_uses'];
        $t = Bookings::today();
        $state = !(int) $r['active'] ? 'off'
            : ($r['valid_to'] !== null && strcmp($t, (string) $r['valid_to']) > 0 ? 'expired'
            : ($max && $uses >= $max ? 'used'
            : ($r['valid_from'] !== null && strcmp($t, (string) $r['valid_from']) < 0 ? 'later' : 'ready')));
        return [
            'id' => (int) $r['id'], 'code' => $r['code'], 'type' => $r['type'], 'value' => (int) $r['value'], 'label' => self::label($r['type'], (int) $r['value']),
            'maxUses' => $max, 'uses' => $uses, 'left' => $max ? max(0, $max - $uses) : null,
            'validFrom' => $r['valid_from'], 'validTo' => $r['valid_to'], 'active' => (bool) (int) $r['active'], 'state' => $state,
            'batch' => $r['batch'], 'note' => $r['note'], 'createdAt' => Db::fromDbTime($r['created_at']), 'createdBy' => $r['created_by'],
        ];
    }

    public static function get(int $id): ?array
    {
        $r = Db::one('SELECT c.*, ' . self::USES_SQL . ' AS uses FROM coupons c WHERE c.id = ?', [$id]);
        return $r ? self::map($r) : null;
    }

    /** $opts: q (part of a code), batch */
    public static function list(array $opts = []): array
    {
        $where = [];
        $vals = [];
        $q = self::normalize($opts['q'] ?? '');
        if ($q !== '') { $where[] = 'c.code LIKE ?'; $vals[] = '%' . Js::slice($q, 40) . '%'; }
        if (isset($opts['batch']) && is_string($opts['batch']) && $opts['batch'] !== '') { $where[] = 'c.batch = ?'; $vals[] = $opts['batch']; }
        $rows = Db::all('SELECT c.*, ' . self::USES_SQL . ' AS uses FROM coupons c' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.id DESC LIMIT 3000', $vals);
        return array_map([self::class, 'map'], $rows);
    }

    /** Batches made so far: [{batch, count, used, createdAt}] */
    public static function batches(): array
    {
        return array_map(fn ($r) => ['batch' => $r['batch'], 'count' => (int) $r['n'], 'createdAt' => Db::fromDbTime($r['first'])],
            Db::all("SELECT batch, COUNT(*) AS n, MIN(created_at) AS first FROM coupons WHERE batch != '' GROUP BY batch ORDER BY first DESC"));
    }

    /** Where a code was used: bookings and bills. */
    public static function usedBy(string $code): array
    {
        $out = [];
        foreach (Db::all('SELECT id, ref, guest_name, status, coupon_discount, created_at FROM bookings WHERE coupon_code = ? ORDER BY id', [$code]) as $b) {
            $out[] = ['kind' => 'booking', 'id' => (int) $b['id'], 'ref' => $b['ref'], 'guestName' => $b['guest_name'], 'status' => $b['status'],
                'discount' => (int) $b['coupon_discount'], 'counts' => $b['status'] !== 'cancelled', 'at' => Db::fromDbTime($b['created_at'])];
        }
        foreach (Db::all('SELECT id, bill_no, guest_name, status, coupon_discount, created_at FROM bills WHERE coupon_code = ? ORDER BY id', [$code]) as $b) {
            $out[] = ['kind' => 'bill', 'id' => (int) $b['id'], 'ref' => $b['bill_no'], 'guestName' => $b['guest_name'], 'status' => $b['status'],
                'discount' => (int) $b['coupon_discount'], 'counts' => $b['status'] === 'issued', 'at' => Db::fromDbTime($b['created_at'])];
        }
        return $out;
    }

    private static function date(mixed $v, string $what): ?string
    {
        if ($v === null || $v === '') return null;
        if (!Bookings::isDate($v)) throw new BookingError("Please check the $what date.");
        return $v;
    }

    /** type, value, maxUses, validFrom, validTo, note — checked. */
    private static function fields(array $in, ?array $cur = null): array
    {
        $has = fn (string $k) => !$cur || array_key_exists($k, $in);
        $type = $has('type') ? (($in['type'] ?? 'percent') === 'amount' ? 'amount' : 'percent') : $cur['type'];
        $value = $has('value') ? Bookings::int($in['value'] ?? null, 1, $type === 'percent' ? 100 : 100000000) : (int) $cur['value'];
        if ($value === null || ($type === 'percent' && $value > 100)) {
            throw new BookingError($type === 'percent' ? 'The discount must be a percentage from 1 to 100.' : 'The discount must be a whole amount above 0.');
        }
        $max = $has('maxUses') ? Bookings::int($in['maxUses'] ?? 1, 0, 1000000) : (int) $cur['max_uses'];
        if ($max === null) throw new BookingError('The usage limit must be a whole number (0 = no limit).');
        $from = $has('validFrom') ? self::date($in['validFrom'] ?? null, 'start') : $cur['valid_from'];
        $to = $has('validTo') ? self::date($in['validTo'] ?? null, 'end') : $cur['valid_to'];
        if ($from !== null && $to !== null && strcmp($to, $from) < 0) throw new BookingError('The end date is before the start date.');
        $note = $has('note') ? (Bookings::str($in['note'] ?? null, 255) ?? '') : $cur['note'];
        $active = array_key_exists('active', $in) ? (Js::truthy($in['active']) ? 1 : 0) : ($cur ? (int) $cur['active'] : 1);
        return ['type' => $type, 'value' => $value, 'max_uses' => $max, 'valid_from' => $from, 'valid_to' => $to, 'note' => $note, 'active' => $active];
    }

    private static function insert(string $code, array $f, string $batch, string $by): int
    {
        return Db::run('INSERT INTO coupons (code, type, value, max_uses, valid_from, valid_to, active, batch, note, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$code, $f['type'], $f['value'], $f['max_uses'], $f['valid_from'], $f['valid_to'], $f['active'], $batch, $f['note'], Db::toDbTime(Js::isoNow()), $by]);
    }

    private static function randomPart(int $len): string
    {
        $s = '';
        foreach (str_split(random_bytes($len)) as $b) $s .= self::ALPHABET[ord($b) % strlen(self::ALPHABET)];
        return $s;
    }

    /** One coupon: code (empty = a random one), type, value, maxUses, validFrom, validTo, note. */
    public static function create(array $in, string $by): array
    {
        $f = self::fields($in);
        $code = self::normalize($in['code'] ?? '');
        if ($code === '') {
            do { $code = self::randomPart(8); } while (Db::one('SELECT id FROM coupons WHERE code = ?', [$code]));
        } elseif (!self::validCode($code)) {
            throw new BookingError('A coupon code is 3 to 40 letters, numbers or dashes.');
        } elseif (Db::one('SELECT id FROM coupons WHERE code = ?', [$code])) {
            throw new BookingError("There is already a coupon $code.");
        }
        return self::get(self::insert($code, $f, '', $by));
    }

    /**
     * A batch of coupons with the same discount and limit: count, prefix, style (random: PREFIX-7K3M9Q,
     * numbered: PREFIX-001, PREFIX-002…), start (numbered), batch (its name), and the coupon fields.
     */
    public static function generate(array $in, string $by): array
    {
        $f = self::fields($in);
        $count = Bookings::int($in['count'] ?? null, 1, self::MAX_BATCH);
        if ($count === null) throw new BookingError('Make between 1 and ' . self::MAX_BATCH . ' coupons at a time.');
        $prefix = self::normalize($in['prefix'] ?? '');
        $prefix = rtrim($prefix, '-');
        if (!preg_match('/^[A-Z0-9]{0,16}$/', $prefix)) throw new BookingError('The prefix can have up to 16 letters and numbers.');
        $numbered = ($in['style'] ?? 'random') === 'numbered';
        $start = Bookings::int($in['start'] ?? 1, 0, 9999999);
        if ($start === null) throw new BookingError('The first number must be a whole number.');
        if ($numbered && $prefix === '') throw new BookingError('Numbered coupons need a prefix, e.g. YOD.');
        $join = $prefix === '' ? '' : $prefix . '-';
        $codes = [];
        if ($numbered) {
            $digits = max(3, strlen((string) ($start + $count - 1)));
            for ($i = 0; $i < $count; $i++) $codes[] = $join . str_pad((string) ($start + $i), $digits, '0', STR_PAD_LEFT);
            $taken = array_column(Db::all('SELECT code FROM coupons WHERE code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')', $codes), 'code');
            if ($taken) throw new BookingError('Some of those codes already exist (' . implode(', ', array_slice($taken, 0, 5)) . (count($taken) > 5 ? '…' : '') . '). Choose another prefix or first number.');
        } else {
            $seen = [];
            while (count($codes) < $count) {
                $c = $join . self::randomPart(6);
                if (isset($seen[$c]) || Db::one('SELECT id FROM coupons WHERE code = ?', [$c])) continue;
                $seen[$c] = true;
                $codes[] = $c;
            }
        }
        $name = Bookings::str($in['batch'] ?? null, 60) ?? '';
        if ($name === '') $name = ($prefix !== '' ? $prefix : 'Coupons') . ' · ' . (new \DateTimeImmutable('now', new \DateTimeZone(Config::get()['timezone'])))->format('j M Y H:i');
        $base = $name;
        for ($n = 2; Db::one('SELECT id FROM coupons WHERE batch = ?', [$name]); $n++) $name = Js::slice($base, 54) . " ($n)";
        Db::transaction(function () use ($codes, $f, $name, $by) {
            foreach ($codes as $c) self::insert($c, $f, $name, $by);
        });
        return ['batch' => $name, 'coupons' => self::list(['batch' => $name])];
    }

    /** Change a coupon (not its code): active, type, value, maxUses, validFrom, validTo, note. */
    public static function update(int $id, array $in): array
    {
        $cur = Db::one('SELECT * FROM coupons WHERE id = ?', [$id]);
        if (!$cur) throw new BookingError('Coupon not found', 404, 'not_found');
        $f = self::fields($in, $cur);
        Db::run('UPDATE coupons SET type = ?, value = ?, max_uses = ?, valid_from = ?, valid_to = ?, note = ?, active = ? WHERE id = ?',
            [$f['type'], $f['value'], $f['max_uses'], $f['valid_from'], $f['valid_to'], $f['note'], $f['active'], $id]);
        return self::get($id);
    }

    /** Switch a whole batch on or off. Returns how many coupons it has. */
    public static function setBatchActive(string $batch, bool $on): int
    {
        if ($batch === '') throw new BookingError('Choose a batch.');
        Db::run('UPDATE coupons SET active = ? WHERE batch = ?', [$on ? 1 : 0, $batch]);
        return (int) Db::one('SELECT COUNT(*) AS n FROM coupons WHERE batch = ?', [$batch])['n'];
    }

    /** Delete a coupon nobody has used yet (a typo, a test). Used ones are switched off instead. */
    public static function delete(int $id): void
    {
        $cur = Db::one('SELECT * FROM coupons WHERE id = ?', [$id]);
        if (!$cur) throw new BookingError('Coupon not found', 404, 'not_found');
        if (self::usedBy($cur['code'])) throw new BookingError("{$cur['code']} has been used, so it is kept for the records. Switch it off instead.", 409, 'used');
        Db::run('DELETE FROM coupons WHERE id = ?', [$id]);
    }
}
