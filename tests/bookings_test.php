<?php
// Booking and POS rules. Always runs against a THROWAWAY database — never the site's real one:
//   php tests/bookings_test.php                                  (temporary SQLite file)
//   TEST_MYSQL_DATABASE=<an EMPTY database> php tests/bookings_test.php   (MySQL; uses the
//                                  DB_HOST / DB_USERNAME / DB_PASSWORD from .env)
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/topmountain-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
putenv("DATA_DIR=$tmp");
// the tests' own rooms, options and menu (tests/seed.php), not the ones the website seeds
putenv('YDR_TEST_SEED=' . __DIR__ . '/seed.php');
putenv('SMTP_HOST=');
// environment variables override .env, so the configured (real) database is never used
$testMysql = (string) getenv('TEST_MYSQL_DATABASE');
if ($testMysql !== '') {
    putenv('DB_CONNECTION=mysql');
    putenv("DB_DATABASE=$testMysql");
} else {
    putenv('DB_CONNECTION=sqlite');
}
require dirname(__DIR__) . '/app/bootstrap.php';

if ($testMysql !== '') {
    // refuse to touch a database that holds anything
    $pdo = new PDO('mysql:host=' . Ydr\Config::get()['db']['host'] . ';port=' . Ydr\Config::get()['db']['port'] . ';dbname=' . $testMysql . ';charset=utf8mb4',
        Ydr\Config::get()['db']['username'], Ydr\Config::get()['db']['password']);
    if ($pdo->query('SHOW TABLES')->fetchColumn() !== false) {
        fwrite(STDERR, "Refusing to run: database \"$testMysql\" is not empty. Use a new, empty database for tests.\n");
        exit(2);
    }
}

use Ydr\Bookings as B;
use Ydr\BookingError;
use Ydr\Pos;
use Ydr\Settings;

