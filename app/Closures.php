<?php
// Days the whole resort is closed — for repairs, a private booking of the whole place, or any
// other reason (Admin → Calendar → Close days). On those days the website takes no bookings:
// no rooms, no per-person stays and no day visits, and its calendar shows the days crossed out.
// Staff can still enter bookings on them (e.g. the private booking itself), with a warning.
// A closed day closes that night: a guest who leaves on its morning is fine.
// An outdoor party can close its date too (Booking → Other guests): "no other stays" closes
// the night to other stays; "whole resort private" also closes it to day visits.
declare(strict_types=1);

namespace Ydr;

final class Closures
{
    /** Why — what guests see on the booking page. */
    public const REASONS = ['private' => 'Private booking', 'repairs' => 'Closed for repairs', 'closed' => 'Closed'];
    private const MAX_DAYS = 366;

    private static function map(array $r): array
    {
        $reason = isset(self::REASONS[$r['reason']]) ? $r['reason'] : 'closed';
        return [
            'id' => (int) $r['id'], 'from' => $r['starts_on'], 'to' => $r['ends_on'], 'days' => Bookings::nightsBetween($r['starts_on'], $r['ends_on']) + 1,
            'reason' => $reason, 'label' => self::REASONS[$reason], 'note' => $r['note'], 'createdBy' => $r['created_by'], 'createdAt' => Db::fromDbTime($r['created_at']),
        ];
    }

    /** Closures that are not over yet (or every one ending on or after $from). */
    public static function upcoming(?string $from = null): array
    {
        return array_map([self::class, 'map'], Db::all('SELECT * FROM closures WHERE ends_on >= ? ORDER BY starts_on, id', [$from ?? Bookings::today()]));
    }

    /** Closures (Close days) with a day in [from, to). */
    private static function closedDays(string $from, string $to): array
    {
        return array_map([self::class, 'map'], Db::all('SELECT * FROM closures WHERE starts_on < ? AND ends_on >= ? ORDER BY starts_on, id', [$to, $from]));
    }

