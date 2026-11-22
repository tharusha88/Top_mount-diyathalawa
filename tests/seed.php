<?php
// The tests' own sample data: the 9 rooms, per-person options, extras and POS menu the booking-rule
// tests were written against (the booking system's first resort, Yodhaara). tests/bookings_test.php
// points RoomsSeed here with YDR_TEST_SEED, so the rules are tested the same way whatever rooms and
// prices the live site seeds. Not used by the website.
declare(strict_types=1);

namespace Ydr\Tests;

final class TestSeed
{
    /** Sample prices for the party area and JBL speaker (per night / day). */
    public static function extras(): array
    {
        return ['price_party_area' => 15000, 'price_jbl_speaker' => 3000];
    }

    /**
     * Sample per-person options (Admin → Booking setup): [name, description, kind, adult price,
     * child price, shown on the website]. "stay" is per person per night; "day" is a day visit.
     */
    public static function packages(): array
    {
        return [
            ['Full board', 'Breakfast, lunch and dinner', 'stay', 15000, 7500, true],
            ['Half board', 'Breakfast and dinner', 'stay', 12000, 6000, true],
            ['Bed & breakfast', 'Breakfast only', 'stay', 9000, 4500, false],
            ['Day out', 'A day by the falls, no overnight stay', 'day', 4500, 2500, false],
        ];
    }

    /** Sample POS categories, in order (Admin → Menu). */
    public static function posCategories(): array
    {
        return ['Bite', 'Food', 'Beverages', 'Other'];
    }

    /** Sample POS menu: [name, category, price]. */
    public static function posItems(): array
    {
        return [
            ...self::biteItems(),
            ['Sri Lankan breakfast', 'Food', 1800], ['Rice & curry', 'Food', 1800], ['Chicken kottu', 'Food', 1600],
            ['Vegetable fried rice', 'Food', 1400], ['Devilled chicken', 'Food', 2200], ['Egg hoppers (3)', 'Food', 900],
            ['Ceylon tea', 'Beverages', 300], ['Coffee', 'Beverages', 450], ['Fresh fruit juice', 'Beverages', 650],
            ['King coconut', 'Beverages', 350], ['Soft drink', 'Beverages', 300], ['Bottled water (1 L)', 'Beverages', 200],
            ['Lion lager', 'Beverages', 900],
            ['Laundry (per piece)', 'Other', 250], ['Bonfire & firewood', 'Other', 2500], ['Ella station pickup', 'Other', 3500],
        ];
    }

    public static function biteItems(): array
    {
        return [['Chicken', 'Bite', 1800], ['Fish', 'Bite', 2000], ['Mix', 'Bite', 2200]];
    }

    public static function all(): array
    {
        // capacity = guests the room sleeps; room bookings no longer include breakfast
        return array_map(fn ($r) => ['capacity' => $r['maxAdults'], 'features' => array_values(array_diff($r['features'], ['Breakfast included']))] + $r, self::rooms());
    }

