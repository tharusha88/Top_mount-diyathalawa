<?php
// Data only used to seed an empty database: Top Mountain's 20 rooms, the per-person options,
// the extras prices and the POS menu. Everything is editable in the admin panel.
declare(strict_types=1);

namespace Ydr;

final class RoomsSeed
{
    private static ?array $fixture = null;

    /**
     * The tests use fixed sample data (tests/seed.php, named by YDR_TEST_SEED), so the booking rules are
     * tested the same way whatever rooms and prices this site seeds. Null for the website.
     */
    private static function fixture(string $part): ?array
    {
        $file = getenv('YDR_TEST_SEED');
        if (!$file) return null;
        self::$fixture ??= require $file;
        return self::$fixture[$part];
    }

    /**
     * The party area and JBL speaker extras (per night / day). 0 = not offered: the booking page
     * hides an extra until it has a price (Admin → Booking setup → Extras).
     */
    public static function extras(): array
    {
        return self::fixture('extras') ?? ['price_party_area' => 0, 'price_jbl_speaker' => 0];
    }

    /**
     * Sample per-person options (Admin → Booking setup): [name, description, kind, adult price,
     * child price, shown on the website]. "stay" is per person per night; "day" is a day visit.
     * The prices are samples, so every option starts hidden: show one in Booking setup once its
     * real prices are in.
     */
    public static function packages(): array
    {
        return self::fixture('packages') ?? [
            ['Full board', 'Breakfast, lunch and dinner', 'stay', 15000, 7500, false],
            ['Half board', 'Breakfast and dinner', 'stay', 12000, 6000, false],
            ['Bed & breakfast', 'Breakfast only', 'stay', 9000, 4500, false],
            ['Day out', 'A day at the resort, no overnight stay', 'day', 4500, 2500, false],
        ];
    }

    /** Sample POS categories, in order (Admin → Menu). */
    public static function posCategories(): array
    {
        return self::fixture('posCategories') ?? ['Bite', 'Food', 'Beverages', 'Other'];
    }

    /** Sample POS menu: [name, category, price]. */
    public static function posItems(): array
    {
        return self::fixture('posItems') ?? [
            ...self::biteItems(),
            ['Sri Lankan breakfast', 'Food', 1800], ['Rice & curry', 'Food', 1800], ['Chicken kottu', 'Food', 1600],
            ['Vegetable fried rice', 'Food', 1400], ['Devilled chicken', 'Food', 2200], ['Egg hoppers (3)', 'Food', 900],
            ['Ceylon tea', 'Beverages', 300], ['Coffee', 'Beverages', 450], ['Fresh fruit juice', 'Beverages', 650],
            ['King coconut', 'Beverages', 350], ['Soft drink', 'Beverages', 300], ['Bottled water (1 L)', 'Beverages', 200],
            ['Lion lager', 'Beverages', 900],
            ['Laundry (per piece)', 'Other', 250], ['Bonfire & firewood', 'Other', 2500], ['Diyatalawa station pickup', 'Other', 1500],
        ];
    }

    public static function biteItems(): array
    {
        return self::fixture('biteItems') ?? [['Chicken', 'Bite', 1800], ['Fish', 'Bite', 2000], ['Mix', 'Bite', 2200]];
    }

    public static function all(): array
    {
        if ($rooms = self::fixture('rooms')) return $rooms;
        // capacity = guests the room sleeps; room bookings no longer include breakfast
        return array_map(fn ($r) => ['capacity' => $r['maxAdults'], 'features' => array_values(array_diff($r['features'], ['Breakfast included']))] + $r, self::rooms());
    }

    /**
     * Top Mountain's 20 rooms, with the photos the website shows for each. The names and photos are
     * real; the type, price per night and how many each sleeps are SAMPLES (the website doesn't say
     * which room is which kind of stay). Set the real ones in Admin → Rooms before going live.
     */
    private static function rooms(): array
    {
        $amenities = ['Private bathroom', 'Hot water shower', 'Kettle', 'Flat-screen TV', 'Free Wi-Fi'];
        $room = fn (string $slug, string $name, string $type, array $images, int $sort) => [
            'slug' => $slug, 'name' => $name, 'type' => $type, 'tagline' => '', 'description' => '',
            'price' => 8000, 'maxAdults' => 2, 'maxChildren' => 1, 'beds' => '', 'size' => 0,
            'features' => $amenities, 'images' => $images, 'sort' => $sort,
        ];
        return [
            $room('room-101', 'Room 101', 'Room', ['room-101-01', 'room-101-02', 'room-101-03', 'room-101-04', 'room-101-05', 'room-101-06', 'room-101-07', 'room-101-08', 'room-101-09'], 1),
            $room('room-102', 'Room 102', 'Room', ['room-102-02', 'room-101-01', 'room-101-03', 'room-102-04', 'room-102-06'], 2),
            $room('room-103', 'Room 103', 'Room', ['room-103-03', 'room-101-01', 'room-101-03', 'room-103-05'], 3),
            $room('room-104', 'Room 104', 'Room', ['room-104-04', 'room-104-01', 'room-104-02', 'room-104-03', 'room-104-05'], 4),
            $room('room-105', 'Room 105', 'Room', ['room-105-02', 'room-105-01', 'room-105-03'], 5),
            $room('room-106', 'Room 106', 'Room', ['room-106-04', 'room-106-01', 'room-106-02', 'room-106-03'], 6),
            $room('room-108', 'Room 108', 'Room', ['room-108-02', 'room-108-01', 'room-108-03', 'room-108-04', 'room-108-05', 'room-108-06'], 7),
            $room('room-110', 'Room 110', 'Room', ['room-110-01', 'room-110-02', 'room-110-03', 'room-110-04', 'room-110-05', 'room-110-06', 'room-110-07', 'room-110-08', 'room-110-09'], 8),
            $room('room-111', 'Room 111', 'Room', ['room-111-03', 'room-111-01', 'room-111-02', 'room-111-04', 'room-111-05', 'room-111-06', 'room-111-07'], 9),
            $room('room-112', 'Room 112', 'Room', ['room-112-03', 'room-112-01', 'room-112-02', 'room-112-04', 'room-111-07'], 10),
            $room('room-114', 'Room 114', 'Room', ['room-114-01', 'room-114-02', 'room-114-03'], 11),
            $room('room-115', 'Room 115', 'Room', ['room-115-01', 'room-115-02'], 12),
            $room('room-116', 'Room 116', 'Room', ['room-116-01', 'room-116-02', 'room-116-03', 'room-116-04', 'room-116-05'], 13),
            $room('room-117', 'Room 117', 'Room', ['room-117-02', 'room-117-01', 'room-117-03'], 14),
            $room('room-121', 'Room 121', 'Room', ['room-121-02', 'room-121-01', 'room-121-03', 'room-121-04'], 15),
            $room('room-125', 'Room 125', 'Room', ['room-125-02', 'room-125-01', 'room-125-03'], 16),
            $room('room-126', 'Room 126', 'Room', ['room-126-01', 'room-125-03', 'room-126-03'], 17),
            $room('room-127', 'Room 127', 'Room', ['room-127-03', 'room-127-01', 'room-125-03'], 18),
            $room('room-128', 'Room 128', 'Room', ['room-128-03', 'room-128-01', 'room-128-02'], 19),
            $room('rose-bungalow', 'Rose Bungalow', 'Bungalow', ['room-rose-01', 'room-rose-02', 'room-rose-03'], 20),
        ];
    }
}