    /** Parties in [from, to) that close their date to other guests, shaped like closures (with bookingId). */
    private static function partyDays(string $from, string $to, int $excludeId = 0): array
    {
        $rows = Db::all("SELECT id, ref, guest_name, check_in, event_slot, event_others FROM bookings WHERE package = 'event' AND event_others IN ('rooms', 'private')
            AND status IN ('pending', 'confirmed', 'checked_in') AND check_in >= ? AND check_in < ? AND id <> ? ORDER BY check_in, id", [$from, $to, $excludeId]);
        return array_map(fn ($r) => [
            'id' => 'party-' . $r['id'], 'bookingId' => (int) $r['id'], 'from' => $r['check_in'], 'to' => $r['check_in'], 'days' => 1, 'reason' => 'party',
            'label' => 'Private event', // what guests see
            'staffLabel' => ($r['event_others'] === 'private' ? 'Whole resort private' : 'No other stays') . ' · ' . (Bookings::EVENT_SLOTS[$r['event_slot']] ?? 'Party'),
            'dayVisits' => $r['event_others'] === 'private', 'note' => $r['guest_name'] . ' · ' . $r['ref'], 'createdBy' => '', 'createdAt' => null,
        ], $rows);
    }

    /** Everything closing days in [from, to): closures and parties (for the admin calendar). */
    public static function between(string $from, string $to): array
    {
        return array_merge(array_map(fn ($c) => $c + ['staffLabel' => $c['label'], 'dayVisits' => true, 'bookingId' => null], self::closedDays($from, $to)), self::partyDays($from, $to));
    }

    /**
     * Closed days in [from, to): ['YYYY-MM-DD' => 'Private booking', …]. For stays (nights), or for
     * day visits ($dayVisits) — a party with "no other stays" leaves day visits open.
     */
    public static function days(string $from, string $to, bool $dayVisits = false, int $excludeId = 0): array
    {
        $list = array_merge(self::closedDays($from, $to), array_filter(self::partyDays($from, $to, $excludeId), fn ($p) => !$dayVisits || $p['dayVisits']));
        $out = [];
        foreach ($list as $c) {
            $d = strcmp($c['from'], $from) > 0 ? $c['from'] : $from;
            for (; strcmp($d, $c['to']) <= 0 && strcmp($d, $to) < 0; $d = Bookings::addDays($d, 1)) $out[$d] = $out[$d] ?? $c['label'];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * The closed days a stay [checkIn, checkOut) or a day visit (checkOut = the next day) falls on:
     * [{date, label}]. $kind 'day_out' checks day visits; $excludeId leaves a party out (itself).
     */
    public static function clashes(mixed $checkIn, mixed $checkOut, string $kind = '', int $excludeId = 0): array
    {
        if (!Bookings::isDate($checkIn) || !Bookings::isDate($checkOut) || strcmp($checkOut, $checkIn) <= 0) return [];
        $out = [];
        foreach (self::days($checkIn, $checkOut, $kind === 'day_out', $excludeId) as $d => $label) $out[] = ['date' => (string) $d, 'label' => $label];
        return $out;
    }

    /** "Fri 20 Nov" / "Fri 20 – Sun 22 Nov" */
    private static function span(array $clash): string
    {
        $f = fn (string $d, string $fmt) => (new \DateTimeImmutable($d . ' 00:00:00', new \DateTimeZone('UTC')))->format($fmt);
        $a = $clash[0]['date'];
        $b = $clash[count($clash) - 1]['date'];
        return $a === $b ? $f($a, 'D j M') : $f($a, 'D j M') . ' – ' . $f($b, 'D j M');
    }

    /** Website bookings: refuse closed days, saying which and why. ($kind 'day_out': a day visit) */
    public static function assertOpen(mixed $checkIn, mixed $checkOut, string $kind = ''): void
    {
        $c = self::clashes($checkIn, $checkOut, $kind);
        if (!$c) return;
        throw new BookingError('Sorry, we are closed on ' . self::span($c) . ' (' . strtolower($c[0]['label']) . '). Please choose other dates.', 409, 'closed');
    }

    /** Bookings already on those days (so staff can move or cancel them); $excludeId leaves one out. */
    public static function affected(string $from, string $to, int $excludeId = 0, bool $dayVisits = true): array
    {
        $end = Bookings::addDays($to, 1);
        return array_values(array_filter(Bookings::listBookings(['status' => 'active', 'from' => $from, 'to' => $end, 'limit' => 200]),
            fn ($b) => $b['source'] !== 'block' && $b['id'] !== $excludeId && ($dayVisits || $b['kind'] !== 'day_out')
                && strcmp($b['checkIn'], $end) < 0 && strcmp($b['checkOut'], $from) > 0));
    }

    /** {from, to (inclusive, empty = one day), reason, note} → the closure and the bookings already on those days. */
    public static function create(array $in, string $by): array
    {
        $from = $in['from'] ?? null;
        $to = ($in['to'] ?? '') === '' ? $from : $in['to'];
        if (!Bookings::isDate($from) || !Bookings::isDate($to)) throw new BookingError('Choose the first and last day the resort is closed.');
        if (strcmp($to, $from) < 0) [$from, $to] = [$to, $from];
        if (strcmp($to, Bookings::today()) < 0) throw new BookingError('Those days are already past.');
        if (Bookings::nightsBetween($from, $to) + 1 > self::MAX_DAYS) throw new BookingError('Close the resort for up to a year at a time.');
        $reason = (string) ($in['reason'] ?? 'closed');
        if (!isset(self::REASONS[$reason])) throw new BookingError('Choose why the resort is closed.');
        $note = Js::trim(Js::str($in['note'] ?? ''));
        if (Js::length($note) > 255) throw new BookingError('The note can be up to 255 characters.');
        $id = Db::run('INSERT INTO closures (starts_on, ends_on, reason, note, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$from, $to, $reason, $note, Db::toDbTime(Js::isoNow()), $by]);
        return ['closure' => self::map(Db::one('SELECT * FROM closures WHERE id = ?', [$id])), 'bookings' => self::affected($from, $to)];
    }

    /** Open the resort again on those days. */
    public static function delete(int $id): void
    {
        if (!Db::one('SELECT id FROM closures WHERE id = ?', [$id])) throw new BookingError('Not found.', 404);
        Db::run('DELETE FROM closures WHERE id = ?', [$id]);
    }
}