    private static function rooms(): array
    {
        return [
            [
                'slug' => 'kumbuk-treehouse',
                'name' => 'Kumbuk Treehouse',
                'type' => 'Waterfall Treehouse',
                'tagline' => 'Wake up level with the treetops and the roar of the falls.',
                'description' => 'Built high among the old kumbuk trees, this timber treehouse looks straight down the valley to the waterfall. Slide open the windows and let the mist and birdsong in, then step down to your own plunge pool hidden in the ferns.',
                'price' => 48000,
                'maxAdults' => 2,
                'maxChildren' => 1,
                'beds' => '1 king bed',
                'size' => 45,
                'features' => ['Private plunge pool', 'Waterfall view balcony', 'King-size log bed', 'Rain shower', 'Breakfast included', 'Free Wi-Fi'],
                'images' => ['villa-hillside', 'cabin-bedroom', 'waterfall-sun'],
                'sort' => 1,
            ],
            [
                'slug' => 'na-treehouse',
                'name' => 'Na Treehouse',
                'type' => 'Waterfall Treehouse',
                'tagline' => 'A wooden nest under the Na trees, with a pool of its own.',
                'description' => 'Named after Sri Lanka’s national tree, the Na Treehouse is a quiet hideaway of polished pine and hand-cut log furniture. Its balcony faces the waterfall gorge — perfect for long breakfasts and lantern-lit evenings.',
                'price' => 48000,
                'maxAdults' => 2,
                'maxChildren' => 1,
                'beds' => '1 king bed',
                'size' => 45,
                'features' => ['Private plunge pool', 'Waterfall view balcony', 'King-size log bed', 'Rain shower', 'Breakfast included', 'Free Wi-Fi'],
                'images' => ['treehouse-restaurant', 'cabin-bedroom', 'falls-deck'],
                'sort' => 2,
            ],
            [
                'slug' => 'mara-pool-villa',
                'name' => 'Mara Pool Villa',
                'type' => 'Forest Pool Villa',
                'tagline' => 'Your own pool, shaded by a giant rain tree.',
                'description' => 'A spacious wooden villa opening onto a private pool and a sun deck in the canopy. Inside: a king-size log bed, cool timber floors and wide windows framed by forest green.',
                'price' => 42000,
                'maxAdults' => 2,
                'maxChildren' => 2,
                'beds' => '1 king bed + sofa bed',
                'size' => 55,
                'features' => ['Private pool', 'Sun deck in the trees', 'King-size bed', 'Outdoor shower', 'Breakfast included', 'Free Wi-Fi'],
                'images' => ['cabin-bedroom', 'boardwalk-pool', 'forest-deck'],
                'sort' => 3,
            ],
            [
                'slug' => 'hora-pool-villa',
                'name' => 'Hora Pool Villa',
                'type' => 'Forest Pool Villa',
                'tagline' => 'Swim at sunrise while the valley wakes up.',
                'description' => 'Tucked among tall hora trees on the slope, this villa pairs a private pool with an outdoor rain shower and a hammock deck. The sound of the waterfall drifts up through the trees all day long.',
                'price' => 42000,
                'maxAdults' => 2,
                'maxChildren' => 2,
                'beds' => '1 king bed + sofa bed',
                'size' => 55,
                'features' => ['Private pool', 'Sun deck in the trees', 'King-size bed', 'Outdoor shower', 'Breakfast included', 'Free Wi-Fi'],
                'images' => ['waterfall-mist', 'cabin-bedroom', 'forest-deck'],
                'sort' => 4,
            ],
            [
                'slug' => 'domba-pool-villa',
                'name' => 'Domba Pool Villa',
                'type' => 'Forest Pool Villa',
                'tagline' => 'Quiet, private and wrapped in green.',
                'description' => 'The most secluded of our pool villas, reached by a short forest path. Slow mornings, a pool all to yourself, and evenings under a ceiling of stars and fireflies.',
                'price' => 42000,
                'maxAdults' => 2,
                'maxChildren' => 2,
                'beds' => '1 king bed + sofa bed',
                'size' => 55,
                'features' => ['Private pool', 'Sun deck in the trees', 'King-size bed', 'Outdoor shower', 'Breakfast included', 'Free Wi-Fi'],
                'images' => ['forest-deck', 'cabin-bedroom', 'boardwalk-pool'],
                'sort' => 5,
            ],
            [
                'slug' => 'kitul-canopy-cabin',
                'name' => 'Kitul Canopy Cabin',
                'type' => 'Canopy Cabin',
                'tagline' => 'A cosy cabin on stilts for the adventurous at heart.',
                'description' => 'Raised on timber stilts above the hillside, the Kitul Cabin is wrapped in windows on every side. Ideal for trekkers and explorers who want to fall asleep to the forest and wake up ready for the trail.',
                'price' => 28000,
                'maxAdults' => 2,
                'maxChildren' => 0,
                'beds' => '1 queen bed',
                'size' => 30,
                'features' => ['Raised on stilts', 'Wrap-around windows', 'Queen-size bed', 'Hot water shower', 'Breakfast included', 'Free Wi-Fi'],
                'images' => ['falls-deck', 'villa-hillside', 'cabin-bedroom'],
                'sort' => 6,
            ],
            [
                'slug' => 'weera-canopy-cabin',
                'name' => 'Weera Canopy Cabin',
                'type' => 'Canopy Cabin',
                'tagline' => 'Simple, wooden, wonderful.',
                'description' => 'Clean lines of pine, a thatched roof and a big view of the mountains of Ella. The Weera Cabin is our simplest stay — and for many guests, their favourite.',
                'price' => 28000,
                'maxAdults' => 2,
                'maxChildren' => 0,
                'beds' => '1 queen bed',
                'size' => 30,
                'features' => ['Raised on stilts', 'Wrap-around windows', 'Queen-size bed', 'Hot water shower', 'Breakfast included', 'Free Wi-Fi'],
                'images' => ['waterfall-sun', 'villa-hillside', 'cabin-bedroom'],
                'sort' => 7,
            ],
            [
                'slug' => 'yodha-family-lodge',
                'name' => 'Yodha Family Lodge',
                'type' => 'Family Lodge',
                'tagline' => 'Two bedrooms, one pool, and room for the whole tribe.',
                'description' => 'Named for the legendary giants of Yodhaara, our largest lodge has two bedrooms, a shared living deck and a private pool. Kids love the treehouse feel; parents love the space and the quiet.',
                'price' => 75000,
                'maxAdults' => 4,
                'maxChildren' => 2,
                'beds' => '1 king bed + 2 single beds',
                'size' => 90,
                'features' => ['Two bedrooms', 'Private pool', 'Living deck', 'Kids welcome', 'Breakfast included', 'Free Wi-Fi'],
                'images' => ['boardwalk-pool', 'villa-hillside', 'lobby'],
                'sort' => 8,
            ],
            [
                'slug' => 'dahara-honeymoon-hideaway',
                'name' => 'Dahara Honeymoon Hideaway',
                'type' => 'Honeymoon Hideaway',
                'tagline' => 'The closest you can sleep to the waterfall.',
                'description' => 'Our most romantic hideaway sits a few steps from the falls. A private pool, an outdoor bathtub under the trees, and a candle-lit dinner for two on your first night — the forest takes care of the rest.',
                'price' => 58000,
                'maxAdults' => 2,
                'maxChildren' => 0,
                'beds' => '1 king bed',
                'size' => 50,
                'features' => ['Private pool', 'Closest to the falls', 'Candle-lit dinner for two', 'Outdoor bathtub', 'Breakfast included', 'Free Wi-Fi'],
                'images' => ['dinner-candlelight', 'cabin-bedroom', 'waterfall-cascade'],
                'sort' => 9,
            ],
        ];
    }
}

return [
    'rooms' => TestSeed::all(),
    'extras' => TestSeed::extras(),
    'packages' => TestSeed::packages(),
    'posCategories' => TestSeed::posCategories(),
    'posItems' => TestSeed::posItems(),
    'biteItems' => TestSeed::biteItems(),
];
