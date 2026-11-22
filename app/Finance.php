<?php
// Admin → Finance: expenses, staff and their pay, the service charge shared among the staff who
// served, money paid into each bank account, stock, commissions, room and food figures, and a
// summary for any period (printable, and as a PDF). Amounts are whole rupees, like the rest of
// the site. Dates are the resort's days (YYYY-MM-DD).
declare(strict_types=1);

namespace Ydr;

final class Finance
{
    public const EXPENSE_CATEGORIES = ['Food & drink supplies', 'Housekeeping supplies', 'Utilities (power, water, gas)', 'Repairs & maintenance',
        'Transport & fuel', 'Commission', 'Marketing', 'Staff food & welfare', 'Licences & fees', 'Other'];
    public const PAY_KINDS = ['salary' => 'Salary', 'service' => 'Service charge', 'advance' => 'Advance', 'bonus' => 'Bonus / other'];

    // ---------- small helpers ----------
    private static function fail(string $msg, int $status = 400): never
    {
        throw new BookingError($msg, $status);
    }

    private static function now(): string
    {
        return Db::toDbTime(Js::isoNow());
    }

    private static function text(mixed $v, int $max, string $what = '', bool $required = false): string
    {
        $s = Js::trim(Js::str($v ?? ''));
        if ($required && $s === '') self::fail("Please enter the $what.");
        if (Js::length($s) > $max) self::fail(ucfirst($what ?: 'text') . " can be up to $max characters.");
        return $s;
    }

    private static function amount(mixed $v, string $what = 'amount', bool $zeroOk = false): int
    {
        $n = Bookings::int($v, $zeroOk ? 0 : 1, 100000000);
        if ($n === null) self::fail('The ' . $what . ' must be a whole number' . ($zeroOk ? ' of 0 or more.' : ' more than 0.'));
        return $n;
    }

    private static function day(mixed $v, string $what = 'date'): string
    {
        if (!Bookings::isDate($v)) self::fail("Please choose the $what.");
        return (string) $v;
    }

    private static function qty(mixed $v): float
    {
        $n = is_numeric($v) ? round((float) $v, 2) : NAN;
        if (is_nan($n) || $n == 0 || abs($n) > 1000000) self::fail('Enter how many (not 0).');
        return $n;
    }

    /** "2.5", "3" — quantities without trailing zeros. */
    public static function qtyText(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /** [from, to] inclusive; default: this month so far is the whole month. */
    public static function period(mixed $from, mixed $to): array
    {
        $t = Bookings::today();
        $from = Bookings::isDate($from) ? (string) $from : substr($t, 0, 8) . '01';
        $to = Bookings::isDate($to) ? (string) $to : date('Y-m-t', strtotime($from . ' 00:00:00 UTC'));
        if (strcmp($to, $from) < 0) [$from, $to] = [$to, $from];
        if (Bookings::nightsBetween($from, $to) > 366) self::fail('Choose a period of up to a year.');
        return ['from' => $from, 'to' => $to, 'days' => Bookings::nightsBetween($from, $to) + 1];
    }

    /** created_at bounds for a period (stored as UTC times). */
    private static function timeBounds(array $p): array
    {
        return [Pos::localDayStart($p['from']), Pos::localDayStart(Bookings::addDays($p['to'], 1))];
    }

    // ---------- accounts: where money is kept ----------
    /** The label of the payment field that says where money went ("Received into"), or ''. */
    public static function accountField(): array
    {
        foreach (Pos::paymentFields() as $f) if ($f['type'] === 'choice') return $f;
        return ['key' => '', 'label' => '', 'options' => []];
    }

    /** Account names to choose from: the "Received into" choices, then any others already used. */
    public static function accounts(): array
    {
        $list = self::accountField()['options'];
        foreach (['expenses' => 'account', 'bank_moves' => 'account', 'staff_payments' => 'account'] as $t => $c) {
            foreach (Db::all("SELECT DISTINCT $c AS a FROM $t WHERE $c <> ''") as $r) if (!in_array($r['a'], $list, true)) $list[] = (string) $r['a'];
        }
        return $list;
    }

    // ---------- employees ----------
    private static function mapEmployee(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'name' => $r['name'], 'position' => $r['position'], 'phone' => $r['phone'], 'idNo' => $r['id_no'],
            'joinedOn' => $r['joined_on'] ?: null, 'salary' => (int) $r['salary'], 'serviceShare' => (bool) (int) $r['service_share'],
            'active' => (bool) (int) $r['active'], 'note' => $r['note'],
        ];
    }

