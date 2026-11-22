# Top Mountain Resort: website and booking system

The botanical contemporary redesign of the Top Mountain Resort site (HTML, CSS, vanilla JS, with
GSAP + ScrollTrigger from cdnjs for motion), joined to the booking system first built for
Yodhaara Nature Resort (`D:/Projects/Panora/Yoodhara`). Top Mountain has its **own copy** of that
system: its own database, rooms, admin login and branding. Nothing is shared with Yodhaara.

It runs on **plain PHP 8.1+** with **MySQL / MariaDB** (or SQLite for a local copy), which every
ordinary shared hosting plan includes. It no longer deploys to GitHub Pages: the home page and the
booking page are served by PHP.

- **Website** (`/`): the redesign. Its booking form asks the booking system which rooms are free
  for the dates, then sends the guest to the booking page with the dates filled in. If the system
  can't be reached, the guest can still call or WhatsApp with the details, as before.
- **Booking** (`/book`): live availability, room choice, guest details (name, NIC or passport,
  email, phone). No payment is taken online: the booking is **pending** until the advance is paid,
  and the guest is told how to pay and whom to call. Styled like the redesign.
- **Advance (optional)**: in Admin → Booking setup → Advance payment, **Collect an advance before
  arrival** switches it on or off, and the advance is a **% of the total** or a **fixed amount**
  (0 = the whole total; never more than the total). Off: guests are told there is nothing to pay
  before they arrive and they pay at the resort; bookings stay pending until staff confirm them and
  are never released or sent payment reminders. Each booking can override it under **Edit booking
  → Advance** (or on a new booking): as in Booking setup, no advance, or its own amount
  (`bookings.advance_amount`: NULL / 0 / amount). Only a reservation that asks for an advance is
  held and released.
- **Several rooms, one booking**: a family or group can add up to 10 rooms to one booking (one
  reference, one advance, one invoice, one bill). Each room is priced per night; an agreed rate
  set in the admin panel is a night for all its rooms together. Staff add or change a booking's
  rooms with **Add or change rooms** in the booking, or tick **Add more rooms** on a new booking.
  The first room is the booking's main room (`bookings.room_id`); a booking with 2+ rooms lists
  them all in `booking_rooms`, like a per-person group.
- **Admin panel** (`/admin/`): pending requests (Confirm / Decline), calendar, bookings list with
  CSV export, manual bookings (phone, WhatsApp, walk-in, Booking.com, Agoda…), closed days,
  rooms and the rate sheet (room categories and RO / BB / HB / FB prices), booking setup (per-person options, capacity, advance-payment details), coupons, bills,
  finance, staff logins, the contact details shown on the website, WhatsApp alerts and printing.
- **POS** (`/pos`): check guests in, tap items onto their bill, take payments, check out and
  issue numbered bills (`INV-00001`). Works on a tablet or a phone.
- **Email**: the admin is emailed for each website booking; guests get "booking received",
  confirmation and cancellation emails. Without SMTP settings they are saved in `data/outbox/`.

- **Reminders**: guests get an email 2 days before check-in (confirmed bookings), and 1 day before
  the hold date runs out if a reservation is still unpaid. Each reminder goes out once per booking.
  Add a daily cron job (cPanel / Hostinger → Cron Jobs): `0 8 * * * php /path/to/scripts/send-reminders.php`
  (`--dry-run` lists what is due). Without cron the website sends them itself, at most once an hour,
  when someone visits. In **Admin → Settings → Reminders** you can switch the automatic sending
  off, change the days (0 turns that kind off), see what is due, and press **Send now**. To remind
  one guest at any time, open their booking and press **Send reminder**.