$pass = 0;
$fail = 0;
function test(string $name, callable $fn): void
{
    global $pass, $fail;
    try { $fn(); $pass++; echo "✔ $name\n"; }
    catch (Throwable $e) { $fail++; echo "✖ $name\n    " . $e->getMessage() . "\n"; }
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

$t = B::today();
$d = fn (int $n) => B::addDays($t, $n);
$guest = ['guestName' => 'Test Guest', 'guestEmail' => 'guest@example.com', 'guestPhone' => '+94 77 123 4567', 'guestIdNo' => '199012345678'];
$room = fn (string $slug) => B::getRoom($slug);
$pkg = fn (string $name) => current(array_filter(B::listPackages(true), fn ($p) => $p['name'] === $name));
$roomIds = fn (array $b) => array_column($b['rooms'], 'id');
$slugs = fn (array $b) => array_map(fn ($r) => B::getRoom($r['id'])['slug'], $b['rooms']);

// ---------- set-up ----------
test('seeds nine rooms with their capacity, two per-person options and the hotel capacity', function () use ($pkg) {
    eq(count(B::listRooms()), 9);
    eq(B::getRoom('yodha-family-lodge')['capacity'], 4);
    eq(array_column(B::listPackages(), 'name'), ['Full board', 'Half board']);
    eq([$pkg('Day out')['kind'], $pkg('Day out')['active']], ['day', false]);
    eq(B::hotelCapacity(), 20);
    eq(B::labels(), ['room' => 'Select room', 'person' => 'Per person']);
});

// ---------- category 1: a room ----------
test('a room booking is pending, priced per room per night and needs no guest numbers', function () use ($guest, $d, $room) {
    $b = B::createBooking($guest + ['kind' => 'room', 'room' => 'mara-pool-villa', 'checkIn' => $d(10), 'checkOut' => $d(13)]);
    eq([$b['status'], $b['source'], $b['kind'], $b['nights'], $b['adults'], $b['capacity']], ['pending', 'website', 'room', 3, 0, 2]);
    eq($b['totalAmount'], 3 * $room('mara-pool-villa')['price']);
    if (!preg_match('/^TMR-[A-Z2-9]{6}$/', $b['ref'])) throw new Exception('bad ref ' . $b['ref']);
});

test('overlapping room bookings are refused, back-to-back ones allowed', function () use ($guest, $d) {
    throws(fn () => B::createBooking($guest + ['room' => 'mara-pool-villa', 'checkIn' => $d(12), 'checkOut' => $d(14)]), 'code:unavailable');
    throws(fn () => B::createBooking($guest + ['room' => 'mara-pool-villa', 'checkIn' => $d(8), 'checkOut' => $d(11)]), 'code:unavailable');
    eq(B::createBooking($guest + ['room' => 'mara-pool-villa', 'checkIn' => $d(13), 'checkOut' => $d(15)])['checkIn'], $d(13));
    $rooms = array_column(B::searchRooms($d(10), $d(13)), null, 'slug');
    eq([$rooms['mara-pool-villa']['available'], $rooms['hora-pool-villa']['available']], [false, true]);
    if (!in_array($d(11), B::unavailableNights('room', B::getRoom('mara-pool-villa'), 1, $d(0), $d(30)), true)) throw new Exception('night not shown as taken');
});

test('bad dates, emails and guest numbers are refused', function () use ($guest, $d) {
    throws(fn () => B::createBooking($guest + ['room' => 'kitul-canopy-cabin', 'checkIn' => $d(-3), 'checkOut' => $d(-1)]), '/past/');
    throws(fn () => B::createBooking(['guestEmail' => 'not-an-email'] + $guest + ['room' => 'kitul-canopy-cabin', 'checkIn' => $d(20), 'checkOut' => $d(21)]), '/email/');
    throws(fn () => B::createBooking($guest + ['room' => 'kitul-canopy-cabin', 'checkIn' => $d(21), 'checkOut' => $d(20)]), '/after check-in/');
    throws(fn () => B::createBooking(['adults' => 3] + $guest + ['room' => 'kitul-canopy-cabin', 'checkIn' => $d(20), 'checkOut' => $d(21)]), '/sleeps up to 2 guests/');
});

test('website guests give their NIC or passport number; staff may leave it out', function () use ($guest, $d, $room) {
    $noId = array_diff_key($guest, ['guestIdNo' => 1]);
    throws(fn () => B::createBooking($noId + ['room' => 'kitul-canopy-cabin', 'checkIn' => $d(500), 'checkOut' => $d(501)]), '/NIC or passport/');
    throws(fn () => B::createBooking(['guestIdNo' => '12'] + $guest + ['room' => 'kitul-canopy-cabin', 'checkIn' => $d(500), 'checkOut' => $d(501)]), '/check the NIC/');
    $b = B::createBooking(['guestIdNo' => '  901234567v '] + $guest + ['room' => 'kitul-canopy-cabin', 'checkIn' => $d(500), 'checkOut' => $d(501)]);
    eq($b['guestIdNo'], '901234567V', 'tidied');
    eq(array_column(B::listBookings(['q' => '901234567V']), 'id'), [$b['id']], 'found by ID');
    eq(B::updateBooking($b['id'], ['guestIdNo' => 'N1234567'])['guestIdNo'], 'N1234567');
    $phone = B::createBooking(['roomId' => $room('weera-canopy-cabin')['id'], 'checkIn' => $d(502), 'checkOut' => $d(503), 'guestName' => 'Phone Guest', 'source' => 'phone'], true);
    eq($phone['guestIdNo'], '');
});

test('admins enter phone bookings, past stays and blocked dates', function () use ($d, $room) {
    $b = B::createBooking(['roomId' => $room('kumbuk-treehouse')['id'], 'checkIn' => $d(30), 'checkOut' => $d(33), 'guestName' => 'Phone Guest', 'source' => 'whatsapp', 'status' => 'confirmed', 'totalAmount' => 120000], true, 'admin');
    eq([$b['status'], $b['source'], $b['totalAmount']], ['confirmed', 'whatsapp', 120000]);
    eq(B::createBooking(['roomId' => $room('weera-canopy-cabin')['id'], 'checkIn' => $d(-5), 'checkOut' => $d(-3), 'guestName' => 'Walk-in', 'source' => 'walk_in', 'status' => 'checked_out'], true)['status'], 'checked_out');
    $block = B::createBooking(['roomId' => $room('yodha-family-lodge')['id'], 'checkIn' => $d(50), 'checkOut' => $d(52), 'source' => 'block'], true);
    eq([$block['totalAmount'], $block['kind']], [0, 'room']);
});

test('noting guests on a room keeps its total; a per-person group is re-priced', function () use ($guest, $d, $pkg, $room) {
    $b = B::createBooking(['roomId' => $room('weera-canopy-cabin')['id'], 'checkIn' => $d(300), 'checkOut' => $d(302), 'guestName' => 'Agoda Guest', 'source' => 'agoda', 'totalAmount' => 33333], true);
    eq(B::updateBooking($b['id'], ['adults' => 2, 'children' => 0])['totalAmount'], 33333, 'room total');
    $fb = $pkg('Full board');
    $g = B::createBooking($guest + ['kind' => 'per_person', 'packageId' => $fb['id'], 'checkIn' => $d(304), 'checkOut' => $d(305), 'adults' => 2]);
    eq(B::updateBooking($g['id'], ['adults' => 3])['totalAmount'], 3 * $fb['priceAdult'], 'group total');
});

test('cancelling frees the dates; moving into a clash is refused', function () use ($guest, $d, $room) {
    $b = B::createBooking($guest + ['room' => 'domba-pool-villa', 'checkIn' => $d(40), 'checkOut' => $d(42)]);
    $other = B::createBooking($guest + ['room' => 'hora-pool-villa', 'checkIn' => $d(40), 'checkOut' => $d(42)]);
    throws(fn () => B::updateBooking($other['id'], ['roomId' => $room('domba-pool-villa')['id']]), 'code:unavailable');
    B::updateBooking($b['id'], ['status' => 'cancelled']);
    eq(B::updateBooking($other['id'], ['roomId' => $room('domba-pool-villa')['id']])['roomSlug'], 'domba-pool-villa');
});

test('room edits are validated, capacity included', function () use ($room) {
    $r = $room('na-treehouse');
    $u = B::updateRoom($r['id'], ['price' => 50000, 'capacity' => 3, 'active' => false]);
    eq([$u['price'], $u['capacity'], $u['active']], [50000, 3, false]);
    B::updateRoom($r['id'], ['capacity' => 2, 'active' => true]);
    throws(fn () => B::updateRoom($r['id'], ['capacity' => 0]), '/Invalid value/');
    throws(fn () => B::updateRoom($r['id'], ['images' => ['does-not-exist']]), '/Invalid value/');
});

// ---------- category 2: per person ----------
test('per-person options are priced per adult and per child per night', function () use ($guest, $d, $pkg) {
    $fb = $pkg('Full board');
    $q = B::quote('per_person', null, $fb, 2, 2, 1, false, false);
    eq($q['total'], 2 * 2 * $fb['priceAdult'] + 1 * 2 * $fb['priceChild']);
    eq(count($q['lines']), 2);
});

test('a per-person group gets rooms from the priority list, which then can\'t be booked', function () use ($guest, $d, $pkg, $room, $slugs) {
    B::saveSetup(['priority' => array_map(fn ($s) => $room($s)['id'], ['kitul-canopy-cabin', 'weera-canopy-cabin', 'hora-pool-villa', 'domba-pool-villa'])]);
    $hb = $pkg('Half board');
    $three = B::createBooking($guest + ['kind' => 'per_person', 'packageId' => $hb['id'], 'adults' => 2, 'children' => 1, 'checkIn' => $d(60), 'checkOut' => $d(62)]);
    eq([$three['kind'], $three['packageName'], $three['roomId']], ['per_person', 'Half board', null]);
    eq($slugs($three), ['kitul-canopy-cabin', 'weera-canopy-cabin']);
    eq($three['totalAmount'], 2 * 2 * $hb['priceAdult'] + 2 * $hb['priceChild']);
    $two = B::createBooking($guest + ['kind' => 'per_person', 'packageId' => $hb['id'], 'adults' => 2, 'checkIn' => $d(61), 'checkOut' => $d(63)]);
    eq($slugs($two), ['hora-pool-villa'], 'next free room in the list');
    throws(fn () => B::createBooking($guest + ['room' => 'kitul-canopy-cabin', 'checkIn' => $d(61), 'checkOut' => $d(62)]), 'code:unavailable');
    $rooms = array_column(B::searchRooms($d(60), $d(61)), null, 'slug');
    eq([$rooms['kitul-canopy-cabin']['available'], $rooms['mara-pool-villa']['available']], [false, true]);
});

test('a room that is not bookable on the website can still be given to per-person groups', function () use ($guest, $d, $pkg, $room, $slugs) {
    $dahara = $room('dahara-honeymoon-hideaway');
    $before = B::priorityRoomIds();
    B::updateRoom($dahara['id'], ['active' => false]);
    B::saveSetup(['priority' => [$dahara['id'], $room('yodha-family-lodge')['id']]]);
    eq(array_column(B::searchRooms($d(520), $d(521)), 'slug') === array_values(array_diff(array_column(B::searchRooms($d(520), $d(521)), 'slug'), ['dahara-honeymoon-hideaway'])), true, 'guests cannot pick it');
    $g = B::createBooking($guest + ['kind' => 'per_person', 'packageId' => $pkg('Full board')['id'], 'adults' => 2, 'checkIn' => $d(520), 'checkOut' => $d(521)]);
    eq($slugs($g), ['dahara-honeymoon-hideaway'], 'groups still get it');
    eq(in_array($d(520), B::unavailableNights('per_person', null, 4, $d(519), $d(522)), true), false, 'the family lodge still sleeps 4');
    eq(in_array($dahara['id'], array_column(B::roomsInUse(), 'id'), true), true);
    B::updateRoom($dahara['id'], ['active' => true]);
    B::saveSetup(['priority' => $before]);
});

test('per-person groups are refused when the priority rooms are full', function () use ($guest, $d, $pkg, $room) {
    // only domba is still free on night 61 in the priority list
    throws(fn () => B::createBooking($guest + ['kind' => 'per_person', 'packageId' => $pkg('Full board')['id'], 'adults' => 3, 'checkIn' => $d(61), 'checkOut' => $d(62)]), 'code:full');
    eq(B::checkGroup($d(61), $d(62), 2)['rooms'], [$room('domba-pool-villa')['id']]);
    if (!in_array($d(61), B::unavailableNights('per_person', null, 3, $d(55), $d(70)), true)) throw new Exception('night 61 should be full for 3');
    eq(in_array($d(64), B::unavailableNights('per_person', null, 3, $d(55), $d(70)), true), false);
});

test('the hotel capacity limits every booking', function () use ($guest, $d, $pkg) {
    B::saveSetup(['capacity' => 5]);
    $fb = $pkg('Full board');
    B::createBooking($guest + ['kind' => 'per_person', 'packageId' => $fb['id'], 'adults' => 3, 'checkIn' => $d(70), 'checkOut' => $d(71)]);
    eq(B::capacityLeft(B::occupancy($d(70), $d(71))), 2);
    throws(fn () => B::createBooking($guest + ['kind' => 'per_person', 'packageId' => $fb['id'], 'adults' => 3, 'checkIn' => $d(70), 'checkOut' => $d(71)]), 'code:full');
    B::createBooking($guest + ['room' => 'mara-pool-villa', 'checkIn' => $d(70), 'checkOut' => $d(71)]); // takes its 2
    throws(fn () => B::createBooking($guest + ['room' => 'na-treehouse', 'checkIn' => $d(70), 'checkOut' => $d(71)]), 'code:full');
    eq(count(B::unavailableNights('room', null, 1, $d(70), $d(71))), 1, 'no room left that night');
    $admin = B::createBooking(['roomId' => B::getRoom('na-treehouse')['id'], 'checkIn' => $d(70), 'checkOut' => $d(71), 'guestName' => 'Extra bed', 'source' => 'phone'], true);
    eq($admin['status'], 'confirmed', 'admins may go over the capacity');
    B::saveSetup(['capacity' => 20]);
});

test('hidden options are for admins only; a day visit uses no rooms or capacity', function () use ($guest, $d, $pkg) {
    $day = $pkg('Day out');
    throws(fn () => B::createBooking($guest + ['kind' => 'per_person', 'packageId' => $day['id'], 'adults' => 4, 'checkIn' => $d(80)]), '/choose an option/');
    B::savePackage($day['id'], ['active' => true, 'priceAdult' => 4000, 'priceChild' => 2000]);
    $b = B::createBooking($guest + ['kind' => 'per_person', 'packageId' => $day['id'], 'adults' => 20, 'children' => 5, 'checkIn' => $d(80), 'partyArea' => true, 'jblSpeaker' => true]);
    eq([$b['kind'], $b['rooms'], $b['nights'], $b['checkOut'], $b['partyArea']], ['day_out', [], 0, $d(81), false]);
    eq($b['totalAmount'], 20 * 4000 + 5 * 2000 + B::prices()['jblSpeaker']);
    eq(B::capacityLeft(B::occupancy($d(80), $d(81))), 20);
    throws(fn () => B::createBooking(['adults' => 51] + $guest + ['kind' => 'per_person', 'packageId' => $day['id'], 'checkIn' => $d(5)]), '/up to 50 adults/');
});

test('admins can give a group other rooms, but not taken ones', function () use ($guest, $d, $pkg, $room, $slugs) {
    $b = B::createBooking($guest + ['kind' => 'per_person', 'packageId' => $pkg('Full board')['id'], 'adults' => 2, 'checkIn' => $d(90), 'checkOut' => $d(92)]);
    eq($slugs($b), ['kitul-canopy-cabin']);
    B::createBooking($guest + ['room' => 'mara-pool-villa', 'checkIn' => $d(91), 'checkOut' => $d(92)]);
    throws(fn () => B::assignRooms($b['id'], [$room('mara-pool-villa')['id']]), 'code:unavailable');
    $c = B::confirmBooking($b['id'], ['rooms' => [$room('dahara-honeymoon-hideaway')['id']]]);
    eq([$c['status'], $slugs($c)], ['confirmed', ['dahara-honeymoon-hideaway']]);
    eq(B::searchRooms($d(90), $d(91))[array_search('kitul-canopy-cabin', array_column(B::listRooms(), 'slug'))]['available'], true, 'kitul freed');
    throws(fn () => B::confirmBooking($b['id'], []), 'code:status');
    $choices = array_column(B::roomChoices($b['id']), null, 'id');
    eq([$choices[$room('dahara-honeymoon-hideaway')['id']]['assigned'], $choices[$room('mara-pool-villa')['id']]['free']], [true, false]);
});

test('changing a group\'s dates keeps its rooms, unless they are taken', function () use ($guest, $d, $pkg, $slugs) {
    $b = B::createBooking($guest + ['kind' => 'per_person', 'packageId' => $pkg('Half board')['id'], 'adults' => 2, 'checkIn' => $d(100), 'checkOut' => $d(101)]);
    eq($slugs(B::updateBooking($b['id'], ['checkOut' => $d(102)])), ['kitul-canopy-cabin']);
    B::createBooking($guest + ['room' => 'kitul-canopy-cabin', 'checkIn' => $d(103), 'checkOut' => $d(104)]);
    throws(fn () => B::updateBooking($b['id'], ['checkOut' => $d(104)]), 'code:unavailable');
    $r = B::createBooking($guest + ['room' => 'domba-pool-villa', 'checkIn' => $d(110), 'checkOut' => $d(111)]);
    $u = B::updateBooking($r['id'], ['kind' => 'per_person', 'packageId' => $pkg('Full board')['id'], 'adults' => 2]);
    eq([$u['kind'], $u['roomId'], $slugs($u)], ['per_person', null, ['kitul-canopy-cabin']]);
});

test('the party area takes one group per night, and room bookers are told', function () use ($guest, $d) {
    $p = B::createBooking($guest + ['room' => 'hora-pool-villa', 'checkIn' => $d(120), 'checkOut' => $d(123), 'partyArea' => true, 'jblSpeaker' => true]);
    eq($p['totalAmount'], 3 * B::getRoom('hora-pool-villa')['price'] + 3 * B::prices()['partyArea'] + 3 * B::prices()['jblSpeaker']);
    throws(fn () => B::createBooking($guest + ['room' => 'mara-pool-villa', 'checkIn' => $d(122), 'checkOut' => $d(124), 'partyArea' => true]), 'code:party_area_unavailable');
    eq(B::partyAreaStatus($d(121), $d(125))['taken'], [$d(121), $d(122)]);
    eq(B::createBooking($guest + ['room' => 'domba-pool-villa', 'checkIn' => $d(123), 'checkOut' => $d(124), 'partyArea' => true])['partyArea'], true);
});

test('setup is validated and saved', function () use ($room) {
    $s = B::saveSetup(['labels' => ['room' => 'Choose a room', 'person' => 'Per person'], 'payment' => ['bank' => 'BOC', 'accountName' => 'Yodhaara', 'accountNo' => '0012345', 'advancePct' => 50]]);
    eq([$s['labels']['room'], $s['payment']['accountNo'], $s['payment']['advancePct']], ['Choose a room', '0012345', 50]);
    eq(B::advanceDue(10001), 5001);
    throws(fn () => B::saveSetup(['labels' => ['room' => '']]), '/cannot be empty/');
    throws(fn () => B::saveSetup(['payment' => ['advancePct' => 120]]), '/percentage/');
    throws(fn () => B::saveSetup(['priority' => [999]]), '/no longer exists/');
    B::saveSetup(['labels' => ['room' => 'Select room']]);
    throws(fn () => B::savePackage(null, ['name' => '', 'priceAdult' => 1]), '/name the option/');
    eq(B::savePackage(null, ['name' => 'Bed & breakfast plus', 'priceAdult' => 9500, 'priceChild' => 4000])['active'], true);
});

// ---------- POS ----------
$stay = fn (string $slug, int $in, int $out, array $extra = []) => B::createBooking($extra + ['roomId' => B::getRoom($slug)['id'], 'checkIn' => $d($in), 'checkOut' => $d($out),
    'guestName' => 'POS Guest ' . $slug, 'guestEmail' => 'pos@example.com', 'source' => 'phone', 'status' => 'checked_in', 'totalAmount' => 50000], true, 'admin');
$itemId = fn (string $name) => current(array_filter(Pos::listItems(true), fn ($i) => $i['name'] === $name))['id'];
$cat = fn (string $name) => current(array_filter(Pos::listCategories(true), fn ($c) => $c['name'] === $name));
$A = $stay('kumbuk-treehouse', -1, 1);
$Bk = $stay('mara-pool-villa', -1, 2);

test('the POS has editable categories, Bite first with chicken, fish and mix', function () use ($cat) {
    eq(array_column(Pos::listCategories(), 'name'), ['Bite', 'Food', 'Beverages', 'Other']);
    eq(array_column(array_values(array_filter(Pos::listItems(), fn ($i) => $i['category'] === 'Bite')), 'name'), ['Chicken', 'Fish', 'Mix']);
    $c = Pos::saveCategory(null, ['name' => 'Desserts']);
    $it = Pos::saveItem(null, ['name' => 'Watalappan', 'categoryId' => $c['id'], 'price' => 700]);
    eq([$it['category'], $it['price']], ['Desserts', 700]);
    Pos::saveCategory($c['id'], ['active' => false]);
    eq(in_array($it['id'], array_column(Pos::listItems(), 'id'), true), false, 'hidden with its category');
    throws(fn () => Pos::saveCategory(null, ['name' => 'Food']), '/already a category/');
    throws(fn () => Pos::saveItem(null, ['name' => 'Bad', 'categoryId' => 999, 'price' => 1]), '/choose a category/');
});

test('each tap charges an item to the room bill, totalled by category', function () use ($A, $itemId) {
    foreach (range(1, 5) as $_) Pos::addCharges($A['id'], [['itemId' => $itemId('Chicken')]], 'cashier');
    $f = Pos::addCharges($A['id'], [['itemId' => $itemId('Lion lager'), 'qty' => 3, 'note' => 'cold'], ['name' => 'Birthday cake', 'price' => 4500]], 'cashier');
    eq(count($f['charges']), 7);
    $lines = array_column($f['lines'], null, 'name');
    eq([$lines['Chicken']['qty'], $lines['Chicken']['amount'], count($lines['Chicken']['chargeIds'])], [5, 9000, 5]);
    eq($f['totals']['categories'], [['name' => 'Bite', 'amount' => 9000], ['name' => 'Beverages', 'amount' => 2700], ['name' => 'Other', 'amount' => 4500]]);
    // the 10% service charge (Booking setup) is on the running bill too
    eq([$f['totals']['stay'], $f['totals']['items'], $f['totals']['service'], $f['totals']['balance']], [50000, 16200, 6620, 72820]);
});

test('pending, cancelled and blocked bookings take no charges', function () use ($guest, $d, $itemId) {
    $pending = B::createBooking($guest + ['room' => 'domba-pool-villa', 'checkIn' => $d(150), 'checkOut' => $d(151)]);
    throws(fn () => Pos::addCharges($pending['id'], [['itemId' => $itemId('Coffee')]], 'x'), '/pending request/');
    $block = B::createBooking(['roomId' => B::getRoom('domba-pool-villa')['id'], 'checkIn' => $d(152), 'checkOut' => $d(153), 'source' => 'block'], true);
    throws(fn () => Pos::addCharges($block['id'], [['itemId' => $itemId('Coffee')]], 'x'), 'code:closed');
});

test('the − button removes one; removals stay in the history', function () use ($A) {
    $f = Pos::folio($A['id']);
    $beer = current(array_filter($f['charges'], fn ($c) => $c['name'] === 'Lion lager'));
    $f = Pos::voidCharge($beer['id'], null, 'cashier', 1);
    $lines = array_column($f['lines'], null, 'name');
    eq($lines['Lion lager']['qty'], 2);
    eq(count(array_filter($f['charges'], fn ($c) => $c['voided'] && $c['name'] === 'Lion lager' && $c['qty'] === 1)), 1);
    $cake = current(array_filter($f['charges'], fn ($c) => $c['name'] === 'Birthday cake'));
    $f = Pos::voidCharge($cake['id'], 'Wrong guest', 'cashier');
    eq($f['totals']['items'], 9000 + 1800);
    throws(fn () => Pos::voidCharge($cake['id'], 'again', 'x'), 'code:voided');
});

test('items move between guests, whole lines or part of one', function () use ($A, $Bk) {
    $beer = current(array_filter(Pos::folio($A['id'])['charges'], fn ($c) => $c['name'] === 'Lion lager' && !$c['voided']));
    throws(fn () => Pos::transfer($A['id'], $A['id'], [['chargeId' => $beer['id']]], 'x'), '/different guest/');
    $r = Pos::transfer($A['id'], $Bk['id'], [['chargeId' => $beer['id'], 'qty' => 1]], 'cashier');
    eq([$r['from']['totals']['items'], $r['to']['totals']['items']], [9000 + 900, 900]);
    eq(current(array_filter($r['to']['charges'], fn ($c) => $c['name'] === 'Lion lager'))['movedFrom']['bookingId'], $A['id']);
});

test('advance payments count towards the balance', function () use ($A) {
    $f = Pos::addPayment($A['id'], ['amount' => 20000, 'method' => 'bank', 'note' => 'deposit'], 'cashier');
    eq([$f['totals']['paid'], $f['totals']['balance']], [20000, 50000 + 9900 + 5990 - 20000]);
    throws(fn () => Pos::addPayment($A['id'], ['amount' => 0, 'method' => 'cash'], 'x'), '/above 0/');
});

test('the POS checks guests in: a group can get other rooms, and an advance is taken', function () use ($guest, $d, $pkg, $room, $slugs) {
    $b = B::createBooking($guest + ['kind' => 'per_person', 'packageId' => $pkg('Full board')['id'], 'adults' => 3, 'checkIn' => $d(0), 'checkOut' => $d(2)]);
    eq(in_array($b['id'], array_column(Pos::arrivals(), 'id'), true), true, 'on the arrivals list');
    $choices = array_column(array_filter(B::roomChoices($b['id']), fn ($r) => $r['free'] && $r['active']), 'id');
    $two = array_slice(array_values(array_diff($choices, $b['rooms'] ? array_column($b['rooms'], 'id') : [])), 0, 2);
    $f = Pos::checkIn($b['id'], ['rooms' => $two, 'advance' => ['amount' => 30000, 'method' => 'cash']], 'reception');
    eq([$f['booking']['status'], array_column($f['booking']['rooms'], 'id'), $f['totals']['paid']], ['checked_in', $two, 30000]);
    throws(fn () => Pos::checkIn($b['id'], [], 'x'), '/already checked in/');
    $r = B::createBooking($guest + ['room' => 'weera-canopy-cabin', 'checkIn' => $d(0), 'checkOut' => $d(1)]);
    eq(Pos::checkIn($r['id'], ['adults' => 2, 'children' => 0], 'reception')['booking']['adults'], 2);
});

test('checkout: one bill with custom lines and a percentage discount', function () use ($A, $itemId) {
    $bill = Pos::checkout([$A['id']], ['lines' => [['name' => 'Late checkout', 'amount' => 2000]], 'discount' => ['type' => 'percent', 'value' => 10],
        'payments' => [['amount' => 25000, 'method' => 'card']]], 'cashier');
    if (!preg_match('/^INV-\d{5}$/', $bill['billNo'])) throw new Exception('bad bill no ' . $bill['billNo']);
    $sub = 50000 + 9900 + 2000;
    $after = $sub - (int) round($sub * 0.1);  // the discount comes off first
    $service = (int) round($after * 0.1);     // then the 10% service charge is added
    eq([$bill['itemsTotal'], $bill['discount'], $bill['serviceCharge'], $bill['total'], $bill['paid']], [11900, (int) round($sub * 0.1), $service, $after + $service, 45000]);
    eq([$bill['snapshot']['totals']['discountLabel'], $bill['snapshot']['totals']['servicePct'], $bill['snapshot']['totals']['service']], ['10%', 10, $service]);
    $lines = array_column($bill['snapshot']['lines'], null, 'name');
    eq([$lines['Chicken']['qty'], $lines['Late checkout']['category']], [5, 'Other']);
    eq([B::getBooking($A['id'])['status'], B::getBooking($A['id'])['billId']], ['checked_out', $bill['id']]);
    throws(fn () => Pos::addCharges($A['id'], [['itemId' => $itemId('Coffee')]], 'x'), '/already billed/');
    throws(fn () => B::updateBooking($A['id'], ['status' => 'checked_in']), 'code:billed');
    eq(Pos::addBillPayment($bill['id'], ['amount' => $bill['balance'], 'method' => 'cash'], 'cashier')['balance'], 0);
    throws(fn () => Pos::checkout([$A['id']], ['discount' => ['type' => 'percent', 'value' => 150]], 'x'), '/percentage/');
});

test('several guests on one bill; voiding a bill reopens their room bills', function () use ($Bk, $stay, $itemId) {
    $C = $stay('hora-pool-villa', -2, 0);
    Pos::addCharges($C['id'], [['itemId' => $itemId('Coffee'), 'qty' => 2]], 'cashier');
    // the service charge can be left off a bill
    $bill = Pos::checkout([$Bk['id'], $C['id']], ['serviceCharge' => false, 'payments' => [['amount' => 101800, 'method' => 'cash']]], 'cashier');
    eq([$bill['stayTotal'], $bill['itemsTotal'], $bill['serviceCharge'], $bill['balance'], $bill['places']], [100000, 1800, 0, 0, ['Mara Pool Villa', 'Hora Pool Villa']]);
    $void = Pos::voidBill($bill['id'], 'Guest is staying one more night', 'manager');
    eq([$void['status'], B::getBooking($C['id'])['status'], Pos::folio($Bk['id'])['totals']['paid']], ['void', 'checked_in', 101800]);
    throws(fn () => Pos::voidBill($bill['id'], 'again', 'x'), 'code:void');
});

test('the service charge comes from Booking setup', function () use ($stay) {
    $G = $stay('dahara-honeymoon-hideaway', 410, 411);
    eq([Pos::folio($G['id'])['totals']['service'], Pos::folio($G['id'])['totals']['total']], [5000, 55000]);
    B::saveSetup(['serviceCharge' => 0]);
    eq([Pos::folio($G['id'])['totals']['service'], Pos::folio($G['id'])['totals']['total']], [0, 50000]);
    throws(fn () => B::saveSetup(['serviceCharge' => 150]), '/percentage/');
    eq(B::saveSetup(['serviceCharge' => 10])['serviceCharge'], 10);
});

test('walk-ins without a booking are entered at the POS and checked in', function () use ($d, $room) {
    $stay = ['kind' => 'room', 'roomId' => $room('dahara-honeymoon-hideaway')['id'], 'checkIn' => $d(400), 'checkOut' => $d(402)];
    $count = fn () => (int) Ydr\Db::one('SELECT COUNT(*) AS n FROM bookings')['n'];
    $before = $count();
    throws(fn () => Pos::walkIn($stay, 'reception'), '/guest name/');
    throws(fn () => Pos::walkIn($stay + ['guestName' => 'Walk-in Guest', 'payment' => ['amount' => 5000, 'method' => 'gold']], 'reception'), '/how it was paid/');
    eq($count(), $before, 'nothing saved when the details are wrong');
    // source and status can't be chosen: a walk-in is checked in straight away
    $f = Pos::walkIn($stay + ['guestName' => 'Walk-in Guest', 'guestPhone' => '+94 77 000 1111', 'adults' => 2, 'source' => 'website', 'status' => 'pending',
        'payment' => ['amount' => 10000, 'method' => 'cash']], 'reception');
    eq([$f['open'], $f['booking']['source'], $f['booking']['status'], $f['booking']['createdBy'], $f['booking']['adults'], $f['totals']['paid']],
        [true, 'walk_in', 'checked_in', 'admin:reception', 2, 10000]);
    eq(in_array('checkin', array_column($f['log'], 'action'), true), true, 'check-in logged');
    throws(fn () => Pos::walkIn($stay + ['guestName' => 'Second Guest'], 'reception'), 'code:unavailable');
});

test('the home page contact details are edited in Admin → Website', function () {
    $env = Ydr\Config::get()['site'];
    eq(Ydr\Site::info()['phone'], $env['phone'], '.env until changed');
    $s = Ydr\Site::save(['contact' => ['phone' => '077 075 3774', 'whatsapp' => '0770753774', 'address' => 'Ella road, Wellawaya', 'mapCoords' => '6.81, 81.11']]);
    eq([Ydr\Site::info()['phone'], Ydr\Site::info()['whatsapp'], Ydr\Site::info()['address'], Ydr\Site::info()['mapCoords']], ['077 075 3774', '94770753774', 'Ella road, Wellawaya', '6.81,81.11']);
    eq(Ydr\Config::location()['lat'], 6.81, 'the map pin follows');
    throws(fn () => Ydr\Site::save(['contact' => ['email' => 'not an email']]), '/email/');
    throws(fn () => Ydr\Site::save(['contact' => ['facebook' => 'facebook.com/x']]), '/https/');
    throws(fn () => Ydr\Site::save(['contact' => ['mapCoords' => '600, 2']]), '/latitude/');
    // Top Mountain's home page takes its photos from the media build: no slots, slideshow or gallery here
    eq([$s['slots'], $s['hero'], $s['gallery']], [[], [], []]);
    eq(Ydr\Site::uses('room-101-01'), false, 'website photos are never blocked from rooms');
    // back to .env
    Ydr\Site::save(['contact' => ['phone' => '', 'whatsapp' => '', 'address' => '', 'mapCoords' => '']]);
    eq(Ydr\Site::info()['phone'], $env['phone']);
});

test('a confirmed booking queues a WhatsApp alert; without an API key it waits in the outbox', function () use ($guest, $d) {
    eq(Ydr\Whatsapp::admin()['phone'], '94777368264', 'the number to alert (the hotline)');
    $b = B::createBooking($guest + ['room' => 'kitul-canopy-cabin', 'checkIn' => $d(510), 'checkOut' => $d(512)]);
    $b = B::confirmBooking($b['id'], []);
    Ydr\Whatsapp::bookingConfirmed($b);
    eq(Ydr\Whatsapp::hasQueue(), true);
    $r = Ydr\Whatsapp::send("Test alert {$b['ref']}");
    if (empty($r['outbox']) || !str_contains((string) file_get_contents($r['outbox']), $b['ref'])) throw new Exception('not saved to the outbox');
    Ydr\Whatsapp::flushQueue();
    eq(Ydr\Whatsapp::hasQueue(), false);
    throws(fn () => Ydr\Whatsapp::save(['phone' => '12']), '/country code/');
    eq(Ydr\Whatsapp::save(['apiKey' => 'abc1234'])['keyHint'], '••••234');
    eq(Ydr\Whatsapp::enabled(), true);
    Ydr\Whatsapp::save(['removeKey' => true]);
    eq(Ydr\Whatsapp::enabled(), false);
});

test('bookings show in the Google Calendar feed at a secret link; cancelled ones drop out', function () use ($guest, $d) {
    $feed = Ydr\Ical::admin();
    if (!preg_match('#/calendar/([0-9a-f]{32})\.ics$#', $feed['url'], $m)) throw new Exception('odd link ' . $feed['url']);
    $wrong = ($m[1][0] === 'a' ? 'b' : 'a') . substr($m[1], 1); // one character off
    eq([Ydr\Ical::matches($m[1]), Ydr\Ical::matches(''), Ydr\Ical::matches($wrong)], [true, false, false]);
    $b = B::createBooking(['guestName' => 'Calendar, Guest; Test', 'specialRequests' => "Late arrival\nvegetarian, no pork"] + $guest
        + ['room' => 'kitul-canopy-cabin', 'checkIn' => $d(520), 'checkOut' => $d(523)]);
    $event = function () use ($b) {
        $ics = Ydr\Ical::feed();
        if (!str_starts_with($ics, "BEGIN:VCALENDAR\r\n") || !str_ends_with($ics, "END:VCALENDAR\r\n")) throw new Exception('not a calendar file');
        foreach (explode("\r\n", $ics) as $l) if (strlen($l) > 75) throw new Exception('line longer than 75 bytes: ' . $l);
        $all = str_replace("\r\n ", '', $ics); // unfold
        return preg_match('/BEGIN:VEVENT\r\nUID:booking-' . $b['id'] . '@[^\r]+\r\n.*?END:VEVENT/s', $all, $e) ? $e[0] : null;
    };
    $e = (string) $event();
    foreach (['DTSTART;VALUE=DATE:' . str_replace('-', '', $d(520)), 'DTEND;VALUE=DATE:' . str_replace('-', '', $d(523)),
        'SUMMARY:Pending · Calendar\, Guest\; Test · ' . $b['roomName'], 'Late arrival\nvegetarian\, no pork', 'STATUS:TENTATIVE', $b['ref']] as $want) {
        if (!str_contains($e, $want)) throw new Exception("missing $want in\n$e");
    }
    B::confirmBooking($b['id'], []);
    $e = (string) $event();
    if (!str_contains($e, 'STATUS:CONFIRMED') || str_contains($e, 'SUMMARY:Pending')) throw new Exception("not confirmed in\n$e");
    B::updateBooking($b['id'], ['status' => 'cancelled']);
    eq($event(), null, 'a cancelled booking is left out');
    Ydr\Ical::reset();
    eq(Ydr\Ical::matches($m[1]), false, 'the old link stops working');
});

// ---------- reservations, agreed rates, commission, payment details, invoices, receipts, coupons ----------
test('a reservation is held for the set days for its advance, then released', function () use ($guest, $d, $room) {
    eq(B::holdDays(), 7);
    $far = B::createBooking($guest + ['room' => 'hora-pool-villa', 'checkIn' => $d(440), 'checkOut' => $d(442)]);
    eq([$far['status'], $far['holdUntil']], ['pending', $d(7)], 'a website request waits 7 days');
    eq(B::defaultHold($d(3)), $d(3), 'but not past the arrival');
    $mine = B::createBooking(['guestName' => 'Phone Reservation', 'kind' => 'room', 'roomId' => $room('hora-pool-villa')['id'], 'checkIn' => $d(444), 'checkOut' => $d(445),
        'source' => 'phone', 'status' => 'pending', 'holdUntil' => $d(2)], true, 'admin');
    eq($mine['holdUntil'], $d(2), 'staff choose the hold date');
    throws(fn () => B::updateBooking($mine['id'], ['holdUntil' => $d(-1)]), '/past/');
    eq(B::updateBooking($mine['id'], ['holdUntil' => $d(5)])['holdUntil'], $d(5));
    eq(B::createBooking(['guestName' => 'No hold', 'kind' => 'room', 'roomId' => $room('hora-pool-villa')['id'], 'checkIn' => $d(446), 'checkOut' => $d(447),
        'source' => 'phone', 'status' => 'pending', 'holdUntil' => ''], true, 'admin')['holdUntil'], null, 'empty = never released');
    eq(B::createBooking(['guestName' => 'Confirmed', 'kind' => 'room', 'roomId' => $room('hora-pool-villa')['id'], 'checkIn' => $d(448), 'checkOut' => $d(449),
        'source' => 'phone', 'status' => 'confirmed'], true, 'admin')['holdUntil'], null, 'only reservations are held');
    // the hold date passes with no advance: released, dates free again
    Ydr\Db::run('UPDATE bookings SET hold_until = ? WHERE id IN (?, ?)', [$d(-1), $far['id'], $mine['id']]);
    Ydr\Db::run('INSERT INTO pos_payments (booking_id, bill_id, amount, method, note, created_at) VALUES (?, NULL, 1000, ?, ?, ?)', [$mine['id'], 'bank', 'paid', Ydr\Db::toDbTime(Ydr\Js::isoNow())]);
    eq(B::releaseExpired(), [$far['ref']], 'one with a payment stays');
    $far = B::getBooking($far['id']);
    eq($far['status'], 'cancelled');
    if (!str_contains($far['adminNotes'], 'Released automatically')) throw new Exception('no note saying why');
    eq(B::getBooking($mine['id'])['status'], 'pending');
    eq(B::releaseExpired(), [], 'nothing twice');
    B::createBooking($guest + ['room' => 'hora-pool-villa', 'checkIn' => $d(440), 'checkOut' => $d(442)]); // the dates are free again
    B::saveSetup(['holdDays' => 0]);
    eq(B::defaultHold($d(30)), null, '0 days = never released');
    throws(fn () => B::saveSetup(['holdDays' => -2]), '/whole number of days/');
    B::saveSetup(['holdDays' => 7]);
});

test('agreed rates set the price, are kept when guests change, and reset with another option', function () use ($d, $room, $pkg) {
    $nights = 3;
    $b = B::createBooking(['guestName' => 'Negotiated', 'kind' => 'room', 'roomId' => $room('domba-pool-villa')['id'], 'checkIn' => $d(450), 'checkOut' => $d(450 + $nights),
        'source' => 'phone', 'status' => 'confirmed', 'rateRoom' => 30000], true, 'admin');
    eq([$b['totalAmount'], $b['rates']['room'], $b['listRates']['room']], [90000, 30000, 42000]);
    eq(B::updateBooking($b['id'], ['checkOut' => $d(450 + 4)])['totalAmount'], 120000, 'a longer stay at the agreed rate');
    eq(B::updateBooking($b['id'], ['rateRoom' => ''])['totalAmount'], 168000, 'empty = back to the price list');
    eq(B::updateBooking($b['id'], ['rateRoom' => 42000])['rates']['room'], null, 'the list rate is stored as "the price list"');
    $fb = $pkg('Full board');
    $g = B::createBooking(['guestName' => 'Group deal', 'kind' => 'per_person', 'packageId' => $fb['id'], 'checkIn' => $d(460), 'checkOut' => $d(462), 'adults' => 4, 'children' => 2,
        'source' => 'phone', 'status' => 'confirmed', 'rateAdult' => 9000, 'rateChild' => 4000], true, 'admin');
    eq($g['totalAmount'], (4 * 9000 + 2 * 4000) * 2);
    eq(B::updateBooking($g['id'], ['adults' => 5])['totalAmount'], (5 * 9000 + 2 * 4000) * 2, 're-priced at the agreed rates');
    $hb = $pkg('Half board');
    $g = B::updateBooking($g['id'], ['kind' => 'per_person', 'packageId' => $hb['id']]);
    eq([$g['rates']['adult'], $g['totalAmount']], [null, (5 * $hb['priceAdult'] + 2 * $hb['priceChild']) * 2], 'another option starts from its price list');
    throws(fn () => B::createBooking(['guestName' => 'x', 'kind' => 'room', 'roomId' => $room('domba-pool-villa')['id'], 'checkIn' => $d(470), 'checkOut' => $d(471),
        'source' => 'phone', 'rateRoom' => -5], true, 'admin'), '/whole numbers/');
    $q = B::quote('room', $room('domba-pool-villa'), null, 2, 0, 0, true, false, ['room' => 30000]);
    eq([$q['stay'], $q['total'] - $q['stay']], [60000, B::prices()['partyArea'] * 2], 'the room charge apart from the extras');
});

test('commission: a % or an amount, and the net the resort keeps', function () use ($d, $room) {
    $b = B::createBooking(['guestName' => 'Agent booking', 'kind' => 'room', 'roomId' => $room('domba-pool-villa')['id'], 'checkIn' => $d(472), 'checkOut' => $d(474),
        'source' => 'travel_agent', 'status' => 'confirmed', 'commissionType' => 'percent', 'commissionValue' => 15, 'commissionTo' => 'Kamal Tours'], true, 'admin');
    eq([$b['commission']['amount'], $b['commission']['to'], $b['netAmount']], [12600, 'Kamal Tours', 84000 - 12600]);
    $b = B::updateBooking($b['id'], ['commissionType' => 'amount', 'commissionValue' => 5000]);
    eq([$b['commission']['amount'], $b['netAmount']], [5000, 79000]);
    eq(B::updateBooking($b['id'], ['commissionType' => ''])['commission']['amount'], 0);
    throws(fn () => B::updateBooking($b['id'], ['commissionType' => 'percent', 'commissionValue' => 150]), '/percentage/');
});

test('payments note their extra details and any fee; the receipt shows the full amount', function () use ($stay) {
    eq(array_column(Pos::paymentFields(), 'label'), ['Received into'], 'a default field until it is set up');
    Pos::savePaymentFields([['label' => 'Received into', 'type' => 'choice', 'options' => "Cash in hand\nBOC 123", 'required' => true], ['label' => 'Bank reference', 'type' => 'text']]);
    eq(array_column(Pos::paymentFields(), 'key'), ['received_into', 'bank_reference']);
    throws(fn () => Pos::savePaymentFields([['label' => 'Till', 'type' => 'choice', 'options' => '']]), '/choices/');
    $H = $stay('weera-canopy-cabin', 475, 476, ['status' => 'confirmed']);
    throws(fn () => Pos::addPayment($H['id'], ['amount' => 10000, 'method' => 'bank'], 'cashier'), '/Received into/');
    throws(fn () => Pos::addPayment($H['id'], ['amount' => 10000, 'method' => 'bank', 'details' => ['received_into' => 'Sampath 9']], 'cashier'), '/one of the options/');
    throws(fn () => Pos::addPayment($H['id'], ['amount' => 10000, 'method' => 'bank', 'fee' => 20000, 'details' => ['received_into' => 'BOC 123']], 'cashier'), '/fee/');
    $f = Pos::addPayment($H['id'], ['amount' => 10000, 'method' => 'bank', 'fee' => 500, 'details' => ['received_into' => 'BOC 123', 'bank_reference' => 'TT55']], 'cashier');
    $p = current(array_filter($f['payments'], fn ($x) => $x['id'] === $f['paymentId']));
    eq([$p['amount'], $p['fee'], $p['net'], $p['details']], [10000, 500, 9500, [['label' => 'Received into', 'value' => 'BOC 123'], ['label' => 'Bank reference', 'value' => 'TT55']]]);
    eq([$f['totals']['paid'], $f['totals']['fees'], $f['totals']['received'], $f['totals']['balance']], [10000, 500, 9500, $f['totals']['total'] - 10000], 'the guest owes 10,000 less');
    // the receipt, at its private link
    preg_match('#/receipt/(RCT-\d+)/([0-9a-f]+)$#', $p['receiptUrl'], $m);
    eq(Ydr\Docs::receiptPayment($m[1], $m[2])['id'], $p['id']);
    eq(Ydr\Docs::receiptPayment($m[1], str_repeat('0', strlen($m[2]))), null, 'a wrong link shows nothing');
    $html = Ydr\Docs::receiptHtml($p);
    foreach ([$p['receiptNo'], 'LKR 10,000', 'Ten thousand rupees only', 'BOC 123', 'TT55', $H['ref']] as $want) if (!str_contains($html, $want)) throw new Exception("receipt lacks $want");
    if (preg_match('/9,500|fee/i', strip_tags($html))) throw new Exception('the receipt must not show the fee');
    eq([Ydr\Docs::words(1250), Ydr\Docs::words(2015000)], ['One thousand two hundred and fifty', 'Two million fifteen thousand']);
    Ydr\Settings::set(['payment_fields' => '']); // back to the default for the other tests
});

test('the payment invoice: what to pay, the bank details and the hold date, at a private link', function () use ($guest, $d) {
    B::saveSetup(['payment' => ['bank' => 'Bank of Ceylon', 'accountName' => 'Yodhaara (Pvt) Ltd', 'accountNo' => '0070 1234 5678', 'branch' => 'Wellawaya', 'advancePct' => 30]]);
    $b = B::createBooking($guest + ['room' => 'na-treehouse', 'checkIn' => $d(478), 'checkOut' => $d(480)]);
    $url = Ydr\Docs::invoiceUrl($b);
    preg_match('#/invoice/([^/]+)/([0-9a-f]+)$#', $url, $m);
    eq([$m[1], Ydr\Docs::invoiceBooking($m[1], $m[2])['id']], [$b['ref'], $b['id']]);
    eq(Ydr\Docs::invoiceBooking($m[1], 'nope'), null);
    $html = Ydr\Docs::invoiceHtml($b);
    $advance = 'LKR ' . number_format((int) round($b['totalAmount'] * 0.3));
    $balance = 'LKR ' . number_format($b['totalAmount'] - (int) round($b['totalAmount'] * 0.3));
    foreach (['Reservation invoice', $b['ref'], 'Advance to pay now (30%)', $advance, 'Balance on arrival', $balance, '0070 1234 5678', 'held until', 'size: A5'] as $want) if (!str_contains($html, $want)) throw new Exception("invoice lacks $want");
    Ydr\Docs::savePaper('A4');
    if (!str_contains(Ydr\Docs::invoiceHtml($b), 'size: A4')) throw new Exception('A4 paper');
    Ydr\Docs::savePaper('A5');
    throws(fn () => Ydr\Docs::savePaper('Letter'), '/A5 or A4/');
    B::saveSetup(['payment' => ['advancePct' => 0]]);
});

test('coupons: off the room charge only, with a usage limit, dates and batches', function () use ($guest, $d, $room) {
    $c = Ydr\Coupons::create(['code' => 'test10', 'type' => 'percent', 'value' => 10, 'maxUses' => 1], 'admin');
    eq([$c['code'], $c['state'], $c['left']], ['TEST10', 'ready', 1]);
    throws(fn () => Ydr\Coupons::create(['code' => 'TEST10', 'value' => 5], 'admin'), '/already a coupon/');
    $b = B::createBooking($guest + ['room' => 'kumbuk-treehouse', 'checkIn' => $d(430), 'checkOut' => $d(432), 'partyArea' => true, 'couponCode' => ' test10 ']);
    $party = B::prices()['partyArea'] * 2;
    eq([$b['couponCode'], $b['couponDiscount'], $b['totalAmount']], ['TEST10', 9600, 96000 + $party - 9600], '10% of the room only, not the party area');
    throws(fn () => B::createBooking($guest + ['room' => 'na-treehouse', 'checkIn' => $d(430), 'checkOut' => $d(431), 'couponCode' => 'TEST10']), '/already been used/');
    B::updateBooking($b['id'], ['status' => 'cancelled']);
    eq(Ydr\Coupons::usesOf('TEST10'), 0, 'a cancelled booking frees its use');
    $b = B::createBooking($guest + ['room' => 'kumbuk-treehouse', 'checkIn' => $d(430), 'checkOut' => $d(432), 'couponCode' => 'TEST10']);
    eq($b['couponDiscount'], 9600);
    eq(B::updateBooking($b['id'], ['checkOut' => $d(433)])['couponDiscount'], 14400, 'a longer stay: 10% of the new room charge');
    throws(fn () => B::createBooking($guest + ['room' => 'na-treehouse', 'checkIn' => $d(436), 'checkOut' => $d(437), 'couponCode' => 'NOPE99']), '/not a valid coupon/');
    $old = Ydr\Coupons::create(['code' => 'OLD-1', 'value' => 2000, 'type' => 'amount', 'validTo' => $d(-1)], 'admin');
    throws(fn () => Ydr\Coupons::check('OLD-1', 50000), '/expired/');
    Ydr\Coupons::create(['code' => 'LATER-1', 'value' => 5, 'validFrom' => $d(3)], 'admin');
    throws(fn () => Ydr\Coupons::check('LATER-1', 50000), '/can be used from/');
    $amt = Ydr\Coupons::create(['code' => 'FLAT5000', 'type' => 'amount', 'value' => 5000, 'maxUses' => 0], 'admin');
    eq([Ydr\Coupons::check('flat5000', 3000)['discount'], Ydr\Coupons::check('FLAT5000', 60000)['discount']], [3000, 5000], 'never more than the room charge');
    Ydr\Coupons::update($amt['id'], ['active' => false]);
    throws(fn () => Ydr\Coupons::check('FLAT5000', 60000), '/no longer valid/');
    // batches: numbered or random
    $r = Ydr\Coupons::generate(['count' => 5, 'style' => 'numbered', 'prefix' => 'tst', 'start' => 9, 'type' => 'percent', 'value' => 15, 'maxUses' => 1, 'batch' => 'Fair'], 'admin');
    eq([$r['batch'], array_column(array_reverse($r['coupons']), 'code')], ['Fair', ['TST-009', 'TST-010', 'TST-011', 'TST-012', 'TST-013']]);
    throws(fn () => Ydr\Coupons::generate(['count' => 2, 'style' => 'numbered', 'prefix' => 'TST', 'start' => 12, 'value' => 5], 'admin'), '/already exist/');
    $r2 = Ydr\Coupons::generate(['count' => 3, 'prefix' => 'GIFT', 'value' => 10, 'batch' => 'Fair'], 'admin');
    eq([$r2['batch'], count($r2['coupons']), count(array_filter($r2['coupons'], fn ($x) => preg_match('/^GIFT-[A-Z2-9]{6}$/', $x['code'])))], ['Fair (2)', 3, 3]);
    eq(Ydr\Coupons::setBatchActive('Fair', false), 5);
    throws(fn () => Ydr\Coupons::check('TST-009', 1000), '/no longer valid/');
    throws(fn () => Ydr\Coupons::delete($c['id']), '/has been used/');
    Ydr\Coupons::delete($old['id']);
    eq(Ydr\Coupons::get($old['id']), null);
});

test('a coupon at checkout comes off the room charge, not food or drinks', function () use ($stay, $itemId) {
    Ydr\Coupons::create(['code' => 'STAY20', 'type' => 'percent', 'value' => 20, 'maxUses' => 1], 'admin');
    $J = $stay('yodha-family-lodge', 476, 477);
    Pos::addCharges($J['id'], [['itemId' => $itemId('Coffee'), 'qty' => 4]], 'cashier');
    eq(Pos::couponPreview('stay20', (string) $J['id'])['discount'], 10000, '20% of the 50,000 stay');
    $bill = Pos::checkout([$J['id']], ['coupon' => 'STAY20'], 'cashier');
    $items = $bill['itemsTotal'];
    eq([$bill['couponCode'], $bill['couponDiscount'], $bill['total']], ['STAY20', 10000, (int) (50000 + $items - 10000 + round((50000 + $items - 10000) * 0.1))]);
    eq($bill['snapshot']['totals']['coupon']['discount'], 10000);
    eq($bill['serviceCharge'], (int) round((50000 + $items - 10000) * 0.1));
    eq(Ydr\Coupons::usesOf('STAY20'), 1);
    Pos::voidBill($bill['id'], 'test', 'cashier');
    eq(Ydr\Coupons::usesOf('STAY20'), 0, 'a void bill frees the coupon');
    throws(fn () => Pos::couponPreview('STAY20', ''), '/room bill/');
});

test('the POS arrival list has only check-ins dated today', function () use ($d) {
    // rooms nobody has around today
    $busy = [];
    foreach (B::occupancy($d(-3), $d(4)) as $night) $busy += $night['rooms'];
    $free = array_values(array_filter(B::listRooms(true), fn ($r) => !isset($busy[$r['id']])));
    if (count($free) < 3) throw new Exception('the test needs three free rooms around today');
    $mk = fn (array $r, int $in, int $out) => B::createBooking(['guestName' => "Arrival {$r['slug']}", 'kind' => 'room', 'roomId' => $r['id'], 'checkIn' => $d($in), 'checkOut' => $d($out),
        'source' => 'phone', 'status' => 'confirmed'], true, 'admin');
    $late = $mk($free[0], -3, 3);   // began days ago, never checked in
    $now = $mk($free[1], 0, 2);     // arriving today
    $later = $mk($free[2], 2, 4);
    $ids = array_map(fn ($f) => $f['booking']['id'], Pos::openFolios());
    eq([in_array($late['id'], $ids, true), in_array($now['id'], $ids, true), in_array($later['id'], $ids, true)], [false, true, false]);
    // an advance on a future booking doesn't put it on the POS
    Pos::addPayment($later['id'], ['amount' => 5000, 'method' => 'bank'], 'admin');
    eq(in_array($later['id'], array_map(fn ($f) => $f['booking']['id'], Pos::openFolios()), true), false);
    eq(in_array($late['ref'], array_column(Pos::arrivals(), 'ref'), true), true, 'the late one is still in the check-in list');
});

test('a booking with items on its bill cannot be cancelled', function () use ($stay, $itemId) {
    $E = $stay('weera-canopy-cabin', 200, 201, ['status' => 'confirmed']);
    Pos::addCharges($E['id'], [['itemId' => $itemId('Coffee')]], 'cashier');
    throws(fn () => B::updateBooking($E['id'], ['status' => 'cancelled']), 'code:has_charges');
});

test('room figures count group rooms and leave day visits out', function () use ($t) {
    $s = B::stats();
    if ($s['inHouse'] < 3) throw new Exception('expected the checked-in rooms tonight, got ' . $s['inHouse']);
    eq($s['capacity'], 20);
    eq(is_int($s['guestsTonight']), true);
});

test('staff logins: the role decides what each login can use, and the API checks it', function () {
    $front = Ydr\Users::create(['username' => 'FrontDesk', 'name' => 'Nimal', 'role' => 'pos', 'password' => 'front-desk-pass']);
    eq([$front['username'], $front['displayName'], $front['role']], ['frontdesk', 'Nimal', 'pos']);
    $row = Ydr\Auth::login('FrontDesk', 'front-desk-pass');
    eq($row !== null && Ydr\Auth::can($row, 'pos') && !Ydr\Auth::can($row, 'bookings') && !Ydr\Auth::can($row, 'void'), true, 'a front-desk login only has the POS');
    eq(Ydr\Auth::login('frontdesk', 'wrong-password-x'), null);
    throws(fn () => Ydr\Users::create(['username' => 'frontdesk', 'name' => 'X', 'role' => 'pos', 'password' => 'another-pass-1']), '/taken/');
    throws(fn () => Ydr\Users::create(['username' => 'shortpw', 'name' => 'X', 'role' => 'pos', 'password' => 'short']), '/at least 10/');
    throws(fn () => Ydr\Users::create(['username' => 'a b', 'name' => 'X', 'role' => 'pos', 'password' => 'long-enough-pw']), '/username/');
    throws(fn () => Ydr\Users::create(['username' => 'norole', 'name' => 'X', 'role' => 'owner', 'password' => 'long-enough-pw']), '/can use/');
    // what each API call needs
    $need = fn (string $m, string $p) => Ydr\App::permFor($m, $p);
    eq([$need('GET', '/me'), $need('POST', '/password'), $need('GET', '/stats'), $need('PATCH', '/bookings/5'), $need('GET', '/bookings/5'), $need('POST', '/bookings/5/rooms'), $need('GET', '/check')],
        [[], [], ['bookings'], ['bookings'], ['bookings', 'pos'], ['bookings', 'pos'], ['bookings', 'pos']]);
    eq([$need('POST', '/pos/checkout'), $need('POST', '/pos/charges/9/void'), $need('POST', '/pos/payments/9/void'), $need('POST', '/pos/bills/9/void'), $need('POST', '/pos/items'), $need('GET', '/pos/categories')],
        [['pos'], ['pos'], ['void'], ['void'], ['menu'], ['menu']]);
    eq([$need('GET', '/users'), $need('PATCH', '/rooms/2'), $need('GET', '/rooms'), $need('GET', '/coupons.csv'), $need('PATCH', '/setup'), $need('PATCH', '/printing'), $need('POST', '/test-email'), $need('GET', '/Bookings.csv')],
        [['users'], ['setup'], ['bookings'], ['coupons'], ['setup'], ['setup'], ['site'], ['bookings']]);
    // switched off: can't sign in, and an open session stops working
    $boss = Ydr\Users::create(['username' => 'boss', 'name' => 'The Boss', 'role' => 'admin', 'password' => 'boss-password-1']);
    $token = Ydr\Auth::issueToken(Ydr\Auth::login('frontdesk', 'front-desk-pass'));
    eq(Ydr\Auth::readToken($token)['username'], 'frontdesk');
    Ydr\Users::update($front['id'], ['active' => false], ['id' => $boss['id']]);
    eq(Ydr\Auth::readToken($token), null, 'switched off');
    eq((int) Ydr\Auth::login('frontdesk', 'front-desk-pass')['active'], 0);
    Ydr\Users::update($front['id'], ['active' => true, 'password' => 'a-new-password'], ['id' => $boss['id']]);
    eq(Ydr\Auth::readToken($token), null, 'a new password signs the old session out');
    // there is always an active admin, and nobody locks themselves out
    $admins = array_filter(Ydr\Users::list(), fn ($u) => $u['role'] === 'admin' && $u['active']);
    foreach ($admins as $u) if ($u['id'] !== $boss['id']) Ydr\Users::update($u['id'], ['role' => 'manager'], ['id' => $boss['id']]);
    throws(fn () => Ydr\Users::update($boss['id'], ['role' => 'pos'], ['id' => $front['id']]), '/at least one active admin/');
    throws(fn () => Ydr\Users::update($boss['id'], ['active' => false], ['id' => $front['id']]), '/at least one active admin/');
    throws(fn () => Ydr\Users::update($boss['id'], ['active' => false], ['id' => $boss['id']]), '/your own login/');
    throws(fn () => Ydr\Users::update($boss['id'], ['password' => 'my-new-password'], ['id' => $boss['id']]), '/Settings/');
    throws(fn () => Ydr\Users::delete($boss['id'], ['id' => $boss['id']]), '/your own login/');
    foreach ($admins as $u) if ($u['id'] !== $boss['id']) Ydr\Users::update($u['id'], ['role' => 'admin'], ['id' => $boss['id']]);
    // only a login that was never used can be removed
    $temp = Ydr\Users::create(['username' => 'temp', 'name' => '', 'role' => 'manager', 'password' => 'temporary-pw']);
    Ydr\Auth::touchLogin(Ydr\Auth::login('frontdesk', 'a-new-password'));
    throws(fn () => Ydr\Users::delete($front['id'], ['id' => $boss['id']]), '/switched off/');
    Ydr\Users::delete($temp['id'], ['id' => $boss['id']]);
    eq(in_array('temp', array_column(Ydr\Users::list(), 'username'), true), false);
});

test('printing: the time and who issued it go in the page margins, nothing from the browser', function () use ($stay, $itemId) {
    $css = Ydr\Docs::pageCss('A5', '17 Nov 2026, 2:43 PM', 'Kas"un </style>');
    foreach (['size: A5', '@top-left { content: "17 Nov 2026, 2:43 PM"', 'Issued by Kas\"un \3c /style\3e ', '@bottom-center { content: ""; }'] as $want) if (!str_contains($css, $want)) throw new Exception("page css lacks $want");
    eq(str_contains($css, '</style>'), false, 'a name cannot end the style');
    // bills and receipts carry the staff member's name, not the username
    Ydr\Users::create(['username' => 'kasun', 'name' => 'Kasun Perera', 'role' => 'pos', 'password' => 'kasun-password']);
    $K = $stay('weera-canopy-cabin', 230, 231, ['status' => 'confirmed']);
    Pos::checkIn($K['id'], [], 'kasun');
    $f = Pos::addPayment($K['id'], ['amount' => 5000, 'method' => 'cash'], 'kasun');
    $p = Pos::payment($f['paymentId']);
    eq([$p['createdBy'], $p['createdByName']], ['kasun', 'Kasun Perera']);
    $html = Ydr\Docs::receiptHtml($p);
    foreach (['Received by Kasun Perera', 'Issued by Kasun Perera', '@bottom-center'] as $want) if (!str_contains($html, $want)) throw new Exception("receipt lacks $want");
    $bill = Pos::checkout([$K['id']], ['serviceCharge' => false, 'payments' => [['amount' => 1, 'method' => 'cash']]], 'kasun');
    eq([$bill['snapshot']['issuedBy'], $bill['createdByName']], ['Kasun Perera', 'Kasun Perera']);
    // the bill is a document like the invoice and receipt: same page, paper and margins, at a private link
    preg_match('#/bill/(INV-\d+)/([0-9a-f]+)$#', $bill['billUrl'], $m);
    eq([$m[1], Ydr\Docs::billByLink($m[1], $m[2])['id']], [$bill['billNo'], $bill['id']]);
    eq(Ydr\Docs::billByLink($m[1], 'nope'), null);
    $html = Ydr\Docs::billHtml($bill);
    foreach (['<article class="doc doc--bill', $bill['billNo'], 'Billed to', 'Weera Canopy Cabin', 'Total', 'Balance due', 'size: A5', '@top-left', 'Issued by Kasun Perera', '@bottom-center'] as $want) {
        if (!str_contains($html, $want)) throw new Exception("bill lacks $want");
    }
    if (str_contains(Ydr\Docs::invoiceHtml(B::getBooking($K['id'])), 'doc--bill')) throw new Exception('the invoice is not a bill');
});

test('a walk-in keeps the rates agreed at the desk', function () use ($t) {
    $day = current(array_filter(B::listPackages(true), fn ($p) => $p['kind'] === 'day'));
    if (!$day) throw new Exception('the test needs a day-visit option');
    $f = Pos::walkIn(['guestName' => 'Walk-in deal', 'kind' => 'day_out', 'packageId' => $day['id'], 'checkIn' => $t, 'adults' => 3, 'children' => 1,
        'rateAdult' => 2000, 'rateChild' => 1000], 'desk');
    $b = $f['booking'];
    eq([$b['status'], $b['source'], $b['rates']['adult'], $b['rates']['child'], $b['totalAmount']], ['checked_in', 'walk_in', 2000, 1000, 3 * 2000 + 1000]);
    // a room at an agreed nightly rate (one that is free tonight and tomorrow)
    $busy = [];
    foreach (B::occupancy($t, B::addDays($t, 2)) as $night) $busy += $night['rooms'];
    $free = current(array_filter(B::listRooms(true), fn ($r) => !isset($busy[$r['id']]) && $r['capacity'] >= 2));
    if (!$free) throw new Exception('the test needs a room free tonight');
    $f = Pos::walkIn(['guestName' => 'Room deal', 'kind' => 'room', 'roomId' => $free['id'], 'checkIn' => $t, 'checkOut' => B::addDays($t, 2), 'adults' => 2, 'rateRoom' => 25000], 'desk');
    eq([$f['booking']['rates']['room'], $f['booking']['totalAmount'], $f['booking']['listRates']['room']], [25000, 50000, $free['price']]);
});

test('finance: staff and salaries, the service charge shared among who served, expenses, bank, stock and commissions', function () use ($stay, $itemId, $d, $t) {
    $F = Ydr\Finance::class;
    $now = $F::period(null, null); // this month: bills and payments made today fall in it
    $a = $F::saveEmployee(null, ['name' => 'Amal', 'position' => 'Waiter', 'salary' => 45000]);
    $b = $F::saveEmployee(null, ['name' => 'Bimal', 'position' => 'Cook', 'salary' => 55000]);
    $c = $F::saveEmployee(null, ['name' => 'Chamari', 'position' => 'Room girl', 'salary' => 40000]);
    throws(fn () => $F::saveEmployee(null, ['name' => '']), '/name/');
    // salaries for a month
    $month = substr($t, 0, 7);
    $F::addStaffPayment(['employeeId' => $a['id'], 'kind' => 'salary', 'amount' => 45000, 'paidOn' => $t, 'period' => $month, 'account' => 'Cash in hand'], 'admin');
    $F::addStaffPayment(['employeeId' => $b['id'], 'kind' => 'salary', 'amount' => 20000, 'paidOn' => $t, 'period' => $month], 'admin');
    $sal = $F::salaryMonth($month);
    $row = fn ($id) => current(array_filter($sal['rows'], fn ($r) => $r['employee']['id'] === $id));
    eq([$row($a['id'])['due'], $row($b['id'])['due'], $row($c['id'])['due']], [0, 35000, 40000]);
    throws(fn () => $F::addStaffPayment(['employeeId' => $a['id'], 'kind' => 'tip', 'amount' => 5], 'admin'), '/for/');
    // a bill's service charge, shared equally among the staff who served (the odd rupees to the first)
    $busy = [];
    foreach (B::occupancy($t, $d(1)) as $night) $busy += $night['rooms'];
    $free = current(array_filter(B::listRooms(true), fn ($r) => $r['active'] && !isset($busy[$r['id']])));
    if (!$free) throw new Exception('the test needs a room free tonight');
    $S = B::createBooking(['guestName' => 'Service test', 'kind' => 'room', 'roomId' => $free['id'], 'checkIn' => $t, 'checkOut' => $d(1), 'source' => 'phone', 'status' => 'confirmed'], true, 'admin');
    Pos::checkIn($S['id'], [], 'desk');
    Pos::addCharges($S['id'], [['itemId' => $itemId('Coffee')]], 'desk');
    $bill = Pos::checkout([$S['id']], ['payments' => [['amount' => 1000, 'method' => 'cash']]], 'desk');
    $service = Pos::getBill($bill['id'])['serviceCharge'];
    if ($service <= 0) throw new Exception('the test bill needs a service charge');
    $r = $F::setShares($bill['id'], [$a['id'], $b['id'], $c['id']], null, 'desk');
    $each = intdiv($service, 3);
    eq(array_sum(array_column($r['shares'], 'amount')), $service);
    eq(min(array_column($r['shares'], 'amount')), $each);
    throws(fn () => $F::setShares($bill['id'], [$a['id'], $b['id']], [$a['id'] => 1, $b['id'] => 2], 'desk'), '/add up/');
    $F::setShares($bill['id'], [$a['id'], $b['id']], [$a['id'] => $service - 100, $b['id'] => 100], 'desk');
    eq(array_column(Pos::getBill($bill['id'])['serviceShares'], 'amount', 'name'), ['Amal' => $service - 100, 'Bimal' => 100]);
    $F::addStaffPayment(['employeeId' => $a['id'], 'kind' => 'service', 'amount' => 500, 'paidOn' => $t], 'admin');
    $bal = array_column(array_map(fn ($x) => ['n' => $x['employee']['name'], 'owed' => $x['owed']], $F::serviceBalances($now)), 'owed', 'n');
    eq([$bal['Amal'], $bal['Bimal'], $bal['Chamari']], [$service - 100 - 500, 100, 0]);
    eq(in_array($bill['id'], array_column($F::serviceBills($now), 'id'), true), true);
    // expenses
    $e = $F::saveExpense(null, ['spentOn' => $t, 'category' => 'Utilities (power, water, gas)', 'description' => 'Electricity', 'amount' => 18000, 'account' => 'Cash in hand'], 'admin');
    $F::saveExpense(null, ['spentOn' => $t, 'category' => 'Commission', 'payee' => 'Kamal Tours', 'amount' => 3000], 'admin');
    throws(fn () => $F::saveExpense(null, ['spentOn' => $t, 'category' => 'Other', 'amount' => 0], 'admin'), '/more than 0/');
    eq($F::saveExpense($e['id'], ['spentOn' => $t, 'category' => 'Utilities (power, water, gas)', 'amount' => 19000], 'admin')['amount'], 19000);
    eq(count($F::expenses($now, 'Commission')), 1);
    // money in: by the "Received into" account, and deposits to the bank
    Pos::addPayment($stay('na-treehouse', 640, 641, ['status' => 'confirmed'])['id'], ['amount' => 7000, 'method' => 'bank', 'details' => ['received_into' => 'Cash in hand'], 'fee' => 200], 'admin');
    $rec = $F::received($now);
    eq(($rec['total'] - $rec['net']) >= 200, true);
    $F::saveBankMove(['movedOn' => $t, 'account' => 'Peoples Bank', 'fromAccount' => 'Cash in hand', 'amount' => 25000, 'reference' => 'Slip 77'], 'admin');
    eq(array_sum(array_column($F::bankMoves($now), 'amount')), 25000);
    // stock: bought and used, with a low-stock warning
    $rice = $F::saveStockItem(null, ['name' => 'Rice', 'category' => 'Kitchen', 'unit' => 'kg', 'reorderLevel' => 5]);
    $F::addStockMove(['itemId' => $rice['id'], 'qty' => 25, 'unitCost' => 280, 'movedOn' => $t], 'admin');
    $it = $F::addStockMove(['itemId' => $rice['id'], 'qty' => -21.5, 'movedOn' => $t, 'note' => 'Kitchen'], 'admin');
    eq([$it['onHand'], $it['low'], $F::qtyText($it['onHand'])], [3.5, true, '3.5']);
    throws(fn () => $F::addStockMove(['itemId' => $rice['id'], 'qty' => 0], 'admin'), '/not 0/');
    // commissions: earned on stays arriving in the period, paid as "Commission" expenses
    $cb = B::createBooking(['guestName' => 'Agent guest', 'kind' => 'room', 'roomId' => B::getRoom('hora-pool-villa')['id'], 'checkIn' => $d(620), 'checkOut' => $d(622),
        'source' => 'phone', 'status' => 'confirmed', 'commissionType' => 'percent', 'commissionValue' => 10, 'commissionTo' => 'Kamal Tours'], true, 'admin');
    $cp = $F::period($d(620), $d(622));
    $com = $F::commissions($cp);
    eq([$com['agents'][0]['to'], $com['agents'][0]['earned']], ['Kamal Tours', (int) round($cb['totalAmount'] / 10)]);
    // room figures for that stay: two nights in its room
    $an = $F::analytics($cp);
    $hora = current(array_filter($an['rooms'], fn ($r) => $r['name'] === B::getRoom('hora-pool-villa')['name']));
    eq([$hora['nights'], $hora['revenue']], [2, $cb['totalAmount']]);
    // the summary, and its page
    $sum = $F::summary($now);
    eq($sum['net'], $sum['received']['net'] - $sum['expenseTotal'] - $sum['staffTotal']);
    eq($sum['expenseTotal'] >= 22000 && $sum['staffTotal'] >= 65500 && $sum['bankTotal'] >= 25000 && $sum['sales']['bills'] >= 1, true);
    $html = Ydr\Docs::summaryHtml($sum, false, 'Owner');
    foreach (['Finance summary', 'Money received', 'Expenses', 'Paid to staff', 'Paid into bank accounts', 'Commissions', 'Rooms', 'Food and drinks', 'Rice — low', 'Issued by Owner', 'size: A5'] as $want) {
        if (!str_contains($html, $want)) throw new Exception("summary lacks $want");
    }
    // only admins have finance; the POS can share a bill's service charge
    eq([Ydr\App::permFor('GET', '/finance/summary'), Ydr\App::permFor('POST', '/pos/bills/3/service'), Ydr\App::permFor('GET', '/pos/staff')], [['finance'], ['pos'], ['pos']]);
    eq([Ydr\Auth::can(['role' => 'admin'], 'finance'), Ydr\Auth::can(['role' => 'manager'], 'finance'), Ydr\Auth::can(['role' => 'pos'], 'finance')], [true, false, false]);
});

test('closed days: the website books nothing then, staff still can, and guests leaving that morning are fine', function () use ($guest, $d, $pkg) {
    $C = Ydr\Closures::class;
    $r = $C::create(['from' => $d(533), 'to' => $d(532), 'reason' => 'private', 'note' => 'Wedding'], 'admin'); // the order doesn't matter
    $c = $r['closure'];
    eq([$c['from'], $c['to'], $c['days'], $c['label']], [$d(532), $d(533), 2, 'Private booking']);
    eq(array_keys($C::days($d(530), $d(536))), [$d(532), $d(533)]);
    // the website calendar crosses the days out for rooms, groups and day visits
    eq(array_values(array_intersect([$d(531), $d(532), $d(533), $d(534)], B::unavailableNights('room', null, 1, $d(530), $d(536)))), [$d(532), $d(533)]);
    eq(B::unavailableNights('day_out', null, 1, $d(530), $d(536)), [$d(532), $d(533)]);
    eq(in_array($d(533), B::unavailableNights('per_person', null, 2, $d(530), $d(536)), true), true);
    // website bookings on those days are refused, with the reason
    throws(fn () => B::createBooking($guest + ['room' => 'mara-pool-villa', 'checkIn' => $d(531), 'checkOut' => $d(533)]), 'code:closed');
    throws(fn () => B::createBooking($guest + ['room' => 'mara-pool-villa', 'checkIn' => $d(531), 'checkOut' => $d(533)]), '/closed on .* \(private booking\)/');
    throws(fn () => B::searchRooms($d(533), $d(535)), 'code:closed');
    $day = current(array_filter(B::listPackages(true), fn ($x) => $x['kind'] === 'day'));
    throws(fn () => B::createBooking($guest + ['kind' => 'day_out', 'packageId' => $day['id'], 'checkIn' => $d(532), 'adults' => 2]), 'code:closed');
    // leaving on the morning of the first closed day, or arriving the day after, is fine
    $leave = B::createBooking($guest + ['room' => 'mara-pool-villa', 'checkIn' => $d(530), 'checkOut' => $d(532)]);
    $arrive = B::createBooking($guest + ['room' => 'mara-pool-villa', 'checkIn' => $d(534), 'checkOut' => $d(535)]);
    eq([$leave['status'], $arrive['status']], ['pending', 'pending']);
    // staff can still book them (the private booking itself), and see the warning
    $own = B::createBooking(['guestName' => 'Wedding party', 'kind' => 'per_person', 'packageId' => $pkg('Full board')['id'], 'checkIn' => $d(532), 'checkOut' => $d(534),
        'adults' => 6, 'source' => 'phone', 'status' => 'confirmed'], true, 'admin');
    eq(count($C::clashes($own['checkIn'], $own['checkOut'])), 2);
    // closing days that are already booked lists those bookings
    $r2 = $C::create(['from' => $d(530), 'reason' => 'repairs'], 'admin');
    eq([$r2['closure']['days'], $r2['closure']['label'], in_array($leave['id'], array_column($r2['bookings'], 'id'), true)], [1, 'Closed for repairs', true]);
    throws(fn () => $C::create(['from' => $d(-3), 'to' => $d(-2)], 'admin'), '/past/');
    throws(fn () => $C::create(['from' => $d(542), 'reason' => 'party'], 'admin'), '/why/');
    // Google Calendar shows them; opening the days again lets the website book
    if (!str_contains(Ydr\Ical::feed(), 'Resort closed · Private booking')) throw new Exception('the calendar feed lacks the closure');
    $C::delete($c['id']);
    $C::delete($r2['closure']['id']);
    eq(B::createBooking($guest + ['room' => 'na-treehouse', 'checkIn' => $d(532), 'checkOut' => $d(533)])['status'], 'pending');
    eq([Ydr\App::permFor('GET', '/closures'), Ydr\App::permFor('POST', '/closures'), Ydr\App::permFor('DELETE', '/closures/3')], [['bookings', 'pos'], ['setup'], ['setup']]);
});

test('commission: earned on the booking, kept from a payment, and only what reached the account counts as received', function () use ($d) {
    $F = Ydr\Finance::class;
    $acct = $F::accountField();
    $b = B::createBooking(['guestName' => 'Agent sold', 'kind' => 'room', 'roomId' => B::getRoom('dahara-honeymoon-hideaway')['id'], 'checkIn' => $d(250), 'checkOut' => $d(252),
        'source' => 'phone', 'status' => 'confirmed', 'commissionType' => 'percent', 'commissionValue' => 10, 'commissionTo' => 'Lanka Tours'], true, 'admin');
    $earn = (int) round($b['totalAmount'] / 10);
    eq([$b['commission']['amount'], $b['commission']['kept'], $b['commission']['toPay']], [$earn, 0, $earn]);
    // the agent passes on the advance less their commission
    $bank = $acct['options'] ? $acct['options'][count($acct['options']) - 1] : '';
    $details = $bank !== '' ? [$acct['key'] => $bank] : [];
    Pos::addPayment($b['id'], ['amount' => 20000, 'method' => 'bank', 'fee' => 2000, 'details' => $details], 'admin');
    $b = B::getBooking($b['id']);
    eq([$b['commission']['kept'], $b['commission']['toPay']], [2000, $earn - 2000]);
    // the account got 18,000, not 20,000
    $now = $F::period(null, null);
    $rec = $F::received($now);
    if ($bank !== '') {
        $row = current(array_filter($rec['accounts'], fn ($a) => $a['account'] === $bank));
        eq([$row['kept'] >= 2000, $row['amount'], $row['paid'] - $row['kept']], [true, $row['amount'], $row['amount']]);
    }
    eq($rec['commissionKept'] >= 2000 && $rec['net'] === $rec['total'] - $rec['fees'] && $rec['charges'] === $rec['fees'] - $rec['commissionKept'], true);
    // the agent: earned, kept, and the rest still to pay — until it's paid
    $cp = $F::period($d(250), $d(252));
    $agent = fn () => current(array_filter($F::commissions($cp)['agents'], fn ($a) => $a['to'] === 'Lanka Tours'));
    eq([$agent()['earned'], $agent()['kept'], $agent()['paid'], $agent()['toPay']], [$earn, 2000, 0, $earn - 2000]);
    $F::saveExpense(null, ['spentOn' => $d(252), 'category' => 'Commission', 'payee' => 'Lanka Tours', 'amount' => $earn - 2000], 'admin');
    eq([$agent()['paid'], $agent()['toPay']], [$earn - 2000, 0]);
    // every transaction: the payment in (with what was kept), and filters
    $t = $F::transactions($now, ['type' => 'in', 'q' => 'Agent sold']);
    eq(count($t['rows']), 1);
    $r = $t['rows'][0];
    eq([$r['in'], $r['kept'], $r['keptBy'], $r['kind']], [20000, 2000, 'Commission kept by Lanka Tours', 'Payment']);
    eq([$t['totals']['in'], $t['totals']['kept'], $t['totals']['netIn'], $t['totals']['out']], [20000, 2000, 18000, 0]);
    $all = $F::transactions($now);
    eq(in_array('out', array_column($all['rows'], 'type'), true) && $all['totals']['net'] === $all['totals']['in'] - $all['totals']['kept'] - $all['totals']['out'], true);
    if ($bank !== '') eq(in_array('Agent sold', array_column($F::transactions($now, ['account' => $bank])['rows'], 'what'), true), true);
    eq(Ydr\App::permFor('GET', '/finance/transactions.csv'), ['finance']);
});

test('outdoor parties: booked by staff, hold the party area and any rooms, and decide what other guests can book', function () use ($guest, $d) {
    $D = $d(345);
    $held = B::getRoom('kitul-canopy-cabin');
    $party = B::createBooking(['guestName' => 'Perera wedding', 'guestPhone' => '0771234567', 'kind' => 'event', 'checkIn' => $D, 'eventSlot' => 'night', 'eventOthers' => 'rooms',
        'adults' => 80, 'children' => 10, 'arrivalTime' => '7 pm – 1 am', 'rooms' => [$held['id']], 'totalAmount' => 250000, 'source' => 'phone', 'status' => 'confirmed'], true, 'admin');
    eq([$party['kind'], $party['checkOut'], $party['nights'], $party['partyArea'], $party['packageLabel'], $party['totalAmount'], array_column($party['rooms'], 'id')],
        ['event', $d(346), 0, true, 'Night party', 250000, [$held['id']]]);
    eq($party['eventOthersLabel'], 'No other guests stay that night');
    // the website can't book a party
    throws(fn () => B::createBooking($guest + ['kind' => 'event', 'checkIn' => $d(347)]), '/how you would like to book/');
    // "no other stays": the night is closed to other stays on the website; day visits stay open
    throws(fn () => B::createBooking($guest + ['room' => 'na-treehouse', 'checkIn' => $d(344), 'checkOut' => $d(346)]), '/closed on .* \(private event\)/');
    eq(B::createBooking($guest + ['room' => 'na-treehouse', 'checkIn' => $d(343), 'checkOut' => $D])['status'], 'pending', 'leaving that morning is fine');
    $day = current(array_filter(B::listPackages(true), fn ($x) => $x['kind'] === 'day'));
    eq(B::createBooking($guest + ['kind' => 'day_out', 'packageId' => $day['id'], 'checkIn' => $D, 'adults' => 2])['status'], 'pending', 'day visits stay open');
    eq(in_array($D, B::unavailableNights('room', null, 1, $d(340), $d(350)), true) && !in_array($D, B::unavailableNights('day_out', null, 1, $d(340), $d(350)), true), true);
    // the room held for party guests is taken; staff can book other rooms that night, with a warning
    throws(fn () => B::createBooking(['guestName' => 'x', 'kind' => 'room', 'roomId' => $held['id'], 'checkIn' => $D, 'checkOut' => $d(346), 'source' => 'phone', 'status' => 'confirmed'], true, 'admin'), '/already booked/');
    $other = B::createBooking(['guestName' => 'Friend of the family', 'kind' => 'room', 'roomId' => B::getRoom('hora-pool-villa')['id'], 'checkIn' => $D, 'checkOut' => $d(346), 'source' => 'phone', 'status' => 'confirmed'], true, 'admin');
    eq(Ydr\Closures::clashes($D, $d(346))[0]['label'], 'Private event');
    eq(Ydr\Closures::clashes($D, $d(346), 'event', $party['id']), [], 'a party is not closed by itself');
    // the party area is the party's: a stay can't add it that night, nor a second party
    throws(fn () => B::createBooking(['guestName' => 'Stay with the area', 'kind' => 'room', 'roomId' => B::getRoom('mara-pool-villa')['id'], 'checkIn' => $D, 'checkOut' => $d(346), 'partyArea' => true, 'source' => 'phone', 'status' => 'confirmed'], true, 'admin'), '/[Pp]arty area/');
    throws(fn () => B::createBooking(['guestName' => 'Second party', 'kind' => 'event', 'checkIn' => $D, 'eventSlot' => 'day', 'adults' => 20, 'totalAmount' => 50000, 'source' => 'phone', 'status' => 'confirmed'], true, 'admin'), '/[Pp]arty area/');
    // whole resort private: day visits close too; open: everything can be booked again
    B::updateBooking($party['id'], ['eventOthers' => 'private']);
    throws(fn () => B::createBooking($guest + ['kind' => 'day_out', 'packageId' => $day['id'], 'checkIn' => $D, 'adults' => 2]), 'code:closed');
    $p2 = B::updateBooking($party['id'], ['eventOthers' => 'open', 'eventSlot' => 'full', 'adults' => 120, 'rooms' => []]);
    eq([$p2['eventSlot'], $p2['packageLabel'], $p2['adults'], $p2['rooms'], $p2['totalAmount']], ['full', 'Day & night party', 120, [], 250000], 'a party keeps its agreed price');
    eq(B::createBooking($guest + ['room' => 'na-treehouse', 'checkIn' => $D, 'checkOut' => $d(346)])['status'], 'pending', 'open: rooms can be booked');
    throws(fn () => B::updateBooking($party['id'], ['eventOthers' => 'everyone']), '/other guests/');
    // the invoice, and Finance
    $html = Ydr\Docs::invoiceHtml(B::getBooking($party['id']));
    foreach (['Party', 'day &amp; night party', '130 guests', 'LKR 250,000'] as $want) if (!str_contains($html, $want)) throw new Exception("party invoice lacks $want");
    $an = Ydr\Finance::analytics(Ydr\Finance::period($d(340), $d(350)));
    eq([$an['parties'] >= 1, $an['partyRevenue'] >= 250000], [true, true]);
    // cancelled: the date opens again
    B::updateBooking($party['id'], ['eventOthers' => 'private']);
    B::updateBooking($party['id'], ['status' => 'cancelled']);
    eq(Ydr\Closures::clashes($D, $d(346)), []);
});

// ---------- one booking, several rooms ----------
test('one booking can hold several rooms: all are taken, each priced per night', function () use ($guest, $d, $room, $slugs) {
    [$kitul, $weera] = [$room('kitul-canopy-cabin'), $room('weera-canopy-cabin')];
    $guestsBefore = B::occupancy($d(490), $d(491))[$d(490)]['guests'];
    $b = B::createBooking($guest + ['kind' => 'room', 'rooms' => ['kitul-canopy-cabin', 'weera-canopy-cabin'], 'checkIn' => $d(490), 'checkOut' => $d(492)]);
    eq([$b['status'], $b['kind'], $b['roomId'], $slugs($b)], ['pending', 'room', $kitul['id'], ['kitul-canopy-cabin', 'weera-canopy-cabin']]);
    eq($b['totalAmount'], ($kitul['price'] + $weera['price']) * 2);
    eq([$b['capacity'], $b['roomName'], $b['place']], [4, 'Kitul Canopy Cabin, Weera Canopy Cabin', 'Kitul Canopy Cabin, Weera Canopy Cabin']);
    eq($b['listRates']['room'], $kitul['price'] + $weera['price'], 'the list rate is a night for all the rooms');
    // both rooms are gone for those nights, and the hotel counts both rooms' beds
    $free = array_column(array_filter(B::searchRooms($d(490), $d(492)), fn ($r) => $r['available']), 'slug');
    eq([in_array('kitul-canopy-cabin', $free, true), in_array('weera-canopy-cabin', $free, true)], [false, false]);
    eq(B::occupancy($d(490), $d(491))[$d(490)]['guests'] - $guestsBefore, 4);
    throws(fn () => B::createBooking($guest + ['room' => 'weera-canopy-cabin', 'checkIn' => $d(491), 'checkOut' => $d(493)]), 'code:unavailable');
    throws(fn () => B::createBooking($guest + ['rooms' => ['domba-pool-villa', 'kitul-canopy-cabin'], 'checkIn' => $d(491), 'checkOut' => $d(493)]), 'code:unavailable');
    // the invoice lists each room
    eq(array_column(B::priceLines($b), 'label'), ['Kitul Canopy Cabin', 'Weera Canopy Cabin']);
    $html = Ydr\Docs::invoiceHtml(B::getBooking($b['id']));
    foreach (['Kitul Canopy Cabin', 'Weera Canopy Cabin'] as $want) if (!str_contains($html, $want)) throw new Exception("invoice lacks $want");
    // finance (confirmed stays) gives every room its nights and a share of the price
    $before = array_column(Ydr\Finance::analytics(Ydr\Finance::period($d(490), $d(491)))['rooms'], null, 'id');
    B::updateBooking($b['id'], ['status' => 'confirmed']);
    $after = array_column(Ydr\Finance::analytics(Ydr\Finance::period($d(490), $d(491)))['rooms'], null, 'id');
    $gain = fn (int $id, string $k) => ($after[$id][$k] ?? 0) - ($before[$id][$k] ?? 0);
    eq([$gain($kitul['id'], 'nights'), $gain($weera['id'], 'nights')], [2, 2]);
    eq($gain($kitul['id'], 'revenue') + $gain($weera['id'], 'revenue'), $b['totalAmount']);
    B::updateBooking($b['id'], ['status' => 'cancelled']);
    eq(count(array_filter(B::searchRooms($d(490), $d(492)), fn ($r) => in_array($r['slug'], ['kitul-canopy-cabin', 'weera-canopy-cabin'], true) && $r['available'])), 2, 'cancelling frees every room');
});

test('a booking\'s rooms can be changed: it is re-priced, and can go back to one room', function () use ($guest, $d, $room, $slugs) {
    [$kitul, $weera, $domba, $yodha] = [$room('kitul-canopy-cabin'), $room('weera-canopy-cabin'), $room('domba-pool-villa'), $room('yodha-family-lodge')];
    $b = B::createBooking($guest + ['rooms' => ['kitul-canopy-cabin', 'weera-canopy-cabin'], 'checkIn' => $d(494), 'checkOut' => $d(496)]);
    // add a room (the admin room chooser)
    $b = B::assignRooms($b['id'], [$kitul['id'], $weera['id'], $domba['id']]);
    eq([$slugs($b), $b['totalAmount']], [['domba-pool-villa', 'kitul-canopy-cabin', 'weera-canopy-cabin'], ($kitul['price'] + $weera['price'] + $domba['price']) * 2]);
    // a new main room takes the old main room's place; the others stay
    $b = B::updateBooking($b['id'], ['roomId' => $yodha['id']]);
    eq([$b['roomId'], count($b['rooms']), in_array($kitul['id'], array_column($b['rooms'], 'id'), true)], [$yodha['id'], 3, false]);
    eq($b['totalAmount'], ($yodha['price'] + $weera['price'] + $domba['price']) * 2);
    // saving the main room unchanged keeps every room
    $b = B::updateBooking($b['id'], ['roomId' => $yodha['id'], 'guestName' => 'Renamed Guest']);
    eq(count($b['rooms']), 3);
    // an agreed rate is a night for all the rooms together
    $b = B::updateBooking($b['id'], ['rateRoom' => 100000]);
    eq([$b['rates']['room'], $b['totalAmount'], count(B::priceLines($b))], [100000, 200000, 1]);
    // back to one room: the others are free again
    $b = B::updateBooking($b['id'], ['rooms' => [$domba['id']]]);
    eq([$b['roomId'], $slugs($b), $b['totalAmount']], [$domba['id'], ['domba-pool-villa'], $domba['price'] * 2]);
    $free = array_column(array_filter(B::searchRooms($d(494), $d(496)), fn ($r) => $r['available']), 'slug');
    eq([in_array('weera-canopy-cabin', $free, true), in_array('yodha-family-lodge', $free, true)], [true, true]);
    B::updateBooking($b['id'], ['status' => 'cancelled']);
});

test('several rooms: each must be bookable, guests fit all of them, and the hotel has space', function () use ($guest, $d) {
    throws(fn () => B::createBooking($guest + ['rooms' => ['kitul-canopy-cabin', 'no-such-room'], 'checkIn' => $d(497), 'checkOut' => $d(498)]), '/no longer available/');
    throws(fn () => B::createBooking($guest + ['rooms' => array_fill(0, B::MAX_ROOMS + 1, 'kitul-canopy-cabin'), 'checkIn' => $d(497), 'checkOut' => $d(498)]), '/up to ' . B::MAX_ROOMS . ' rooms/');
    throws(fn () => B::createBooking($guest + ['rooms' => ['kitul-canopy-cabin', 'weera-canopy-cabin'], 'adults' => 5, 'checkIn' => $d(497), 'checkOut' => $d(498)]), '/These rooms sleep up to 4 guests/');
    $cap = B::hotelCapacity();
    B::saveSetup(['capacity' => 3]);
    try {
        throws(fn () => B::createBooking($guest + ['rooms' => ['kitul-canopy-cabin', 'weera-canopy-cabin'], 'checkIn' => $d(497), 'checkOut' => $d(498)]), 'code:full');
    } finally {
        B::saveSetup(['capacity' => $cap]);
    }
    // a listed room twice counts once
    $b = B::createBooking($guest + ['rooms' => ['kitul-canopy-cabin', 'kitul-canopy-cabin'], 'checkIn' => $d(497), 'checkOut' => $d(498)]);
    eq(count($b['rooms']), 1);
    B::updateBooking($b['id'], ['status' => 'cancelled']);
});

// ---------- reminders ----------
test('arrival reminders go to confirmed bookings inside the window, once', function () use ($guest, $d, $room) {
    $outbox = Ydr\Config::get()['dataDir'] . '/outbox';
    $count = fn () => count(glob("$outbox/*.html") ?: []);
    $free = fn (int $in) => current(array_filter(B::searchRooms($d($in), $d($in + 1)), fn ($r) => $r['available']))['slug'];
    $mk = fn (string $slug, int $in, string $status) => B::createBooking($guest + ['roomId' => $room($free($in))['id'], 'checkIn' => $d($in), 'checkOut' => $d($in + 1), 'source' => 'phone', 'status' => $status], true);
    $soon = $mk('kitul-canopy-cabin', 2, 'confirmed');
    $far = $mk('weera-canopy-cabin', 9, 'confirmed');
    $pending = $mk('hora-pool-villa', 2, 'pending');
    $ids = array_column(Ydr\Reminders::due(), 'id');
    eq(in_array($soon['id'], $ids, true), true, 'inside window');
    eq(in_array($far['id'], $ids, true), false, 'too far away');
    eq(in_array($pending['id'], $ids, true), false, 'pending is not an arrival reminder');
    $before = $count();
    $sent = Ydr\Reminders::run();
    Ydr\Mailer::flushQueue();
    eq($sent['arrival'] >= 1, true, 'sent');
    eq($count() > $before, true, 'email written');
    eq(in_array($soon['id'], array_column(Ydr\Reminders::due(), 'id'), true), false, 'not again');
    eq(Ydr\Reminders::run(), ['arrival' => 0, 'payment' => 0], 'second run sends nothing');
});

test('payment reminders go to unpaid reservations whose hold ends soon; reminders can be switched off', function () use ($guest, $d, $room) {
    $b = B::createBooking($guest + ['roomId' => $room(current(array_filter(B::searchRooms($d(300), $d(301)), fn ($r) => $r['available']))['slug'])['id'], 'checkIn' => $d(300), 'checkOut' => $d(301), 'source' => 'phone', 'status' => 'pending', 'holdUntil' => $d(1)], true);
    $row = current(array_filter(Ydr\Reminders::due(), fn ($x) => $x['id'] === $b['id']));
    eq([$row['kind'], $row['daysLeft']], ['payment', 1]);
    Settings::set(['reminders_on' => '0']);
    try { eq(Ydr\Reminders::run(), ['arrival' => 0, 'payment' => 0], 'off'); } finally { Settings::set(['reminders_on' => '1']); }
    $sent = Ydr\Reminders::run();
    Ydr\Mailer::flushQueue();
    eq($sent['payment'] >= 1, true, 'sent');
    eq(in_array($b['id'], array_column(Ydr\Reminders::due(), 'id'), true), false, 'not again');
});

test('admin can change reminder settings, and send a reminder for one booking whenever they like', function () use ($guest, $d, $room) {
    eq(Ydr\Reminders::save(['arrivalDays' => 3, 'paymentDays' => 0, 'on' => false]) ['arrivalDays'], 3);
    eq([Ydr\Reminders::admin()['paymentDays'], Ydr\Reminders::admin()['on']], [0, false]);
    throws(fn () => Ydr\Reminders::save(['arrivalDays' => 99]), '/0 to 30/');
    throws(fn () => Ydr\Reminders::save(['paymentDays' => 'x']), '/0 to 30/');
    Ydr\Reminders::save(['arrivalDays' => 2, 'paymentDays' => 1, 'on' => true]);
    $free = fn (int $in) => current(array_filter(B::searchRooms($d($in), $d($in + 1)), fn ($r) => $r['available']))['slug'];
    $mk = fn (int $in, string $status, array $more = []) => B::createBooking($guest + ['roomId' => $room($free($in))['id'], 'checkIn' => $d($in), 'checkOut' => $d($in + 1), 'source' => 'phone', 'status' => $status] + $more, true);
    $far = $mk(25, 'confirmed'); // far outside the automatic window
    eq(Ydr\Reminders::sendFor($far), 'arrival', 'manual send ignores the window');
    eq(Ydr\Reminders::sendFor($far), 'arrival', 'and can be repeated');
    eq((int) Ydr\Db::one("SELECT COUNT(*) n FROM booking_reminders WHERE booking_id = ? AND kind = 'arrival'", [$far['id']])['n'], 1, 'one record');
    $open = $mk(26, 'pending', ['holdUntil' => '']);
    eq(Ydr\Reminders::sendFor($open), 'payment', 'a reservation with no hold date can still be reminded');
    throws(fn () => Ydr\Reminders::sendFor($mk(27, 'cancelled')), '/confirmed bookings/');
    throws(fn () => Ydr\Reminders::sendFor(array_merge($far, ['guestEmail' => ''])), '/no email/');
    throws(fn () => Ydr\Reminders::sendFor(array_merge($far, ['checkIn' => $d(-4)])), '/already started/');
    $r = Ydr\Reminders::admin();
    eq(count($r['recent']) >= 2, true, 'recently sent list');
    Ydr\Mailer::flushQueue();
});

echo "\n$pass passed, $fail failed  (test database: " . (Ydr\Db::driver() === 'mysql' ? 'MySQL ' . Ydr\Config::get()['db']['database'] : $tmp) . ")\n";
exit($fail ? 1 : 0);