    public static function employees(bool $activeOnly = false): array
    {
        return array_map([self::class, 'mapEmployee'], Db::all('SELECT * FROM employees' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY active DESC, name'));
    }

    public static function employee(int $id): ?array
    {
        $r = Db::one('SELECT * FROM employees WHERE id = ?', [$id]);
        return $r ? self::mapEmployee($r) : null;
    }

    /** {name, position, phone, idNo, joinedOn, salary, serviceShare, active, note} */
    public static function saveEmployee(?int $id, array $in): array
    {
        $cur = $id ? self::employee($id) : null;
        if ($id && !$cur) self::fail('Employee not found.', 404);
        $v = fn (string $k, $d) => array_key_exists($k, $in) ? $in[$k] : $d;
        $row = [
            'name' => self::text($v('name', $cur['name'] ?? ''), 120, 'name', true),
            'position' => self::text($v('position', $cur['position'] ?? ''), 80, 'position'),
            'phone' => self::text($v('phone', $cur['phone'] ?? ''), 40, 'phone'),
            'id_no' => self::text($v('idNo', $cur['idNo'] ?? ''), 30, 'NIC'),
            'joined_on' => ($j = $v('joinedOn', $cur['joinedOn'] ?? null)) === null || $j === '' ? null : self::day($j, 'joining date'),
            'salary' => self::amount($v('salary', $cur['salary'] ?? 0), 'salary', true),
            'service_share' => Js::truthy($v('serviceShare', $cur['serviceShare'] ?? true)) ? 1 : 0,
            'active' => Js::truthy($v('active', $cur['active'] ?? true)) ? 1 : 0,
            'note' => self::text($v('note', $cur['note'] ?? ''), 255, 'note'),
        ];
        if ($id) {
            Db::run('UPDATE employees SET ' . implode(', ', array_map(fn ($c) => "$c = ?", array_keys($row))) . ' WHERE id = ?', [...array_values($row), $id]);
        } else {
            $id = Db::run('INSERT INTO employees (' . implode(', ', array_keys($row)) . ', created_at) VALUES (' . implode(', ', array_fill(0, count($row) + 1, '?')) . ')', [...array_values($row), self::now()]);
        }
        return self::employee($id);
    }

    // ---------- paying staff ----------
    private static function mapStaffPayment(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'employeeId' => (int) $r['employee_id'], 'employee' => $r['employee'] ?? '', 'kind' => $r['kind'],
            'kindLabel' => self::PAY_KINDS[$r['kind']] ?? $r['kind'], 'period' => $r['period'], 'amount' => (int) $r['amount'],
            'paidOn' => $r['paid_on'], 'account' => $r['account'], 'note' => $r['note'], 'createdBy' => $r['created_by'],
        ];
    }

    /** opts: from, to (paid on), employeeId, kind */
    public static function staffPayments(array $opts = []): array
    {
        $where = [];
        $vals = [];
        if (Bookings::isDate($opts['from'] ?? null)) { $where[] = 'sp.paid_on >= ?'; $vals[] = $opts['from']; }
        if (Bookings::isDate($opts['to'] ?? null)) { $where[] = 'sp.paid_on <= ?'; $vals[] = $opts['to']; }
        if (!empty($opts['employeeId'])) { $where[] = 'sp.employee_id = ?'; $vals[] = (int) $opts['employeeId']; }
        if (!empty($opts['kind'])) { $where[] = 'sp.kind = ?'; $vals[] = (string) $opts['kind']; }
        $sql = 'SELECT sp.*, e.name AS employee FROM staff_payments sp JOIN employees e ON e.id = sp.employee_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY sp.paid_on DESC, sp.id DESC';
        return array_map([self::class, 'mapStaffPayment'], Db::all($sql, $vals));
    }

    /** {employeeId, kind, amount, paidOn, period (YYYY-MM, salary), account, note} */
    public static function addStaffPayment(array $in, string $by): array
    {
        $emp = self::employee((int) Js::number($in['employeeId'] ?? 0));
        if (!$emp) self::fail('Choose who is paid.');
        $kind = (string) ($in['kind'] ?? '');
        if (!isset(self::PAY_KINDS[$kind])) self::fail('Choose what the payment is for.');
        $amount = self::amount($in['amount'] ?? null);
        $paidOn = self::day($in['paidOn'] ?? Bookings::today(), 'payment date');
        $period = '';
        if ($kind === 'salary') {
            $period = (string) ($in['period'] ?? substr($paidOn, 0, 7));
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) self::fail('Choose the month the salary is for.');
        }
        $id = Db::run('INSERT INTO staff_payments (employee_id, kind, period, amount, paid_on, account, note, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$emp['id'], $kind, $period, $amount, $paidOn, self::text($in['account'] ?? '', 120, 'account'), self::text($in['note'] ?? '', 255, 'note'), self::now(), $by]);
        return self::mapStaffPayment(Db::one('SELECT sp.*, e.name AS employee FROM staff_payments sp JOIN employees e ON e.id = sp.employee_id WHERE sp.id = ?', [$id]));
    }

    public static function deleteStaffPayment(int $id): void
    {
        if (!Db::one('SELECT id FROM staff_payments WHERE id = ?', [$id])) self::fail('Payment not found.', 404);
        Db::run('DELETE FROM staff_payments WHERE id = ?', [$id]);
    }

    /** Salaries for a month (YYYY-MM): each active employee with a salary, and what was paid for that month. */
    public static function salaryMonth(string $month): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = substr(Bookings::today(), 0, 7);
        $paid = [];
        foreach (Db::all("SELECT employee_id, SUM(amount) AS amount FROM staff_payments WHERE kind = 'salary' AND period = ? GROUP BY employee_id", [$month]) as $r) $paid[(int) $r['employee_id']] = (int) $r['amount'];
        $rows = [];
        foreach (self::employees() as $e) {
            if ((!$e['active'] || !$e['salary']) && !isset($paid[$e['id']])) continue;
            $rows[] = ['employee' => $e, 'salary' => $e['salary'], 'paid' => $paid[$e['id']] ?? 0, 'due' => max(0, $e['salary'] - ($paid[$e['id']] ?? 0))];
        }
        return ['month' => $month, 'rows' => $rows, 'salary' => array_sum(array_column($rows, 'salary')), 'paid' => array_sum(array_column($rows, 'paid')), 'due' => array_sum(array_column($rows, 'due'))];
    }

    // ---------- the service charge, shared among the staff who served ----------
    private const BILL_SERVICE = '(bl.total - bl.stay_total - bl.items_total + bl.discount + bl.coupon_discount)';

    /** Who shares a bill's service charge: [{employeeId, name, amount}] */
    public static function sharesOf(int $billId): array
    {
        return array_map(fn ($r) => ['employeeId' => (int) $r['employee_id'], 'name' => $r['name'], 'amount' => (int) $r['amount']],
            Db::all('SELECT s.employee_id, s.amount, e.name FROM service_shares s JOIN employees e ON e.id = s.employee_id WHERE s.bill_id = ? ORDER BY e.name', [$billId]));
    }

    /** Staff who can share the service charge (for the POS window). */
    public static function serviceStaff(): array
    {
        return array_map(fn ($e) => ['id' => $e['id'], 'name' => $e['name'], 'position' => $e['position']],
            array_values(array_filter(self::employees(true), fn ($e) => $e['serviceShare'])));
    }

