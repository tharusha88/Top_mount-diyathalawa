<?php
// Booking confirmations (app/Confirmations.php): staff send a guest a link to check a booking,
// fill in what is missing and confirm it. Runs on a THROWAWAY SQLite database, like bookings_test.php:
//   php tests/confirmations_test.php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/topmountain-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
putenv("DATA_DIR=$tmp");
putenv('YDR_TEST_SEED=' . __DIR__ . '/seed.php');
putenv('SMTP_HOST=');
putenv('DB_CONNECTION=sqlite');
putenv('BASE_URL=https://resort.test');
require dirname(__DIR__) . '/app/bootstrap.php';

use Ydr\Bookings as B;
use Ydr\BookingError;
use Ydr\Confirmations as C;
use Ydr\Mailer;

$pass = 0;
$fail = 0;
function test(string $name, callable $fn): void
{
    global $pass, $fail;
    try { $fn(); $pass++; echo "✔ $name\n"; }
    catch (Throwable $e) { $fail++; echo "✖ $name\n    " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n"; }
}
function eq($a, $b, string $msg = ''): void
{
    if ($a !== $b) throw new Exception(($msg ? "$msg: " : '') . 'expected ' . var_export($b, true) . ', got ' . var_export($a, true));
}
function throws(callable $fn, string $match): void
{
    try { $fn(); } catch (BookingError $e) {
        if (str_starts_with($match, 'code:')) { eq($e->errorCode, substr($match, 5)); return; }
        if (!preg_match($match, $e->getMessage())) throw new Exception("error '{$e->getMessage()}' does not match $match");
        return;
    }
    throw new Exception("expected an error matching $match");
}
/** The emails waiting to go out (the queue is flushed to data/outbox when SMTP isn't set up). */
function outbox(): array
{
    Mailer::flushQueue();
    $dir = Ydr\Config::get()['dataDir'] . '/outbox';
    $out = [];
    foreach (glob("$dir/*.html") ?: [] as $f) { $out[] = file_get_contents($f); unlink($f); }
    return $out;
}

$t = B::today();
$d = fn (int $n) => B::addDays($t, $n);
// what a guest types on the confirmation page
$typed = ['guestName' => 'Nimal Perera', 'guestIdNo' => '199012345678', 'guestEmail' => 'nimal@example.com', 'guestPhone' => '0771234567', 'agree' => true];
// a booking taken on the phone: a name and a number, nothing more
$phoneBooking = fn (int $day, array $more = []) => B::createBooking($more + ['room' => 'mara-pool-villa', 'checkIn' => $d($day), 'checkOut' => $d($day + 2),
    'guestName' => 'Nimal', 'guestPhone' => '077 123 4567', 'source' => 'phone', 'status' => 'pending'], true, 'admin');

test('a booking made by staff lists the details the guest must still give, and has its own private link', function () use ($phoneBooking) {
    $b = $phoneBooking(10);
    eq(C::missing($b), ['guestIdNo', 'guestEmail']);
    $L = C::forAdmin($b);
    eq([$L['state'], $L['missingLabels'], $L['record'], $L['canEmail']], ['open', ['NIC / passport number', 'Email'], null, false]);
    if (!preg_match('#^https://resort\.test/confirm/' . preg_quote($b['ref'], '#') . '/[0-9a-f]{24}$#', $L['url'])) throw new Exception('bad link ' . $L['url']);
    $code = explode('/', (string) parse_url($L['url'], PHP_URL_PATH))[3]; // /confirm/<ref>/<code>
    eq(C::byLink($b['ref'], $code)['id'], $b['id']);
    eq(C::byLink(strtolower($b['ref']), $code)['id'], $b['id'], 'the reference in any case');
    eq(C::byLink($b['ref'], str_repeat('0', 24)), null, 'a wrong code');
    eq(C::byLink('TMR-NOPE00', $code), null, 'another booking');
    // the invoice's code doesn't open it
    $inv = explode('/', parse_url(Ydr\Docs::invoiceUrl($b), PHP_URL_PATH))[3];
    eq(C::byLink($b['ref'], $inv), null, 'the invoice code');
    if (!str_contains($L['waText'], $b['ref']) || !str_starts_with($L['waText'], 'Hello Nimal')) throw new Exception('WhatsApp text: ' . $L['waText']);
});

test('blocked dates have no confirmation link', function () use ($d) {
    $blk = B::createBooking(['room' => 'hora-pool-villa', 'checkIn' => $d(30), 'checkOut' => $d(31), 'source' => 'block'], true, 'admin');
    eq(C::byLink($blk['ref'], 'x'), null);
    throws(fn () => C::markSent($blk, 'admin', 'link'), '/no guest/');
});

test('sending the link is noted: first when it was issued, then each time it is sent again', function () use ($phoneBooking) {
    $b = $phoneBooking(40);
    eq(C::statuses([$b['id']]), []);
    throws(fn () => C::markSent($b, 'admin', 'carrier-pigeon'), '/how the link was sent/');
    $L = C::markSent($b, 'admin', 'whatsapp');
    eq([$L['record']['sentVia'], $L['record']['sentViaLabel'], $L['record']['confirmedAt'], $L['state']], ['whatsapp', 'WhatsApp', null, 'open']);
    $first = $L['record']['issuedAt'];
    usleep(5000);
    $L = C::markSent($b, 'admin', 'link');
    eq([$L['record']['sentVia'], $L['record']['issuedAt']], ['link', $first]);
    eq(C::statuses([$b['id'], 999999]), [$b['id'] => 'sent']);
    $list = C::decorate([['id' => $b['id']], ['id' => 999999]]);
    eq(array_column($list, 'guestConfirm'), ['sent', null]);
});

test('the guest must agree and give a name, NIC or passport, email and phone', function () use ($phoneBooking, $typed) {
    $b = $phoneBooking(50);
    throws(fn () => C::confirm($b, ['agree' => false] + $typed), '/tick the box/');
    throws(fn () => C::confirm($b, ['guestEmail' => 'nimal@'] + $typed), '/valid email/');
    throws(fn () => C::confirm($b, ['guestIdNo' => ''] + $typed), '/enter your NIC/');
    throws(fn () => C::confirm($b, ['guestIdNo' => '12'] + $typed), '/check the NIC/');
    throws(fn () => C::confirm($b, ['guestPhone' => '123'] + $typed), '/valid phone/');
    throws(fn () => C::confirm($b, ['guestName' => '  '] + $typed), '/full name/');
    throws(fn () => C::confirm($b, ['country' => ['x']] + $typed), '/check the Country/');
    eq(C::state(B::getBooking($b['id'])), 'open', 'nothing was saved');
    eq(B::getBooking($b['id'])['guestEmail'], '');
});

test('confirming saves what the guest filled in, notes what changed, and leaves the status to staff', function () use ($phoneBooking, $typed) {
    $b = $phoneBooking(60);
    C::markSent($b, 'admin', 'whatsapp');
    $r = C::confirm($b, ['country' => 'Sri Lanka', 'arrivalTime' => '3 PM – 6 PM', 'specialRequests' => 'Vegetarian meals', 'guestIdNo' => ' 901234567v '] + $typed);
    $n = $r['booking'];
    eq([$n['guestName'], $n['guestIdNo'], $n['guestEmail'], $n['guestPhone'], $n['country'], $n['arrivalTime'], $n['specialRequests']],
        ['Nimal Perera', '901234567V', 'nimal@example.com', '0771234567', 'Sri Lanka', '3 PM – 6 PM', 'Vegetarian meals']);
    eq([$n['status'], $n['totalAmount'], $n['checkIn'], $n['roomId']], ['pending', $b['totalAmount'], $b['checkIn'], $b['roomId']], 'the stay itself is unchanged');
    eq(array_column($r['changes'], 'field'), ['guestName', 'guestIdNo', 'guestEmail', 'guestPhone', 'country', 'arrivalTime', 'specialRequests']);
    eq($r['changes'][0], ['field' => 'guestName', 'label' => 'Full name', 'from' => 'Nimal', 'to' => 'Nimal Perera']);
    eq(C::changesText($r['changes']), 'added NIC / passport number, Email, Country, Estimated arrival, Special requests · changed Full name, Phone / WhatsApp');
    $L = C::forAdmin($n);
    eq([$L['state'], $L['missing'], $L['record']['sentVia'], count($L['record']['changes'])], ['confirmed', [], 'whatsapp', 7]);
    if (!$L['record']['confirmedAt']) throw new Exception('no confirmation time');
    eq(C::statuses([$n['id']]), [$n['id'] => 'confirmed']);
    // once confirmed, the link can't change it again
    throws(fn () => C::confirm($n, $typed + ['guestName' => 'Someone Else']), 'code:already_confirmed');
    eq(B::getBooking($n['id'])['guestName'], 'Nimal Perera');
});

test('a guest can confirm from a link staff never noted as sent, and changing nothing is fine', function () use ($d, $typed) {
    $b = B::createBooking(['room' => 'domba-pool-villa', 'checkIn' => $d(70), 'checkOut' => $d(71), 'source' => 'whatsapp', 'status' => 'confirmed'] + array_diff_key($typed, ['agree' => 0]), true, 'admin');
    eq(C::missing($b), []);
    $r = C::confirm($b, $typed);
    eq([$r['changes'], $r['booking']['status'], C::state($r['booking'])], [[], 'confirmed', 'confirmed']);
    eq(C::forAdmin($r['booking'])['record']['sentVia'], 'link');
    eq(C::changesText([]), '');
});

test('a cancelled booking or one already staying can\'t be confirmed', function () use ($phoneBooking, $typed) {
    $b = $phoneBooking(80);
    $x = B::updateBooking($b['id'], ['status' => 'cancelled']);
    eq(C::state($x), 'closed');
    throws(fn () => C::confirm($x, $typed), '/cancelled/');
    $y = B::updateBooking($phoneBooking(90)['id'], ['status' => 'checked_in']);
    eq(C::state($y), 'closed');
    throws(fn () => C::confirm($y, $typed), 'code:closed');
});

test('a party\'s time is not the guest\'s to change', function () use ($d, $typed) {
    $p = B::createBooking(['guestName' => 'Perera wedding', 'guestPhone' => '0771234567', 'kind' => 'event', 'checkIn' => $d(120), 'eventSlot' => 'night',
        'adults' => 40, 'arrivalTime' => '7 pm – 1 am', 'totalAmount' => 250000, 'source' => 'phone', 'status' => 'pending'], true, 'admin');
    $page = C::page($p);
    eq([in_array('arrivalTime', $page['fields'], true), $page['time']], [false, '7 pm – 1 am']);
    $r = C::confirm($p, $typed + ['arrivalTime' => 'Morning (before 12 PM)']);
    eq($r['booking']['arrivalTime'], '7 pm – 1 am');
});

test('the guest\'s page shows the stay, the price, what to pay and what is needed', function () use ($phoneBooking) {
    $b = $phoneBooking(150, ['mealPlan' => 'RO', 'specialRequests' => 'Late arrival']);
    $page = C::page($b);
    eq([$page['ref'], $page['state'], $page['status'], $page['kind'], $page['nights'], $page['total'], $page['missing']],
        [$b['ref'], 'open', 'pending', 'room', 2, $b['totalAmount'], ['guestIdNo', 'guestEmail']]);
    eq(array_sum(array_column($page['lines'], 'amount')), $b['totalAmount']);
    eq([$page['guest']['guestName'], $page['guest']['guestPhone'], $page['guest']['specialRequests'], $page['guest']['guestEmail']], ['Nimal', '077 123 4567', 'Late arrival', '']);
    eq($page['fields'], array_keys(C::FIELDS));
    eq($page['invoiceUrl'], Ydr\Docs::invoiceUrl($b));
    eq(array_key_exists('adminNotes', $page) || array_key_exists('commission', $page), false, 'nothing private to staff');
});

test('emails: the link to the guest, and the confirmation to staff and the guest', function () use ($phoneBooking, $typed) {
    outbox();
    $b = $phoneBooking(200);
    eq(Mailer::sendConfirmLink($b, C::url($b)), false, 'no email address');
    $b = B::updateBooking($b['id'], ['guestEmail' => 'nimal@example.com']);
    eq(Mailer::sendConfirmLink($b, C::url($b)), true);
    $mails = outbox();
    eq(count($mails), 1);
    if (!str_contains($mails[0], htmlspecialchars(C::url($b))) || !str_contains($mails[0], 'nic / passport number')) throw new Exception('the link email lacks the link or what is needed');
    Ydr\Settings::set(['notify_emails' => json_encode(['owner@resort.test'])]);
    $r = C::confirm($b, $typed);
    C::notify($r['booking'], $r['changes']);
    $mails = outbox();
    eq(count($mails), 2, 'one to staff, one to the guest');
    $staff = current(array_filter($mails, fn ($m) => str_contains($m, 'owner@resort.test')));
    if (!$staff || !str_contains($staff, 'Guest confirmed') || !str_contains($staff, 'NIC / passport number')) throw new Exception('the staff email');
    $guest = current(array_filter($mails, fn ($m) => str_contains($m, 'To: nimal@example.com')));
    if (!$guest || !str_contains($guest, $b['ref'])) throw new Exception('the guest email');
});

echo "\n$pass passed, $fail failed  (test database: $tmp)\n";
exit($fail ? 1 : 0);
