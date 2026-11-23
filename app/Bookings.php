<?php
// Booking domain logic. There are two ways to book (both editable in Admin → Booking setup):
//   • a room: the guest chooses a room, priced per room per night;
//   • per person: an option such as full or half board, priced per adult and per child per
//     night. The group is given rooms automatically from the room priority list, so those
//     rooms can't be booked by anyone else. An option can also be a day visit (no rooms).
// The hotel's capacity (guests per night) is shared by every booking. Rooms, the party area
// and the capacity are checked and saved inside one locked transaction, so nothing is
// double-booked.
declare(strict_types=1);

namespace Ydr;

final class BookingError extends \RuntimeException
{
    public int $status;
    public string $errorCode;

    public function __construct(string $message, int $status = 400, string $code = 'invalid')
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $code;
    }
}

final class Bookings
{
    public const STATUSES = ['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled'];
    // statuses that occupy rooms and capacity (cancelled / checked-out do not block new bookings)
    public const HOLDING = ['pending', 'confirmed', 'checked_in'];
    public const SOURCES = [
        'website' => 'Website',
        'phone' => 'Phone call',
        'whatsapp' => 'WhatsApp',
        'walk_in' => 'Walk-in',
        'booking_com' => 'Booking.com',
        'agoda' => 'Agoda',
        'airbnb' => 'Airbnb',
        'expedia' => 'Expedia',
        'travel_agent' => 'Travel agent',
        'other' => 'Other',
        'block' => 'Blocked dates',
    ];
    /** bookings.package: what was booked */
    public const KINDS = ['room' => 'Room', 'per_person' => 'Per person', 'day_out' => 'Day out', 'event' => 'Outdoor party'];
    /**
     * Outdoor parties (admins and managers book them): on one date, in the party area, at an agreed
     * price, optionally holding rooms for party guests who stay. While one is on, other guests can
     * still book (open), not stay that night (rooms), or not come at all (private) — Closures.
     */
    public const EVENT_SLOTS = ['day' => 'Day party', 'night' => 'Night party', 'full' => 'Day & night party'];
    public const EVENT_OTHERS = ['open' => 'Rooms and day visits stay open to other guests', 'rooms' => 'No other guests stay that night', 'private' => 'Whole resort private — no other stays or day visits'];
    public const MAX_EVENT_GUESTS = 1000;
    private const PRICE_SETTINGS = ['partyArea' => 'price_party_area', 'jblSpeaker' => 'price_jbl_speaker'];
    public const POLICY_MAX = 5000; // characters in the cancellation policy
    private const MAX_NIGHTS = 30;
    private const MAX_ADVANCE_DAYS = 540;
    public const MAX_GROUP_ADULTS = 50;
    public const MAX_GROUP_CHILDREN = 30;
    /** rooms one room booking can hold (a family or group taking several rooms) */
    public const MAX_ROOMS = 10;
    /**
     * Meal plans a room is booked with (the rate sheet's columns): code => [name, what it includes].
     * Each room has a price per night for each (rooms.price is room only); 0 = not offered.
     */
    public const MEAL_PLANS = [
        'RO' => ['Room only', 'No meals'],
        'BB' => ['Bed & breakfast', 'Breakfast'],
        'HB' => ['Half board', 'Breakfast and dinner'],
        'FB' => ['Full board', 'Breakfast, lunch and dinner'],
    ];
    private const PLAN_COLUMNS = ['RO' => 'price', 'BB' => 'price_bb', 'HB' => 'price_hb', 'FB' => 'price_fb'];
    private const WHOLE_COLUMNS = ['RO' => 'whole_ro', 'BB' => 'whole_bb', 'HB' => 'whole_hb', 'FB' => 'whole_fb'];

    private static function holdingSql(): string
    {
        return implode(',', array_fill(0, count(self::HOLDING), '?'));
    }

    private static function marks(array $list): string
    {
        return implode(',', array_fill(0, count($list), '?'));
    }

    private static function isSource(mixed $s): bool
    {
        return is_string($s) && array_key_exists($s, self::SOURCES);
    }

