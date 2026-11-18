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

  craft: [
    { title: 'Timber', text: 'Log walls and branch-cut railings on the wooden cabanas, and stairs built by hand inside.', image: 'timber-stair' },
    { title: 'Clay', text: 'Hand-finished clay and a living rock face in the Mud House.', image: 'mud-lounge' },
    { title: 'Lantern light', text: 'Hanging lanterns on every veranda, lit as the mist comes in.', image: 'mud-veranda' },
    { title: 'Handmade murals', text: 'Fresco-style figures painted straight onto the wall.', image: 'mural' }
  ],

  dining: [
    'Sri Lankan curries',
    'International favourites',
    'Campfire BBQ',
    'Sunset Ceylon tea'
  ],

  // c/r = columns/rows spanned on the 12-column desktop grid; mc/mr = the same on the 2-column mobile grid.
  gallery: [
    { image: 'estate-golden', c: 7, r: 4, mc: 2, mr: 2 },
    { image: 'timber-stair', c: 5, r: 4, mc: 1, mr: 2 },
    { image: 'mud-veranda', c: 3, r: 3, mc: 1, mr: 1 },
    { video: 'peacock', image: 'peacock-poster', c: 3, r: 3, mc: 1, mr: 2, caption: 'A peacock on a balcony rail, filmed at the resort' },
    { image: 'cabana-palms', c: 6, r: 3, mc: 1, mr: 1 },
    { image: 'misty-villa', c: 5, r: 4, mc: 2, mr: 2 },
    { image: 'mural', c: 4, r: 2, mc: 1, mr: 1 },
    { image: 'veranda-tables', c: 3, r: 4, mc: 1, mr: 2 },
    { image: 'cabin-bedroom', c: 4, r: 2, mc: 1, mr: 1 },
    { image: 'villa-drone', c: 4, r: 3, mc: 2, mr: 2 },
    { image: 'dining-deck', c: 4, r: 3, mc: 1, mr: 1 },
    { image: 'valley-aerial', c: 4, r: 3, mc: 1, mr: 1 }
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
