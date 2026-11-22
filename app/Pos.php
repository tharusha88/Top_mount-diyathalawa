<?php
// Point of sale: guests are checked in (per-person groups get their rooms then), food,
// drinks and other items go on their room bill (folio), items can move between guests,
// payments are taken in advance or at checkout, and checkout issues one bill.
// Every change is written to pos_log, and an issued bill keeps a copy of itself
// (bills.snapshot), so the history never changes. Mistakes are fixed by voiding a bill.
declare(strict_types=1);

namespace Ydr;

final class Pos
{
    public const METHODS = ['cash' => 'Cash', 'card' => 'Card', 'bank' => 'Bank transfer', 'online' => 'Online / QR', 'other' => 'Other'];
    // a room bill takes charges while the guest is expected, staying, or checked out but not billed yet
    private const OPEN_STATUSES = ['confirmed', 'checked_in', 'checked_out'];
    private const MAX_QTY = 999;
    private const MAX_PRICE = 10000000;
    private const MAX_AMOUNT = 100000000;
    private const OTHER = 'Other'; // category for custom lines without one

    private static function now(): string
    {
        return Db::toDbTime(Js::isoNow());
    }

    private static function idOf(mixed $v): int
    {
        return Bookings::int($v, 1, PHP_INT_MAX) ?? 0;
    }

    public static function billNo(int $id): string
    {
        return 'INV-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    /** The service charge added to bills, in % (Admin → Booking setup; 0 = none). */
    public static function servicePct(): int
    {
        return max(0, min(100, Settings::int('service_charge_pct')));
    }

    /** The service charge on an amount (the bill after any discount). */
    public static function serviceOn(int $amount, int $pct): int
    {
        return $pct > 0 && $amount > 0 ? (int) round($amount * $pct / 100) : 0;
    }

    // ---------- menu: categories and items ----------
    private static function mapCategory(array $r): array
    {
        return ['id' => (int) $r['id'], 'name' => $r['name'], 'sort' => (int) $r['sort'], 'active' => (bool) (int) $r['active']];
    }

    public static function listCategories(bool $includeHidden = false): array
    {
        $rows = Db::all('SELECT * FROM pos_categories ' . ($includeHidden ? '' : 'WHERE active = 1 ') . 'ORDER BY sort, id');
        return array_map([self::class, 'mapCategory'], $rows);
    }

    /** Create ($id = null) or update a category. Categories are hidden, never deleted. */
    public static function saveCategory(?int $id, array $in): array
    {
        $cur = $id ? Db::one('SELECT * FROM pos_categories WHERE id = ?', [$id]) : null;
        if ($id && !$cur) throw new BookingError('Category not found', 404, 'not_found');
        $name = !$cur || array_key_exists('name', $in) ? Bookings::str($in['name'] ?? null, 60, true) : $cur['name'];
        if ($name === null) throw new BookingError('Please name the category.');
        $dupe = Db::one('SELECT id FROM pos_categories WHERE name = ? AND id != ?', [$name, $id ?? 0]);
        if ($dupe) throw new BookingError("There is already a category called $name.");
        $active = array_key_exists('active', $in) ? (Js::truthy($in['active']) ? 1 : 0) : (int) ($cur['active'] ?? 1);
        $sort = array_key_exists('sort', $in) ? Bookings::int($in['sort'], 0, 10000) : ($cur ? (int) $cur['sort'] : (int) Db::one('SELECT COALESCE(MAX(sort), 0) AS n FROM pos_categories')['n'] + 1);
        if ($sort === null) throw new BookingError('Invalid position.');
        if ($cur) Db::run('UPDATE pos_categories SET name = ?, active = ?, sort = ? WHERE id = ?', [$name, $active, $sort, $id]);
        else $id = Db::run('INSERT INTO pos_categories (name, sort, active, created_at) VALUES (?, ?, ?, ?)', [$name, $sort, $active, self::now()]);
        return self::mapCategory(Db::one('SELECT * FROM pos_categories WHERE id = ?', [$id]));
    }

    private const ITEM_SELECT = 'SELECT i.*, c.name AS category_name, c.active AS category_active, c.sort AS category_sort
        FROM pos_items i LEFT JOIN pos_categories c ON c.id = i.category_id';

    private static function mapItem(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'name' => $r['name'], 'price' => (int) $r['price'], 'active' => (bool) (int) $r['active'], 'sort' => (int) $r['sort'],
            'categoryId' => $r['category_id'] === null ? null : (int) $r['category_id'], 'category' => $r['category_name'] ?? self::OTHER,
        ];
    }

    /** Menu items, by category order. $includeHidden also lists hidden items and items in hidden categories. */
    public static function listItems(bool $includeHidden = false): array
    {
        $rows = Db::all(self::ITEM_SELECT . ($includeHidden ? '' : ' WHERE i.active = 1 AND (c.active = 1 OR c.id IS NULL)') . ' ORDER BY c.sort, c.id, i.sort, i.name, i.id');
        return array_map([self::class, 'mapItem'], $rows);
    }

    private static function category(mixed $id): array
    {
        $c = Db::one('SELECT * FROM pos_categories WHERE id = ?', [self::idOf($id)]);
        if (!$c) throw new BookingError('Please choose a category.');
        return $c;
    }

    /** Create ($id = null) or update a menu item. Items are hidden, never deleted, so old bills keep them. */
    public static function saveItem(?int $id, array $in): array
    {
        $cur = $id ? Db::one('SELECT * FROM pos_items WHERE id = ?', [$id]) : null;
        if ($id && !$cur) throw new BookingError('Item not found', 404, 'not_found');
        $name = !$cur || array_key_exists('name', $in) ? Bookings::str($in['name'] ?? null, 120, true) : $cur['name'];
        if ($name === null) throw new BookingError('Please enter the item name.');
        $categoryId = !$cur || array_key_exists('categoryId', $in) ? (int) self::category($in['categoryId'] ?? null)['id'] : ($cur['category_id'] === null ? null : (int) $cur['category_id']);
        $price = !$cur || array_key_exists('price', $in) ? Bookings::int($in['price'] ?? null, 0, self::MAX_PRICE) : (int) $cur['price'];
        if ($price === null) throw new BookingError('The price must be a whole number of 0 or more.');
        $active = array_key_exists('active', $in) ? (Js::truthy($in['active']) ? 1 : 0) : (int) ($cur['active'] ?? 1);
        if ($cur) {
            Db::run('UPDATE pos_items SET name = ?, category_id = ?, price = ?, active = ? WHERE id = ?', [$name, $categoryId, $price, $active, $id]);
        } else {
            $sort = (int) Db::one('SELECT COALESCE(MAX(sort), 0) AS n FROM pos_items')['n'] + 1;
            $id = Db::run("INSERT INTO pos_items (name, category_id, category, price, active, sort, created_at) VALUES (?, ?, '', ?, ?, ?, ?)", [$name, $categoryId, $price, $active, $sort, self::now()]);
        }
        return self::mapItem(Db::one(self::ITEM_SELECT . ' WHERE i.id = ?', [$id]));
    }

    // ---------- room bills (folios) ----------
    private static function booking(mixed $id): array
    {
        $b = Bookings::getBooking(self::idOf($id));
        if (!$b) throw new BookingError('Booking not found', 404, 'not_found');
        return $b;
    }

    /** Can items and payments still go on this booking's room bill? */
    public static function isOpen(array $b): bool
    {
        return $b['billId'] === null && $b['source'] !== 'block' && in_array($b['status'], self::OPEN_STATUSES, true);
    }

    private static function requireOpen(array $b): void
    {
        if ($b['source'] === 'block') throw new BookingError('Blocked dates have no room bill.', 409, 'closed');
        if ($b['billId'] !== null) throw new BookingError("{$b['ref']} is already billed on " . self::billNo($b['billId']) . '. Void that bill first to change it.', 409, 'closed');
        if ($b['status'] === 'pending') throw new BookingError("{$b['ref']} is still a pending request. Confirm or check it in first.", 409, 'closed');
        if (!in_array($b['status'], self::OPEN_STATUSES, true)) throw new BookingError("{$b['ref']} is cancelled.", 409, 'closed');
    }