- **Booking confirmations**: staff fill in a booking (Admin → New booking: tick **Then send the guest
  a link…**, or open any booking and press **Send booking confirmation**) and send the guest a private
  link on WhatsApp, by email or copied. The guest's page (`/confirm/<ref>/<code>`) shows the stay and
  the price, with the details staff entered filled in and anything still missing marked **Needed**
  (NIC or passport, email, phone, name, as the website asks). The guest confirms; staff are emailed and
  get a WhatsApp alert, and the guest gets the invoice (and how to pay a reservation). Confirming
  doesn't change the booking's status: staff still confirm it once the advance is in. Booking lists
  mark **Awaiting guest** / **Guest confirmed**. Once confirmed, the link can't change the booking
  again. Code: `app/Confirmations.php` (its own `booking_confirms` table, made when first needed),
  `views/confirm.html`, `js/confirm.js`, `js/admin-confirm.js`.
- **Chatbot**: a chat window on the home and booking pages, answered by Top Mountain's own bot on
  the Panora chatbot platform (`D:/Projects/Panora/Chatbot`), a "hotel" brand with hotel safety
  rules. **Admin → Chatbot** makes the bot (it starts with what the website says: stays and
  starting rates, rooms, facilities, food, things to do, places nearby, contact, check-in times,
  how to book), edits its name, instructions and knowledge entries, has a **Try the bot** chat,
  and switches the website's chat window on or off. The browser never talks to the chatbot: the
  chat window calls `/api/chat` (rate-limited), and `app/Chatbot.php` calls the chatbot's bridge
  with a shared secret. The conversation is kept in the visitor's browser for the visit only.
  The same bot can later answer the resort's WhatsApp through a CRM.

Booking references look like `TMR-7K3M9Q`. Double bookings are impossible: every booking is checked
and saved inside a database transaction.

## Run locally

Needs PHP 8.1+ (the XAMPP install on `D:/xampp` works). For a quick local copy, use SQLite: no
database server needed.

1. Copy `.env.example` to `.env` and set `DB_CONNECTION=sqlite` (or fill in the `DB_` lines for a
   MySQL database). Set `ADMIN_PASSWORD` to something long: it creates the first admin account.
2. Double-click `start-site.bat`, or run:

```bash
D:/xampp/php/php.exe -d extension=gd -S localhost:3100 -t public public/index.php
```

- Website: http://localhost:3100/ · booking page: http://localhost:3100/book
- Admin panel: http://localhost:3100/admin/ · POS: http://localhost:3100/pos (sign in with
  `ADMIN_USERNAME` / `ADMIN_PASSWORD` from `.env`; change the password later in Admin → Settings).
- `php scripts/seed-demo.php` adds a few sample bookings so the admin panel and POS have something
  to show; `php scripts/seed-demo.php --clear` removes them.
- `php tests/confirmations_test.php` runs the booking-confirmation link tests the same way.
- `php tests/bookings_test.php` runs the booking and POS rule tests on a throwaway SQLite file
  (never the real database). The tests use their own fixed sample rooms (`tests/seed.php`).
- `php public/check.php` runs the hosting check from the command line.

The tables, the 20 rooms and the settings are created the first time the site opens.

**The chatbot, locally** (optional): run the Panora chatbot on this machine and point the site at it.

1. In `D:/Projects/Panora/Chatbot`: `venv\Scripts\python app.py` (it serves `http://127.0.0.1:5000`;
   the first time, also run `venv\Scripts\python init_db.py` and `venv\Scripts\python migrations.py`).
   Put a Google Gemini key after `GEMINI_API_KEY=` in its `.env` for real answers; without one the
   chat window says it can't answer and offers the phone number and WhatsApp.
2. In this site's `.env`: `CHATBOT_URL=http://127.0.0.1:5000`, `CHATBOT_SECRET=` the chatbot's
   `CRM_BRIDGE_SHARED_SECRET`, `CHATBOT_TENANT=topmountain`.
3. **Admin → Chatbot → Make the bot**, check its answers in **Try the bot**, then switch the chat
   window on.

