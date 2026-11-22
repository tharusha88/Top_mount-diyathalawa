<?php
// Adds a handful of sample bookings so the admin panel and the POS have something to show.
//   php scripts/seed-demo.php           → add demo bookings
//   php scripts/seed-demo.php --clear   → remove them again
// Demo bookings are tagged "DEMO" in their private notes, and the guests who are checked in
// get a few POS items. No emails are sent. --clear also removes their rooms, POS items,
// payments and bills.
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Ydr\Bookings as B;
use Ydr\Db;
use Ydr\Pos;

$TAG = 'DEMO — sample booking, safe to remove with: php scripts/seed-demo.php --clear';

if (in_array('--clear', $argv, true)) {
    $ids = array_map('intval', array_column(Db::all("SELECT id FROM bookings WHERE admin_notes LIKE 'DEMO%'"), 'id'));
    if ($ids) {
        $in = implode(',', $ids);
        $shared = (int) Db::one("SELECT COUNT(*) n FROM bookings WHERE id NOT IN ($in)
            AND bill_id IN (SELECT bill_id FROM bookings WHERE id IN ($in) AND bill_id IS NOT NULL)")['n'];
        if ($shared) { echo "A demo booking shares a bill with a real booking. Void that bill in Admin → Bills first.\n"; exit(1); }
        Db::transaction(function () use ($in) {
            Db::run("DELETE FROM bills WHERE id IN (SELECT bill_id FROM bookings WHERE id IN ($in) AND bill_id IS NOT NULL) OR booking_id IN ($in)");
            foreach (['pos_charges', 'pos_payments', 'pos_log', 'booking_rooms'] as $t) Db::run("DELETE FROM $t WHERE booking_id IN ($in)");
            Db::run("DELETE FROM bookings WHERE id IN ($in)");
        });
    }
    echo 'Removed ' . count($ids) . " demo booking(s) and their POS records.\n";
    exit;
}

$t = B::today();
$d = fn (int $n) => B::addDays($t, $n);
$room = fn (string $slug) => B::getRoom($slug)['id'];
$options = array_column(B::listPackages(true), null, 'name');
$option = fn (string $name) => $options[$name]['id'] ?? null;
$dayOut = current(array_filter(B::listPackages(true), fn ($p) => $p['kind'] === 'day')) ?: null;

$demo = [
    ['kind' => 'room', 'roomId' => $room('room-101'), 'checkIn' => $d(-1), 'checkOut' => $d(2), 'guestName' => 'Amaya & Ruwan Jayasinghe', 'guestPhone' => '+94 77 555 0101', 'country' => 'Sri Lanka', 'source' => 'phone', 'status' => 'checked_in', 'adults' => 2],
    ['kind' => 'room', 'roomId' => $room('room-104'), 'checkIn' => $d(0), 'checkOut' => $d(3), 'guestName' => 'Sophie Müller', 'guestEmail' => 'sophie@example.com', 'guestPhone' => '+49 151 555 0102', 'country' => 'Germany', 'source' => 'booking_com', 'status' => 'confirmed', 'adminNotes' => 'Booking.com #4411'],
    // per-person groups take the next free rooms from the priority list (Admin → Booking setup)
    ['kind' => 'per_person', 'packageId' => $option('Full board'), 'checkIn' => $d(0), 'checkOut' => $d(2), 'guestName' => 'The Silva group', 'guestPhone' => '+94 77 555 0107', 'country' => 'Sri Lanka', 'source' => 'whatsapp', 'status' => 'checked_in', 'adults' => 3, 'children' => 1],
    ['kind' => 'per_person', 'packageId' => $option('Half board'), 'partyArea' => true, 'jblSpeaker' => true, 'checkIn' => $d(2), 'checkOut' => $d(5), 'guestName' => 'The Perera family', 'guestPhone' => '+94 71 555 0103', 'country' => 'Sri Lanka', 'source' => 'whatsapp', 'status' => 'confirmed', 'adults' => 4, 'children' => 2],
    ['kind' => 'per_person', 'packageId' => $option('Full board'), 'checkIn' => $d(4), 'checkOut' => $d(7), 'guestName' => 'Liam & Grace Walker', 'guestEmail' => 'liam@example.com', 'guestPhone' => '+44 7700 900103', 'country' => 'United Kingdom', 'source' => 'website', 'status' => 'pending', 'adults' => 2, 'specialRequests' => 'Honeymoon, candle-lit dinner'],
    ['kind' => 'room', 'roomId' => $room('room-105'), 'checkIn' => $d(6), 'checkOut' => $d(8), 'guestName' => 'Arjun Mehta', 'guestPhone' => '+91 98200 55501', 'country' => 'India', 'source' => 'agoda', 'status' => 'confirmed'],
    ['kind' => 'room', 'roomId' => $room('room-110'), 'checkIn' => $d(9), 'checkOut' => $d(11), 'source' => 'block', 'status' => 'confirmed'],
    ['kind' => 'room', 'roomId' => $room('room-102'), 'checkIn' => $d(-6), 'checkOut' => $d(-3), 'guestName' => 'Nadeesha Silva', 'guestPhone' => '+94 76 555 0104', 'country' => 'Sri Lanka', 'source' => 'walk_in', 'status' => 'checked_out', 'adults' => 2],
];
if ($dayOut) {
    $demo[] = ['kind' => 'day_out', 'packageId' => $dayOut['id'], 'jblSpeaker' => true, 'checkIn' => $d(3), 'guestName' => 'Kandy Rovers office trip', 'guestPhone' => '+94 77 555 0105', 'country' => 'Sri Lanka', 'source' => 'phone', 'status' => 'confirmed', 'adults' => 18];
}

$added = 0;
$inHouse = [];
foreach ($demo as $b) {
    try {
        $b['adminNotes'] = isset($b['adminNotes']) ? "$TAG · {$b['adminNotes']}" : $TAG;
        $made = B::createBooking($b, true, 'demo');
        if ($made['status'] === 'checked_in') $inHouse[] = $made;
        $added++;
    } catch (Ydr\BookingError $e) {
        echo "Skipped one ({$e->getMessage()})\n";
    }
}
// a few POS items on the guests who are checked in
$menu = array_column(Pos::listItems(), 'id', 'name');
$orders = [
    [['Sri Lankan breakfast', 2], ['Fresh fruit juice', 2], ['Bonfire & firewood', 1]],
    [['Chicken', 2], ['Mix', 1], ['Lion lager', 4]],
];
foreach ($inHouse as $i => $b) {
    $lines = [];
    foreach ($orders[$i] ?? [] as [$name, $qty]) if (isset($menu[$name])) $lines[] = ['itemId' => $menu[$name], 'qty' => $qty];
    if ($lines) Pos::addCharges($b['id'], $lines, 'demo');
}
echo "Added $added demo booking(s). Open /admin/ or /pos to see them.\n";