    /** Inside a transaction: lock a booking and check its room bill is still open. */
    private static function lockOpen(mixed $id): array
    {
        $b = self::booking($id);
        Db::lockBooking($b['id']);
        $b = self::booking($b['id']); // re-read under the lock
        self::requireOpen($b);
        return $b;
    }

    private static function labelOf(array $b): string
    {
        return "{$b['place']} ({$b['guestName']}, {$b['ref']})";
    }

    private static function stayAmount(array $b): int
    {
        return $b['source'] === 'block' ? 0 : $b['totalAmount'];
    }

    private const CHARGE_SELECT = 'SELECT c.*, fb.ref AS moved_ref, fb.package AS moved_package, fb.package_name AS moved_package_name, fr.name AS moved_room
        FROM pos_charges c LEFT JOIN bookings fb ON fb.id = c.moved_from LEFT JOIN rooms fr ON fr.id = fb.room_id';

    private static function mapCharge(array $c): array
    {
        return [
            'id' => (int) $c['id'],
            'bookingId' => (int) $c['booking_id'],
            'itemId' => $c['item_id'] === null ? null : (int) $c['item_id'],
            'name' => $c['name'],
            'category' => $c['category'],
            'unitPrice' => (int) $c['unit_price'],
            'qty' => (int) $c['qty'],
            'amount' => (int) $c['amount'],
            'note' => $c['note'],
            'createdAt' => Db::fromDbTime($c['created_at']),
            'createdBy' => $c['created_by'],
            'movedFrom' => $c['moved_from'] === null ? null : [
                'bookingId' => (int) $c['moved_from'], 'ref' => $c['moved_ref'],
                'label' => $c['moved_room'] ?? ($c['moved_package_name'] ?: 'another guest'),
            ],
            'movedAt' => Db::fromDbTime($c['moved_at']),
            'movedBy' => $c['moved_by'],
            'voided' => $c['voided_at'] !== null,
            'voidedAt' => Db::fromDbTime($c['voided_at']),
            'voidedBy' => $c['voided_by'],
            'voidReason' => $c['void_reason'],
        ];
    }

    private static function mapPayment(array $p): array
    {
        $details = json_decode((string) ($p['details'] ?? ''), true);
        $fee = (int) ($p['fee'] ?? 0);
        return [
            'id' => (int) $p['id'],
            'receiptNo' => self::receiptNo((int) $p['id']),
            'receiptUrl' => Docs::receiptUrl((int) $p['id']),
            'bookingId' => (int) $p['booking_id'],
            'billId' => $p['bill_id'] === null ? null : (int) $p['bill_id'],
            'amount' => (int) $p['amount'],
            'fee' => $fee,                        // commission / charges taken off (staff only — not on the receipt)
            'net' => (int) $p['amount'] - $fee,   // what actually reached the resort
            'method' => $p['method'],
            'methodLabel' => self::METHODS[$p['method']] ?? $p['method'],
            'note' => $p['note'],
            'details' => is_array($details) ? array_map(fn ($k, $v) => ['label' => (string) $k, 'value' => (string) $v], array_keys($details), $details) : [],
            // the bank slip staff attached (staff only: the link needs an admin login)
            'slipUrl' => ($p['slip'] ?? '') !== '' ? '/api/admin/pos/payments/' . (int) $p['id'] . '/slip' : null,
            'createdAt' => Db::fromDbTime($p['created_at']),
            'createdBy' => $p['created_by'],
            'createdByName' => Auth::nameOf((string) $p['created_by']),
            'voided' => $p['voided_at'] !== null,
            'voidedAt' => Db::fromDbTime($p['voided_at']),
            'voidedBy' => $p['voided_by'],
        ];
    }

    private static function log(int $bookingId, string $action, string $detail, ?int $amount, string $by): void
    {
        Db::run('INSERT INTO pos_log (booking_id, action, detail, amount, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$bookingId, $action, Js::slice($detail, 500), $amount, self::now(), $by]);
    }

    /** Category names in menu order (categories no longer on the menu go last). */
    private static function categoryOrder(): array
    {
        return array_flip(array_column(self::listCategories(true), 'name'));
    }

    /** Item lines grouped by category, then item and price: [{category, name, unitPrice, qty, amount, chargeIds}]. */
    private static function groupLines(array $charges): array
    {
        $order = self::categoryOrder();
        $groups = [];
        foreach ($charges as $c) {
            if ($c['voided']) continue;
            $key = $c['category'] . "\x1f" . $c['name'] . "\x1f" . $c['unitPrice'];
            $g = $groups[$key] ?? ['key' => md5($key), 'category' => $c['category'], 'name' => $c['name'], 'unitPrice' => $c['unitPrice'], 'qty' => 0, 'amount' => 0, 'chargeIds' => []];
            $g['qty'] += $c['qty'];
            $g['amount'] += $c['amount'];
            $g['chargeIds'][] = $c['id'];
            $groups[$key] = $g;
        }
        $list = array_values($groups);
        usort($list, fn ($a, $b) => [$order[$a['category']] ?? PHP_INT_MAX, $a['category']] <=> [$order[$b['category']] ?? PHP_INT_MAX, $b['category']]);
        return $list;
    }

    /** [{name, amount}] per category, in menu order. */
    private static function categoryTotals(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) $out[$l['category']] = ($out[$l['category']] ?? 0) + $l['amount'];
        return array_map(fn ($name, $amount) => ['name' => (string) $name, 'amount' => $amount], array_keys($out), $out);
    }

    /** Stay + items, the service charge, what has been paid in advance, and the balance. */
    public static function totals(array $b, array $charges, array $payments): array
    {
        $lines = self::groupLines($charges);
        $stay = self::stayAmount($b);
        $items = array_sum(array_column($lines, 'amount'));
        $paid = array_sum(array_map(fn ($p) => $p['voided'] ? 0 : $p['amount'], $payments));
        $fees = array_sum(array_map(fn ($p) => $p['voided'] ? 0 : ($p['fee'] ?? 0), $payments));
        $pct = self::servicePct();
        $service = self::serviceOn($stay + $items, $pct);
        return ['stay' => $stay, 'categories' => self::categoryTotals($lines), 'items' => $items, 'itemCount' => array_sum(array_column($lines, 'qty')),
            'subtotal' => $stay + $items, 'servicePct' => $pct, 'service' => $service,
            'total' => $stay + $items + $service, 'paid' => $paid, 'fees' => $fees, 'received' => $paid - $fees, 'balance' => $stay + $items + $service - $paid];
    }

    /** A booking's room bill: the stay, every item (removed ones too), the grouped lines, advance payments and the history. */
    public static function folio(mixed $bookingId): array
    {
        $b = self::booking($bookingId);
        $charges = array_map([self::class, 'mapCharge'], Db::all(self::CHARGE_SELECT . ' WHERE c.booking_id = ? ORDER BY c.created_at, c.id', [$b['id']]));
        $payments = array_map([self::class, 'mapPayment'], Db::all('SELECT * FROM pos_payments WHERE booking_id = ? AND bill_id IS NULL ORDER BY created_at, id', [$b['id']]));
        $log = array_map(fn ($l) => [
            'action' => $l['action'], 'detail' => $l['detail'], 'amount' => $l['amount'] === null ? null : (int) $l['amount'],
            'at' => Db::fromDbTime($l['created_at']), 'by' => $l['created_by'],
        ], Db::all('SELECT * FROM pos_log WHERE booking_id = ? ORDER BY id DESC LIMIT 300', [$b['id']]));
        return [
            'booking' => $b,
            'open' => self::isOpen($b),
            'invoiceUrl' => $b['source'] === 'block' ? null : Docs::invoiceUrl($b),
            'bill' => $b['billId'] === null ? null : ['id' => $b['billId'], 'billNo' => self::billNo($b['billId'])],
            'charges' => $charges,
            'lines' => self::groupLines($charges),
            'payments' => $payments,
            'guestSlips' => self::guestSlips($b['id']), // payment slips the guest sent from their invoice link
            'log' => $log,
            'totals' => self::totals($b, $charges, $payments),
        ];
    }

