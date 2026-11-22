/*
  Top Mountain Resort - site content.
  Facts here are taken from the resort's existing website. Do not add prices,
  amenities, reviews or places that the resort has not confirmed.
  Images and videos are referenced by key; the keys live in tools/media.config.json.
*/
window.TMR_CONTENT = {
  contact: {
    phones: [
      { label: '+94 77 736 8264', href: 'tel:+94777368264', primary: true },
      { label: '+94 57 222 7143', href: 'tel:+94572227143' }
    ],
    // The same hotline number, as used for WhatsApp in the resort's other site build.
    whatsapp: '94777368264',
    address: ['122/1A, Pahala Kadurugamuwa Road', 'Diyatalawa, Sri Lanka'],
    mapsUrl: 'https://www.google.com/maps/search/?api=1&query=Top+Mountain+Resort+Diyatalawa',
    // Add the resort's real profiles here, e.g. { label: 'Instagram', href: 'https://instagram.com/...' }.
    // The footer only shows this group when at least one link exists.
    socials: []
  },

  /*
    Highlights: the expanding photo panels at the end of the estate introduction.
    Shown in this order; the first one opens first. Photos come from
    D:/Projects/New folder/New folder (keys in tools/media.config.json).
  */
  highlights: [
    { image: 'hl-valley-terrace', title: 'The valley terrace', text: 'A garden swing and timber benches on an open terrace, with the wooded valley beyond the rail.' },
    { image: 'hl-aframe-room', title: 'Under the timber roof', text: 'Two double beds in an A-frame room lined in polished timber from floor to ridge.' },
    { image: 'hl-elephant-wall', title: 'The elephant wall', text: 'An elephant shaped in relief on a clay wall, with tree-trunk seats and lanterns on the veranda.' },
    { image: 'hl-villa-veranda', title: 'A table on the veranda', text: 'Marble underfoot, pendant lamps overhead and a long timber table beside the rooms.' },
    { image: 'hl-aframe-loft', title: 'The A-frame loft', text: 'Four beds tucked under the sloping roof, with a window at the far end.' },
    { image: 'hl-sigiriya-mural', title: 'Sigiriya, on the wall', text: 'Maidens in the style of the Sigiriya frescoes, painted beside a room door.' }
  ],

  /*
    Every stay, shown in this order (lowest starting rate first).
    photo  = main room photo; detail = optional small floating photo (keys in tools/media.config.json).
  */
  stays: [
    {
      id: 'cabana', name: 'Wooden Cabana', kind: 'Cabana',
      tagline: 'Timber walls and a branch-rail balcony',
      desc: 'Rustic timber walls, a branch-rail balcony and mountain views. A double room for couples, or a family room.',
      size: '25 – 35 m²', guests: '2 – 4 adults, 1 child', view: 'Mountain view', from: 'Rs 8,000',
      photo: 'cabin-bedroom', detail: 'cabana-forest'
    },
    {
      id: 'mud-house', name: 'Mud House', kind: 'Family villa',
      tagline: 'Clay walls, a rock face and lanterns',
      desc: 'A family villa with clay walls, a rock face for one side of the room, and a veranda hung with lanterns.',
      size: '40 m²', guests: '4 adults, 1 child', view: 'Nature view', from: 'Rs 13,200',
      photo: 'mud-lounge', detail: 'mud-house'
    },
    {
      id: 'villas', name: 'The Villas', kind: 'Double & family rooms',
      tagline: 'Glass, light and a private balcony',
      desc: 'Bright double and family rooms with glass from floor to ceiling and a private balcony over the valley.',
      size: '45 – 120 m²', guests: '2 – 6 adults', view: 'Private balcony', from: 'Rs 16,500',
      photo: 'room-window', detail: 'villa-balcony'
    },
    {
      id: 'family-villa', name: 'Family Deluxe Villa', kind: 'For families',
      tagline: 'Room for the whole family',
      size: '120 m²', guests: '6 adults, 2 children', from: 'Rs 22,000',
      photo: 'room-family'
    },
    {
      id: 'bungalow', name: 'Whole Bungalow', kind: 'For groups',
      tagline: 'The whole house, just for your group',
      size: '150 m²', guests: '8 adults, 3 children', from: 'Rs 31,200',
      photo: 'bungalow-aerial'
    },
    {
      id: 'luxury-cabana', name: 'Luxury Cabana', kind: 'For large groups',
      tagline: 'Our largest stay, for big groups',
      size: '150 m²', guests: '12 adults, 4 children', from: 'Rs 38,000',
      photo: 'timber-stair'
    }
  ],

  /*
    Accommodation gallery: one tile per room, in this order. The first photo is the tile's cover;
    the button on the tile opens all of them. Photo keys come from tools/media.config.json.
    Numbered rooms show 'Room' over the number; others can set kicker and label (kicker over label).
  */
  rooms: [
    { id: '101', name: 'Room 101', photos: ['room-101-01', 'room-101-02', 'room-101-03', 'room-101-04', 'room-101-05', 'room-101-06', 'room-101-07', 'room-101-08', 'room-101-09'] },
    { id: '102', name: 'Room 102', photos: ['room-102-02', 'room-101-01', 'room-101-03', 'room-102-04', 'room-102-06'] },
    { id: '103', name: 'Room 103', photos: ['room-103-03', 'room-101-01', 'room-101-03', 'room-103-05'] },
    { id: '104', name: 'Room 104', photos: ['room-104-04', 'room-104-01', 'room-104-02', 'room-104-03', 'room-104-05'] },
    { id: '105', name: 'Room 105', photos: ['room-105-02', 'room-105-01', 'room-105-03'] },
    { id: '106', name: 'Room 106', photos: ['room-106-04', 'room-106-01', 'room-106-02', 'room-106-03'] },
    { id: '108', name: 'Room 108', photos: ['room-108-02', 'room-108-01', 'room-108-03', 'room-108-04', 'room-108-05', 'room-108-06'] },
    { id: '110', name: 'Room 110', photos: ['room-110-01', 'room-110-02', 'room-110-03', 'room-110-04', 'room-110-05', 'room-110-06', 'room-110-07', 'room-110-08', 'room-110-09'] },
    { id: '111', name: 'Room 111', photos: ['room-111-03', 'room-111-01', 'room-111-02', 'room-111-04', 'room-111-05', 'room-111-06', 'room-111-07'] },
    { id: '112', name: 'Room 112', photos: ['room-112-03', 'room-112-01', 'room-112-02', 'room-112-04', 'room-111-07'] },
    { id: '114', name: 'Room 114', photos: ['room-114-01', 'room-114-02', 'room-114-03'] },
    { id: '115', name: 'Room 115', photos: ['room-115-01', 'room-115-02'] },
    { id: '116', name: 'Room 116', photos: ['room-116-01', 'room-116-02', 'room-116-03', 'room-116-04', 'room-116-05'] },
    { id: '117', name: 'Room 117', photos: ['room-117-02', 'room-117-01', 'room-117-03'] },
    { id: '121', name: 'Room 121', photos: ['room-121-02', 'room-121-01', 'room-121-03', 'room-121-04'] },
    { id: '125', name: 'Room 125', photos: ['room-125-02', 'room-125-01', 'room-125-03'] },
    { id: '126', name: 'Room 126', photos: ['room-126-01', 'room-125-03', 'room-126-03'] },
    { id: '127', name: 'Room 127', photos: ['room-127-03', 'room-127-01', 'room-125-03'] },
    { id: '128', name: 'Room 128', photos: ['room-128-03', 'room-128-01', 'room-128-02'] },
    { id: 'rose', name: 'Rose Bungalow', kicker: 'Bungalow', label: 'Rose', photos: ['room-rose-01', 'room-rose-02', 'room-rose-03'] }
  ],
  ratesNote: 'Starting rates. Your exact rate is confirmed when you enquire.',

  amenities: [
    { icon: 'wifi', label: 'Free Wi-Fi' },
    { icon: 'shower', label: 'Hot water shower' },
    { icon: 'bath', label: 'Private bathroom' },
    { icon: 'kettle', label: 'Kettle' },
    { icon: 'tv', label: 'Flat-screen TV' },
    { icon: 'parking', label: 'Private parking' }
  ],

  experiences: [
    { name: 'Campfire and stargazing', text: 'A crackling fire, hot Ceylon tea and a sky full of stars.', image: 'campfire', when: 'After dusk' },
    { name: 'Natural spring and rope swing', text: 'Find the natural spring, then take a turn on the rope swing.', image: 'estate-aerial', when: 'Water' },
    { name: 'Tea-estate walks', text: 'Tea-estate trails start right outside your door.', image: 'valley-aerial', when: 'Walking' },
    { name: 'Panoramic viewing deck', text: 'Long views over the hills, with mist lifting off the valley.', image: 'veranda', when: 'Views' },
    { name: 'Lawn cricket', text: 'Bats out on the lawn for a family game.', image: 'lawn-court', when: 'Games' },
    { name: 'Badminton', text: 'Rackets out for a game of evening badminton.', image: 'villa-aerial', when: 'Games' }
  ],


  dining: [
    'Sri Lankan curries',
    'International favourites',
    'Campfire BBQ',
    'Sunset Ceylon tea'
  ],

  // the Dining tab of the experiences section: these photo cards, then an "On the table" card listing `dining`
  diningPlaces: [
    { name: 'The dining deck', text: 'An open-air pavilion raised above the rooms, among palms and trees.', image: 'dining-deck', when: 'The deck' },
    { name: 'Campfire BBQ', text: 'Food sizzling on the grills in the low evening sun.', image: 'bbq', when: 'Evenings' },
    { name: 'Sunset Ceylon tea', text: 'A pot of Ceylon tea as the light goes, looking out to the hills.', image: 'veranda', when: 'Sunset' },
    { name: 'The long veranda', text: 'Timber chairs and tables beside the rooms.', image: 'veranda-tables', when: 'Close to the rooms' }
  ],


  location: [
    ['Town', 'Diyatalawa'],
    ['Close by', 'Bandarawela'],
    ['Day trips', 'Ella · Horton Plains'],
    ['Tea-estate trails', 'Outside your door']
  ],

  nearby: [
    { name: 'Idalgashinna Rock', km: 13, type: 'Viewpoint', image: 'near-idalgashinna', map: 'Idalgashinna Rock', desc: 'Tall pines, drifting mist and a long view off the edge of the hill country.' },
    { name: 'Adisham Bungalow', km: 13, type: 'Heritage', image: 'near-adisham', map: 'Adisham Bungalow Haputale', desc: 'A 1930s Tudor-style stone house near Haputale, now a Benedictine monastery with English-style gardens.' },
    { name: 'Ella Organic Tea Factory', km: 16, type: 'Tea', image: 'near-tea-factory', map: 'Ella Organic Tea Factory', desc: 'Walk the tea rows, see how the leaf is made, and finish with a fresh cup of Uva tea.' },
    { name: 'Flying Ravana', km: 21, type: 'Adventure', image: 'near-flying-ravana', map: 'Flying Ravana Ella', desc: 'A long zipline ride across a forested valley just outside Ella.' },
    { name: 'Ravana Cave', km: 21, type: 'Legend', image: 'near-ravana-cave', map: 'Ravana Cave Ella', desc: 'A cliffside cave near Ella, tied by legend to King Ravana of the Ramayana. Reached by a steep stairway.' },
    { name: 'Mahamevnawa Meditation Monastery', km: 21, type: 'Temple', image: 'near-mahamevnawa', map: 'Mahamevnawa Meditation Monastery Ella', desc: 'A quiet meditation monastery and a white stupa on a ridge, often wrapped in morning mist.' },
    { name: 'Nine Arch Bridge', km: 22, type: 'Railway', image: 'near-nine-arch', map: 'Nine Arch Bridge Ella', desc: 'The colonial-era railway viaduct near Ella. Time it right and watch a train curve across its nine arches.' },
    { name: 'Ella Rock', km: 22, type: 'Hike', image: 'near-ella-rock', map: 'Ella Rock', desc: 'A half-day hike along the railway and through tea estates to a summit high above the Ella Gap.' },
    { name: 'Little Adam’s Peak', km: 22, type: 'Hike', image: 'near-little-adams', map: "Little Adam's Peak Ella", desc: 'An easy climb from Ella through tea gardens, with wide views of Ella Rock and the gap.' },
    { name: 'Ravana Falls', km: 25, type: 'Waterfall', image: 'near-ravana-falls', map: 'Ravana Falls', desc: 'A wide, tiered waterfall right beside the Ella–Wellawaya road.' },
    { name: 'Lipton’s Seat', km: 25, type: 'Viewpoint', image: 'near-liptons', map: "Lipton's Seat Haputale", desc: 'The lookout above Dambatenne where Sir Thomas Lipton surveyed his tea estates. Best at sunrise.' },
    { name: 'The Devil’s Staircase', km: 26, type: 'Trail', image: 'near-devils', map: "Devil's Staircase Ohiya", desc: 'A steep, hairpinned track dropping off the high plateau, loved by hikers and 4×4 drivers.' },
    { name: 'Wangedigala Rock', km: 28, type: 'Sunrise hike', image: 'near-wangedigala', map: 'Wangedigala Rock', desc: 'An early climb to a rocky top for sunrise, often above a sea of cloud.' },
    { name: 'Pilkington View Point', km: 32, type: 'Viewpoint', image: 'near-pilkington', map: 'Pilkington View Point', desc: 'A grassy hilltop with a lone tree, where the cloud often sits right at your feet.' },
    { name: 'Diyaluma Falls', km: 42, type: 'Waterfall', image: 'near-diyaluma', map: 'Diyaluma Falls', desc: 'Sri Lanka’s second-highest waterfall, with natural rock pools to swim in at the top.' },
    { name: 'Bakers Bend', km: 51, type: 'Scenic road', image: 'near-bakers-bend', map: 'Bakers Bend Sri Lanka', desc: 'A road that loops through steep green hills. Worth the drive for the bends alone.' }
  ],

  alsoNearby: [
    ['Thomas Seat View Point', 12],
    ['Haputale Main View Point', 12],
    ['Porawagala View Point', 12],
    ['Dowa Raja Maha Viharaya', 13],
    ['Ella View Point', 22],
    ['Nildiya Pokuna', 24],
    ['Horton Plains', 28],
    ['Lanka Ella Falls', 32]
  ],

  /*
    Verified guest reviews only. Copy them word for word from a real source,
    with the guest's permission or the platform's terms allowing it. The section
    stays hidden while this list is empty, so no placeholder ever shows.
    Shape: { quote: '...', guest: 'First name L.', source: 'Google', date: 'March 2026', stay: 'Mud House', rating: '5/5' }
  */
  reviews: [],
  // Optional: link to the resort's public review page, e.g. { label: 'Read reviews on Google', href: 'https://...' }
  reviewsLink: null
};