    /**
     * Share a bill's service charge among the staff who served: equally, the odd rupees to the
     * first ones — or the amounts given ({employeeId: amount}), which must add up to it.
     * An empty list clears the shares.
     */
    public static function setShares(int $billId, mixed $employeeIds, mixed $amounts, string $by): array
    {
        return Db::transaction(function () use ($billId, $employeeIds, $amounts, $by) {
            Db::lockBill($billId);
            $bill = Pos::getBill($billId);
            if (!$bill) self::fail('Bill not found.', 404);
            if ($bill['status'] !== 'issued') self::fail('This bill is void, so it has no service charge to share.', 409);
            $service = (int) $bill['serviceCharge'];
            $ids = [];
            foreach (is_array($employeeIds) ? $employeeIds : [] as $v) {
                $id = (int) Js::number($v);
                if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
            }
            if ($ids && $service <= 0) self::fail('This bill has no service charge.');
            foreach ($ids as $id) if (!self::employee($id)) self::fail('Choose staff from the list.');
            $split = [];
            if ($ids && is_array($amounts) && $amounts) {
                foreach ($ids as $id) $split[$id] = self::amount($amounts[$id] ?? $amounts[(string) $id] ?? 0, 'share', true);
                if (array_sum($split) !== $service) self::fail('The shares must add up to the service charge of ' . Site::info()['currency'] . ' ' . number_format($service) . '.');
            } elseif ($ids) {
                $each = intdiv($service, count($ids));
                $odd = $service - $each * count($ids);
                foreach ($ids as $i => $id) $split[$id] = $each + ($i < $odd ? 1 : 0);
            }
            Db::run('DELETE FROM service_shares WHERE bill_id = ?', [$billId]);
            $now = self::now();
            foreach ($split as $id => $amt) Db::run('INSERT INTO service_shares (bill_id, employee_id, amount, created_at, created_by) VALUES (?, ?, ?, ?, ?)', [$billId, $id, $amt, $now, $by]);
            return ['billId' => $billId, 'service' => $service, 'shares' => self::sharesOf($billId)];
        });
    }

    /** Bills in a period with a service charge, and how each was shared. */
    public static function serviceBills(array $p): array
    {
        [$a, $b] = self::timeBounds($p);
        $rows = Db::all('SELECT bl.id, bl.bill_no, bl.guest_name, bl.created_at, ' . self::BILL_SERVICE . " AS service FROM bills bl
            WHERE bl.status = 'issued' AND bl.created_at >= ? AND bl.created_at < ? AND " . self::BILL_SERVICE . ' > 0 ORDER BY bl.created_at DESC', [$a, $b]);
        return array_map(fn ($r) => ['id' => (int) $r['id'], 'billNo' => $r['bill_no'], 'guestName' => $r['guest_name'], 'createdAt' => Db::fromDbTime($r['created_at']),
            'service' => (int) $r['service'], 'shares' => self::sharesOf((int) $r['id'])], $rows);
    }

    /** What each employee earned from service charges and was paid: all time, and in the period. */
    public static function serviceBalances(array $p): array
    {
        [$a, $b] = self::timeBounds($p);
        $earned = $earnedP = $paid = $paidP = [];
        foreach (Db::all("SELECT s.employee_id, SUM(s.amount) AS amount FROM service_shares s JOIN bills bl ON bl.id = s.bill_id WHERE bl.status = 'issued' GROUP BY s.employee_id") as $r) $earned[(int) $r['employee_id']] = (int) $r['amount'];
        foreach (Db::all("SELECT s.employee_id, SUM(s.amount) AS amount FROM service_shares s JOIN bills bl ON bl.id = s.bill_id WHERE bl.status = 'issued' AND bl.created_at >= ? AND bl.created_at < ? GROUP BY s.employee_id", [$a, $b]) as $r) $earnedP[(int) $r['employee_id']] = (int) $r['amount'];
        foreach (Db::all("SELECT employee_id, SUM(amount) AS amount FROM staff_payments WHERE kind = 'service' GROUP BY employee_id") as $r) $paid[(int) $r['employee_id']] = (int) $r['amount'];
        foreach (Db::all("SELECT employee_id, SUM(amount) AS amount FROM staff_payments WHERE kind = 'service' AND paid_on >= ? AND paid_on <= ? GROUP BY employee_id", [$p['from'], $p['to']]) as $r) $paidP[(int) $r['employee_id']] = (int) $r['amount'];
        $out = [];
        foreach (self::employees() as $e) {
            $id = $e['id'];
            if (!isset($earned[$id]) && !isset($paid[$id]) && !($e['active'] && $e['serviceShare'])) continue;
            $out[] = ['employee' => $e, 'earned' => $earned[$id] ?? 0, 'paid' => $paid[$id] ?? 0, 'owed' => ($earned[$id] ?? 0) - ($paid[$id] ?? 0),
                'earnedInPeriod' => $earnedP[$id] ?? 0, 'paidInPeriod' => $paidP[$id] ?? 0];
        }
        return $out;
    }

    // ---------- expenses ----------
    private static function mapExpense(array $r): array
    {
        return ['id' => (int) $r['id'], 'spentOn' => $r['spent_on'], 'category' => $r['category'], 'description' => $r['description'], 'payee' => $r['payee'],
            'amount' => (int) $r['amount'], 'account' => $r['account'], 'note' => $r['note'], 'createdBy' => $r['created_by']];
    }

    public static function expenses(array $p, string $category = ''): array
    {
        $sql = 'SELECT * FROM expenses WHERE spent_on >= ? AND spent_on <= ?' . ($category !== '' ? ' AND category = ?' : '') . ' ORDER BY spent_on DESC, id DESC';
        return array_map([self::class, 'mapExpense'], Db::all($sql, $category !== '' ? [$p['from'], $p['to'], $category] : [$p['from'], $p['to']]));
    }

    /** The default categories, then any others already used. */
    public static function expenseCategories(): array
    {
        $list = self::EXPENSE_CATEGORIES;
        foreach (Db::all('SELECT DISTINCT category FROM expenses ORDER BY category') as $r) if (!in_array($r['category'], $list, true)) $list[] = (string) $r['category'];
        return $list;
    }

    /** {spentOn, category, description, payee, amount, account, note} */
    public static function saveExpense(?int $id, array $in, string $by): array
    {
        if ($id && !Db::one('SELECT id FROM expenses WHERE id = ?', [$id])) self::fail('Expense not found.', 404);
        $row = [
            'spent_on' => self::day($in['spentOn'] ?? null), 'category' => self::text($in['category'] ?? '', 60, 'category', true),
            'description' => self::text($in['description'] ?? '', 255, 'description'), 'payee' => self::text($in['payee'] ?? '', 120, 'payee'),
            'amount' => self::amount($in['amount'] ?? null), 'account' => self::text($in['account'] ?? '', 120, 'account'), 'note' => self::text($in['note'] ?? '', 255, 'note'),
        ];
        if ($id) Db::run('UPDATE expenses SET ' . implode(', ', array_map(fn ($c) => "$c = ?", array_keys($row))) . ' WHERE id = ?', [...array_values($row), $id]);
        else $id = Db::run('INSERT INTO expenses (' . implode(', ', array_keys($row)) . ', created_at, created_by) VALUES (' . implode(', ', array_fill(0, count($row) + 2, '?')) . ')', [...array_values($row), self::now(), $by]);
        return self::mapExpense(Db::one('SELECT * FROM expenses WHERE id = ?', [$id]));
    }

    public static function deleteExpense(int $id): void
    {
        if (!Db::one('SELECT id FROM expenses WHERE id = ?', [$id])) self::fail('Expense not found.', 404);
        Db::run('DELETE FROM expenses WHERE id = ?', [$id]);
    }

    // ---------- money into the bank ----------
    private static function mapBankMove(array $r): array
    {
        return ['id' => (int) $r['id'], 'movedOn' => $r['moved_on'], 'account' => $r['account'], 'fromAccount' => $r['from_account'], 'amount' => (int) $r['amount'],
            'reference' => $r['reference'], 'note' => $r['note'], 'createdBy' => $r['created_by']];
    }

    public static function bankMoves(array $p): array
    {
        return array_map([self::class, 'mapBankMove'], Db::all('SELECT * FROM bank_moves WHERE moved_on >= ? AND moved_on <= ? ORDER BY moved_on DESC, id DESC', [$p['from'], $p['to']]));
    }

    /** {movedOn, account, fromAccount, amount, reference, note} */
    public static function saveBankMove(array $in, string $by): array
    {
        $id = Db::run('INSERT INTO bank_moves (moved_on, account, from_account, amount, reference, note, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            self::day($in['movedOn'] ?? null), self::text($in['account'] ?? '', 120, 'bank account', true), self::text($in['fromAccount'] ?? '', 120, 'where it came from'),
            self::amount($in['amount'] ?? null), self::text($in['reference'] ?? '', 120, 'reference'), self::text($in['note'] ?? '', 255, 'note'), self::now(), $by]);
        return self::mapBankMove(Db::one('SELECT * FROM bank_moves WHERE id = ?', [$id]));
    }