    /** Validate one line to charge: [{itemId, qty, note}] for a menu item or [{name, price, categoryId, qty, note}] for a custom one. */
    private static function chargeLine(mixed $l): array
    {
        if (!is_array($l)) throw new BookingError('Invalid item.');
        $qty = Bookings::int($l['qty'] ?? 1, 1, self::MAX_QTY);
        if ($qty === null) throw new BookingError('Quantities must be whole numbers from 1 to ' . self::MAX_QTY . '.');
        $note = Bookings::str($l['note'] ?? null, 255) ?? '';
        if (($l['itemId'] ?? null) !== null && $l['itemId'] !== '') {
            $item = Db::one(self::ITEM_SELECT . ' WHERE i.id = ?', [self::idOf($l['itemId'])]);
            if (!$item) throw new BookingError('One of those items is no longer on the menu.');
            return ['item_id' => (int) $item['id'], 'name' => $item['name'], 'category' => $item['category_name'] ?? self::OTHER, 'unit_price' => (int) $item['price'], 'qty' => $qty, 'note' => $note];
        }
        $name = Bookings::str($l['name'] ?? null, 160, true);
        if ($name === null) throw new BookingError('Please describe the custom item.');
        $category = ($l['categoryId'] ?? null) !== null && $l['categoryId'] !== '' ? self::category($l['categoryId'])['name'] : self::OTHER;
        $price = Bookings::int($l['price'] ?? null, 0, self::MAX_PRICE);
        if ($price === null) throw new BookingError('The price must be a whole number of 0 or more.');
        return ['item_id' => null, 'name' => $name, 'category' => $category, 'unit_price' => $price, 'qty' => $qty, 'note' => $note];
    }

    private static function insertCharge(int $bookingId, array $r, string $now, string $by): void
    {
        $amount = $r['unit_price'] * $r['qty'];
        Db::run('INSERT INTO pos_charges (booking_id, item_id, name, category, unit_price, qty, amount, note, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$bookingId, $r['item_id'], $r['name'], $r['category'], $r['unit_price'], $r['qty'], $amount, $r['note'], $now, $by]);
        self::log($bookingId, 'add', "{$r['qty']} × {$r['name']}" . ($r['note'] !== '' ? " ({$r['note']})" : ''), $amount, $by);
    }

    /** Charge items to a room bill (each POS tap adds one). */
    public static function addCharges(mixed $bookingId, mixed $lines, string $by): array
    {
        if (!is_array($lines) || !$lines || !Js::isList($lines) || count($lines) > 100) throw new BookingError('Choose at least one item.');
        $rows = array_map([self::class, 'chargeLine'], $lines);
        $id = Db::transaction(function () use ($bookingId, $rows, $by) {
            $b = self::lockOpen($bookingId);
            $now = self::now();
            foreach ($rows as $r) self::insertCharge($b['id'], $r, $now, $by);
            return $b['id'];
        });
        return self::folio($id);
    }

    /**
     * Take an item off a room bill — the whole line, or $qty of it (the POS "−" button
     * removes one). The removed part stays in the history.
     */
    public static function voidCharge(mixed $chargeId, mixed $reason, string $by, mixed $qty = null): array
    {
        $reason = Bookings::str($reason, 255) ?? '';
        if ($reason === '') $reason = 'Removed';
        $c = Db::one('SELECT * FROM pos_charges WHERE id = ?', [self::idOf($chargeId)]);
        if (!$c) throw new BookingError('Item not found', 404, 'not_found');
        Db::transaction(function () use ($c, $reason, $by, $qty) {
            $b = self::lockOpen((int) $c['booking_id']);
            $c = Db::one('SELECT * FROM pos_charges WHERE id = ?', [(int) $c['id']]);
            if ((int) $c['booking_id'] !== $b['id']) throw new BookingError('That item has just been moved to another guest. Reload and try again.', 409, 'stale');
            if ($c['voided_at'] !== null) throw new BookingError('That item was already removed.', 409, 'voided');
            $have = (int) $c['qty'];
            $n = $qty === null || $qty === '' ? $have : Bookings::int($qty, 1, $have);
            if ($n === null) throw new BookingError("Remove between 1 and $have of {$c['name']}.");
            $unit = (int) $c['unit_price'];
            $now = self::now();
            if ($n === $have) {
                Db::run('UPDATE pos_charges SET voided_at = ?, voided_by = ?, void_reason = ? WHERE id = ?', [$now, $by, $reason, (int) $c['id']]);
            } else {
                // keep the rest on the bill; the removed part becomes its own (removed) line
                Db::run('UPDATE pos_charges SET qty = ?, amount = ? WHERE id = ?', [$have - $n, ($have - $n) * $unit, (int) $c['id']]);
                Db::run('INSERT INTO pos_charges (booking_id, item_id, name, category, unit_price, qty, amount, note, created_at, created_by, moved_from, moved_at, moved_by, voided_at, voided_by, void_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$b['id'], $c['item_id'] === null ? null : (int) $c['item_id'], $c['name'], $c['category'], $unit, $n, $n * $unit, $c['note'], $c['created_at'], $c['created_by'],
                        $c['moved_from'] === null ? null : (int) $c['moved_from'], $c['moved_at'], $c['moved_by'], $now, $by, $reason]);
            }
            self::log($b['id'], 'void', "Removed $n × {$c['name']}: $reason", -($n * $unit), $by);
        });
        return self::folio((int) $c['booking_id']);
    }