Live, the chatbot runs on its own server (it is Python, not PHP). The site calls it from its server,
so `CHATBOT_URL` must be reachable from the hosting (an `https://` address), and the bridge must only
accept calls carrying the secret: put it behind HTTPS and, if you can, allow only the hosting's IP.

## Putting it online (shared hosting)

```bash
php scripts/build.php
```

It creates `dist/topmountain-php-hosting.zip` (upload into `public_html` and extract) and
`dist/DEPLOY.md`, a step-by-step guide. The zip includes a production `.env` with a freshly
generated admin password and a hosting check page (`/check.php`). Real production values (the
live database login) can go in `hosting/production.env` (git-ignored); the build merges them in.
The zip is large (about 180 MB) because it carries the site's photos and videos.

## Before going live: sample values to replace

- **Room rates** (Admin → Rate sheet): the resort's rate sheet is loaded — 25 rooms in 8 categories
  (Wooden Cabana, White House, Standard Villa ×2, Mud House, Bungalow, Family Deluxe Villa, Luxury
  Villa), each with its room type, guests and a price per night for each meal plan (RO room only,
  BB bed & breakfast, HB half board, FB full board), plus the whole-villa prices. Guests choose the
  meal plan when booking; a whole villa books every room of its category at one price. Edit it all
  in the Rate sheet table (a price of 0 = that plan isn't offered). The home page shows it under
  "Room rates". **Rooms 118, 119, 120 and Bungalow B2 / B3** were added from the sheet and borrow
  the photos of a room like them (and the website's "Rose Bungalow" is now Bungalow B1): give
  them their own photos in Admin → Rooms.
- **Per-person options** (Admin → Booking setup): Full board, Half board, Bed & breakfast and Day
  out are seeded with sample prices and **hidden**. Show one once its real prices are in.
- **Extras**: the party area and JBL speaker start at price 0, which hides them on the booking
  page. Set a price to offer one.
- **Advance payment** (Admin → Booking setup): bank, account and % advance are empty until filled
  in, so guests only see the number to call (and, with the advance at 0, are asked for the whole
  total). Set the % or a fixed amount, or switch the advance off if guests pay at the resort.
- **Email**: the website lists no email address, so `RESORT_EMAIL` is empty and `MAIL_FROM` is a
  placeholder (`bookings@example.com`). Create the resort's mailbox and fill in the SMTP settings.
- **Booking notifications** (Admin → Settings): every new website booking is emailed to each
  admin address on the list there (until one is saved, `ADMIN_NOTIFY_EMAILS` from `.env`), and sent
  by WhatsApp to each number on the WhatsApp alerts list, again when it is confirmed (each can be
  switched off). Every WhatsApp number needs its own free CallMeBot key; the list starts with the
  hotline (94777368264). Without a key, or without SMTP, messages wait in `data/outbox/`.
- **POS menu** (Admin → POS menu): sample items and prices.
- **Map pin**: `RESORT_MAP_COORDS` is empty, so the maps link searches for the resort by name.

## How it's organised

```
public/              web root: index.php (front controller), .htaccess, .user.ini, check.php
  css/style.css      the redesign: design tokens and every layout of the home page
  css/booking-base.css, book.css   the booking page (the redesign's palette and type)
  css/admin.css, pos.css, docs.css the admin panel, POS and invoices / bills
  js/data/content.js highlights, stays, prices, experiences, dining, gallery, contacts, reviews
  js/data/media.js   GENERATED media manifest (tools/build_media.py). Don't edit by hand
  js/media.js, render.js, main.js  the home page (main.js holds the booking form)
  js/common.js, calendar.js, book.js   the booking page
  js/admin*.js, pos.js, staff.js, docs.js, pdf.js   the admin panel and POS
  assets/            the site's optimised photos, videos, logo and icons
  media/             the booking system's photo uploads (manifest.json) and its logo files
views/               page templates: index.html (the home page), book.html, admin.html, pos.html
app/                 PHP backend (bookings, POS, admin API, email, settings)
  App.php            routes, security headers, CSRF guard, pages and the JSON API
  RoomsSeed.php      Top Mountain's 20 rooms and the sample options, extras and POS menu
  Media.php          photo library: uploads plus the website's own photos (read-only)
data/                SQLite database, email outbox, login key (created automatically, private)
scripts/             build.php (hosting zip), seed-demo.php (demo bookings)
tests/               booking-rule tests and their fixed sample data
tools/               the media build
hosting/             DEPLOY.md and POS-PRINTING.md
```

The home page is `views/index.html`. Its paths are absolute (`/css/style.css`, `/js/main.js`) so
PHP can add `?v=<file date>` to each one: browsers keep CSS and JS for a week, so without it they
would run old scripts after an upload. Its inline script carries `nonce="{{nonce}}"`, because the
site sends a strict Content-Security-Policy.

Contact details: `content.js` holds the defaults, and the phone, WhatsApp number and maps link
set in Admin → Website (or `.env`) replace them on the home page (`render.js`).

## Changing photos or videos

No page or script refers to a media file path directly. Everything goes through a key defined in
`tools/media.config.json`. Every website photo is also in the admin's photo library (Admin →
Rooms → *Choose / upload photos*), so rooms can use any of them.

1. Put the original in `D:/Projects/New folder` (prefix `new:`),
   `D:/Projects/New folder/Resourses` (`res:`) or the old site's `assets`
   folder (`legacy:`). These locations are listed under `sources` in the config.
   The originals stay there; only the optimised copies go into this project.
2. Add or edit an entry in `tools/media.config.json`: a key, `src` (or `frame` +
   `time` to use a still from a video), `alt`, and optionally `pos` (focal point).
3. Run the build. It needs Pillow, plus ffmpeg for videos and video stills
   (on PATH, via `--ffmpeg`, or `pip install imageio-ffmpeg`):

```bash
python tools/build_media.py
```

It writes into `public/assets/media/` and `public/js/data/media.js`. Only missing or out-of-date
files are rebuilt. Add `--force` to rebuild everything. Editing the config marks every file as out
of date, so to rebuild just what you changed, name the keys:
`python tools/build_media.py --key hero --key hero-poster`.

A video variant with an `av1` block also gets an AV1 copy, about 20–30% smaller
than the H.264 file at the same quality. Browsers that decode AV1 smoothly get it;
the rest get H.264. AV1 needs an ffmpeg with `libaom-av1` and is slow to encode
(about 8 minutes for the 16-second hero).

The hero's bitrates are set for a VMAF of about 90 against the original (good,
with no visible blockiness). Lowering `crf`/`maxrate` makes smaller files but
softer, blockier footage: this drone video is dense foliage, which needs bitrate.

4. To use the new key, reference it in `content.js` (stays, gallery and so on)
   or in `views/index.html` with `data-img="key"`.

## Content to fill in

- **Guest reviews:** `reviews` in `public/js/data/content.js` is empty on purpose. The
  section stays hidden until real, verified reviews are added there.
- **Social links:** `contact.socials` is empty. The footer's "Follow" column
  appears once a link is added.
- **WhatsApp:** the hotline number, as in the resort's other site build. Change it in
  Admin → Website if that number isn't on WhatsApp.
- **Possible stock photos:** `campfire` and `bbq` (from the old site's
  `campfire.jpg` and `family-meal.jpg`) look like stock images. If they aren't
  the resort's own, swap their `src` in the media config and rebuild.

## Security notes

- Admin passwords are hashed with bcrypt; sessions are HMAC-signed, HttpOnly, SameSite=Strict
  cookies. Sign-in and booking forms are rate-limited, and the booking form has a spam honeypot.
- State-changing API calls must be JSON from the same origin (CSRF protection).
- A strict Content-Security-Policy is in place (scripts: this site and cdnjs for GSAP).
- `.htaccess` keeps `app/`, `views/`, `data/` and `.env` private, even if URL rewriting is
  unavailable.