    public static function deleteBankMove(int $id): void
    {
        if (!Db::one('SELECT id FROM bank_moves WHERE id = ?', [$id])) self::fail('Entry not found.', 404);
        Db::run('DELETE FROM bank_moves WHERE id = ?', [$id]);
    }

    /**
     * Payments taken in a period. The guest paid `amount`; an agent or bank may have kept part of it
     * before passing it on (the payment's "kept": the agent's commission when the booking has one,
     * otherwise bank or card charges), so the account only got `amount − kept`.
     * By account ("Received into") the figures are what reached it.
     */
    public static function received(array $p): array
    {
        [$a, $b] = self::timeBounds($p);
        $label = self::accountField()['label'];
        $byAccount = $byMethod = [];
        $total = $kept = $commission = $count = 0;
        $rows = Db::all('SELECT p.amount, p.fee, p.method, p.details, b.commission_type FROM pos_payments p LEFT JOIN bookings b ON b.id = p.booking_id
            WHERE p.voided_at IS NULL AND p.created_at >= ? AND p.created_at < ?', [$a, $b]);
        foreach ($rows as $r) {
            $d = json_decode((string) $r['details'], true);
            $acct = is_array($d) && $label !== '' && isset($d[$label]) && $d[$label] !== '' ? (string) $d[$label] : 'Not noted';
            $amt = (int) $r['amount'];
            $fee = (int) $r['fee'];
            $byAccount[$acct] = $byAccount[$acct] ?? ['account' => $acct, 'paid' => 0, 'kept' => 0, 'amount' => 0, 'count' => 0];
            $byAccount[$acct]['paid'] += $amt;
            $byAccount[$acct]['kept'] += $fee;
            $byAccount[$acct]['amount'] += $amt - $fee; // what reached the account
            $byAccount[$acct]['count']++;
            $m = (string) $r['method'];
            $byMethod[$m] = ($byMethod[$m] ?? 0) + $amt - $fee;
            $total += $amt;
            $kept += $fee;
            if ((string) ($r['commission_type'] ?? '') !== '') $commission += $fee;
            $count++;
        }
        usort($byAccount, fn ($x, $y) => $y['amount'] <=> $x['amount']);
        arsort($byMethod);
        return [
            'total' => $total,                         // what guests paid
            'fees' => $kept,                           // kept back by agents and banks
            'commissionKept' => $commission,           // … of which agents' commission
            'charges' => $kept - $commission,          // … and bank or card charges
            'net' => $total - $kept,                   // what reached the accounts
            'count' => $count, 'accountLabel' => $label ?: 'Account',
            'accounts' => array_values($byAccount),
            'methods' => array_map(fn ($k, $v) => ['method' => (string) $k, 'label' => Pos::METHODS[$k] ?? (string) $k, 'amount' => $v], array_keys($byMethod), $byMethod),
        ];
    }

    // ---------- every transaction ----------
    /** A stored UTC time → [resort day YYYY-MM-DD, "HH:MM"]. */
    private static function localTime(string $dbTime): array
    {
        $t = (new \DateTimeImmutable((string) Db::fromDbTime($dbTime)))->setTimezone(new \DateTimeZone(Config::get()['timezone']));
        return [$t->format('Y-m-d'), $t->format('H:i')];
    }

    /**
     * Every money movement in a period, newest first: payments from guests (in, with what an agent
     * or bank kept), expenses and staff pay (out), and money paid into the bank (a move between
     * accounts). opts: type (in|out|move), account, q (search).
     */
    public static function transactions(array $p, array $opts = []): array
    {
        [$a, $b] = self::timeBounds($p);
        $label = self::accountField()['label'];
        $rows = [];
        $pays = Db::all('SELECT p.*, b.ref, b.guest_name, b.commission_type, b.commission_to, bl.bill_no FROM pos_payments p JOIN bookings b ON b.id = p.booking_id
            LEFT JOIN bills bl ON bl.id = p.bill_id WHERE p.voided_at IS NULL AND p.created_at >= ? AND p.created_at < ?', [$a, $b]);
        foreach ($pays as $r) {
            [$day, $time] = self::localTime((string) $r['created_at']);
            $d = json_decode((string) $r['details'], true);
            $acct = is_array($d) && $label !== '' && isset($d[$label]) && $d[$label] !== '' ? (string) $d[$label] : '';
            $fee = (int) $r['fee'];
            $isCommission = (string) $r['commission_type'] !== '';
            $rows[] = ['type' => 'in', 'kind' => 'Payment', 'day' => $day, 'time' => $time, 'id' => 'p' . $r['id'],
                'what' => (string) $r['guest_name'], 'detail' => implode(' · ', array_filter([$r['bill_no'] ?: $r['ref'], Pos::METHODS[$r['method']] ?? $r['method'], Pos::receiptNo((int) $r['id']), (string) $r['note']])),
                'account' => $acct, 'in' => (int) $r['amount'], 'kept' => $fee,
                'keptBy' => $fee ? ($isCommission ? 'Commission kept by ' . ($r['commission_to'] !== '' ? $r['commission_to'] : 'the agent') : 'Bank or card charges') : '',
                'out' => 0, 'moved' => 0, 'by' => (string) $r['created_by']];
        }
        foreach (Db::all('SELECT * FROM expenses WHERE spent_on >= ? AND spent_on <= ?', [$p['from'], $p['to']]) as $r) {
            $rows[] = ['type' => 'out', 'kind' => 'Expense', 'day' => $r['spent_on'], 'time' => '', 'id' => 'e' . $r['id'], 'what' => (string) $r['category'],
                'detail' => implode(' · ', array_filter([(string) $r['description'], $r['payee'] !== '' ? 'to ' . $r['payee'] : '', (string) $r['note']])),
                'account' => (string) $r['account'], 'in' => 0, 'kept' => 0, 'keptBy' => '', 'out' => (int) $r['amount'], 'moved' => 0, 'by' => (string) $r['created_by']];
        }
        foreach (self::staffPayments(['from' => $p['from'], 'to' => $p['to']]) as $x) {
            $rows[] = ['type' => 'out', 'kind' => 'Staff', 'day' => $x['paidOn'], 'time' => '', 'id' => 's' . $x['id'], 'what' => $x['kindLabel'] . ' — ' . $x['employee'],
                'detail' => implode(' · ', array_filter([$x['period'] !== '' ? 'for ' . $x['period'] : '', $x['note']])),
                'account' => $x['account'], 'in' => 0, 'kept' => 0, 'keptBy' => '', 'out' => $x['amount'], 'moved' => 0, 'by' => $x['createdBy']];
        }
        foreach (self::bankMoves($p) as $x) {
            $rows[] = ['type' => 'move', 'kind' => 'Into the bank', 'day' => $x['movedOn'], 'time' => '', 'id' => 'b' . $x['id'], 'what' => 'Paid into ' . $x['account'],
                'detail' => implode(' · ', array_filter([$x['fromAccount'] !== '' ? 'from ' . $x['fromAccount'] : '', $x['reference'], $x['note']])),
                'account' => $x['account'], 'in' => 0, 'kept' => 0, 'keptBy' => '', 'out' => 0, 'moved' => $x['amount'], 'by' => $x['createdBy']];
        }
        $type = (string) ($opts['type'] ?? '');
        $account = (string) ($opts['account'] ?? '');
        $lower = fn (string $t) => function_exists('mb_strtolower') ? mb_strtolower($t) : strtolower($t);
        $q = $lower(trim((string) ($opts['q'] ?? '')));
        $rows = array_values(array_filter($rows, fn ($r) => ($type === '' || $r['type'] === $type)
            && ($account === '' || $r['account'] === $account || ($account === '—' && $r['account'] === ''))
            && ($q === '' || str_contains($lower($r['what'] . ' ' . $r['detail'] . ' ' . $r['account'] . ' ' . $r['kind']), $q))));
        usort($rows, fn ($x, $y) => [$y['day'], $y['time'], $y['id']] <=> [$x['day'], $x['time'], $x['id']]);
        $sum = fn (string $k) => array_sum(array_column($rows, $k));
        return ['rows' => $rows, 'totals' => ['in' => $sum('in'), 'kept' => $sum('kept'), 'netIn' => $sum('in') - $sum('kept'), 'out' => $sum('out'), 'moved' => $sum('moved'),
            'net' => $sum('in') - $sum('kept') - $sum('out')]];
    }

    // ---------- stock ----------
    public static function stockItems(): array
    {
        $rows = Db::all('SELECT i.*, (SELECT COALESCE(SUM(m.qty), 0) FROM stock_moves m WHERE m.item_id = i.id) AS on_hand,
            (SELECT m.unit_cost FROM stock_moves m WHERE m.item_id = i.id AND m.qty > 0 ORDER BY m.moved_on DESC, m.id DESC LIMIT 1) AS last_cost
            FROM stock_items i ORDER BY i.active DESC, i.category, i.name');
        return array_map(fn ($r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'category' => $r['category'], 'unit' => $r['unit'], 'reorderLevel' => (float) $r['reorder_level'],
            'active' => (bool) (int) $r['active'], 'onHand' => round((float) $r['on_hand'], 2), 'lastCost' => (int) ($r['last_cost'] ?? 0),
            'low' => (bool) (int) $r['active'] && (float) $r['reorder_level'] > 0 && (float) $r['on_hand'] <= (float) $r['reorder_level'],
        ], $rows);
    }

    /** {name, category, unit, reorderLevel, active} */
    public static function saveStockItem(?int $id, array $in): array
    {
        if ($id && !Db::one('SELECT id FROM stock_items WHERE id = ?', [$id])) self::fail('Item not found.', 404);
        $reorder = is_numeric($in['reorderLevel'] ?? 0) ? max(0, round((float) ($in['reorderLevel'] ?? 0), 2)) : 0;
        $row = ['name' => self::text($in['name'] ?? '', 120, 'item name', true), 'category' => self::text($in['category'] ?? '', 60, 'category'),
            'unit' => self::text($in['unit'] ?? 'pcs', 20, 'unit') ?: 'pcs', 'reorder_level' => $reorder, 'active' => Js::truthy($in['active'] ?? true) ? 1 : 0];
        if ($id) Db::run('UPDATE stock_items SET ' . implode(', ', array_map(fn ($c) => "$c = ?", array_keys($row))) . ' WHERE id = ?', [...array_values($row), $id]);
        else $id = Db::run('INSERT INTO stock_items (' . implode(', ', array_keys($row)) . ', created_at) VALUES (?, ?, ?, ?, ?, ?)', [...array_values($row), self::now()]);
        foreach (self::stockItems() as $it) if ($it['id'] === $id) return $it;
        self::fail('Item not found.', 404);
    }

    /** {itemId, movedOn, qty (+ in, − out), unitCost, note} */
    public static function addStockMove(array $in, string $by): array
    {
        $item = Db::one('SELECT * FROM stock_items WHERE id = ?', [(int) Js::number($in['itemId'] ?? 0)]);
        if (!$item) self::fail('Choose the item.');
        $qty = self::qty($in['qty'] ?? null);
        $cost = $qty > 0 ? self::amount($in['unitCost'] ?? 0, 'price', true) : 0;
        Db::run('INSERT INTO stock_moves (item_id, moved_on, qty, unit_cost, note, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [(int) $item['id'], self::day($in['movedOn'] ?? Bookings::today()), $qty, $cost, self::text($in['note'] ?? '', 255, 'note'), self::now(), $by]);
        foreach (self::stockItems() as $it) if ($it['id'] === (int) $item['id']) return $it;
        self::fail('Item not found.', 404);
    }

    public static function stockMoves(array $p, int $itemId = 0): array
    {
        $rows = Db::all('SELECT m.*, i.name, i.unit FROM stock_moves m JOIN stock_items i ON i.id = m.item_id WHERE m.moved_on >= ? AND m.moved_on <= ?'
            . ($itemId ? ' AND m.item_id = ?' : '') . ' ORDER BY m.moved_on DESC, m.id DESC LIMIT 300', $itemId ? [$p['from'], $p['to'], $itemId] : [$p['from'], $p['to']]);
        return array_map(fn ($r) => ['id' => (int) $r['id'], 'itemId' => (int) $r['item_id'], 'name' => $r['name'], 'unit' => $r['unit'], 'movedOn' => $r['moved_on'],
            'qty' => round((float) $r['qty'], 2), 'unitCost' => (int) $r['unit_cost'], 'cost' => $r['qty'] > 0 ? (int) round((float) $r['qty'] * (int) $r['unit_cost']) : 0,
            'note' => $r['note'], 'createdBy' => $r['created_by']], $rows);
    }

    // ---------- commissions ----------
    /** Each commission booking with what it earns and what the agent already kept from payments. */
    private static function commissionBookings(string $where, array $vals): array
    {
        $rows = Db::all("SELECT b.id, b.ref, b.guest_name, b.check_in, b.total_amount, b.commission_type, b.commission_value, b.commission_to,
            (SELECT COALESCE(SUM(p.fee), 0) FROM pos_payments p WHERE p.booking_id = b.id AND p.voided_at IS NULL) AS kept
            FROM bookings b WHERE b.commission_type <> '' AND b.status <> 'cancelled' AND b.source <> 'block'$where ORDER BY b.check_in", $vals);
        return array_map(function ($r) {
            $c = Bookings::commissionOf((string) $r['commission_type'], (int) $r['commission_value'], (string) $r['commission_to'], (int) $r['total_amount']);
            return ['id' => (int) $r['id'], 'ref' => $r['ref'], 'guestName' => $r['guest_name'], 'checkIn' => $r['check_in'], 'total' => (int) $r['total_amount'],
                'type' => $c['type'], 'value' => $c['value'], 'amount' => $c['amount'], 'kept' => (int) $r['kept'], 'to' => $c['to'] !== '' ? $c['to'] : 'Not named'];
        }, $rows);
    }

    /**
     * Commission agents and websites earn (set on each booking — the guest still pays the full price).
     * An agent is settled either by keeping it from a payment they pass on (the payment's "kept"), or
     * by the resort paying them (an expense in the "Commission" category, with the agent as "paid to").
     * The period's figures are for stays arriving in it; "still to pay" counts every booking and payment.
     */
    public static function commissions(array $p): array
    {
        $agents = [];
        $agent = function (string $to) use (&$agents) {
            return $agents[$to] = $agents[$to] ?? ['to' => $to, 'bookings' => 0, 'earned' => 0, 'kept' => 0, 'paid' => 0, 'toPay' => 0];
        };
        $bookings = self::commissionBookings(' AND b.check_in >= ? AND b.check_in <= ?', [$p['from'], $p['to']]);
        foreach ($bookings as $x) {
            $agent($x['to']);
            $agents[$x['to']]['bookings']++;
            $agents[$x['to']]['earned'] += $x['amount'];
            $agents[$x['to']]['kept'] += $x['kept'];
        }
        foreach (Db::all("SELECT payee, SUM(amount) AS amount FROM expenses WHERE category = 'Commission' AND spent_on >= ? AND spent_on <= ? GROUP BY payee", [$p['from'], $p['to']]) as $r) {
            $to = (string) $r['payee'] !== '' ? (string) $r['payee'] : 'Not named';
            $agent($to);
            $agents[$to]['paid'] += (int) $r['amount'];
        }
        // still to pay: everything earned, less everything kept and paid, ever
        $owed = [];
        foreach (self::commissionBookings('', []) as $x) $owed[$x['to']] = ($owed[$x['to']] ?? 0) + $x['amount'] - $x['kept'];
        foreach (Db::all("SELECT payee, SUM(amount) AS amount FROM expenses WHERE category = 'Commission' GROUP BY payee") as $r) {
            $to = (string) $r['payee'] !== '' ? (string) $r['payee'] : 'Not named';
            $owed[$to] = ($owed[$to] ?? 0) - (int) $r['amount'];
        }
        foreach ($owed as $to => $v) if ($v !== 0 || isset($agents[$to])) { $agent($to); $agents[$to]['toPay'] = $v; }
        usort($agents, fn ($x, $y) => [$y['toPay'], $y['earned']] <=> [$x['toPay'], $x['earned']]);
        $rec = self::received($p);
        return ['agents' => array_values($agents), 'bookings' => array_map(fn ($x) => $x + ['toPay' => $x['amount'] - $x['kept']], $bookings),
            'earned' => array_sum(array_column($agents, 'earned')), 'kept' => array_sum(array_column($agents, 'kept')), 'paid' => array_sum(array_column($agents, 'paid')),
            'toPay' => max(0, array_sum(array_column($agents, 'toPay'))), 'charges' => $rec['charges']];
    }

    // ---------- room and food figures ----------
    public static function analytics(array $p): array
    {
        $rooms = array_values(array_filter(Bookings::listRooms(true), fn ($r) => $r['active']));
        $byRoom = [];
        foreach (Bookings::listRooms(true) as $r) $byRoom[$r['id']] = ['id' => $r['id'], 'name' => $r['name'], 'nights' => 0, 'revenue' => 0];
        $end = Bookings::addDays($p['to'], 1);
        $roomNights = $guestNights = $roomRevenue = 0;
        $dayVisits = $dayGuests = $dayRevenue = 0;
        $parties = $partyGuests = $partyRevenue = 0;
        $ids = Db::all("SELECT id FROM bookings WHERE status IN ('confirmed', 'checked_in', 'checked_out') AND source <> 'block' AND check_in <= ? AND check_out > ?", [$p['to'], $p['from']]);
        foreach ($ids as $row) {
            $b = Bookings::getBooking((int) $row['id']);
            if (!$b) continue;
            if ($b['kind'] === 'event') {
                // an outdoor party: counted on its date; rooms it holds are occupied that night (their price is in the party's)
                if (strcmp($b['checkIn'], $p['from']) < 0 || strcmp($b['checkIn'], $p['to']) > 0) continue;
                $parties++;
                $partyGuests += $b['adults'] + $b['children'];
                $partyRevenue += $b['totalAmount'];
                foreach (array_column($b['rooms'] ?? [], 'id') as $rid) {
                    if (!isset($byRoom[(int) $rid])) continue;
                    $byRoom[(int) $rid]['nights']++;
                    $roomNights++;
                }
                continue;
            }
            if ($b['kind'] === 'day_out') {
                if (strcmp($b['checkIn'], $p['from']) < 0 || strcmp($b['checkIn'], $p['to']) > 0) continue;
                $dayVisits++;
                $dayGuests += $b['adults'] + $b['children'];
                $dayRevenue += $b['totalAmount'];
                continue;
            }
            $start = strcmp($b['checkIn'], $p['from']) > 0 ? $b['checkIn'] : $p['from'];
            $stop = strcmp($b['checkOut'], $end) < 0 ? $b['checkOut'] : $end;
            $n = Bookings::nightsBetween($start, $stop);
            if ($n <= 0 || $b['nights'] <= 0) continue;
            $revenue = (int) round($b['totalAmount'] * $n / $b['nights']);
            // every room the booking holds (a room booking can hold several), shared between them below
            $roomIds = array_column($b['rooms'] ?? [], 'id') ?: array_filter([$b['roomId']]);
            $roomIds = array_values(array_filter(array_map('intval', $roomIds)));
            $roomNights += $n * max(1, count($roomIds));
            $guestNights += $n * ($b['adults'] + $b['children']);
            $roomRevenue += $revenue;
            foreach ($roomIds as $i => $rid) {
                if (!isset($byRoom[$rid])) continue;
                $byRoom[$rid]['nights'] += $n;
                // the stay price, shared between its rooms (the odd rupees to the first)
                $part = intdiv($revenue, count($roomIds)) + ($i === 0 ? $revenue % count($roomIds) : 0);
                $byRoom[$rid]['revenue'] += $part;
            }
        }
        $available = count($rooms) * $p['days'];
        foreach ($byRoom as &$r) $r['occupancy'] = $p['days'] ? round($r['nights'] * 100 / $p['days'], 1) : 0;
        unset($r);
        // food and drinks: POS items charged in the period
        [$a, $b2] = self::timeBounds($p);
        $cats = [];
        foreach (Db::all('SELECT category, SUM(qty) AS qty, SUM(amount) AS amount FROM pos_charges WHERE voided_at IS NULL AND created_at >= ? AND created_at < ? GROUP BY category ORDER BY amount DESC', [$a, $b2]) as $r) {
            $cats[] = ['category' => (string) $r['category'], 'qty' => (int) $r['qty'], 'amount' => (int) $r['amount']];
        }
        $items = array_map(fn ($r) => ['name' => (string) $r['name'], 'category' => (string) $r['category'], 'qty' => (int) $r['qty'], 'amount' => (int) $r['amount']],
            Db::all('SELECT name, category, SUM(qty) AS qty, SUM(amount) AS amount FROM pos_charges WHERE voided_at IS NULL AND created_at >= ? AND created_at < ? GROUP BY name, category ORDER BY amount DESC, name LIMIT 20', [$a, $b2]));
        return [
            'rooms' => array_values(array_filter($byRoom, fn ($r) => $r['nights'] || in_array($r['id'], array_column($rooms, 'id'), true))),
            'roomNights' => $roomNights, 'available' => $available, 'occupancy' => $available ? round($roomNights * 100 / $available, 1) : 0,
            'roomRevenue' => $roomRevenue, 'adr' => $roomNights ? (int) round($roomRevenue / $roomNights) : 0, 'revpar' => $available ? (int) round($roomRevenue / $available) : 0,
            'guestNights' => $guestNights, 'dayVisits' => $dayVisits, 'dayGuests' => $dayGuests, 'dayRevenue' => $dayRevenue,
            'parties' => $parties, 'partyGuests' => $partyGuests, 'partyRevenue' => $partyRevenue,
            'food' => ['categories' => $cats, 'items' => $items, 'total' => array_sum(array_column($cats, 'amount')), 'qty' => array_sum(array_column($cats, 'qty'))],
        ];
    }

    // ---------- the summary ----------
    public static function summary(array $p): array
    {
        [$a, $b] = self::timeBounds($p);
        $sales = Db::one('SELECT COUNT(*) AS bills, COALESCE(SUM(stay_total), 0) AS stay, COALESCE(SUM(items_total), 0) AS items, COALESCE(SUM(discount), 0) AS discount,
            COALESCE(SUM(coupon_discount), 0) AS coupon, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(' . str_replace('bl.', '', self::BILL_SERVICE) . "), 0) AS service
            FROM bills WHERE status = 'issued' AND created_at >= ? AND created_at < ?", [$a, $b]);
        $expenses = [];
        foreach (Db::all('SELECT category, SUM(amount) AS amount, COUNT(*) AS n FROM expenses WHERE spent_on >= ? AND spent_on <= ? GROUP BY category ORDER BY amount DESC', [$p['from'], $p['to']]) as $r) {
            $expenses[] = ['category' => (string) $r['category'], 'amount' => (int) $r['amount'], 'count' => (int) $r['n']];
        }
        $staff = [];
        foreach (Db::all('SELECT kind, SUM(amount) AS amount FROM staff_payments WHERE paid_on >= ? AND paid_on <= ? GROUP BY kind', [$p['from'], $p['to']]) as $r) {
            $staff[] = ['kind' => (string) $r['kind'], 'label' => self::PAY_KINDS[$r['kind']] ?? (string) $r['kind'], 'amount' => (int) $r['amount']];
        }
        $bank = [];
        foreach (Db::all('SELECT account, SUM(amount) AS amount, COUNT(*) AS n FROM bank_moves WHERE moved_on >= ? AND moved_on <= ? GROUP BY account ORDER BY amount DESC', [$p['from'], $p['to']]) as $r) {
            $bank[] = ['account' => (string) $r['account'], 'amount' => (int) $r['amount'], 'count' => (int) $r['n']];
        }
        $stockIn = Db::one('SELECT COALESCE(SUM(qty * unit_cost), 0) AS cost FROM stock_moves WHERE qty > 0 AND moved_on >= ? AND moved_on <= ?', [$p['from'], $p['to']]);
        $received = self::received($p);
        $expenseTotal = array_sum(array_column($expenses, 'amount'));
        $staffTotal = array_sum(array_column($staff, 'amount'));
        $comm = self::commissions($p);
        $an = self::analytics($p);
        return [
            'period' => $p,
            'sales' => array_map('intval', $sales),
            'received' => $received,
            'expenses' => $expenses, 'expenseTotal' => $expenseTotal,
            'staff' => $staff, 'staffTotal' => $staffTotal,
            'bank' => $bank, 'bankTotal' => array_sum(array_column($bank, 'amount')),
            'stockBought' => (int) round((float) $stockIn['cost']),
            'commissions' => ['earned' => $comm['earned'], 'kept' => $comm['kept'], 'paid' => $comm['paid'], 'toPay' => $comm['toPay'], 'charges' => $comm['charges']],
            'rooms' => ['nights' => $an['roomNights'], 'occupancy' => $an['occupancy'], 'revenue' => $an['roomRevenue'], 'adr' => $an['adr'], 'dayVisits' => $an['dayVisits'],
                'parties' => $an['parties'], 'partyRevenue' => $an['partyRevenue']],
            'food' => ['total' => $an['food']['total'], 'top' => array_slice($an['food']['items'], 0, 5)],
            'lowStock' => array_values(array_filter(self::stockItems(), fn ($i) => $i['low'])),
            // money taken in, less what was spent and paid to staff (fees kept by banks or agents already out)
            'net' => $received['net'] - $expenseTotal - $staffTotal,
        ];
    }
}