    /**
     * Move items from one room bill to another. $lines: [{chargeId, qty}]; a qty smaller than
     * the line's splits it (e.g. move 1 of 3 beers). Returns both room bills.
     */
    public static function transfer(mixed $fromId, mixed $toId, mixed $lines, string $by): array
    {
        $from = self::idOf($fromId);
        $to = self::idOf($toId);
        if (!$from || !$to) throw new BookingError('Choose the guest to move the items to.');
        if ($from === $to) throw new BookingError('Choose a different guest to move the items to.');
        if (!is_array($lines) || !$lines || !Js::isList($lines) || count($lines) > 200) throw new BookingError('Choose the items to move.');
        $want = [];
        foreach ($lines as $l) {
            $cid = is_array($l) ? self::idOf($l['chargeId'] ?? null) : 0;
            if (!$cid || array_key_exists($cid, $want)) throw new BookingError('Invalid item selection.');
            $want[$cid] = $l['qty'] ?? null;
        }
        Db::transaction(function () use ($from, $to, $want, $by) {
            // lock both room bills in id order, so two opposite moves can never deadlock
            Db::lockBooking(min($from, $to));
            Db::lockBooking(max($from, $to));
            $a = self::booking($from);
            $b = self::booking($to);
            self::requireOpen($a);
            self::requireOpen($b);
            $now = self::now();
            $moved = [];
            foreach ($want as $cid => $qtyIn) {
                $c = Db::one('SELECT * FROM pos_charges WHERE id = ?', [$cid]);
                if (!$c || (int) $c['booking_id'] !== $from) throw new BookingError('Some of those items are no longer on this bill. Reload and try again.', 409, 'stale');
                if ($c['voided_at'] !== null) throw new BookingError("{$c['name']} was removed from the bill, so it can't be moved.", 409, 'voided');
                $have = (int) $c['qty'];
                $qty = $qtyIn === null || $qtyIn === '' ? $have : Bookings::int($qtyIn, 1, $have);
                if ($qty === null) throw new BookingError("Move between 1 and $have of {$c['name']}.");
                $unit = (int) $c['unit_price'];
                if ($qty === $have) {
                    Db::run('UPDATE pos_charges SET booking_id = ?, moved_from = ?, moved_at = ?, moved_by = ? WHERE id = ?', [$to, $from, $now, $by, $cid]);
                } else {
                    // split the line: the rest stays on this bill
                    Db::run('UPDATE pos_charges SET qty = ?, amount = ? WHERE id = ?', [$have - $qty, ($have - $qty) * $unit, $cid]);
                    Db::run('INSERT INTO pos_charges (booking_id, item_id, name, category, unit_price, qty, amount, note, created_at, created_by, moved_from, moved_at, moved_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$to, $c['item_id'] === null ? null : (int) $c['item_id'], $c['name'], $c['category'], $unit, $qty, $qty * $unit, $c['note'], $c['created_at'], $c['created_by'], $from, $now, $by]);
                }
                $moved[] = ["$qty × {$c['name']}", $qty * $unit];
            }
            $what = implode(', ', array_column($moved, 0));
            $sum = array_sum(array_column($moved, 1));
            self::log($from, 'transfer_out', "Moved $what to " . self::labelOf($b), -$sum, $by);
            self::log($to, 'transfer_in', "Received $what from " . self::labelOf($a), $sum, $by);
        });
        return ['from' => self::folio($from), 'to' => self::folio($to)];
    }

    // ---------- payments: extra fields staff fill in (the account it went into, a reference…) ----------
    private const MAX_FIELDS = 8;

    /** The extra payment fields (Admin → Booking setup): [{key, label, type: text|choice, options, required}]. */
    public static function paymentFields(): array
    {
        $raw = Settings::get('payment_fields');
        $list = $raw === '' ? null : json_decode($raw, true);
        if (!is_array($list)) {
            // until it is set up: where the money went, the bank account from the advance-payment details
            $pay = Bookings::paymentInfo();
            $acct = trim(implode(' · ', array_filter([$pay['bank'], $pay['accountNo']], fn ($s) => $s !== '')));
            return [['key' => 'received_into', 'label' => 'Received into', 'type' => 'choice', 'options' => array_values(array_filter(['Cash in hand', $acct], fn ($s) => $s !== '')), 'required' => false]];
        }
        return array_values(array_filter(array_map(fn ($f) => is_array($f) && isset($f['key'], $f['label']) ? [
            'key' => (string) $f['key'], 'label' => (string) $f['label'], 'type' => ($f['type'] ?? '') === 'choice' ? 'choice' : 'text',
            'options' => array_values(array_map('strval', is_array($f['options'] ?? null) ? $f['options'] : [])), 'required' => !empty($f['required']),
        ] : null, $list)));
    }

    /** Save the payment fields: [{label, type, options, required}] (keys are made from the labels). */
    public static function savePaymentFields(mixed $list): array
    {
        if (!is_array($list) || !Js::isList($list) || count($list) > self::MAX_FIELDS) throw new BookingError('Up to ' . self::MAX_FIELDS . ' payment fields.');
        $out = [];
        $keys = [];
        foreach ($list as $f) {
            if (!is_array($f)) throw new BookingError('Invalid payment field.');
            $label = Bookings::str($f['label'] ?? null, 60, true);
            if ($label === null) throw new BookingError('Give every payment field a name.');
            $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_') ?: 'field';
            if (isset($keys[$key])) throw new BookingError("There are two payment fields called $label.");
            $keys[$key] = true;
            $type = ($f['type'] ?? '') === 'choice' ? 'choice' : 'text';
            $options = [];
            if ($type === 'choice') {
                $raw = is_array($f['options'] ?? null) ? $f['options'] : preg_split('/\r?\n|,/', (string) ($f['options'] ?? ''));
                foreach ($raw as $o) {
                    $o = Bookings::str($o, 80) ?? '';
                    if ($o !== '' && !in_array($o, $options, true)) $options[] = $o;
                }
                if (!$options) throw new BookingError("Add the choices for “{$label}” (one per line).");
                if (count($options) > 30) throw new BookingError("Up to 30 choices for “{$label}”.");
            }
            $out[] = ['key' => $key, 'label' => $label, 'type' => $type, 'options' => $options, 'required' => !empty($f['required'])];
        }
        Settings::set(['payment_fields' => Js::json($out)]);
        return self::paymentFields();
    }

    /** The extra fields of a payment, checked: [label => value], in the order they are set up. */
    private static function paymentDetails(mixed $in): array
    {
        $in = is_array($in) ? $in : [];
        $out = [];
        foreach (self::paymentFields() as $f) {
            $v = $in[$f['key']] ?? '';
            $v = is_string($v) || is_int($v) ? Js::slice(Js::trim((string) $v), 120) : '';
            if ($v === '') {
                if ($f['required']) throw new BookingError("Please fill in “{$f['label']}” for the payment.");
                continue;
            }
            if ($f['type'] === 'choice' && !in_array($v, $f['options'], true)) throw new BookingError("Choose one of the options for “{$f['label']}”.");
            $out[$f['label']] = $v;
        }
        return $out;
    }

    /** {amount, method, note, fee (commission or charges taken off), details {fieldKey: value}, slip (from saveSlip, optional)} */
    public static function paymentInput(mixed $in): array
    {
        if (!is_array($in)) throw new BookingError('Invalid payment.');
        $amount = Bookings::int($in['amount'] ?? null, 1, self::MAX_AMOUNT);
        if ($amount === null) throw new BookingError('Enter the amount paid, a whole number above 0.');
        $method = $in['method'] ?? 'cash';
        if (!is_string($method) || !isset(self::METHODS[$method])) throw new BookingError('Choose how it was paid.');
        $fee = ($in['fee'] ?? '') === '' || ($in['fee'] ?? null) === null ? 0 : Bookings::int($in['fee'], 0, $amount);
        if ($fee === null) throw new BookingError('The commission or fee must be a whole number, not more than the payment.');
        return ['amount' => $amount, 'method' => $method, 'note' => Bookings::str($in['note'] ?? null, 255) ?? '', 'fee' => $fee, 'details' => self::paymentDetails($in['details'] ?? []), 'slip' => self::slipInput($in['slip'] ?? null)];
    }

    /** Insert a payment row; returns its id. $billId null = an advance on an open room bill. */
    private static function insertPayment(int $bookingId, ?int $billId, array $p, string $now, string $by): int
    {
        $id = Db::run('INSERT INTO pos_payments (booking_id, bill_id, amount, method, note, fee, details, slip, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$bookingId, $billId, $p['amount'], $p['method'], $p['note'], $p['fee'] ?? 0, !empty($p['details']) ? Js::json($p['details']) : '', $p['slip'] ?? '', $now, $by]);
        // a slip the guest sent: now recorded as this payment
        if (($p['slip'] ?? '') !== '') Db::run('UPDATE booking_slips SET payment_id = ? WHERE slip = ? AND payment_id IS NULL', [$id, $p['slip']]);
        return $id;
    }

    // ---------- payment slips: a photo or PDF of the bank slip, kept in DATA_DIR/slips (never public) ----------
    public const SLIP_LIMIT = 10485760; // 10 MB
    private const SLIP_TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf'];
    private const SLIP_NAME = '/^slip-\d{8}-[a-f0-9]{16}\.(jpg|png|webp|gif|pdf)$/';

    private static function slipDir(): string
    {
        return Config::get()['dataDir'] . '/slips';
    }

    /** What a file is from its first bytes (not the name or the browser's type): jpg|png|webp|gif|pdf, or null. */
    private static function slipKind(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) return 'jpg';
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) return 'png';
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') return 'webp';
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) return 'gif';
        if (str_starts_with($bytes, '%PDF-')) return 'pdf';
        return null;
    }

    /** Keep an uploaded slip until the payment it belongs to is recorded; returns its name for paymentInput's 'slip'. */
    public static function saveSlip(string $bytes): string
    {
        if ($bytes === '') throw new BookingError('No file received.');
        if (strlen($bytes) > self::SLIP_LIMIT) throw new BookingError('The slip is too large. Up to 10 MB.');
        $kind = self::slipKind($bytes);
        if ($kind === null) throw new BookingError('The slip must be a photo (JPG, PNG, WebP) or a PDF.');
        $dir = self::slipDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new \RuntimeException('Could not create the slips folder.');
        $name = 'slip-' . gmdate('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $kind;
        if (@file_put_contents("$dir/$name", $bytes, LOCK_EX) !== strlen($bytes)) throw new \RuntimeException('Could not save the slip.');
        return $name;
    }

    /** A slip name from saveSlip, checked: '' = none. */
    private static function slipInput(mixed $name): string
    {
        if ($name === null || $name === '') return '';
        if (!is_string($name) || !preg_match(self::SLIP_NAME, $name) || !is_file(self::slipDir() . "/$name")) throw new BookingError('The payment slip was not found. Please attach it again.');
        if (Db::one('SELECT id FROM pos_payments WHERE slip = ? AND voided_at IS NULL', [$name])) throw new BookingError('That slip is already on another payment. Please attach it again.');
        return $name;
    }

    /** A kept slip's file: [bytes, mime type, download name ($base + extension)], or null. */
    private static function slipFileOf(string $name, string $base): ?array
    {
        if ($name === '' || !preg_match(self::SLIP_NAME, $name, $m)) return null;
        $bytes = @file_get_contents(self::slipDir() . "/$name");
        return $bytes === false ? null : [$bytes, self::SLIP_TYPES[$m[1]], "$base.{$m[1]}"];
    }

    /** A payment's slip: [bytes, mime type, download name], or null if it has none. */
    public static function slipOf(mixed $paymentId): ?array
    {
        $p = Db::one('SELECT id, slip FROM pos_payments WHERE id = ?', [self::idOf($paymentId)]);
        return $p ? self::slipFileOf((string) $p['slip'], self::receiptNo((int) $p['id']) . '-slip') : null;
    }

    // ---------- slips guests send from their invoice link (booking_slips), until staff record the payment ----------
    private const GUEST_SLIP_STATUSES = ['pending', 'confirmed', 'checked_in'];
    private const MAX_GUEST_SLIPS = 10;

    /** Whether the guest can (still) send a payment slip for this booking. */
    public static function takesGuestSlips(array $b): bool
    {
        return $b['source'] !== 'block' && in_array($b['status'], self::GUEST_SLIP_STATUSES, true);
    }

    /** The guest sends a payment slip (the file's bytes) with what they paid and a note, both optional. Returns it as guestSlips() lists it. */
    public static function addGuestSlip(array $b, string $bytes, mixed $amount = null, mixed $note = null): array
    {
        if (!self::takesGuestSlips($b)) throw new BookingError('This booking no longer takes payment slips. Please call us.');
        if ((int) Db::one('SELECT COUNT(*) AS n FROM booking_slips WHERE booking_id = ?', [$b['id']])['n'] >= self::MAX_GUEST_SLIPS) {
            throw new BookingError('We already have ' . self::MAX_GUEST_SLIPS . ' slips for this booking. Please call us.');
        }
        $amt = null;
        if ($amount !== null && $amount !== '') {
            $amt = Bookings::int($amount, 1, self::MAX_AMOUNT);
            if ($amt === null) throw new BookingError('Enter the amount you paid as a whole number, or leave it empty.');
        }
        $name = self::saveSlip($bytes);
        $id = Db::run('INSERT INTO booking_slips (booking_id, slip, amount, note, created_at) VALUES (?, ?, ?, ?, ?)',
            [$b['id'], $name, $amt, Bookings::str($note, 255) ?? '', self::now()]);
        self::log($b['id'], 'guest_slip', 'The guest sent a payment slip' . ($amt ? ' for ' . number_format($amt) : ''), null, 'guest');
        return self::mapGuestSlip(Db::one('SELECT * FROM booking_slips WHERE id = ?', [$id]));
    }

    private static function mapGuestSlip(array $s): array
    {
        $pid = $s['payment_id'] === null ? null : (int) $s['payment_id'];
        return [
            'id' => (int) $s['id'],
            'key' => $s['slip'], // a payment's 'slip' takes this to keep the guest's slip (staff only)
            'amount' => $s['amount'] === null ? null : (int) $s['amount'],
            'note' => $s['note'],
            'uploadedAt' => Db::fromDbTime($s['created_at']),
            'url' => '/api/admin/pos/guest-slips/' . (int) $s['id'],
            'paymentId' => $pid, // the payment staff recorded with it (null = not yet)
            'receiptNo' => $pid ? self::receiptNo($pid) : null,
        ];
    }

    /** The slips a booking's guest sent, newest first (for staff). */
    public static function guestSlips(int $bookingId): array
    {
        return array_map([self::class, 'mapGuestSlip'], Db::all('SELECT * FROM booking_slips WHERE booking_id = ? ORDER BY id DESC', [$bookingId]));
    }

    /** For the bookings list: each booking + slipWaiting, how many slips its guest sent that are not on a payment yet. */
    public static function withGuestSlips(array $bookings): array
    {
        $ids = array_values(array_filter(array_map(fn ($b) => (int) $b['id'], $bookings)));
        $waiting = [];
        if ($ids) {
            $rows = Db::all('SELECT booking_id, COUNT(*) AS n FROM booking_slips WHERE payment_id IS NULL AND booking_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY booking_id', $ids);
            foreach ($rows as $r) $waiting[(int) $r['booking_id']] = (int) $r['n'];
        }
        return array_map(fn ($b) => $b + ['slipWaiting' => $waiting[(int) $b['id']] ?? 0], $bookings);
    }

    /** What the guest's own invoice shows of the slips they sent: [{uploadedAt, amount, note, recorded}]. */
    public static function guestSlipsPublic(int $bookingId): array
    {
        return array_map(fn ($s) => ['uploadedAt' => $s['uploadedAt'], 'amount' => $s['amount'], 'note' => $s['note'], 'recorded' => $s['paymentId'] !== null], self::guestSlips($bookingId));
    }

    /** A guest's slip for staff to open: [bytes, mime type, download name], or null. */
    public static function guestSlipFile(mixed $id): ?array
    {
        $s = Db::one('SELECT s.id, s.slip, b.ref FROM booking_slips s JOIN bookings b ON b.id = s.booking_id WHERE s.id = ?', [self::idOf($id)]);
        return $s ? self::slipFileOf((string) $s['slip'], $s['ref'] . '-guest-slip-' . (int) $s['id']) : null;
    }

    private static function paymentText(array $p, string $suffix = ''): string
    {
        $details = array_map(fn ($k, $v) => "$k: $v", array_keys($p['details'] ?? []), $p['details'] ?? []);
        $extra = implode(' · ', array_filter([$p['note'] ?? '', ...$details, !empty($p['fee']) ? 'fee ' . number_format((int) $p['fee']) . ' taken off' : ''], fn ($s) => $s !== ''));
        return self::METHODS[$p['method']] . ' payment' . $suffix . ($extra !== '' ? ": $extra" : '');
    }

    public static function receiptNo(int $paymentId): string
    {
        return 'RCT-' . str_pad((string) $paymentId, 5, '0', STR_PAD_LEFT);
    }

    /** A payment with its booking, for a receipt. */
    public static function payment(mixed $id): ?array
    {
        $p = Db::one('SELECT * FROM pos_payments WHERE id = ?', [self::idOf($id)]);
        return $p ? self::mapPayment($p) : null;
    }

    /** Every payment towards a booking (advances and those on its bills), oldest first. */
    public static function bookingPayments(int $bookingId): array
    {
        return array_map([self::class, 'mapPayment'], Db::all('SELECT * FROM pos_payments WHERE booking_id = ? ORDER BY created_at, id', [$bookingId]));
    }

    /** Record an advance payment (a deposit, part payment…) on an open room bill. The folio gets 'paymentId' (for its receipt). */
    public static function addPayment(mixed $bookingId, mixed $in, string $by): array
    {
        $p = self::paymentInput($in);
        [$id, $pid] = Db::transaction(function () use ($bookingId, $p, $by) {
            $b = self::lockOpen($bookingId);
            $pid = self::insertPayment($b['id'], null, $p, self::now(), $by);
            self::log($b['id'], 'payment', self::paymentText($p) . ' (' . self::receiptNo($pid) . ')', $p['amount'], $by);
            return [$b['id'], $pid];
        });
        return self::folio($id) + ['paymentId' => $pid];
    }

    public static function voidPayment(mixed $paymentId, string $by): array
    {
        $p = Db::one('SELECT * FROM pos_payments WHERE id = ?', [self::idOf($paymentId)]);
        if (!$p) throw new BookingError('Payment not found', 404, 'not_found');
        Db::transaction(function () use ($p, $by) {
            $b = self::lockOpen((int) $p['booking_id']);
            $p = Db::one('SELECT * FROM pos_payments WHERE id = ?', [(int) $p['id']]);
            if ($p['bill_id'] !== null) throw new BookingError('That payment is on an issued bill. Void the bill first.', 409, 'closed');
            if ($p['voided_at'] !== null) throw new BookingError('That payment was already cancelled.', 409, 'voided');
            Db::run('UPDATE pos_payments SET voided_at = ?, voided_by = ? WHERE id = ?', [self::now(), $by, (int) $p['id']]);
            Db::run('UPDATE booking_slips SET payment_id = NULL WHERE payment_id = ?', [(int) $p['id']]); // the guest's slip can go on the corrected payment
            self::log($b['id'], 'payment_void', 'Cancelled ' . lcfirst(self::paymentText(['method' => $p['method'], 'note' => $p['note']])), -(int) $p['amount'], $by);
        });
        return self::folio((int) $p['booking_id']);
    }

    // ---------- check-in ----------
    /** Bookings to check in: expected today or earlier and not here yet, then the next two weeks (or a search). */
    public static function arrivals(mixed $q = null): array
    {
        $t = Bookings::today();
        $opts = ['status' => 'arriving', 'limit' => 200];
        if (is_string($q) && Js::trim($q) !== '') $opts['q'] = $q;
        else $opts += ['from' => $t, 'to' => Bookings::addDays($t, 15)];
        return array_values(array_filter(Bookings::listBookings($opts), fn ($b) => $b['source'] !== 'block'));
    }

    /**
     * Check a guest in: a per-person group's rooms can be changed, a room booking's guests
     * noted, and an advance taken. A pending booking is confirmed by checking it in.
     */
    public static function checkIn(mixed $bookingId, array $in, string $by): array
    {
        $b = self::booking($bookingId);
        if ($b['source'] === 'block') throw new BookingError('Blocked dates cannot be checked in.');
        if ($b['status'] === 'checked_in') throw new BookingError("{$b['guestName']} is already checked in.", 409, 'status');
        if (!in_array($b['status'], ['pending', 'confirmed'], true)) throw new BookingError("{$b['ref']} is " . str_replace('_', ' ', $b['status']) . '.', 409, 'status');
        $advance = !empty($in['advance']) && Js::truthy($in['advance']['amount'] ?? null) ? self::paymentInput($in['advance']) : null;
        if ($b['kind'] === 'per_person' && !empty($in['rooms'])) Bookings::assignRooms($b['id'], $in['rooms']);
        $patch = ['status' => 'checked_in'];
        foreach (['adults', 'children'] as $k) if (array_key_exists($k, $in) && $in[$k] !== '' && $in[$k] !== null) $patch[$k] = $in[$k];
        if (array_key_exists('guestIdNo', $in)) $patch['guestIdNo'] = $in['guestIdNo']; // checked against their NIC / passport at the desk
        $b = Bookings::updateBooking($b['id'], $patch);
        self::log($b['id'], 'checkin', 'Checked in' . ($b['kind'] !== 'day_out' && $b['roomName'] ? ': ' . $b['roomName'] : ''), null, $by);
        if ($advance) return self::addPayment($b['id'], $in['advance'], $by); // checked above; the raw input keeps its field keys
        return self::folio($b['id']);
    }

    /**
     * A guest who arrives without a booking: staff enter their details at the POS, a booking is
     * made (source "Walk-in", checked in straight away) and a payment can be taken.
     * $in: what an admin booking takes (kind, roomId / packageId, checkIn, checkOut, adults,
     * children, rooms, partyArea, jblSpeaker, totalAmount, guest details) + payment {amount, method, note}.
     */
    public static function walkIn(array $in, string $by): array
    {
        // check the payment before anything is saved
        $payment = !empty($in['payment']) && Js::truthy($in['payment']['amount'] ?? null) ? self::paymentInput($in['payment']) : null;
        if (Js::trim(Js::str($in['guestName'] ?? '')) === '') throw new BookingError('Please enter the guest name.');
        $b = Bookings::createBooking(['source' => 'walk_in', 'status' => 'checked_in'] + $in, true, $by);
        self::log($b['id'], 'checkin', 'Walk-in, checked in' . ($b['kind'] !== 'day_out' && $b['roomName'] ? ': ' . $b['roomName'] : ''), null, $by);
        if ($payment) return self::addPayment($b['id'], $in['payment'], $by); // checked above; the raw input keeps its field keys
        return self::folio($b['id']);
    }

    /**
     * Open room bills: guests checked in, guests arriving today (check-in date today — not
     * stays that began earlier and were never checked in), and any bill with items or
     * payments that has not been issued yet. state: in_house | expected | checked_out.
     */
    public static function openFolios(): array
    {
        $t = Bookings::today();
        $rows = Db::all("SELECT b.id,
              (SELECT COALESCE(SUM(c.amount), 0) FROM pos_charges c WHERE c.booking_id = b.id AND c.voided_at IS NULL) AS items,
              (SELECT COALESCE(SUM(c.qty), 0) FROM pos_charges c WHERE c.booking_id = b.id AND c.voided_at IS NULL) AS item_count,
              (SELECT COALESCE(SUM(p.amount), 0) FROM pos_payments p WHERE p.booking_id = b.id AND p.bill_id IS NULL AND p.voided_at IS NULL) AS paid
            FROM bookings b
            WHERE b.bill_id IS NULL AND b.source != 'block' AND b.status IN ('confirmed', 'checked_in', 'checked_out')
              AND (b.status = 'checked_in'
                OR (b.status = 'confirmed' AND b.check_in = ?)
                OR EXISTS (SELECT 1 FROM pos_charges c WHERE c.booking_id = b.id AND c.voided_at IS NULL)
                OR (b.status = 'checked_out' AND EXISTS (SELECT 1 FROM pos_payments p WHERE p.booking_id = b.id AND p.bill_id IS NULL AND p.voided_at IS NULL)))
            ORDER BY b.check_in, b.id", [$t]);
        $pct = self::servicePct();
        return array_map(function ($r) use ($pct) {
            $b = Bookings::getBooking((int) $r['id']);
            $stay = self::stayAmount($b);
            $items = (int) $r['items'];
            $paid = (int) $r['paid'];
            $service = self::serviceOn($stay + $items, $pct);
            return [
                'booking' => $b,
                'state' => $b['status'] === 'checked_in' ? 'in_house' : ($b['status'] === 'checked_out' ? 'checked_out' : 'expected'),
                'stay' => $stay, 'items' => $items, 'itemCount' => (int) $r['item_count'], 'paid' => $paid,
                'service' => $service, 'total' => $stay + $items + $service, 'balance' => $stay + $items + $service - $paid,
            ];
        }, $rows);
    }

    // ---------- bills ----------
    /**
     * Check out: close one or more room bills into a single bill (the first booking is the
     * guest it is made out to), add any last custom lines, take a discount (an amount, or a
     * percentage of the bill), add the service charge on what is left, take the payments, and
     * check the guests out.
     * $in: discount (number | {type: amount|percent, value}), lines [{name, amount, categoryId?}], payments, note,
     * serviceCharge (default true: add the service charge from Booking setup).
     */
    public static function checkout(mixed $bookingIds, array $in, string $by): array
    {
        if (!is_array($bookingIds) || !$bookingIds || !Js::isList($bookingIds) || count($bookingIds) > 20) throw new BookingError('Choose the room bill to check out.');
        $ids = [];
        foreach ($bookingIds as $v) {
            $id = self::idOf($v);
            if (!$id) throw new BookingError('Invalid room bill.');
            $ids[$id] = true;
        }
        $ids = array_keys($ids);
        $disc = $in['discount'] ?? 0;
        $discType = is_array($disc) && ($disc['type'] ?? '') === 'percent' ? 'percent' : 'amount';
        $discValue = is_array($disc) ? ($disc['value'] ?? 0) : $disc;
        $discValue = Bookings::int($discValue === '' || $discValue === null ? 0 : $discValue, 0, $discType === 'percent' ? 100 : self::MAX_AMOUNT);
        if ($discValue === null) throw new BookingError($discType === 'percent' ? 'The discount must be a percentage from 0 to 100.' : 'The discount must be a whole number of 0 or more.');
        $extraIn = $in['lines'] ?? [];
        if (!is_array($extraIn) || !Js::isList($extraIn) || count($extraIn) > 30) throw new BookingError('Invalid extra lines.');
        $extra = array_map(fn ($l) => self::chargeLine(is_array($l) ? ['name' => $l['name'] ?? null, 'price' => $l['amount'] ?? null, 'categoryId' => $l['categoryId'] ?? null, 'qty' => $l['qty'] ?? 1] : null), $extraIn);
        $payIn = $in['payments'] ?? [];
        if (!is_array($payIn) || !Js::isList($payIn) || count($payIn) > 10) throw new BookingError('Invalid payments.');
        $pays = array_map([self::class, 'paymentInput'], $payIn);
        $note = Bookings::str($in['note'] ?? null, 1000) ?? '';
        $servicePct = !array_key_exists('serviceCharge', $in) || Js::truthy($in['serviceCharge']) ? self::servicePct() : 0;
        $couponCode = Coupons::normalize($in['coupon'] ?? '');

        $billId = Db::transaction(function () use ($ids, $discType, $discValue, $extra, $pays, $note, $servicePct, $couponCode, $by) {
            $sorted = $ids;
            sort($sorted);
            foreach ($sorted as $id) Db::lockBooking($id);
            $bookings = [];
            foreach ($ids as $id) {
                $b = self::booking($id);
                self::requireOpen($b);
                $bookings[$id] = $b;
            }
            $primary = $bookings[$ids[0]];
            $now = self::now();
            foreach ($extra as $r) self::insertCharge($primary['id'], $r, $now, $by);
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $charges = array_map([self::class, 'mapCharge'], Db::all(self::CHARGE_SELECT . " WHERE c.booking_id IN ($marks) AND c.voided_at IS NULL ORDER BY c.created_at, c.id", $ids));
            $stay = array_sum(array_map([self::class, 'stayAmount'], $bookings));
            $items = array_sum(array_column($charges, 'amount'));
            $discount = $discType === 'percent' ? (int) round(($stay + $items) * $discValue / 100) : $discValue;
            // a coupon comes off the room charge only, of stays that didn't use one when booking
            $coupon = null;
            if ($couponCode !== '') {
                $base = self::couponBase($bookings);
                if ($base <= 0) throw new BookingError('These stays already used a coupon when they were booked, so another one can\'t be added.', 400, 'coupon');
                $coupon = Coupons::check($couponCode, $base, true);
            }
            $couponDiscount = $coupon ? $coupon['discount'] : 0;
            if ($discount + $couponDiscount > $stay + $items) throw new BookingError('The discount is more than the bill.');
            $service = self::serviceOn($stay + $items - $discount - $couponDiscount, $servicePct);
            $total = $stay + $items - $discount - $couponDiscount + $service;

            $billId = Db::run('INSERT INTO bills (bill_no, status, booking_id, guest_name, stay_total, items_total, discount, coupon_code, coupon_discount, total, snapshot, note, created_at, created_by) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                ['issued', $primary['id'], $primary['guestName'], $stay, $items, $discount, $coupon ? $coupon['code'] : '', $couponDiscount, $total, '{}', $note, $now, $by]);
            $billNo = self::billNo($billId);
            // advances already paid on these room bills now count towards this bill
            Db::run("UPDATE pos_payments SET bill_id = ? WHERE booking_id IN ($marks) AND bill_id IS NULL AND voided_at IS NULL", array_merge([$billId], $ids));
            foreach ($pays as $p) self::insertPayment($primary['id'], $billId, $p, $now, $by);
            Db::run("UPDATE bookings SET bill_id = ?, status = 'checked_out', updated_at = ? WHERE id IN ($marks)", array_merge([$billId, $now], $ids));
            $label = $discType === 'percent' && $discValue ? "$discValue%" : '';
            Db::run('UPDATE bills SET bill_no = ?, snapshot = ? WHERE id = ?', [$billNo, Js::json(self::snapshot($billNo, $bookings, $charges, $discount, $label, $service, $service ? $servicePct : 0, $now, $by, $coupon)), $billId]);

            $others = count($ids) > 1 ? ' with ' . implode(', ', array_map(fn ($b) => $b['place'], array_slice(array_values($bookings), 1))) : '';
            foreach ($ids as $id) self::log($id, 'bill', "Checked out on bill $billNo" . ($id === $primary['id'] ? $others : ' (billed with ' . self::labelOf($primary) . ')'), $id === $primary['id'] ? $total : null, $by);
            foreach ($pays as $p) self::log($primary['id'], 'payment', self::paymentText($p, " on $billNo"), $p['amount'], $by);
            return $billId;
        });
        return self::getBill($billId);
    }

    /** The room charge of these stays a coupon can come off (stays that used no coupon when booking). */
    private static function couponBase(array $bookings): int
    {
        return array_sum(array_map(fn ($b) => $b['couponCode'] === '' ? self::stayAmount($b) : 0, $bookings));
    }

    /** What a coupon would take off these room bills at checkout: ['code', 'label', 'discount', 'base']. */
    public static function couponPreview(mixed $code, mixed $bookingIds): array
    {
        $ids = is_string($bookingIds) ? array_filter(array_map('intval', explode(',', $bookingIds))) : [];
        if (!$ids || count($ids) > 20) throw new BookingError('Choose the room bill first.');
        $bookings = array_map(fn ($id) => self::booking($id), $ids);
        $base = self::couponBase($bookings);
        if ($base <= 0) throw new BookingError('These stays already used a coupon when they were booked, so another one can\'t be added.', 400, 'coupon');
        $c = Coupons::check($code, $base);
        return ['code' => $c['code'], 'label' => $c['label'], 'discount' => $c['discount'], 'base' => $base];
    }

    /** The bill exactly as issued, so it reads the same however the bookings change later. */
    private static function snapshot(string $billNo, array $bookings, array $charges, int $discount, string $discountLabel, int $service, int $servicePct, string $now, string $by, ?array $coupon = null): array
    {
        $site = Site::info(); // the contact details as set in Admin → Website
        $first = reset($bookings);
        $lines = self::groupLines($charges);
        $stay = array_sum(array_map([self::class, 'stayAmount'], $bookings));
        $items = array_sum(array_column($lines, 'amount'));
        $couponDiscount = $coupon ? $coupon['discount'] : 0;
        return [
            'version' => 3,
            'billNo' => $billNo,
            'issuedAt' => Db::fromDbTime($now),
            'issuedBy' => Auth::nameOf($by),
            'currency' => $site['currency'],
            'resort' => ['name' => $site['name'], 'address' => $site['address'], 'phone' => $site['phone'], 'email' => $site['email']],
            'guest' => ['name' => $first['guestName'], 'phone' => $first['guestPhone'], 'email' => $first['guestEmail'], 'country' => $first['country'], 'idNo' => $first['guestIdNo']],
            'stays' => array_values(array_map(fn ($b) => [
                'bookingId' => $b['id'], 'ref' => $b['ref'], 'kind' => $b['kind'], 'package' => $b['kind'], 'packageLabel' => $b['packageLabel'],
                'place' => $b['place'], 'rooms' => array_column($b['rooms'], 'name'), 'guestName' => $b['guestName'],
                'checkIn' => $b['checkIn'], 'checkOut' => $b['checkOut'], 'nights' => $b['nights'], 'adults' => $b['adults'], 'children' => $b['children'],
                'extras' => array_values(array_filter([$b['partyArea'] ? 'Party area' : '', $b['jblSpeaker'] ? 'JBL speaker' : ''])),
                // a coupon used when booking is already off the amount
                'couponCode' => $b['couponCode'], 'couponDiscount' => $b['couponDiscount'],
                'amount' => self::stayAmount($b),
            ], $bookings)),
            'lines' => array_map(fn ($l) => ['category' => $l['category'], 'name' => $l['name'], 'qty' => $l['qty'], 'unitPrice' => $l['unitPrice'], 'amount' => $l['amount']], $lines),
            'totals' => ['stay' => $stay, 'categories' => self::categoryTotals($lines), 'items' => $items, 'subtotal' => $stay + $items,
                'discount' => $discount, 'discountLabel' => $discountLabel,
                'coupon' => $coupon ? ['code' => $coupon['code'], 'label' => $coupon['label'], 'discount' => $couponDiscount] : null,
                'servicePct' => $servicePct, 'service' => $service,
                'total' => $stay + $items - $discount - $couponDiscount + $service],
        ];
    }

    private const BILL_SELECT = 'SELECT bl.*,
        (SELECT COALESCE(SUM(p.amount), 0) FROM pos_payments p WHERE p.bill_id = bl.id AND p.voided_at IS NULL) AS paid
        FROM bills bl';

    private static function mapBill(array $r, bool $full = false): array
    {
        $snap = json_decode((string) $r['snapshot'], true) ?: [];
        $out = [
            'id' => (int) $r['id'],
            'billNo' => $r['bill_no'],
            'status' => $r['status'],
            'bookingId' => (int) $r['booking_id'],
            'guestName' => $r['guest_name'],
            'places' => array_column($snap['stays'] ?? [], 'place'),
            'refs' => array_column($snap['stays'] ?? [], 'ref'),
            'stayTotal' => (int) $r['stay_total'],
            'itemsTotal' => (int) $r['items_total'],
            'discount' => (int) $r['discount'],
            'couponCode' => (string) ($r['coupon_code'] ?? ''),
            'couponDiscount' => (int) ($r['coupon_discount'] ?? 0),
            // the rest of the total is the service charge (bills before it existed have none)
            'serviceCharge' => (int) $r['total'] - (int) $r['stay_total'] - (int) $r['items_total'] + (int) $r['discount'] + (int) ($r['coupon_discount'] ?? 0),
            'total' => (int) $r['total'],
            'paid' => (int) $r['paid'],
            'balance' => (int) $r['total'] - (int) $r['paid'],
            'note' => $r['note'],
            'createdAt' => Db::fromDbTime($r['created_at']),
            'createdBy' => $r['created_by'],
            'createdByName' => Auth::nameOf((string) $r['created_by']),
            'voidedAt' => Db::fromDbTime($r['voided_at']),
            'voidedBy' => $r['voided_by'],
            'voidReason' => $r['void_reason'],
        ];
        if ($full) {
            $out['billUrl'] = Docs::billUrl((int) $r['id']); // the printable bill (same page and print settings as invoices and receipts)
            $out['serviceShares'] = Finance::sharesOf((int) $r['id']); // who shared its service charge
            $out['snapshot'] = $snap;
            $out['payments'] = array_map([self::class, 'mapPayment'], Db::all('SELECT * FROM pos_payments WHERE bill_id = ? AND voided_at IS NULL ORDER BY created_at, id', [(int) $r['id']]));
        }
        return $out;
    }

    public static function getBill(mixed $id): ?array
    {
        $r = Db::one(self::BILL_SELECT . ' WHERE bl.id = ?', [self::idOf($id)]);
        return $r ? self::mapBill($r, true) : null;
    }

    /** Midnight at the resort on a YYYY-MM-DD date, as a stored (UTC) timestamp. */
    public static function localDayStart(string $date): string
    {
        $d = new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone(Config::get()['timezone']));
        return Db::toDbTime($d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'));
    }

    /** Bill history. $opts: q (bill no, guest, booking ref, room), from / to (resort dates), status (issued|void|due). */
    public static function listBills(array $opts = []): array
    {
        $where = [];
        $vals = [];
        $status = $opts['status'] ?? null;
        $paid = '(SELECT COALESCE(SUM(p.amount), 0) FROM pos_payments p WHERE p.bill_id = bl.id AND p.voided_at IS NULL)';
        if ($status === 'issued' || $status === 'void') { $where[] = 'bl.status = ?'; $vals[] = $status; }
        if ($status === 'due') $where[] = "bl.status = 'issued' AND bl.total > $paid";
        if (Bookings::isDate($opts['from'] ?? null)) { $where[] = 'bl.created_at >= ?'; $vals[] = self::localDayStart($opts['from']); }
        if (Bookings::isDate($opts['to'] ?? null)) { $where[] = 'bl.created_at < ?'; $vals[] = self::localDayStart(Bookings::addDays($opts['to'], 1)); }
        $q = $opts['q'] ?? null;
        if (is_string($q) && Js::trim($q) !== '') {
            $where[] = '(bl.bill_no LIKE ? OR bl.guest_name LIKE ? OR bl.snapshot LIKE ?)';
            $like = '%' . Js::slice(Js::trim($q), 80) . '%';
            array_push($vals, $like, $like, $like);
        }
        $sql = self::BILL_SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY bl.id DESC LIMIT 1000';
        return array_map(fn ($r) => self::mapBill($r), Db::all($sql, $vals));
    }

    /** A payment received after checkout (a balance settled later). The bill gets 'paymentId' (for its receipt). */
    public static function addBillPayment(mixed $billId, mixed $in, string $by): array
    {
        $p = self::paymentInput($in);
        $id = self::idOf($billId);
        $pid = Db::transaction(function () use ($id, $p, $by) {
            Db::lockBill($id);
            $bill = Db::one('SELECT * FROM bills WHERE id = ?', [$id]);
            if (!$bill) throw new BookingError('Bill not found', 404, 'not_found');
            if ($bill['status'] !== 'issued') throw new BookingError('This bill is void.', 409, 'void');
            $pid = self::insertPayment((int) $bill['booking_id'], $id, $p, self::now(), $by);
            self::log((int) $bill['booking_id'], 'payment', self::paymentText($p, " on {$bill['bill_no']}") . ' (' . self::receiptNo($pid) . ')', $p['amount'], $by);
            return $pid;
        });
        return self::getBill($id) + ['paymentId' => $pid];
    }

    /**
     * Void an issued bill: it stays in the history marked VOID, its room bills open again
     * (guests back to checked in) and its payments return to the main room bill.
     */
    public static function voidBill(mixed $billId, mixed $reason, string $by): array
    {
        $reason = Bookings::str($reason, 255, true);
        if ($reason === null) throw new BookingError('Please say why the bill is being voided.');
        $id = self::idOf($billId);
        Db::transaction(function () use ($id, $reason, $by) {
            Db::lockBill($id);
            $bill = Db::one('SELECT * FROM bills WHERE id = ?', [$id]);
            if (!$bill) throw new BookingError('Bill not found', 404, 'not_found');
            if ($bill['status'] === 'void') throw new BookingError('This bill is already void.', 409, 'void');
            $ids = array_map('intval', array_column(Db::all('SELECT id FROM bookings WHERE bill_id = ? ORDER BY id', [$id]), 'id'));
            foreach ($ids as $bid) Db::lockBooking($bid);
            $now = self::now();
            Db::run("UPDATE bills SET status = 'void', voided_at = ?, voided_by = ?, void_reason = ? WHERE id = ?", [$now, $by, $reason, $id]);
            Db::run('UPDATE pos_payments SET bill_id = NULL WHERE bill_id = ?', [$id]);
            Db::run("UPDATE bookings SET bill_id = NULL, status = 'checked_in', updated_at = ? WHERE bill_id = ?", [$now, $id]);
            foreach ($ids as $bid) self::log($bid, 'bill_void', "Bill {$bill['bill_no']} voided: $reason. The room bill is open again.", null, $by);
        });
        return self::getBill($id);
    }

    /** Is anything still charged to this booking? (used before cancelling it) */
    public static function hasCharges(int $bookingId): bool
    {
        return (bool) Db::one('SELECT 1 FROM pos_charges WHERE booking_id = ? AND voided_at IS NULL', [$bookingId]);
    }
}