    // ---------- dates (all plain YYYY-MM-DD strings, resort-local) ----------
    public static function isDate(mixed $s): bool
    {
        if (!is_string($s) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return false;
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(Config::get()['timezone'])))->format('Y-m-d');
    }

    public static function addDays(string $s, int $n): string
    {
        return (new \DateTimeImmutable($s . ' 00:00:00', new \DateTimeZone('UTC')))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }

    public static function nightsBetween(string $a, string $b): int
    {
        $utc = new \DateTimeZone('UTC');
        $ta = (new \DateTimeImmutable($a . ' 00:00:00', $utc))->getTimestamp();
        $tb = (new \DateTimeImmutable($b . ' 00:00:00', $utc))->getTimestamp();
        return (int) round(($tb - $ta) / 86400);
    }

    // ---------- rooms ----------
    private static function mapRoom(?array $r): ?array
    {
        if (!$r) return null;
        return [
            'id' => (int) $r['id'],
            'slug' => $r['slug'],
            'name' => $r['name'],
            'type' => $r['type'],
            'categoryId' => self::intOrNull($r['category_id'] ?? null),
            'label' => (string) ($r['room_label'] ?? ''), // room type on the rate sheet, e.g. Double Room
            'tagline' => $r['tagline'],
            'description' => $r['description'],
            'price' => (int) $r['price'], // room only
            'prices' => self::planPrices($r),
            'capacity' => (int) $r['capacity'],
            'beds' => $r['beds'],
            'size' => (int) $r['size_sqm'],
            'features' => json_decode($r['features'] ?: '[]', true),
            'images' => json_decode($r['images'] ?: '[]', true),
            'active' => (bool) (int) $r['active'],
            'sort' => (int) $r['sort'],
        ];
    }

    public static function listRooms(bool $includeInactive = false): array
    {
        $rows = Db::all('SELECT * FROM rooms ' . ($includeInactive ? '' : 'WHERE active = 1') . ' ORDER BY sort, id');
        return array_map([self::class, 'mapRoom'], $rows);
    }

    public static function getRoom(mixed $idOrSlug): ?array
    {
        if ((is_int($idOrSlug) || is_float($idOrSlug)) || preg_match('/^\d+$/', Js::str($idOrSlug ?? 'undefined'))) {
            $n = Js::number($idOrSlug);
            if (is_nan($n) || floor($n) != $n) return null;
            return self::mapRoom(Db::one('SELECT * FROM rooms WHERE id = ?', [(int) $n]));
        }
        return self::mapRoom(Db::one('SELECT * FROM rooms WHERE slug = ?', [$idOrSlug === null ? 'undefined' : Js::str($idOrSlug)]));
    }

    public static function updateRoom(mixed $id, array $patch): array
    {
        $room = self::getRoom($id);
        if (!$room) throw new BookingError('Room not found', 404, 'not_found');
        $fields = [
            'name' => ['name', fn ($v) => self::str($v, 80, true)],
            'type' => ['type', fn ($v) => self::str($v, 60, true)],
            'label' => ['room_label', fn ($v) => self::str($v, 80)],
            'tagline' => ['tagline', fn ($v) => self::str($v, 200)],
            'description' => ['description', fn ($v) => self::str($v, 2000)],
            'price' => ['price', fn ($v) => self::int($v, 0, 10000000)],
            'capacity' => ['capacity', fn ($v) => self::int($v, 1, 50)],
            'beds' => ['beds', fn ($v) => self::str($v, 80)],
            'size' => ['size_sqm', fn ($v) => self::int($v, 0, 2000)],
            'active' => ['active', fn ($v) => Js::truthy($v) ? 1 : 0],
            'features' => ['features', function ($v) {
                $list = (is_array($v) && Js::isList($v)) ? $v : [];
                $out = [];
                foreach ($list as $x) {
                    $s = self::str($x, 60);
                    if ($s !== null && $s !== '') $out[] = $s;
                }
                return Js::json(array_slice($out, 0, 12));
            }],
            'images' => ['images', function ($v) {
                $list = (is_array($v) && Js::isList($v)) ? $v : [];
                $keys = array_values(array_filter($list, fn ($k) => is_string($k) && Media::has($k)));
                $keys = array_slice($keys, 0, 8);
                return $keys ? Js::json($keys) : null;
            }],
        ];
        $sets = [];
        $vals = [];
        // a category: the room's type follows its name
        if (array_key_exists('categoryId', $patch)) {
            $none = $patch['categoryId'] === null || $patch['categoryId'] === '';
            $cat = $none ? null : self::getCategory($patch['categoryId']);
            if (!$none && !$cat) throw new BookingError('That room category no longer exists.');
            $sets[] = 'category_id = ?';
            $vals[] = $cat ? $cat['id'] : null;
            if ($cat) { unset($patch['type']); $sets[] = 'type = ?'; $vals[] = $cat['name']; }
        }
        foreach ($fields as $k => [$col, $fn]) {
            if (!array_key_exists($k, $patch)) continue;
            $v = $fn($patch[$k]);
            if ($v === null) throw new BookingError("Invalid value for $k ({$room['name']})");
            $sets[] = "$col = ?";
            $vals[] = $v;
        }
        // the price of each meal plan: {RO, BB, HB, FB} (RO is the room's price)
        if (array_key_exists('prices', $patch)) {
            if (!is_array($patch['prices'])) throw new BookingError('Invalid prices');
            foreach (self::PLAN_COLUMNS as $plan => $col) {
                if (!array_key_exists($plan, $patch['prices']) || ($col === 'price' && array_key_exists('price', $patch))) continue;
                $v = self::int($patch['prices'][$plan], 0, 10000000);
                if ($v === null) throw new BookingError("{$room['name']}: the " . self::MEAL_PLANS[$plan][0] . ' price must be a whole number of 0 or more.');
                $sets[] = "$col = ?";
                $vals[] = $v;
            }
        }
        if ($sets) Db::run('UPDATE rooms SET ' . implode(', ', $sets) . ' WHERE id = ?', array_merge($vals, [$room['id']]));
        return self::getRoom($room['id']);
    }

    /** A room's price per night for each meal plan, from a rooms row ($p: column prefix, e.g. room_). */
    private static function planPrices(array $row, string $p = ''): array
    {
        $out = [];
        foreach (self::PLAN_COLUMNS as $plan => $col) $out[$plan] = (int) ($row[$p . $col] ?? 0);
        return $out;
    }

    /** What a room costs a night with this meal plan (0 = not offered). */
    public static function planPrice(array $room, string $plan): int
    {
        return (int) ($room['prices'][$plan] ?? ($plan === 'RO' ? $room['price'] : 0));
    }

    /** A meal plan code from input ('' / null = room only). */
    public static function planOf(mixed $v): string
    {
        if ($v === null || $v === '') return 'RO';
        $code = is_string($v) ? strtoupper($v) : '';
        if (!isset(self::MEAL_PLANS[$code])) throw new BookingError('Please choose a meal plan: room only, bed & breakfast, half board or full board.');
        return $code;
    }

    /** The meal plans for the pages: [['code', 'name', 'includes'], ...]. */
    public static function mealPlans(): array
    {
        $out = [];
        foreach (self::MEAL_PLANS as $code => [$name, $inc]) $out[] = ['code' => $code, 'name' => $name, 'includes' => $inc];
        return $out;
    }

    // ---------- room categories: the tables of the rate sheet ----------
    private static function mapCategory(?array $c): ?array
    {
        if (!$c) return null;
        $prices = [];
        foreach (self::WHOLE_COLUMNS as $plan => $col) $prices[$plan] = (int) $c[$col];
        return [
            'id' => (int) $c['id'],
            'name' => $c['name'],
            'sort' => (int) $c['sort'],
            // every room of the category together, at one price (e.g. "Whole Villa"); null = not offered
            'whole' => $c['whole_label'] !== '' ? ['code' => $c['whole_code'], 'label' => $c['whole_label'], 'capacity' => (int) $c['whole_pax'], 'prices' => $prices] : null,
            'wholeCode' => $c['whole_code'], 'wholeLabel' => $c['whole_label'], 'wholeCapacity' => (int) $c['whole_pax'], 'wholePrices' => $prices,
        ];
    }

    public static function listCategories(): array
    {
        return array_map([self::class, 'mapCategory'], Db::all('SELECT * FROM room_categories ORDER BY sort, id'));
    }

    public static function getCategory(mixed $id): ?array
    {
        $n = self::int($id, 1, PHP_INT_MAX);
        return $n ? self::mapCategory(Db::one('SELECT * FROM room_categories WHERE id = ?', [$n])) : null;
    }

    /** The rooms of a category that guests can book (a whole-category booking takes them all). */
    public static function categoryRooms(int $categoryId): array
    {
        return array_values(array_filter(self::listRooms(), fn ($r) => $r['categoryId'] === $categoryId));
    }

    /**
     * The rate sheet: each category with its rooms, in order, and the rooms with no category.
     * $public: only rooms guests can book, and only the categories that have one.
     */
    public static function rateSheet(bool $public = false): array
    {
        $rooms = self::listRooms(!$public);
        $cats = [];
        foreach (self::listCategories() as $c) {
            $c['rooms'] = array_values(array_filter($rooms, fn ($r) => $r['categoryId'] === $c['id']));
            if ($public && !$c['rooms']) continue;
            $cats[] = $c;
        }
        $known = array_column($cats, 'id');
        $loose = array_values(array_filter($rooms, fn ($r) => !in_array($r['categoryId'], $known, true)));
        return ['categories' => $cats, 'uncategorised' => $loose, 'mealPlans' => self::mealPlans()];
    }

    /** Create ($id = null) or update a room category: its name, place, and the whole-category option and prices. */
    public static function saveCategory(?int $id, array $in): array
    {
        $cur = $id ? Db::one('SELECT * FROM room_categories WHERE id = ?', [$id]) : null;
        if ($id && !$cur) throw new BookingError('Room category not found', 404, 'not_found');
        $has = fn (string $k) => !$cur || array_key_exists($k, $in);
        $name = $has('name') ? self::str($in['name'] ?? null, 80, true) : $cur['name'];
        if ($name === null) throw new BookingError('Please name the room category.');
        $code = $has('wholeCode') ? self::str($in['wholeCode'] ?? '', 20) : $cur['whole_code'];
        $label = $has('wholeLabel') ? self::str($in['wholeLabel'] ?? '', 80) : $cur['whole_label'];
        $pax = $has('wholeCapacity') ? self::int($in['wholeCapacity'] ?? 0, 0, 500) : (int) $cur['whole_pax'];
        if ($code === null || $label === null || $pax === null) throw new BookingError("$name: check the whole-category option.");
        $prices = [];
        $given = is_array($in['wholePrices'] ?? null) ? $in['wholePrices'] : [];
        foreach (self::WHOLE_COLUMNS as $plan => $col) {
            $v = array_key_exists($plan, $given) ? self::int($given[$plan], 0, 100000000) : ($cur ? (int) $cur[$col] : 0);
            if ($v === null) throw new BookingError("$name: the whole-category prices must be whole numbers of 0 or more.");
            $prices[$col] = $v;
        }
        $sort = array_key_exists('sort', $in) ? self::int($in['sort'], 0, 10000) : ($cur ? (int) $cur['sort'] : (int) Db::one('SELECT COALESCE(MAX(sort), 0) AS n FROM room_categories')['n'] + 1);
        if ($sort === null) throw new BookingError('Invalid position.');
        $vals = [$name, $sort, $code, $label, $pax, ...array_values($prices)];
        if ($cur) {
            Db::run('UPDATE room_categories SET name = ?, sort = ?, whole_code = ?, whole_label = ?, whole_pax = ?, whole_ro = ?, whole_bb = ?, whole_hb = ?, whole_fb = ? WHERE id = ?', [...$vals, $id]);
            if ($name !== $cur['name']) Db::run('UPDATE rooms SET type = ? WHERE category_id = ?', [$name, $id]); // rooms keep their category's name
        } else {
            $id = Db::run('INSERT INTO room_categories (name, sort, whole_code, whole_label, whole_pax, whole_ro, whole_bb, whole_hb, whole_fb) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', $vals);
        }
        return self::getCategory($id);
    }

    /** Delete a room category with no rooms (bookings that took it whole keep their price). */
    public static function deleteCategory(mixed $id): void
    {
        $c = self::getCategory($id);
        if (!$c) throw new BookingError('Room category not found', 404, 'not_found');
        if (Db::one('SELECT id FROM rooms WHERE category_id = ?', [$c['id']])) throw new BookingError("Move the rooms of {$c['name']} to another category first.", 409, 'in_use');
        if (Db::one('SELECT id FROM bookings WHERE whole_category_id = ?', [$c['id']])) throw new BookingError("Bookings have taken the whole of {$c['name']}, so it can't be deleted. Turn its whole option off instead.", 409, 'in_use');
        Db::run('DELETE FROM room_categories WHERE id = ?', [$c['id']]);
    }

    /**
     * Save Admin -> Rate sheet in one go: categories [{id, name, wholeCode, wholeLabel, wholeCapacity,
     * wholePrices: {RO, BB, HB, FB}}] in their order, and rooms [{id, name, categoryId, label,
     * capacity, prices: {RO, BB, HB, FB}}]. All or nothing.
     */
    public static function saveRateSheet(array $in): array
    {
        $cats = is_array($in['categories'] ?? null) && Js::isList($in['categories']) ? $in['categories'] : [];
        $rooms = is_array($in['rooms'] ?? null) && Js::isList($in['rooms']) ? $in['rooms'] : [];
        Db::transaction(function () use ($cats, $rooms) {
            foreach ($cats as $i => $c) {
                if (!is_array($c)) throw new BookingError('Invalid room category');
                self::saveCategory(self::int($c['id'] ?? null, 1, PHP_INT_MAX), ['sort' => $i + 1] + $c);
            }
            foreach ($rooms as $r) {
                if (!is_array($r) || !isset($r['id'])) throw new BookingError('Invalid room');
                self::updateRoom($r['id'], array_intersect_key($r, array_flip(['name', 'categoryId', 'label', 'capacity', 'prices'])));
            }
        });
        return self::rateSheet();
    }

    // ---------- per-person options (packages) ----------
    private static function mapPackage(?array $r): ?array
    {
        if (!$r) return null;
        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'description' => $r['description'],
            'kind' => $r['kind'] === 'day' ? 'day' : 'stay',
            'priceAdult' => (int) $r['price_adult'],
            'priceChild' => (int) $r['price_child'],
            'active' => (bool) (int) $r['active'],
            'sort' => (int) $r['sort'],
        ];
    }

    public static function listPackages(bool $includeHidden = false): array
    {
        $rows = Db::all('SELECT * FROM packages ' . ($includeHidden ? '' : 'WHERE active = 1 ') . 'ORDER BY sort, id');
        return array_map([self::class, 'mapPackage'], $rows);
    }

    public static function getPackage(mixed $id): ?array
    {
        $n = self::int($id, 1, PHP_INT_MAX);
        return $n ? self::mapPackage(Db::one('SELECT * FROM packages WHERE id = ?', [$n])) : null;
    }

    /** Create ($id = null) or update a per-person option. Options are hidden, never deleted. */
    public static function savePackage(?int $id, array $in): array
    {
        $cur = $id ? Db::one('SELECT * FROM packages WHERE id = ?', [$id]) : null;
        if ($id && !$cur) throw new BookingError('Option not found', 404, 'not_found');
        $has = fn (string $k) => !$cur || array_key_exists($k, $in);
        $name = $has('name') ? self::str($in['name'] ?? null, 120, true) : $cur['name'];
        if ($name === null) throw new BookingError('Please name the option.');
        $desc = $has('description') ? self::str($in['description'] ?? null, 255) : $cur['description'];
        if ($desc === null) throw new BookingError('Invalid description.');
        $kind = $in['kind'] ?? ($cur['kind'] ?? 'stay');
        if (!in_array($kind, ['stay', 'day'], true)) throw new BookingError('Choose "per night" or "day visit".');
        $adult = $has('priceAdult') ? self::int($in['priceAdult'] ?? null, 0, 10000000) : (int) $cur['price_adult'];
        $child = $has('priceChild') ? self::int($in['priceChild'] ?? null, 0, 10000000) : (int) $cur['price_child'];
        if ($adult === null || $child === null) throw new BookingError('Prices must be whole numbers of 0 or more.');
        $active = array_key_exists('active', $in) ? (Js::truthy($in['active']) ? 1 : 0) : (int) ($cur['active'] ?? 1);
        $sort = array_key_exists('sort', $in) ? self::int($in['sort'], 0, 10000) : ($cur ? (int) $cur['sort'] : (int) Db::one('SELECT COALESCE(MAX(sort), 0) AS n FROM packages')['n'] + 1);
        if ($sort === null) throw new BookingError('Invalid position.');
        if ($cur) {
            Db::run('UPDATE packages SET name = ?, description = ?, kind = ?, price_adult = ?, price_child = ?, active = ?, sort = ? WHERE id = ?',
                [$name, $desc, $kind, $adult, $child, $active, $sort, $id]);
        } else {
            $id = Db::run('INSERT INTO packages (name, description, kind, price_adult, price_child, active, sort, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$name, $desc, $kind, $adult, $child, $active, $sort, Db::toDbTime(Js::isoNow())]);
        }
        return self::getPackage($id);
    }

    // ---------- booking setup (labels, capacity, room priority, extras, advance) ----------
    public static function labels(): array
    {
        return ['room' => Settings::get('label_room') ?: 'Select room', 'person' => Settings::get('label_person') ?: 'Per person'];
    }

    /** Party area and JBL speaker, per night (per day for a day visit). */
    public static function prices(): array
    {
        $out = [];
        foreach (self::PRICE_SETTINGS as $k => $name) $out[$k] = Settings::int($name);
        return $out;
    }

    public static function updatePrices(array $patch): array
    {
        $set = [];
        foreach (self::PRICE_SETTINGS as $k => $name) {
            if (!array_key_exists($k, $patch)) continue;
            $v = self::int($patch[$k], 0, 10000000);
            if ($v === null) throw new BookingError('Prices must be whole numbers of 0 or more.');
            $set[$name] = $v;
        }
        Settings::set($set);
        return self::prices();
    }

    /** What the guest is told after booking: who to call and where to pay the advance. */
    public static function paymentInfo(): array
    {
        $site = Site::info();
        $rule = self::advanceRule();
        return [
            'phone' => Settings::get('pay_phone') !== '' ? Settings::get('pay_phone') : $site['phone'],
            'whatsapp' => $site['whatsapp'],
            'bank' => Settings::get('pay_bank'),
            'accountName' => Settings::get('pay_account_name'),
            'accountNo' => Settings::get('pay_account_no'),
            'branch' => Settings::get('pay_branch'),
            'advanceOn' => $rule['on'],
            'advanceType' => $rule['type'],
            'advancePct' => $rule['pct'],
            'advanceAmount' => $rule['amount'],
            'note' => Settings::get('pay_note'),
        ];
    }

    /**
     * Booking setup's advance: whether guests pay one before arrival, and how much — a % of the
     * total or a fixed amount (0 = the whole total). Each booking can have its own (advanceOf).
     */
    public static function advanceRule(): array
    {
        return [
            'on' => Settings::get('pay_advance_on') !== '0',
            'type' => Settings::get('pay_advance_type') === 'amount' ? 'amount' : 'pct',
            'pct' => max(0, min(100, Settings::int('pay_advance_pct'))),
            'amount' => max(0, Settings::int('pay_advance_amount')),
        ];
    }

    /**
     * The advance a booking asks for before arrival. $own is the booking's own setting
     * (bookings.advance_amount): null = Booking setup's rule, 0 = no advance, more = that amount.
     * Returns:
     *   required  the booking waits for an advance (false: nothing to pay before arrival)
     *   amount    what to pay before arrival (0 when not required); never more than the total
     *   full      that is the whole total (no advance amount set, or one as big as the total)
     *   pct       the % of the total, when Booking setup's % gives the amount (else null)
     *   source    'booking' (its own) | 'setup' | 'off' (Booking setup collects no advance)
     *   own       $own
     */
    public static function advanceOf(int $total, ?int $own = null): array
    {
        $total = max(0, $total);
        $rule = self::advanceRule();
        $pct = null;
        if ($own !== null) {
            [$required, $amount, $source] = [$own > 0, min(max(0, $own), $total), 'booking'];
        } elseif (!$rule['on']) {
            [$required, $amount, $source] = [false, 0, 'off'];
        } elseif ($rule['type'] === 'amount' && $rule['amount'] > 0) {
            [$required, $amount, $source] = [true, min($rule['amount'], $total), 'setup'];
        } elseif ($rule['type'] === 'pct' && $rule['pct'] > 0) {
            [$required, $amount, $source, $pct] = [true, (int) round($total * $rule['pct'] / 100), 'setup', $rule['pct']];
        } else {
            [$required, $amount, $source] = [true, $total, 'setup']; // no advance amount set: the whole total
        }
        if (!$required) $amount = 0;
        return ['required' => $required, 'amount' => $amount, 'full' => $required && $amount >= $total, 'pct' => $pct, 'source' => $source, 'own' => $own];
    }

    /** A booking's own advance from admin input: '' / null = Booking setup's rule, 0 = none, more = that amount. */
    private static function advanceInput(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        $n = self::int($v, 0, 100000000);
        if ($n === null) throw new BookingError('The advance must be a whole amount of 0 or more (empty = as in Booking setup).');
        return $n;
    }

    /** The cancellation policy shown at the bottom of the booking page (Admin → Booking setup; '' = none). */
    public static function cancellationPolicy(): string
    {
        return trim(Settings::get('cancel_policy'));
    }

    /** Guests per night the hotel takes (0 = only limited by the rooms). */
    public static function hotelCapacity(): int
    {
        return max(0, Settings::int('hotel_capacity'));
    }

    /** Rooms per-person groups are given, in this order. Unset = every room in the usual order. */
    public static function priorityRoomIds(): array
    {
        $all = array_column(self::listRooms(true), 'id');
        $raw = Settings::get('room_priority');
        $ids = $raw === '' ? null : json_decode($raw, true);
        if (!is_array($ids)) return $all;
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => in_array($id, $all, true))));
    }

    /** Rooms the resort uses: bookable on the website, or given to per-person groups. */
    public static function roomsInUse(): array
    {
        $prio = self::priorityRoomIds();
        return array_values(array_filter(self::listRooms(true), fn ($r) => $r['active'] || in_array($r['id'], $prio, true)));
    }

    public static function setup(): array
    {
        $rooms = self::listRooms(true);
        $pay = self::paymentInfo();
        return [
            'labels' => self::labels(),
            'capacity' => self::hotelCapacity(),
            'roomsCapacity' => array_sum(array_column(self::roomsInUse(), 'capacity')),
            'priority' => self::priorityRoomIds(),
            'rooms' => array_map(fn ($r) => ['id' => $r['id'], 'name' => $r['name'], 'type' => $r['type'], 'capacity' => $r['capacity'], 'active' => $r['active']], $rooms),
            'packages' => self::listPackages(true),
            'prices' => self::prices(),
            'serviceCharge' => Pos::servicePct(),
            'payment' => ['phone' => Settings::get('pay_phone')] + $pay,
            'defaultPhone' => Site::info()['phone'],
            'holdDays' => self::holdDays(),
            'paymentFields' => Pos::paymentFields(),
            'cancellationPolicy' => self::cancellationPolicy(),
        ];
    }

    public static function saveSetup(array $in): array
    {
        $set = [];
        if (isset($in['labels']) && is_array($in['labels'])) {
            foreach (['room' => 'label_room', 'person' => 'label_person'] as $k => $name) {
                if (!array_key_exists($k, $in['labels'])) continue;
                $v = self::str($in['labels'][$k], 60, true);
                if ($v === null) throw new BookingError('The category names cannot be empty.');
                $set[$name] = $v;
            }
        }
        if (array_key_exists('capacity', $in)) {
            $v = self::int($in['capacity'], 0, 10000);
            if ($v === null) throw new BookingError('The capacity must be a whole number of 0 or more.');
            $set['hotel_capacity'] = $v;
        }
        if (array_key_exists('priority', $in)) {
            if (!is_array($in['priority']) || !Js::isList($in['priority'])) throw new BookingError('Invalid room order.');
            $all = array_column(self::listRooms(true), 'id');
            $ids = [];
            foreach ($in['priority'] as $v) {
                $id = self::int($v, 1, PHP_INT_MAX);
                if (!$id || !in_array($id, $all, true)) throw new BookingError('One of those rooms no longer exists.');
                if (!in_array($id, $ids, true)) $ids[] = $id;
            }
            $set['room_priority'] = Js::json($ids);
        }
        if (array_key_exists('serviceCharge', $in)) {
            $v = self::int($in['serviceCharge'], 0, 100);
            if ($v === null) throw new BookingError('The service charge must be a percentage from 0 to 100.');
            $set['service_charge_pct'] = $v;
        }
        if (array_key_exists('holdDays', $in)) {
            $v = self::int($in['holdDays'], 0, 365);
            if ($v === null) throw new BookingError('Hold reservations for a whole number of days (0 = never release them).');
            $set['hold_days'] = $v;
        }
        if (array_key_exists('cancellationPolicy', $in)) {
            $v = self::str($in['cancellationPolicy'], self::POLICY_MAX);
            if ($v === null) throw new BookingError('Please check the cancellation policy.');
            $set['cancel_policy'] = str_replace("\r\n", "\n", $v);
        }
        if (array_key_exists('paymentFields', $in)) Pos::savePaymentFields($in['paymentFields']);
        if (isset($in['prices']) && is_array($in['prices'])) self::updatePrices($in['prices']);
        if (isset($in['payment']) && is_array($in['payment'])) {
            $fields = ['phone' => ['pay_phone', 40], 'bank' => ['pay_bank', 80], 'accountName' => ['pay_account_name', 120],
                'accountNo' => ['pay_account_no', 60], 'branch' => ['pay_branch', 80], 'note' => ['pay_note', 500]];
            foreach ($fields as $k => [$name, $max]) {
                if (!array_key_exists($k, $in['payment'])) continue;
                $v = self::str($in['payment'][$k], $max);
                if ($v === null) throw new BookingError('Please check the payment details.');
                $set[$name] = $v;
            }
            if (array_key_exists('advanceOn', $in['payment'])) $set['pay_advance_on'] = Js::truthy($in['payment']['advanceOn']) ? '1' : '0';
            if (array_key_exists('advanceType', $in['payment'])) {
                if (!in_array($in['payment']['advanceType'], ['pct', 'amount'], true)) throw new BookingError('Choose a % of the total or a fixed amount for the advance.');
                $set['pay_advance_type'] = $in['payment']['advanceType'];
            }
            if (array_key_exists('advancePct', $in['payment'])) {
                $v = self::int($in['payment']['advancePct'], 0, 100);
                if ($v === null) throw new BookingError('The advance must be a percentage from 0 to 100.');
                $set['pay_advance_pct'] = $v;
            }
            if (array_key_exists('advanceAmount', $in['payment'])) {
                $v = self::int($in['payment']['advanceAmount'], 0, 100000000);
                if ($v === null) throw new BookingError('The advance must be a whole amount of 0 or more.');
                $set['pay_advance_amount'] = $v;
            }
        }
        Settings::set($set);
        return self::setup();
    }

    // ---------- occupancy: rooms and guests per night ----------
    /**
     * For every night in [from, to): the rooms that are taken (room booking, blocked dates
     * or a per-person group: room id => booking id), the guests counted against the hotel's
     * capacity, and people actually staying. Day visits use no rooms or capacity.
     */
    public static function occupancy(string $from, string $to, int $excludeId = 0): array
    {
        $nights = [];
        for ($d = $from; strcmp($d, $to) < 0; $d = self::addDays($d, 1)) $nights[$d] = ['rooms' => [], 'blocked' => [], 'guests' => 0, 'people' => 0];
        if (!$nights) return [];
        $rows = Db::all('SELECT b.id, b.room_id, b.package, b.adults, b.children, b.source, b.check_in, b.check_out, r.capacity AS room_capacity
            FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id
            WHERE b.id != ? AND b.status IN (' . self::holdingSql() . ") AND b.package != 'day_out' AND b.check_in < ? AND b.check_out > ?",
            array_merge([$excludeId], self::HOLDING, [$to, $from]));
        if (!$rows) return $nights;
        $ids = array_map(fn ($r) => (int) $r['id'], $rows);
        $group = [];
        foreach (Db::all('SELECT booking_id, room_id FROM booking_rooms WHERE booking_id IN (' . self::marks($ids) . ')', $ids) as $a) {
            $group[(int) $a['booking_id']][] = (int) $a['room_id'];
        }
        $caps = array_map('intval', array_column(self::listRooms(true), 'capacity', 'id'));
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $isGroup = $r['package'] === 'per_person';
            $isParty = $r['package'] === 'event';
            // booking_rooms: a group's rooms, or every room of a booking that took several; else its one room
            $rooms = $group[$id] ?? ($r['room_id'] !== null ? [(int) $r['room_id']] : []);
            $roomCap = 0;
            foreach ($rooms as $x) $roomCap += $caps[$x] ?? 0;
            $block = $r['source'] === 'block';
            $known = (int) $r['adults'] + (int) $r['children'];
            // rooms count their full capacity; a per-person group counts its guests; a party only the rooms it holds
            $guests = $isParty ? 0 : ($isGroup ? $known : $roomCap);
            $people = $block || $isParty ? 0 : ($isGroup || $known > 0 ? $known : $roomCap);
            for ($d = strcmp($r['check_in'], $from) > 0 ? $r['check_in'] : $from; strcmp($d, $r['check_out']) < 0 && strcmp($d, $to) < 0; $d = self::addDays($d, 1)) {
                foreach ($rooms as $room) {
                    $nights[$d]['rooms'][$room] = $id;
                    if ($block) $nights[$d]['blocked'][$room] = true;
                }
                $nights[$d]['guests'] += $guests;
                $nights[$d]['people'] += $people;
            }
        }
        return $nights;
    }

    /** Rooms taken on any night of $occ: room id => booking id. */
    private static function busyRooms(array $occ): array
    {
        $busy = [];
        foreach ($occ as $n) $busy += $n['rooms'];
        return $busy;
    }

    /** [room id => booking ref] for rooms taken ([room id => booking id]), so staff see who has each room. */
    public static function refsByRoom(array $busy): array
    {
        if (!$busy) return [];
        $ids = array_values(array_unique(array_map('intval', $busy)));
        $refs = array_column(Db::all('SELECT id, ref FROM bookings WHERE id IN (' . self::marks($ids) . ')', $ids), 'ref', 'id');
        $out = [];
        foreach ($busy as $roomId => $bookingId) $out[(int) $roomId] = (string) ($refs[$bookingId] ?? '');
        return $out;
    }

    /** The rooms other bookings hold on any night of a stay: [room id => booking ref] (Admin → New booking's room picker). */
    public static function takenRooms(string $checkIn, string $checkOut, int $exclude = 0): array
    {
        return self::refsByRoom(self::busyRooms(self::occupancy($checkIn, $checkOut, $exclude)));
    }

    /** Guests that can still be added on every night of $occ (null = no hotel limit). */
    public static function capacityLeft(array $occ): ?int
    {
        $cap = self::hotelCapacity();
        if (!$cap) return null;
        $left = $cap;
        foreach ($occ as $n) $left = min($left, $cap - $n['guests']);
        return $left;
    }

    /**
     * Rooms for a per-person group, taken in priority order until they sleep everyone (null = not
     * enough). The priority list alone decides which rooms groups get: a room that isn't bookable
     * on the website (Admin → Rooms) can still be given to groups.
     */
    public static function allocate(int $guests, array $occ): ?array
    {
        $busy = self::busyRooms($occ);
        $rooms = array_column(self::listRooms(true), null, 'id');
        $picked = [];
        $beds = 0;
        foreach (self::priorityRoomIds() as $id) {
            if (!isset($rooms[$id]) || isset($busy[$id])) continue;
            $picked[] = $id;
            $beds += $rooms[$id]['capacity'];
            if ($beds >= $guests) return $picked;
        }
        return null;
    }

    /** Nights in [from, to) that can't be booked for this choice (the website calendar); for a day visit, the closed days. */
    public static function unavailableNights(string $kind, ?array $room, int $guests, string $from, string $to): array
    {
        $closed = array_map('strval', array_keys(Closures::days($from, $to, $kind === 'day_out'))); // closed days, and parties that close them
        if ($kind === 'day_out') return $closed;
        $occ = self::occupancy($from, $to);
        $cap = self::hotelCapacity();
        $rooms = self::listRooms(); // rooms guests can choose themselves
        $byId = array_column(self::listRooms(true), null, 'id'); // groups can be given any room in the list
        $prio = self::priorityRoomIds();
        $out = [];
        foreach ($occ as $night => $n) {
            $left = $cap ? $cap - $n['guests'] : PHP_INT_MAX;
            if ($kind === 'room' && $room) {
                $ok = !isset($n['rooms'][$room['id']]) && $left >= $room['capacity'];
            } elseif ($kind === 'room') {
                $ok = false;
                foreach ($rooms as $r) if (!isset($n['rooms'][$r['id']]) && $left >= $r['capacity']) { $ok = true; break; }
            } else {
                $beds = 0;
                foreach ($prio as $id) if (isset($byId[$id]) && !isset($n['rooms'][$id])) $beds += $byId[$id]['capacity'];
                $ok = $left >= $guests && $beds >= $guests;
            }
            if (!$ok) $out[] = (string) $night;
        }
        $out = array_values(array_unique(array_merge($out, $closed)));
        sort($out, SORT_STRING);
        return $out;
    }

    /** Per-room taken nights in [from, to) for the website's all-rooms grid: rooms guests can choose themselves. */
    public static function roomGrid(string $from, string $to): array
    {
        $closed = array_flip(array_map('strval', array_keys(Closures::days($from, $to, false))));
        $occ = self::occupancy($from, $to);
        $cap = self::hotelCapacity();
        $out = [];
        foreach (self::listRooms() as $r) {
            $taken = [];
            foreach ($occ as $night => $n) {
                $left = $cap ? $cap - $n['guests'] : PHP_INT_MAX;
                if (isset($n['rooms'][$r['id']]) || $left < $r['capacity'] || isset($closed[(string) $night])) $taken[] = (string) $night;
            }
            $out[] = ['slug' => $r['slug'], 'name' => $r['name'], 'type' => $r['type'], 'capacity' => $r['capacity'], 'price' => $r['price'], 'unavailable' => $taken];
        }
        return $out;
    }

    /** Rooms for the "Select room" category, with what each costs for the stay. */
    public static function searchRooms(mixed $checkIn, mixed $checkOut): array
    {
        $nights = self::validateStay($checkIn, $checkOut);
        Closures::assertOpen($checkIn, $checkOut);
        $occ = self::occupancy($checkIn, $checkOut);
        $left = self::capacityLeft($occ);
        $busy = self::busyRooms($occ);
        return array_map(function ($r) use ($nights, $left, $busy) {
            $free = !isset($busy[$r['id']]);
            $fits = $left === null || $left >= $r['capacity'];
            return $r + ['available' => $free && $fits, 'free' => $free, 'fits' => $fits, 'nights' => $nights, 'total' => $r['price'] * $nights];
        }, self::listRooms());
    }

    /** Is there space for a per-person group of $guests? (dates already validated) */
    public static function checkGroup(string $checkIn, string $checkOut, int $guests, int $excludeId = 0): array
    {
        $occ = self::occupancy($checkIn, $checkOut, $excludeId);
        $left = self::capacityLeft($occ);
        $rooms = self::allocate($guests, $occ);
        return ['available' => ($left === null || $left >= $guests) && $rooms !== null, 'rooms' => $rooms ?? [], 'capacityLeft' => $left];
    }

    // ---------- the party area ----------
    /** Bookings holding the party area on any night in [checkIn, checkOut). */
    private static function partyAreaClash(string $checkIn, string $checkOut, int $excludeId = 0): array
    {
        return Db::all('SELECT id, ref, check_in, check_out FROM bookings
            WHERE party_area = 1 AND id != ? AND status IN (' . self::holdingSql() . ')
              AND check_in < ? AND check_out > ?', array_merge([$excludeId], self::HOLDING, [$checkOut, $checkIn]));
    }

    /** Nights (YYYY-MM-DD) in [from, to) on which the party area is taken. */
    public static function partyAreaNights(string $from, string $to, int $excludeId = 0): array
    {
        $set = [];
        foreach (self::partyAreaClash($from, $to, $excludeId) as $r) {
            // (a party on a date holds the party area that day and night)
            for ($d = strcmp($r['check_in'], $from) > 0 ? $r['check_in'] : $from; strcmp($d, $r['check_out']) < 0 && strcmp($d, $to) < 0; $d = self::addDays($d, 1)) {
                $set[$d] = true;
            }
        }
        $out = array_map('strval', array_keys($set));
        sort($out, SORT_STRING);
        return $out;
    }

    /** Can the party area be added? Also tells room bookers a party is on. (dates already validated) */
    public static function partyAreaStatus(string $checkIn, string $checkOut, int $excludeId = 0): array
    {
        $taken = self::partyAreaNights($checkIn, $checkOut, $excludeId);
        return ['free' => !$taken, 'taken' => $taken, 'price' => self::prices()['partyArea']];
    }

    // ---------- validation ----------
    public static function validateStay(mixed $checkIn, mixed $checkOut, bool $allowPast = false): int
    {
        if (!self::isDate($checkIn) || !self::isDate($checkOut)) throw new BookingError('Please choose valid check-in and check-out dates.');
        $n = self::nightsBetween($checkIn, $checkOut);
        if ($n < 1) throw new BookingError('Check-out must be after check-in.');
        if ($n > self::MAX_NIGHTS) throw new BookingError('Stays are limited to ' . self::MAX_NIGHTS . ' nights online — please contact us for longer stays.');
        if (!$allowPast) self::validateNotPast($checkIn, 'Check-in date is in the past.');
        return $n;
    }

    public static function validateDayOut(mixed $date, bool $allowPast = false): void
    {
        if (!self::isDate($date)) throw new BookingError('Please choose the date of your visit.');
        if (!$allowPast) self::validateNotPast($date, 'That date is in the past.');
    }

    private static function validateNotPast(string $date, string $message): void
    {
        $t = self::today();
        if (strcmp($date, $t) < 0) throw new BookingError($message);
        if (self::nightsBetween($t, $date) > self::MAX_ADVANCE_DAYS) throw new BookingError('That date is too far ahead to book online.');
    }

    /** Clean a text field; null means "invalid". */
    public static function str(mixed $v, int $max, bool $required = false): ?string
    {
        if ($v === null) $v = '';
        if (!is_string($v) && !is_int($v) && !is_float($v)) return null;
        $s = Js::str($v);
        $s = (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}]/u', '', $s);
        $s = Js::trim($s);
        if ($required && $s === '') return null;
        return Js::slice($s, $max);
    }

    /** Whole number in range; null means "invalid". */
    public static function int(mixed $v, int $min, int $max): ?int
    {
        $n = Js::number($v);
        if (is_nan($n) || is_infinite($n) || floor($n) != $n || $n < $min || $n > $max) return null;
        return (int) $n;
    }

    /**
     * A NIC or passport number, tidied ("199012345678", "901234567V", "N1234567"): letters,
     * digits, spaces, "-" and "/", at least 5 letters or digits. '' when empty; null when invalid.
     */
    public static function idNo(mixed $v): ?string
    {
        $s = self::str($v, 40);
        if ($s === null) return null;
        $s = strtoupper((string) preg_replace('/\s+/', ' ', $s));
        if ($s === '') return '';
        if (!preg_match('#^[A-Z0-9][A-Z0-9 \-/]{3,39}$#', $s) || preg_match_all('/[A-Z0-9]/', $s) < 5) return null;
        return $s;
    }

    public static function isEmail(string $s): bool
    {
        return (bool) preg_match('/^[^\s@<>()\[\],;:"]+@[^\s@<>()\[\],;:"]+\.[^\s@<>()\[\],;:"]{2,}$/u', $s);
    }

    // ---------- price ----------
    /**
     * Price a booking. A room is per night, at its price for the meal plan ($plan: RO, BB, HB, FB);
     * a whole room category ($whole) is one price a night for all its rooms. A per-person option
     * is per adult and per child per night (a day visit: per person). The party area and JBL
     * speaker are per night (per day for a day visit). $rates: rates agreed with the guest
     * ['room', 'adult', 'child'], null = the price list. Returns ['lines' => [['label', 'unit',
     * 'list', 'detail', 'amount', 'stay'], …], 'stay' (the room charge, which coupons apply to), 'total'].
     * $rooms: every room of a room booking that holds several (else $room is its one room).
     */
    public static function quote(string $kind, ?array $room, ?array $pkg, int $nights, int $adults, int $children, bool $partyArea, bool $jblSpeaker, array $rates = [], array $rooms = [], string $plan = 'RO', ?array $whole = null): array
    {
        $prices = self::prices();
        $count = fn (int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);
        $line = fn (string $label, int $unit, int $list, string $detail, int $amount, bool $stay) => ['label' => $label, 'unit' => $unit, 'list' => $list, 'detail' => $detail, 'amount' => $amount, 'stay' => $stay];
        $lines = [];
        if ($kind === 'event') return ['lines' => [], 'stay' => 0, 'total' => 0]; // an agreed price, typed in
        if ($kind === 'room') {
            $all = count($rooms) > 1 ? $rooms : ($room ? [$room] : []);
            $plan = isset(self::MEAL_PLANS[$plan]) ? $plan : 'RO';
            $planName = ' · ' . self::MEAL_PLANS[$plan][0];
            $list = self::roomsPrice($all, $plan, $whole);
            $nightsText = $count($nights, 'night', 'nights');
            $inWhole = fn (array $r) => $whole && ($r['categoryId'] ?? null) === $whole['id'];
            if ((count($all) > 1 || $whole) && ($rates['room'] ?? null) === null) {
                // the whole category at its one price, then any other rooms, each at its own
                if ($whole) {
                    $w = self::wholePrice($whole, $plan);
                    $lines[] = $line(self::wholeName($whole) . $planName, $w, $w, implode(', ', array_column(array_filter($all, $inWhole), 'name')) . ' · ' . $nightsText, $w * $nights, true);
                }
                foreach ($all as $r) {
                    if ($inWhole($r)) continue;
                    $pr = self::planPrice($r, $plan);
                    $lines[] = $line($r['name'] . $planName, $pr, $pr, $nightsText, $pr * $nights, true);
                }
            } else {
                // one room, or an agreed rate a night for all the rooms together
                $rate = $rates['room'] ?? $list;
                $lines[] = $line(($all ? implode(', ', array_column($all, 'name')) : 'Room') . $planName, $rate, $list, $nightsText, $rate * $nights, true);
            }
            [$units, $per] = [$nights, $nightsText];
        } else {
            $day = $kind === 'day_out';
            $mult = $day ? 1 : $nights;
            $tail = $day ? '' : ' × ' . $count($nights, 'night', 'nights');
            $name = $pkg ? $pkg['name'] : self::KINDS[$kind];
            $listA = (int) ($pkg['priceAdult'] ?? 0);
            $listC = (int) ($pkg['priceChild'] ?? 0);
            $pa = $rates['adult'] ?? $listA;
            $pc = $rates['child'] ?? $listC;
            if ($adults) $lines[] = $line($name, $pa, $listA, $count($adults, 'adult', 'adults') . $tail, $pa * $adults * $mult, true);
            if ($children) $lines[] = $line("$name — children", $pc, $listC, $count($children, 'child', 'children') . $tail, $pc * $children * $mult, true);
            [$units, $per] = $day ? [1, '1 day'] : [$nights, $count($nights, 'night', 'nights')];
        }
        if ($partyArea && $kind !== 'day_out') $lines[] = $line('Party area', $prices['partyArea'], $prices['partyArea'], $per, $prices['partyArea'] * $units, false);
        if ($jblSpeaker) $lines[] = $line('JBL speaker', $prices['jblSpeaker'], $prices['jblSpeaker'], $per, $prices['jblSpeaker'] * $units, false);
        $stay = array_sum(array_map(fn ($l) => $l['stay'] ? $l['amount'] : 0, $lines));
        return ['lines' => $lines, 'stay' => $stay, 'total' => array_sum(array_column($lines, 'amount'))];
    }

    /**
     * Rates agreed with the guest, from admin input (rateRoom, rateAdult, rateChild): an empty
     * value, or the price-list rate, means the price list. Returns only the rates that apply.
     */
    private static function ratesInput(array $in, string $kind, ?array $room, ?array $pkg, array $cur, array $rooms = [], string $plan = 'RO', ?array $whole = null): array
    {
        $out = ['room' => null, 'adult' => null, 'child' => null];
        $keys = $kind === 'room' ? ['room' => 'rateRoom'] : ['adult' => 'rateAdult', 'child' => 'rateChild'];
        // several rooms (or a whole category): the room rate is a night for all of them together
        $roomList = count($rooms) > 1 || $whole ? self::roomsPrice($rooms ?: ($room ? [$room] : []), $plan, $whole) : ($room ? self::planPrice($room, $plan) : null);
        $list = ['room' => $roomList, 'adult' => $pkg['priceAdult'] ?? null, 'child' => $pkg['priceChild'] ?? null];
        foreach ($keys as $k => $field) {
            if (!array_key_exists($field, $in)) { $out[$k] = $cur[$k] ?? null; continue; }
            if ($in[$field] === null || $in[$field] === '') continue;
            $v = self::int($in[$field], 0, 100000000);
            if ($v === null) throw new BookingError('Rates must be whole numbers of 0 or more.');
            $out[$k] = $list[$k] !== null && $v === (int) $list[$k] ? null : $v;
        }
        return $out;
    }

    /** Commission from admin input (commissionType: '' | percent | amount, commissionValue, commissionTo). */
    private static function commissionInput(array $in, array $cur): array
    {
        $type = array_key_exists('commissionType', $in) ? (in_array($in['commissionType'], ['percent', 'amount'], true) ? $in['commissionType'] : '') : ($cur['type'] ?? '');
        $value = $cur['value'] ?? 0;
        if (array_key_exists('commissionValue', $in)) {
            $v = $in['commissionValue'] === '' || $in['commissionValue'] === null ? 0 : self::int($in['commissionValue'], 0, $type === 'percent' ? 100 : 100000000);
            if ($v === null) throw new BookingError($type === 'percent' ? 'The commission must be a percentage from 0 to 100.' : 'The commission must be a whole amount of 0 or more.');
            $value = $v;
        }
        $to = array_key_exists('commissionTo', $in) ? (self::str($in['commissionTo'], 120) ?? '') : ($cur['to'] ?? '');
        if ($type === '') $value = 0;
        if ($type === 'percent' && $value > 100) throw new BookingError('The commission must be a percentage from 0 to 100.');
        return ['type' => $type, 'value' => $value, 'to' => $to];
    }

    // ---------- reservations: held for a few days for their advance ----------
    public static function holdDays(): int
    {
        return max(0, min(365, Settings::int('hold_days')));
    }

    /** How long a new reservation waits for its advance: Booking setup's days, but not past the arrival. */
    public static function defaultHold(string $checkIn): ?string
    {
        $days = self::holdDays();
        if (!$days) return null;
        $t = self::today();
        $d = self::addDays($t, $days);
        return strcmp($checkIn, $t) >= 0 && strcmp($d, $checkIn) > 0 ? $checkIn : $d;
    }

    /** A hold date from input: '' / null = never released automatically. */
    private static function holdInput(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        if (!self::isDate($v)) throw new BookingError('Please check the date the reservation is held until.');
        if (strcmp($v, self::today()) < 0) throw new BookingError('The hold date can\'t be in the past.');
        return $v;
    }

    /**
     * SQL condition (on bookings b): the booking asks for an advance — its own amount, or
     * Booking setup's rule when it has none. Reservations without one are never released or
     * reminded to pay.
     */
    public static function advanceRequiredSql(): string
    {
        return self::advanceRule()['on'] ? '(b.advance_amount IS NULL OR b.advance_amount > 0)' : 'b.advance_amount > 0';
    }

    /**
     * Release reservations whose hold date has passed without an advance: they are cancelled,
     * so their dates are free again, with a note saying why. Runs on every API call (cheap).
     * Reservations that ask for no advance are never released. Returns the bookings released.
     */
    public static function releaseExpired(): array
    {
        $t = self::today();
        $rows = Db::all("SELECT id, ref, hold_until, admin_notes FROM bookings b WHERE b.status = 'pending' AND b.hold_until IS NOT NULL AND b.hold_until < ?
            AND " . self::advanceRequiredSql() . "
            AND NOT EXISTS (SELECT 1 FROM pos_payments p WHERE p.booking_id = b.id AND p.voided_at IS NULL)", [$t]);
        $out = [];
        foreach ($rows as $r) {
            $when = (new \DateTimeImmutable((string) $r['hold_until'] . ' 00:00:00', new \DateTimeZone('UTC')))->format('j M Y');
            $note = trim(((string) $r['admin_notes']) . "\nReleased automatically: no advance received by $when.");
            $now = Db::toDbTime(Js::isoNow());
            Db::run("UPDATE bookings SET status = 'cancelled', admin_notes = ?, updated_at = ? WHERE id = ? AND status = 'pending'", [$note, $now, (int) $r['id']]);
            Db::run('INSERT INTO pos_log (booking_id, action, detail, amount, created_at, created_by) VALUES (?, ?, ?, NULL, ?, ?)',
                [(int) $r['id'], 'expired', "Reservation released: no advance by $when", $now, 'system']);
            $out[] = $r['ref'];
        }
        if ($out) error_log('[bookings] released ' . count($out) . ' reservation(s) with no advance: ' . implode(', ', $out));
        return $out;
    }

    // ---------- create / update ----------
    private static function newRef(): string
    {
        // e.g. TMR-7K3M9Q (Top Mountain Resort): unambiguous characters only
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for (;;) {
            $s = '';
            foreach (str_split(random_bytes(6)) as $b) $s .= $alphabet[ord($b) % strlen($alphabet)];
            $ref = "TMR-$s";
            if (!Db::one('SELECT 1 FROM bookings WHERE ref = ?', [$ref])) return $ref;
        }
    }

    private static function nicePartyNights(array $clash, string $checkIn, string $checkOut): string
    {
        $nights = [];
        foreach ($clash as $c) {
            for ($d = max($c['check_in'], $checkIn); strcmp($d, $c['check_out']) < 0 && strcmp($d, $checkOut) < 0; $d = self::addDays($d, 1)) $nights[$d] = true;
        }
        ksort($nights);
        return implode(', ', array_map(fn ($d) => (new \DateTimeImmutable($d))->format('D j M'), array_keys($nights)));
    }

    private static function partyAreaError(array $clash, string $checkIn, string $checkOut, bool $admin): BookingError
    {
        $when = self::nicePartyNights($clash, $checkIn, $checkOut);
        return new BookingError(
            $admin
                ? "The party area is already booked on $when (" . implode(', ', array_column($clash, 'ref')) . ').'
                : "Sorry — the party area has just been reserved by another group on $when. You can still book without it, or choose other dates.",
            409, 'party_area_unavailable');
    }

    private static function refsOf(array $bookingIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $bookingIds)));
        if (!$ids) return '';
        return implode(', ', array_column(Db::all('SELECT ref FROM bookings WHERE id IN (' . self::marks($ids) . ')', $ids), 'ref'));
    }

    /** What is being booked: [kind, option]. A room needs no option; blocked dates are always a room. */
    private static function resolveKind(array $in, bool $admin, bool $isBlock): array
    {
        $kind = $in['kind'] ?? 'room';
        if ($isBlock || $kind === 'room') return ['room', null];
        if ($kind === 'event') {
            if (!$admin) throw new BookingError('Please choose how you would like to book.');
            return ['event', null];
        }
        if (!in_array($kind, ['per_person', 'day_out'], true)) throw new BookingError('Please choose how you would like to book.');
        $pkg = self::getPackage($in['packageId'] ?? null);
        if (!$pkg || (!$pkg['active'] && !$admin)) throw new BookingError('Please choose an option.');
        return [$pkg['kind'] === 'day' ? 'day_out' : 'per_person', $pkg];
    }

    /** [adults, children]: rooms need no guest numbers (admins may note them); per person needs them. */
    private static function guestCounts(array $in, string $kind, array $rooms, bool $isBlock): array
    {
        if ($isBlock) return [0, 0];
        if ($kind === 'event') {
            $a = self::int($in['adults'] ?? 1, 1, self::MAX_EVENT_GUESTS);
            $c = self::int($in['children'] ?? 0, 0, self::MAX_EVENT_GUESTS);
            if ($a === null || $c === null) throw new BookingError('Please enter how many guests are coming to the party (at least one adult).');
            return [$a, $c];
        }
        if ($kind === 'room') {
            $a = self::int($in['adults'] ?? 0, 0, self::MAX_GROUP_ADULTS);
            $c = self::int($in['children'] ?? 0, 0, self::MAX_GROUP_CHILDREN);
            if ($a === null || $c === null) throw new BookingError('Please check the number of guests.');
            $cap = self::roomsCapacity($rooms);
            if ($rooms && $a + $c > $cap) {
                throw new BookingError((count($rooms) === 1 ? "{$rooms[0]['name']} sleeps" : 'These rooms sleep') . " up to $cap guest" . ($cap === 1 ? '' : 's') . '.');
            }
            return [$a, $c];
        }
        $a = self::int($in['adults'] ?? 2, 1, self::MAX_GROUP_ADULTS);
        $c = self::int($in['children'] ?? 0, 0, self::MAX_GROUP_CHILDREN);
        if ($a === null || $c === null) {
            throw new BookingError('Groups can be booked online for up to ' . self::MAX_GROUP_ADULTS . ' adults and ' . self::MAX_GROUP_CHILDREN . ' children — please contact us for bigger groups.');
        }
        return [$a, $c];
    }

    /** A party's [slot, others] from input, with defaults. */
    private static function eventInput(array $in, array $def): array
    {
        $slot = array_key_exists('eventSlot', $in) ? (string) $in['eventSlot'] : $def['slot'];
        $others = array_key_exists('eventOthers', $in) ? (string) $in['eventOthers'] : $def['others'];
        if (!isset(self::EVENT_SLOTS[$slot])) throw new BookingError('Choose a day party or a night party.');
        if (!isset(self::EVENT_OTHERS[$others])) throw new BookingError('Choose what other guests can book during the party.');
        return [$slot, $others];
    }

    /**
     * The rooms of a room booking from input: `rooms` (a list of room ids or slugs: one booking can
     * hold several rooms) or `roomId` / `room` (one room). Each room once, in the order given; the
     * first is the booking's main room (bookings.room_id). Guests can only choose rooms that are
     * bookable on the website.
     */
    private static function roomsInput(array $in, bool $admin): array
    {
        $raw = $in['rooms'] ?? null;
        $list = is_array($raw) && $raw !== [] ? $raw : [$in['roomId'] ?? ($in['room'] ?? null)];
        if (!Js::isList($list) || count($list) > self::MAX_ROOMS) throw new BookingError('A booking can have up to ' . self::MAX_ROOMS . ' rooms — please contact us for more.');
        $rooms = [];
        foreach ($list as $x) {
            $r = is_int($x) || is_string($x) ? self::getRoom($x) : null;
            if (!$r || (!$r['active'] && !$admin)) throw new BookingError(count($list) > 1 ? 'One of those rooms is no longer available — please choose again.' : 'Please choose a room.');
            $rooms[$r['id']] = $r;
        }
        return array_values($rooms);
    }

    /** How many guests these rooms sleep, and what they cost a night (price list) with this meal plan. */
    private static function roomsCapacity(array $rooms): int { return array_sum(array_column($rooms, 'capacity')); }
    public static function roomsPrice(array $rooms, string $plan = 'RO', ?array $whole = null): int
    {
        $sum = $whole ? self::wholePrice($whole, $plan) : 0; // a whole category: one price for its rooms
        foreach ($rooms as $r) if (!$whole || ($r['categoryId'] ?? null) !== $whole['id']) $sum += self::planPrice($r, $plan);
        return $sum;
    }

    /** A whole category's price a night with this meal plan (0 = not offered), and its name ("Wooden Cabana — Whole Villa"). */
    private static function wholePrice(array $cat, string $plan): int { return (int) ($cat['whole']['prices'][$plan] ?? 0); }
    private static function wholeName(array $cat): string { return $cat['name'] . ' — ' . ($cat['whole']['label'] ?? 'whole'); }

    /**
     * A room booking taking a whole category (wholeCategory: the category's id): [category, rooms].
     * Every room of the category joins the booking, first, then the other rooms given. Empty =
     * [null, $rooms]: each room is priced on its own.
     */
    private static function wholeInput(mixed $v, array $rooms): array
    {
        if ($v === null || $v === '' || $v === false || $v === 0 || $v === '0') return [null, $rooms];
        $cat = self::getCategory($v);
        if (!$cat || !$cat['whole']) throw new BookingError('That whole-villa option is no longer offered — please choose rooms instead.');
        $catRooms = self::categoryRooms($cat['id']);
        if (!$catRooms) throw new BookingError(self::wholeName($cat) . ' has no rooms that can be booked.');
        $all = [];
        foreach (array_merge($catRooms, $rooms) as $r) $all[$r['id']] ??= $r;
        if (count($all) > self::MAX_ROOMS) throw new BookingError('A booking can have up to ' . self::MAX_ROOMS . ' rooms — please contact us for more.');
        return [$cat, array_values($all)];
    }

    /** Throw if a room (or the whole category) of a room booking has no price for this meal plan. */
    private static function requirePlan(string $plan, array $rooms, ?array $whole): void
    {
        if ($plan === 'RO') return; // the room price itself
        $name = self::MEAL_PLANS[$plan][0];
        if ($whole && self::wholePrice($whole, $plan) <= 0) throw new BookingError(self::wholeName($whole) . " has no $name rate — please choose another meal plan.");
        foreach ($rooms as $r) {
            if ($whole && $r['categoryId'] === $whole['id']) continue;
            if (self::planPrice($r, $plan) <= 0) throw new BookingError("{$r['name']} has no $name rate — please choose another meal plan.");
        }
    }

    /** A list of room ids from input (for assigning rooms to a group). */
    private static function roomIdList(mixed $v): array
    {
        if (!is_array($v) || !Js::isList($v) || count($v) > 50) throw new BookingError('Invalid rooms.');
        $ids = [];
        foreach ($v as $x) {
            $id = self::int($x, 1, PHP_INT_MAX);
            if (!$id || !self::getRoom($id)) throw new BookingError('One of those rooms no longer exists.');
            if (!in_array($id, $ids, true)) $ids[] = $id;
        }
        if (!$ids) throw new BookingError('Choose at least one room.');
        return $ids;
    }

    /** Throw if any of these rooms is taken during $occ. */
    private static function requireRoomsFree(array $ids, array $occ): void
    {
        $busy = self::busyRooms($occ);
        foreach ($ids as $id) {
            if (isset($busy[$id])) {
                throw new BookingError(self::getRoom($id)['name'] . ' is already booked on those dates (' . self::refsOf([$busy[$id]]) . ').', 409, 'unavailable');
            }
        }
    }

    private static function saveGroupRooms(int $bookingId, array $roomIds): void
    {
        Db::run('DELETE FROM booking_rooms WHERE booking_id = ?', [$bookingId]);
        foreach ($roomIds as $r) Db::run('INSERT INTO booking_rooms (booking_id, room_id) VALUES (?, ?)', [$bookingId, $r]);
    }

    /**
     * Create a booking. $admin = true for bookings entered from the admin panel (past dates,
     * any source/status, a custom total, optional guest email, rooms of their choice for a
     * group, and no hotel-capacity limit).
     * $in: kind (room | per_person | day_out), rooms (a room booking's rooms: ids or slugs) or
     * roomId / room (one room), mealPlan (a room booking: RO | BB | HB | FB), wholeCategory (a
     * room booking taking every room of a category at its whole price), packageId, checkIn,
     * checkOut, adults, children, partyArea, jblSpeaker, rooms (admin, per person: the group's
     * room ids), guest details…
     */
    public static function createBooking(array $in, bool $admin = false, string $adminUser = ''): array
    {
        $source = $admin ? (self::isSource($in['source'] ?? null) ? $in['source'] : 'other') : 'website';
        $isBlock = $source === 'block';
        [$kind, $pkg] = self::resolveKind($in, $admin, $isBlock);

        $room = null;
        $rooms = []; // a room booking's rooms: the first is its main room (room_id)
        $plan = 'RO';
        $whole = null; // a whole room category, at its one price
        $checkIn = $in['checkIn'] ?? null;
        if ($kind === 'day_out' || $kind === 'event') {
            self::validateDayOut($checkIn, $admin);
            $checkOut = self::addDays($checkIn, 1);
            $nights = 0;
        } else {
            $checkOut = $in['checkOut'] ?? null;
            $nights = self::validateStay($checkIn, $checkOut, $admin);
            if ($kind === 'room') {
                $wantWhole = $isBlock ? null : ($in['wholeCategory'] ?? null);
                $noRooms = empty($in['rooms']) && empty($in['roomId']) && empty($in['room']);
                $rooms = $wantWhole && $noRooms ? [] : self::roomsInput($in, $admin); // a whole category needs no rooms named
                [$whole, $rooms] = self::wholeInput($wantWhole, $rooms);
                $room = $rooms[0];
                if (!$isBlock) {
                    $plan = self::planOf($in['mealPlan'] ?? null);
                    self::requirePlan($plan, $rooms, $whole);
                }
            }
        }
        [$adults, $children] = self::guestCounts($in, $kind, $rooms, $isBlock);
        // the website can't book days the whole resort is closed (staff can — e.g. the private booking itself)
        if (!$admin) Closures::assertOpen($checkIn, $checkOut, $kind);

        // the party area is for overnight stays; neither extra applies to blocked dates
        $partyArea = $kind === 'event' || ($kind !== 'day_out' && !$isBlock && Js::truthy($in['partyArea'] ?? null)); // a party is in the party area
        $jblSpeaker = !$isBlock && Js::truthy($in['jblSpeaker'] ?? null);
        $wantRooms = in_array($kind, ['per_person', 'event'], true) && $admin && !empty($in['rooms']) ? self::roomIdList($in['rooms']) : null;
        [$eventSlot, $eventOthers] = $kind === 'event' ? self::eventInput($in, ['slot' => 'night', 'others' => 'open']) : ['', ''];

        $guestName = self::str($in['guestName'] ?? null, 120, !$isBlock);
        if ($guestName === null) throw new BookingError('Please enter the guest name.');
        $guestEmail = self::str($in['guestEmail'] ?? null, 160) ?? '';
        $guestPhone = self::str($in['guestPhone'] ?? null, 40) ?? '';
        $guestIdNo = self::idNo($in['guestIdNo'] ?? null);
        if ($guestIdNo === null) throw new BookingError('Please check the NIC or passport number.');
        if (!$admin) {
            // email and NIC / passport are optional for guests (checked only when given)
            if ($guestEmail !== '' && !self::isEmail($guestEmail)) throw new BookingError('Please enter a valid email address, or leave it empty.');
            if (strlen((string) preg_replace('/\D/', '', $guestPhone)) < 7) throw new BookingError('Please enter a valid phone number.');
        } elseif ($guestEmail !== '' && !self::isEmail($guestEmail)) {
            throw new BookingError('That email address does not look right.');
        }

        $status = 'pending';
        if ($admin) $status = in_array($in['status'] ?? null, self::STATUSES, true) ? $in['status'] : 'confirmed';
        if ($isBlock) $status = 'confirmed';
        // the booking's own advance (staff; null = Booking setup's rule, 0 = none)
        $ownAdvance = $admin && !$isBlock && array_key_exists('advanceAmount', $in) ? self::advanceInput($in['advanceAmount']) : null;
        // a reservation waits a few days for its advance, then its dates are released (one that asks for no advance isn't held)
        $holdUntil = null;
        if ($status === 'pending' && !$isBlock && self::advanceOf(0, $ownAdvance)['required']) {
            $holdUntil = $admin && array_key_exists('holdUntil', $in) ? self::holdInput($in['holdUntil']) : self::defaultHold($checkIn);
        }

        // rates agreed with the guest (admins), commission, and a coupon off the room charge
        $rates = $admin && !$isBlock && $kind !== 'event' ? self::ratesInput($in, $kind, $room, $pkg, [], $rooms, $plan, $whole) : ['room' => null, 'adult' => null, 'child' => null];
        $commission = $admin && !$isBlock ? self::commissionInput($in, []) : ['type' => '', 'value' => 0, 'to' => ''];
        $coupon = $isBlock ? '' : Coupons::normalize($in['couponCode'] ?? '');
        $quote = self::quote($kind, $room, $pkg, $nights, $adults, $children, $partyArea, $jblSpeaker, $rates, $rooms, $plan, $whole);
        $total = $quote['total'];
        if ($admin && array_key_exists('totalAmount', $in) && $in['totalAmount'] !== '' && $in['totalAmount'] !== null) {
            $t = self::int($in['totalAmount'], 0, 100000000);
            if ($t === null) throw new BookingError('Total amount must be a whole number.');
            $total = $t;
        }
        if ($isBlock) $total = 0;
        $stayBase = min($quote['stay'], $total); // the room charge a coupon comes off

        $row = [
            'ref' => self::newRef(),
            'room_id' => $room ? $room['id'] : null,
            'package' => $kind,
            'package_id' => $pkg ? $pkg['id'] : null,
            'package_name' => $pkg ? $pkg['name'] : '',
            'meal_plan' => $kind === 'room' && !$isBlock ? $plan : '',
            'whole_category_id' => $whole ? $whole['id'] : null,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'nights' => $nights,
            'adults' => $adults,
            'children' => $children,
            'party_area' => $partyArea ? 1 : 0,
            'jbl_speaker' => $jblSpeaker ? 1 : 0,
            'guest_name' => $guestName !== '' ? $guestName : 'Blocked',
            'guest_email' => $guestEmail,
            'guest_phone' => $guestPhone,
            'guest_id_no' => $isBlock ? '' : $guestIdNo,
            'country' => self::str($in['country'] ?? null, 60) ?? '',
            'arrival_time' => self::str($in['arrivalTime'] ?? null, 40) ?? '',
            'special_requests' => self::str($in['specialRequests'] ?? null, 1500) ?? '',
            'total_amount' => $total,
            'rate_room' => $rates['room'],
            'rate_adult' => $rates['adult'],
            'rate_child' => $rates['child'],
            'coupon_code' => '',
            'coupon_discount' => 0,
            'commission_type' => $commission['type'],
            'commission_value' => $commission['value'],
            'commission_to' => $commission['to'],
            'hold_until' => $holdUntil,
            'advance_amount' => $ownAdvance,
            'event_slot' => $eventSlot,
            'event_others' => $eventOthers,
            'status' => $status,
            'source' => $source,
            'admin_notes' => $admin ? (self::str($in['adminNotes'] ?? null, 2000) ?? '') : '',
            'created_by' => $admin ? "admin:$adminUser" : 'guest',
        ];
        $now = Db::toDbTime(Js::isoNow());
        $row['created_at'] = $now;
        $row['updated_at'] = $now;

        // Check + insert atomically so two guests can never take the same rooms, party area or capacity.
        $id = Db::transaction(function () use ($row, $kind, $rooms, $checkIn, $checkOut, $status, $admin, $isBlock, $partyArea, $adults, $children, $wantRooms, $coupon, $stayBase) {
            Db::lockBookings();
            if ($coupon !== '' && $status !== 'cancelled') {
                $c = Coupons::check($coupon, $stayBase, true); // locked: the last use can only be taken once
                $row['coupon_code'] = $c['code'];
                $row['coupon_discount'] = $c['discount'];
                $row['total_amount'] -= $c['discount'];
            }
            $groupRooms = $wantRooms ?? [];
            if (in_array($status, self::HOLDING, true) && $kind !== 'day_out') {
                $occ = self::occupancy($checkIn, $checkOut);
                $left = $admin ? null : self::capacityLeft($occ);
                if ($kind === 'room') {
                    $busy = self::busyRooms($occ);
                    foreach ($rooms as $room) {
                        if (isset($busy[$room['id']])) {
                            throw new BookingError($admin
                                ? $room['name'] . ' is already booked on those dates (' . self::refsOf([$busy[$room['id']]]) . ').'
                                : 'Sorry — ' . $room['name'] . ' was just booked for some of those nights. Please pick other dates or another room.', 409, 'unavailable');
                        }
                    }
                    if (!$isBlock && $left !== null && $left < self::roomsCapacity($rooms)) {
                        throw new BookingError(count($rooms) > 1
                            ? "Sorry — we don't have space for all of those rooms on some of those nights. Try fewer rooms or other dates."
                            : 'Sorry — we are fully booked on some of those nights. Please try other dates.', 409, 'full');
                    }
                } elseif ($kind === 'event') {
                    if ($wantRooms) self::requireRoomsFree($wantRooms, $occ); // rooms held for party guests who stay
                } else {
                    $n = $adults + $children;
                    if ($wantRooms !== null) {
                        self::requireRoomsFree($wantRooms, $occ);
                    } else {
                        if ($left !== null && $left < $n) throw new BookingError("Sorry — we don't have space for $n guests on some of those nights. Try other dates or a smaller group, or contact us.", 409, 'full');
                        $groupRooms = self::allocate($n, $occ);
                        if ($groupRooms === null) {
                            throw new BookingError($admin
                                ? "There aren't enough free rooms in the room priority list for $n guests on those dates. Choose the rooms yourself."
                                : "Sorry — we don't have space for $n guests on some of those nights. Try other dates or a smaller group, or contact us.", 409, 'full');
                        }
                    }
                }
                $party = $partyArea ? self::partyAreaClash($checkIn, $checkOut) : [];
                if ($party) throw self::partyAreaError($party, $checkIn, $checkOut, $admin);
            }
            $cols = array_keys($row);
            $id = Db::run('INSERT INTO bookings (' . implode(',', $cols) . ') VALUES (' . self::marks($cols) . ')', array_values($row));
            if (in_array($kind, ['per_person', 'event'], true) && $groupRooms) self::saveGroupRooms($id, $groupRooms);
            if ($kind === 'room' && count($rooms) > 1) self::saveGroupRooms($id, array_column($rooms, 'id')); // every room of the booking
            return $id;
        });
        return self::getBooking($id);
    }

    public static function updateBooking(mixed $id, array $patch): array
    {
        $cur = self::getBooking($id);
        if (!$cur) throw new BookingError('Booking not found', 404, 'not_found');
        $isBlock = $cur['source'] === 'block' && ($patch['source'] ?? 'block') === 'block';
        $sets = [];

        // ---- what is booked ----
        $kind = $cur['kind'];
        $pkg = $cur['packageId'] ? self::getPackage($cur['packageId']) : null;
        if (array_key_exists('kind', $patch) || array_key_exists('packageId', $patch)) {
            $wanted = ['kind' => $patch['kind'] ?? $cur['kind'], 'packageId' => $patch['packageId'] ?? $cur['packageId']];
            if ($isBlock && $wanted['kind'] !== 'room') throw new BookingError('Blocked dates are always a room.');
            [$kind, $pkg] = self::resolveKind($wanted, true, $isBlock);
            if ($kind !== $cur['kind']) $sets['package'] = $kind;
            $pid = $pkg ? $pkg['id'] : null;
            if ($pid !== $cur['packageId']) { $sets['package_id'] = $pid; $sets['package_name'] = $pkg ? $pkg['name'] : ''; }
        }
        $oneDay = fn (string $k) => $k === 'day_out' || $k === 'event'; // a single date, no nights
        $dayChanged = $oneDay($kind) !== $oneDay($cur['kind']);

        // ---- room / rooms ----
        $whole = null; // a room booking that takes a whole category
        $roomId = $cur['roomId'];
        $groupRooms = in_array($cur['kind'], ['per_person', 'event'], true) ? array_column($cur['rooms'], 'id') : [];
        // a room booking's rooms (several are kept in booking_rooms, like a group's)
        $curRoomIds = $cur['kind'] === 'room' ? array_column($cur['rooms'], 'id') : [];
        $roomIds = $curRoomIds;
        $roomsChanged = false;
        $saveRooms = false;
        $needAlloc = false;
        $main = null; // a main room chosen in this change
        if ($kind === 'room') {
            if (array_key_exists('rooms', $patch) && is_array($patch['rooms']) && $patch['rooms'] !== []) {
                $roomIds = array_column(self::roomsInput(['rooms' => $patch['rooms']], true), 'id');
            } elseif (array_key_exists('roomId', $patch)) {
                $room = self::getRoom($patch['roomId']);
                if (!$room) throw new BookingError('Room not found');
                // a new main room takes the old main room's place; the booking's other rooms stay
                $at = array_search($cur['roomId'], $roomIds, true);
                if ($at === false) $roomIds = [$room['id']]; else $roomIds[$at] = $room['id'];
                $roomIds = array_values(array_unique($roomIds));
                $main = $room['id'];
            }
            // a whole category: choosing it adds all its rooms; it stays while the booking has them all
            $wantWhole = $isBlock ? null : (array_key_exists('wholeCategory', $patch) ? $patch['wholeCategory'] : $cur['wholeCategoryId']);
            if ($wantWhole && array_key_exists('wholeCategory', $patch)) {
                [$whole, $merged] = self::wholeInput($wantWhole, array_values(array_filter(array_map(fn ($i) => self::getRoom($i), $roomIds))));
                $roomIds = array_column($merged, 'id');
            } elseif ($wantWhole) {
                $cat = self::getCategory($wantWhole);
                $catIds = $cat && $cat['whole'] ? array_column(self::categoryRooms($cat['id']), 'id') : [];
                if ($catIds && !array_diff($catIds, $roomIds)) $whole = $cat; // else a room of it left: priced room by room
            }
            if (!$roomIds) throw new BookingError('Please choose a room for this booking.');
            // the room chosen as main room, else the main room stays while the booking still has it
            $roomId = $main ?? (in_array($cur['roomId'], $roomIds, true) ? $cur['roomId'] : $roomIds[0]);
            if ($roomId !== $cur['roomId']) $sets['room_id'] = $roomId;
            $sortIds = function (array $a) { sort($a); return $a; };
            $roomsChanged = $sortIds($roomIds) !== $sortIds($curRoomIds);
            if (count($roomIds) > 1) {
                if ($roomsChanged || $groupRooms) { $groupRooms = $roomIds; $saveRooms = true; }
            } elseif ($groupRooms || count($curRoomIds) > 1) {
                $groupRooms = []; $saveRooms = true; // back to one room: no booking_rooms
            }
        } else {
            if ($cur['roomId'] !== null) { $roomId = null; $sets['room_id'] = null; }
            if ($kind === 'day_out') {
                if ($groupRooms) { $groupRooms = []; $saveRooms = true; }
            } elseif (array_key_exists('rooms', $patch)) {
                $groupRooms = $kind === 'event' && ($patch['rooms'] === [] || $patch['rooms'] === '') ? [] : self::roomIdList($patch['rooms']);
                $saveRooms = true;
            } elseif ($kind === 'per_person' && $cur['kind'] !== 'per_person' && !$groupRooms) {
                $needAlloc = true; // becoming a per-person group: give it rooms
            }
            // a room booking with several rooms becoming something else: its rooms go with it
            if (count($curRoomIds) > 1 && !$saveRooms && !$needAlloc) { $groupRooms = []; $saveRooms = true; }
        }

        // ---- meal plan and whole category (room bookings) ----
        $plan = $cur['mealPlan'] ?: 'RO';
        if ($kind === 'room' && !$isBlock) {
            if (array_key_exists('mealPlan', $patch)) {
                $want = self::planOf($patch['mealPlan']);
                if ($want !== $plan) { $plan = $want; $sets['meal_plan'] = $want; }
            }
        } elseif ($cur['mealPlan'] !== '') {
            $plan = 'RO';
            $sets['meal_plan'] = '';
        }
        $wholeId = $whole ? $whole['id'] : null;
        if ($wholeId !== $cur['wholeCategoryId']) $sets['whole_category_id'] = $wholeId;

        // ---- dates ----
        $checkIn = $cur['checkIn'];
        $checkOut = $cur['checkOut'];
        if ($oneDay($kind)) {
            if ($dayChanged || array_key_exists('checkIn', $patch)) {
                $checkIn = $patch['checkIn'] ?? $cur['checkIn'];
                self::validateDayOut($checkIn, true);
                $checkOut = self::addDays($checkIn, 1);
                [$sets['check_in'], $sets['check_out'], $sets['nights']] = [$checkIn, $checkOut, 0];
            }
        } elseif ($dayChanged || array_key_exists('checkIn', $patch) || array_key_exists('checkOut', $patch)) {
            $checkIn = $patch['checkIn'] ?? $cur['checkIn'];
            $checkOut = $patch['checkOut'] ?? $cur['checkOut'];
            $sets['nights'] = self::validateStay($checkIn, $checkOut, true);
            [$sets['check_in'], $sets['check_out']] = [$checkIn, $checkOut];
        }

        // ---- extras ----
        $partyArea = $cur['partyArea'];
        if ($kind === 'event') {
            $partyArea = true; // a party is in the party area
            if (!$cur['partyArea']) $sets['party_area'] = 1;
        } elseif (array_key_exists('partyArea', $patch) || ($kind === 'day_out' && $cur['partyArea']) || ($cur['kind'] === 'event' && !array_key_exists('partyArea', $patch))) {
            $partyArea = $kind !== 'day_out' && !$isBlock && Js::truthy($patch['partyArea'] ?? null);
            if ($partyArea !== $cur['partyArea']) $sets['party_area'] = $partyArea ? 1 : 0;
        }
        // ---- a party: day or night, and what other guests can book then ----
        if ($kind === 'event') {
            [$slot, $others] = self::eventInput($patch, ['slot' => $cur['eventSlot'] ?: 'night', 'others' => $cur['eventOthers'] ?: 'open']);
            if ($slot !== $cur['eventSlot']) $sets['event_slot'] = $slot;
            if ($others !== $cur['eventOthers']) $sets['event_others'] = $others;
        } elseif ($cur['kind'] === 'event') {
            [$sets['event_slot'], $sets['event_others']] = ['', ''];
        }
        $jbl = $cur['jblSpeaker'];
        if (array_key_exists('jblSpeaker', $patch)) {
            $jbl = !$isBlock && Js::truthy($patch['jblSpeaker']);
            if ($jbl !== $cur['jblSpeaker']) $sets['jbl_speaker'] = $jbl ? 1 : 0;
        }

        // ---- status / source / text ----
        $status = $cur['status'];
        if (array_key_exists('status', $patch)) {
            if (!in_array($patch['status'], self::STATUSES, true)) throw new BookingError('Unknown status');
            if ($patch['status'] !== $cur['status']) {
                if ($cur['billId'] !== null) {
                    throw new BookingError('This stay is billed on ' . Pos::billNo($cur['billId']) . '. Void that bill (Bills) to reopen it.', 409, 'billed');
                }
                if ($patch['status'] === 'cancelled' && Pos::hasCharges($cur['id'])) {
                    throw new BookingError('This booking has items on its room bill. Move or remove them in the POS before cancelling.', 409, 'has_charges');
                }
                $status = $sets['status'] = $patch['status'];
            }
        }
        if (array_key_exists('source', $patch)) {
            if (!self::isSource($patch['source'])) throw new BookingError('Unknown source');
            if ($patch['source'] !== $cur['source']) $sets['source'] = $patch['source'];
        }
        $textFields = [
            'guestName' => ['guest_name', 120], 'guestEmail' => ['guest_email', 160], 'guestPhone' => ['guest_phone', 40],
            'country' => ['country', 60], 'arrivalTime' => ['arrival_time', 40], 'specialRequests' => ['special_requests', 1500],
            'adminNotes' => ['admin_notes', 2000],
        ];
        foreach ($textFields as $k => [$col, $max]) {
            if (!array_key_exists($k, $patch)) continue;
            $v = self::str($patch[$k], $max, $k === 'guestName');
            if ($v === null) throw new BookingError("Invalid $k");
            if ($k === 'guestEmail' && $v !== '' && !self::isEmail($v)) throw new BookingError('That email address does not look right.');
            $sets[$col] = $v;
        }
        if (array_key_exists('guestIdNo', $patch)) {
            $v = self::idNo($patch['guestIdNo']);
            if ($v === null) throw new BookingError('Please check the NIC or passport number.');
            if ($v !== $cur['guestIdNo']) $sets['guest_id_no'] = $v;
        }
        $adults = $cur['adults'];
        $children = $cur['children'];
        foreach (['adults' => $kind === 'event' ? self::MAX_EVENT_GUESTS : self::MAX_GROUP_ADULTS, 'children' => $kind === 'event' ? self::MAX_EVENT_GUESTS : self::MAX_GROUP_CHILDREN] as $k => $max) {
            if (!array_key_exists($k, $patch)) continue;
            $v = self::int($patch[$k], 0, $max);
            if ($v === null) throw new BookingError("Invalid $k");
            if ($k === 'adults') $adults = $v; else $children = $v;
            if ($v !== $cur[$k]) $sets[$k] = $v;
        }
        if ($kind !== 'room' && $adults < 1 && !$isBlock) throw new BookingError('A per-person booking needs at least one adult.');

        // ---- rates agreed with the guest (a different option, kind or set of rooms starts from the price list) ----
        $room = $roomId === null ? null : self::getRoom($roomId);
        $roomList = $kind === 'room' ? array_values(array_filter(array_map(fn ($i) => self::getRoom($i), $roomIds))) : [];
        $whatChanged = $kind !== $cur['kind'] || ($pkg['id'] ?? null) !== $cur['packageId'] || ($roomsChanged && (count($roomIds) > 1 || count($curRoomIds) > 1))
            || isset($sets['meal_plan']) || array_key_exists('whole_category_id', $sets);
        if ($kind === 'room' && !$isBlock && ($roomsChanged || isset($sets['meal_plan']) || array_key_exists('whole_category_id', $sets))) self::requirePlan($plan, $roomList, $whole);
        $rates = $isBlock || $kind === 'event' ? ['room' => null, 'adult' => null, 'child' => null] : self::ratesInput($patch, $kind, $room, $pkg, $whatChanged ? [] : $cur['rates'], $roomList, $plan, $whole);
        foreach (['room' => 'rate_room', 'adult' => 'rate_adult', 'child' => 'rate_child'] as $k => $col) {
            if ($rates[$k] !== $cur['rates'][$k]) $sets[$col] = $rates[$k];
        }

        // ---- commission ----
        if (array_intersect(['commissionType', 'commissionValue', 'commissionTo'], array_keys($patch))) {
            $cm = self::commissionInput($patch, $cur['commission']);
            foreach (['type' => 'commission_type', 'value' => 'commission_value', 'to' => 'commission_to'] as $k => $col) {
                if ($cm[$k] !== $cur['commission'][$k]) $sets[$col] = $cm[$k];
            }
        }

        // ---- the booking's own advance (null = Booking setup's rule, 0 = none, more = that amount) ----
        $own = $cur['advance']['own'];
        if (!$isBlock && array_key_exists('advanceAmount', $patch)) {
            $own = self::advanceInput($patch['advanceAmount']);
            if ($own !== $cur['advance']['own']) $sets['advance_amount'] = $own;
        }
        $needsAdvance = self::advanceOf(0, $own)['required'];

        // ---- how long a reservation waits for its advance (one that asks for none isn't held) ----
        if ($status === 'pending' && !$isBlock) {
            if (!$needsAdvance) {
                if ($cur['holdUntil'] !== null) $sets['hold_until'] = null;
            } elseif (array_key_exists('holdUntil', $patch)) {
                $h = ($patch['holdUntil'] ?? '') === $cur['holdUntil'] ? $cur['holdUntil'] : self::holdInput($patch['holdUntil']);
                if ($h !== $cur['holdUntil']) $sets['hold_until'] = $h;
            } elseif ($cur['status'] !== 'pending' || !$cur['advance']['required']) {
                $sets['hold_until'] = self::defaultHold($checkIn); // back to a reservation, or it now asks for an advance: it waits again
            }
        }

        // ---- price ----
        if (array_key_exists('totalAmount', $patch) && $patch['totalAmount'] !== '') {
            $v = self::int($patch['totalAmount'], 0, 100000000);
            if ($v === null) throw new BookingError('Total amount must be a whole number.');
            $sets['total_amount'] = $v;
        } elseif (($roomsChanged || array_intersect(array_merge(['package', 'package_id', 'room_id', 'nights', 'party_area', 'jbl_speaker', 'rate_room', 'rate_adult', 'rate_child', 'meal_plan', 'whole_category_id'], $kind === 'room' ? [] : ['adults', 'children']), array_keys($sets))) && !$isBlock && $kind !== 'event') {
            // (a party keeps its agreed price)
            // what is being priced changed and no explicit total: re-price (a room's price doesn't depend on its guests)
            $q = self::quote($kind, $room, $pkg, $sets['nights'] ?? $cur['nights'], $adults, $children, $partyArea, $jbl, $rates, $roomList, $plan, $whole);
            $total = $q['total'];
            if ($cur['couponCode'] !== '') {
                // the coupon used when booking comes off the new room charge
                $c = Coupons::find($cur['couponCode']);
                $disc = min($total, $c ? Coupons::discountOn($c['type'], (int) $c['value'], $q['stay']) : $cur['couponDiscount']);
                if ($disc !== $cur['couponDiscount']) $sets['coupon_discount'] = $disc;
                $total -= $disc;
            }
            $sets['total_amount'] = $total;
        }

        if (!$sets && !$saveRooms) return $cur;
        $sets['updated_at'] = Db::toDbTime(Js::isoNow());

        Db::transaction(function () use ($cur, $sets, $kind, $roomIds, $groupRooms, $saveRooms, $needAlloc, $status, $checkIn, $checkOut, $partyArea, $adults, $children) {
            Db::lockBookings();
            if (in_array($status, self::HOLDING, true) && $kind !== 'day_out') {
                $occ = self::occupancy($checkIn, $checkOut, $cur['id']);
                if ($kind === 'room') {
                    self::requireRoomsFree($roomIds, $occ);
                } elseif ($needAlloc) {
                    $groupRooms = self::allocate($adults + $children, $occ);
                    if ($groupRooms === null) throw new BookingError("There aren't enough free rooms for this group on those dates. Choose the rooms yourself.", 409, 'full');
                    $saveRooms = true;
                } elseif ($groupRooms) {
                    self::requireRoomsFree($groupRooms, $occ);
                }
                $party = $partyArea ? self::partyAreaClash($checkIn, $checkOut, $cur['id']) : [];
                if ($party) throw self::partyAreaError($party, $checkIn, $checkOut, true);
            }
            $cols = array_keys($sets);
            Db::run('UPDATE bookings SET ' . implode(', ', array_map(fn ($c) => "$c = ?", $cols)) . ' WHERE id = ?', array_merge(array_values($sets), [$cur['id']]));
            if ($saveRooms) self::saveGroupRooms($cur['id'], $groupRooms);
        });
        return self::getBooking($cur['id']);
    }

    /** Give a per-person group these rooms (Admin → booking, or the POS at check-in). */
    public static function assignRooms(mixed $id, mixed $roomIds): array
    {
        $b = self::getBooking($id);
        if (!$b) throw new BookingError('Booking not found', 404, 'not_found');
        if ($b['kind'] === 'room') return self::updateBooking($b['id'], ['rooms' => self::roomIdList($roomIds)]); // re-priced for its rooms
        if (!in_array($b['kind'], ['per_person', 'event'], true)) throw new BookingError('Only bookings with rooms and parties are given rooms this way.');
        $ids = self::roomIdList($roomIds);
        Db::transaction(function () use ($b, $ids) {
            Db::lockBookings();
            if (in_array($b['status'], self::HOLDING, true)) self::requireRoomsFree($ids, self::occupancy($b['checkIn'], $b['checkOut'], $b['id']));
            self::saveGroupRooms($b['id'], $ids);
            Db::run('UPDATE bookings SET updated_at = ? WHERE id = ?', [Db::toDbTime(Js::isoNow()), $b['id']]);
        });
        return self::getBooking($b['id']);
    }

    /** Every room, with whether it is free for this booking's dates and already given to it. */
    public static function roomChoices(mixed $id): array
    {
        $b = self::getBooking($id);
        if (!$b) throw new BookingError('Booking not found', 404, 'not_found');
        $busy = $b['kind'] === 'day_out' ? [] : self::busyRooms(self::occupancy($b['checkIn'], $b['checkOut'], $b['id']));
        $refs = [];
        if ($busy) foreach (Db::all('SELECT id, ref FROM bookings WHERE id IN (' . self::marks($busy) . ')', array_values($busy)) as $r) $refs[(int) $r['id']] = $r['ref'];
        $mine = array_column($b['rooms'], 'id');
        $prio = array_flip(self::priorityRoomIds());
        return array_map(fn ($r) => [
            'id' => $r['id'], 'name' => $r['name'], 'type' => $r['type'], 'capacity' => $r['capacity'], 'active' => $r['active'],
            'assigned' => in_array($r['id'], $mine, true), 'free' => !isset($busy[$r['id']]),
            'takenBy' => isset($busy[$r['id']]) ? ($refs[$busy[$r['id']]] ?? '') : '', 'priority' => isset($prio[$r['id']]) ? $prio[$r['id']] + 1 : null,
        ], self::listRooms(true));
    }

    /** Confirm a pending booking (once the advance is received), optionally changing a group's rooms. */
    public static function confirmBooking(mixed $id, array $in): array
    {
        $b = self::getBooking($id);
        if (!$b) throw new BookingError('Booking not found', 404, 'not_found');
        if ($b['status'] !== 'pending') throw new BookingError('Only pending bookings can be confirmed.', 409, 'status');
        if ($b['kind'] === 'per_person' && !empty($in['rooms'])) self::assignRooms($b['id'], $in['rooms']);
        return self::updateBooking($b['id'], ['status' => 'confirmed']);
    }

    // ---------- reads ----------
    private static function mapBooking(?array $b, array $groupRooms = []): ?array
    {
        if (!$b) return null;
        $kind = array_key_exists($b['package'], self::KINDS) ? $b['package'] : 'room';
        // a room booking's rooms: several (booking_rooms) or its one room; a group's or party's rooms
        $rooms = $kind === 'room' && count($groupRooms) > 1 ? $groupRooms : ($b['room_id'] !== null
            ? [['id' => (int) $b['room_id'], 'name' => $b['room_name'], 'capacity' => (int) $b['room_capacity'], 'price' => (int) $b['room_price'],
                'prices' => self::planPrices($b, 'room_'), 'categoryId' => self::intOrNull($b['room_category_id'] ?? null)]]
            : $groupRooms);
        // a room booking's meal plan (none for blocked dates), and a whole category taken at its one price
        $plan = $kind === 'room' && $b['source'] !== 'block' ? ((string) ($b['meal_plan'] ?? '') ?: 'RO') : '';
        if ($plan !== '' && !isset(self::MEAL_PLANS[$plan])) $plan = 'RO';
        $whole = $kind === 'room' && ($b['whole_category_id'] ?? null) !== null && ($b['whole_label'] ?? null) !== null ? self::mapCategory([
            'id' => $b['whole_category_id'], 'name' => $b['whole_cat_name'], 'sort' => 0, 'whole_code' => $b['whole_code'], 'whole_label' => $b['whole_label'] ?: 'Whole',
            'whole_pax' => $b['whole_pax'], 'whole_ro' => $b['whole_ro'], 'whole_bb' => $b['whole_bb'], 'whole_hb' => $b['whole_hb'], 'whole_fb' => $b['whole_fb'],
        ]) : null;
        $packageName = $b['package_name'] !== '' ? $b['package_name'] : self::KINDS[$kind];
        $label = $kind === 'room' ? 'Room' : $packageName;
        $slot = $kind === 'event' ? ((string) ($b['event_slot'] ?? '') ?: 'night') : '';
        $others = $kind === 'event' ? ((string) ($b['event_others'] ?? '') ?: 'open') : '';
        if ($kind === 'event') $label = self::EVENT_SLOTS[$slot] ?? 'Outdoor party';
        $roomNames = implode(', ', array_column($rooms, 'name'));
        // "Room 101, Room 102 · Half board" or "Wooden Cabana — Whole Villa (Room 103, …) · Full board"
        $roomPlace = ($whole ? self::wholeName($whole) . " ($roomNames)" : $roomNames) . ($plan !== '' ? ' · ' . self::MEAL_PLANS[$plan][0] : '');
        $commission = self::commissionOf((string) ($b['commission_type'] ?? ''), (int) ($b['commission_value'] ?? 0), (string) ($b['commission_to'] ?? ''), (int) $b['total_amount']);
        // what the agent already kept from payments they passed on (the "kept" on each payment), and what is left to pay them
        $commission['kept'] = $commission['type'] !== '' ? (int) ($b['fees_kept'] ?? 0) : 0;
        $commission['toPay'] = max(0, $commission['amount'] - $commission['kept']);
        return [
            'id' => (int) $b['id'],
            'ref' => $b['ref'],
            'kind' => $kind,
            'package' => $kind,
            'packageId' => $b['package_id'] === null ? null : (int) $b['package_id'],
            'packageName' => $kind === 'room' ? '' : $packageName,
            'packageLabel' => $label,
            // "Room 101", "Full board · Room 101, Room 102" or "Day out"
            'place' => $kind === 'room' ? ($roomNames !== '' ? $roomPlace : (string) $b['room_name']) : ($kind === 'day_out' ? $label : $label . ($roomNames !== '' ? " · $roomNames" : '')),
            'mealPlan' => $plan, // a room booking: RO | BB | HB | FB ('' otherwise)
            'mealPlanLabel' => $plan !== '' ? self::MEAL_PLANS[$plan][0] : '',
            'wholeCategoryId' => $whole ? $whole['id'] : self::intOrNull($b['whole_category_id'] ?? null),
            'wholeCategory' => $whole ? ['id' => $whole['id'], 'name' => $whole['name'], 'label' => $whole['whole']['label'], 'code' => $whole['whole']['code'], 'capacity' => $whole['whole']['capacity']] : null,
            'roomId' => $b['room_id'] === null ? null : (int) $b['room_id'],
            'roomName' => $roomNames !== '' ? $roomNames : null,
            'roomType' => $b['room_type'],
            'roomSlug' => $b['room_slug'],
            'rooms' => $rooms,
            'capacity' => array_sum(array_column($rooms, 'capacity')),
            'checkIn' => $b['check_in'],
            'checkOut' => $b['check_out'],
            'nights' => (int) $b['nights'],
            'adults' => (int) $b['adults'],
            'children' => (int) $b['children'],
            'partyArea' => (bool) (int) $b['party_area'],
            'jblSpeaker' => (bool) (int) $b['jbl_speaker'],
            'guestName' => $b['guest_name'],
            'guestEmail' => $b['guest_email'],
            'guestPhone' => $b['guest_phone'],
            'guestIdNo' => (string) ($b['guest_id_no'] ?? ''),
            'country' => $b['country'],
            'arrivalTime' => $b['arrival_time'],
            'specialRequests' => $b['special_requests'],
            'totalAmount' => (int) $b['total_amount'],
            // rates agreed with the guest (null = the price list), and the price list for comparison
            'rates' => ['room' => self::intOrNull($b['rate_room'] ?? null), 'adult' => self::intOrNull($b['rate_adult'] ?? null), 'child' => self::intOrNull($b['rate_child'] ?? null)],
            // several rooms: the room rate is a night for all of them together
            'listRates' => $kind === 'room'
                ? ['room' => count($rooms) > 1 || $whole ? self::roomsPrice($rooms, $plan ?: 'RO', $whole) : ($rooms ? self::planPrice($rooms[0], $plan ?: 'RO') : null), 'adult' => null, 'child' => null]
                : ['room' => null, 'adult' => self::intOrNull($b['pkg_price_adult'] ?? null), 'child' => self::intOrNull($b['pkg_price_child'] ?? null)],
            'couponCode' => (string) ($b['coupon_code'] ?? ''),
            'couponDiscount' => (int) ($b['coupon_discount'] ?? 0),
            'commission' => $commission,
            'netAmount' => (int) $b['total_amount'] - $commission['amount'], // what the resort keeps of the stay price
            'holdUntil' => ($b['hold_until'] ?? null) ?: null,
            // the advance it asks for before arrival (advanceOf): its own amount, or Booking setup's rule
            'advance' => $b['source'] === 'block' ? self::advanceOf(0, 0) : self::advanceOf((int) $b['total_amount'], self::intOrNull($b['advance_amount'] ?? null)),
            // an outdoor party: when, and what other guests can book then
            'eventSlot' => $slot, 'eventOthers' => $others, 'eventOthersLabel' => $others !== '' ? self::EVENT_OTHERS[$others] : '',
            'status' => $b['status'],
            'billId' => $b['bill_id'] === null ? null : (int) $b['bill_id'],
            'source' => $b['source'],
            'sourceLabel' => self::SOURCES[$b['source']] ?? $b['source'],
            'adminNotes' => $b['admin_notes'],
            'createdBy' => $b['created_by'],
            'createdAt' => Db::fromDbTime($b['created_at']),
            'updatedAt' => Db::fromDbTime($b['updated_at']),
        ];
    }

    /** Map rows, adding the rooms given to per-person groups (one query for the lot). */
    private static function mapRows(array $rows): array
    {
        if (!$rows) return [];
        $ids = array_map(fn ($r) => (int) $r['id'], $rows);
        $group = [];
        foreach (Db::all('SELECT br.booking_id, r.id, r.name, r.capacity, r.price, r.price_bb, r.price_hb, r.price_fb, r.category_id FROM booking_rooms br JOIN rooms r ON r.id = br.room_id
            WHERE br.booking_id IN (' . self::marks($ids) . ') ORDER BY r.sort, r.id', $ids) as $x) {
            $group[(int) $x['booking_id']][] = ['id' => (int) $x['id'], 'name' => $x['name'], 'capacity' => (int) $x['capacity'], 'price' => (int) $x['price'],
                'prices' => self::planPrices($x), 'categoryId' => self::intOrNull($x['category_id'])];
        }
        return array_map(fn ($r) => self::mapBooking($r, $group[(int) $r['id']] ?? []), $rows);
    }

    private const BOOKING_SELECT = 'SELECT b.*, r.name AS room_name, r.type AS room_type, r.slug AS room_slug, r.capacity AS room_capacity, r.price AS room_price,
        r.price_bb AS room_price_bb, r.price_hb AS room_price_hb, r.price_fb AS room_price_fb, r.category_id AS room_category_id,
        p.price_adult AS pkg_price_adult, p.price_child AS pkg_price_child,
        c.name AS whole_cat_name, c.whole_code, c.whole_label, c.whole_pax, c.whole_ro, c.whole_bb, c.whole_hb, c.whole_fb,
        (SELECT COALESCE(SUM(pp.fee), 0) FROM pos_payments pp WHERE pp.booking_id = b.id AND pp.voided_at IS NULL) AS fees_kept
        FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id LEFT JOIN packages p ON p.id = b.package_id LEFT JOIN room_categories c ON c.id = b.whole_category_id';

    /**
     * The commission an agent or website earns on a booking: ['type' => ''|percent|amount, 'value', 'to', 'amount'].
     * The guest still pays the full price. The agent either keeps it from a payment they pass on
     * (each payment's "kept"), or the resort pays it later (Finance → Commissions).
     */
    public static function commissionOf(string $type, int $value, string $to, int $total): array
    {
        $amount = $type === 'percent' ? (int) round($total * $value / 100) : ($type === 'amount' ? $value : 0);
        return ['type' => $type, 'value' => $type === '' ? 0 : $value, 'to' => $to, 'amount' => $amount];
    }

    private static function intOrNull(mixed $v): ?int
    {
        return $v === null || $v === '' ? null : (int) $v;
    }

    public static function getBooking(mixed $id): ?array
    {
        $n = Js::number($id);
        if (is_nan($n) || floor($n) != $n) return null;
        $row = Db::one(self::BOOKING_SELECT . ' WHERE b.id = ?', [(int) $n]);
        return $row ? self::mapRows([$row])[0] : null;
    }

    public static function getByRef(string $ref): ?array
    {
        $row = Db::one(self::BOOKING_SELECT . ' WHERE b.ref = ?', [strtoupper($ref)]);
        return $row ? self::mapRows([$row])[0] : null;
    }

    /** The price lines of a booking as booked (agreed rates), for its invoice; one line if the total was set by hand. */
    public static function priceLines(array $b): array
    {
        $room = $b['roomId'] ? self::getRoom($b['roomId']) : null;
        $rooms = $b['kind'] === 'room' ? array_values(array_filter(array_map(fn ($r) => self::getRoom($r['id']), $b['rooms']))) : [];
        $pkg = $b['packageId'] ? self::getPackage($b['packageId']) : null;
        // a whole category keeps its price even if its whole option was turned off since
        $cat = $b['wholeCategory'] ? self::getCategory($b['wholeCategoryId']) : null;
        $whole = $cat && !$cat['whole'] ? ['whole' => ['code' => '', 'label' => 'Whole', 'capacity' => 0, 'prices' => $cat['wholePrices']]] + $cat : $cat;
        $q = self::quote($b['kind'], $room, $pkg, $b['nights'], $b['adults'], $b['children'], $b['partyArea'], $b['jblSpeaker'], $b['rates'], $rooms, $b['mealPlan'] ?: 'RO', $whole);
        $gross = $b['totalAmount'] + $b['couponDiscount'];
        if ($q['total'] === $gross && ($b['kind'] !== 'room' || $room)) return $q['lines'];
        $when = $b['kind'] === 'day_out' ? '1 day' : $b['nights'] . ' night' . ($b['nights'] === 1 ? '' : 's');
        return [['label' => $b['place'] ?: $b['packageLabel'], 'unit' => 0, 'list' => 0, 'detail' => $when, 'amount' => $gross, 'stay' => true]];
    }

    /** $opts: status (one status, 'active' = holding, 'arriving' = pending or confirmed), source, kind, q, from, to, roomId, sort, limit */
    public static function listBookings(array $opts = []): array
    {
        $status = $opts['status'] ?? null;
        $source = $opts['source'] ?? null;
        $kind = $opts['kind'] ?? ($opts['package'] ?? null);
        $q = $opts['q'] ?? null;
        $from = $opts['from'] ?? null;
        $to = $opts['to'] ?? null;
        $roomId = $opts['roomId'] ?? null;
        $sort = $opts['sort'] ?? 'check_in';
        $limit = $opts['limit'] ?? 500;

        $where = [];
        $vals = [];
        if ($status === 'active') { $where[] = 'b.status IN (' . self::holdingSql() . ')'; array_push($vals, ...self::HOLDING); }
        elseif ($status === 'arriving') { $where[] = "b.status IN ('pending', 'confirmed')"; }
        elseif (in_array($status, self::STATUSES, true)) { $where[] = 'b.status = ?'; $vals[] = $status; }
        if (self::isSource($source)) { $where[] = 'b.source = ?'; $vals[] = $source; }
        if (is_string($kind) && array_key_exists($kind, self::KINDS)) { $where[] = 'b.package = ?'; $vals[] = $kind; }
        if (Js::truthy($roomId)) {
            $n = Js::number($roomId);
            $where[] = '(b.room_id = ? OR EXISTS (SELECT 1 FROM booking_rooms br WHERE br.booking_id = b.id AND br.room_id = ?))';
            array_push($vals, is_nan($n) ? null : (int) $n, is_nan($n) ? null : (int) $n);
        }
        if (self::isDate($from)) { $where[] = 'b.check_out > ?'; $vals[] = $from; }
        if (self::isDate($to)) { $where[] = 'b.check_in < ?'; $vals[] = $to; }
        if (Js::truthy($q)) {
            $where[] = '(b.ref LIKE ? OR b.guest_name LIKE ? OR b.guest_email LIKE ? OR b.guest_phone LIKE ? OR b.guest_id_no LIKE ?)';
            $like = '%' . Js::slice(Js::str($q), 80) . '%';
            array_push($vals, $like, $like, $like, $like, $like);
        }
        $order = $sort === 'created' ? 'b.created_at DESC' : 'b.check_in ASC, b.id ASC';
        $lim = Js::number($limit);
        $lim = (is_nan($lim) || $lim == 0) ? 500 : min($lim, 2000);
        if ($lim < 0) $lim = 1000000000; // a negative limit means "no limit" (MySQL refuses negative values)
        $sql = self::BOOKING_SELECT . ' ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . " ORDER BY $order LIMIT ?";
        $vals[] = (int) floor($lim);
        return self::mapRows(Db::all($sql, $vals));
    }

    public static function stats(): array
    {
        $t = self::today();
        $one = fn (string $sql, array $a = []) => (int) Db::one($sql, $a)['n'];
        $rooms = count(self::roomsInUse()) ?: 1;
        $occ = self::occupancy($t, self::addDays($t, 30));
        $tonight = $occ[$t];
        $roomNights = 0;
        foreach ($occ as $n) $roomNights += count($n['rooms']) - count($n['blocked']);
        $stay = "package != 'day_out' AND source != 'block'";
        return [
            'today' => $t,
            'arrivalsToday' => $one("SELECT COUNT(*) n FROM bookings WHERE check_in = ? AND status IN ('pending','confirmed') AND $stay", [$t]),
            'departuresToday' => $one("SELECT COUNT(*) n FROM bookings WHERE check_out = ? AND status IN ('confirmed','checked_in') AND $stay", [$t]),
            'inHouse' => count($tonight['rooms']) - count($tonight['blocked']), // rooms occupied tonight
            'guestsTonight' => $tonight['people'],
            'capacity' => self::hotelCapacity(),
            'capacityUsed' => $tonight['guests'],
            'dayOutsToday' => $one("SELECT COUNT(*) n FROM bookings WHERE package = 'day_out' AND check_in = ? AND status IN ('pending','confirmed','checked_in')", [$t]),
            'pending' => $one("SELECT COUNT(*) n FROM bookings WHERE status = 'pending'"),
            'occupancy30' => (int) round(($roomNights / ($rooms * 30)) * 100),
            'upcomingRevenue' => $one("SELECT COALESCE(SUM(total_amount),0) n FROM bookings WHERE check_in >= ? AND status IN ('confirmed','checked_in')", [$t]),
            'pendingRevenue' => $one("SELECT COALESCE(SUM(total_amount),0) n FROM bookings WHERE check_in >= ? AND status = 'pending'", [$t]),
            'rooms' => $rooms,
        ];
    }
}
