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
- **Admin panel** (`/admin/`): pending requests (Confirm / Decline), calendar, bookings list with
  CSV export, manual bookings (phone, WhatsApp, walk-in, Booking.com, Agoda…), closed days,
  rooms, booking setup (per-person options, capacity, advance-payment details), coupons, bills,
  finance, staff logins, the contact details shown on the website, WhatsApp alerts and printing.
- **POS** (`/pos`): check guests in, tap items onto their bill, take payments, check out and
  issue numbered bills (`INV-00001`). Works on a tablet or a phone.
- **Email**: the admin is emailed for each website booking; guests get "booking received",
  confirmation and cancellation emails. Without SMTP settings they are saved in `data/outbox/`.

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
- `php tests/bookings_test.php` runs the booking and POS rule tests on a throwaway SQLite file
  (never the real database). The tests use their own fixed sample rooms (`tests/seed.php`).
- `php public/check.php` runs the hosting check from the command line.

The tables, the 20 rooms and the settings are created the first time the site opens.

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

- **Rooms** (Admin → Rooms): the 20 room names and photos come from the website, but each room's
  **type, price per night and how many it sleeps are samples** (Room, Rs 8,000, sleeps 2). The
  website doesn't say which room is which kind of stay. Set the real values. Give rooms the
  type names used on the website (e.g. "Wooden Cabana") and the home page's "Accommodation" choice
  will count only those rooms.
- **Per-person options** (Admin → Booking setup): Full board, Half board, Bed & breakfast and Day
  out are seeded with sample prices and **hidden**. Show one once its real prices are in.
- **Extras**: the party area and JBL speaker start at price 0, which hides them on the booking
  page. Set a price to offer one.
- **Advance payment** (Admin → Booking setup): bank, account and % advance are empty until filled
  in, so guests only see the number to call.
- **Email**: the website lists no email address, so `RESORT_EMAIL` is empty and `MAIL_FROM` is a
  placeholder (`bookings@example.com`). Create the resort's mailbox and fill in the SMTP settings.
- **WhatsApp alerts** go to the hotline (94777368264) once a CallMeBot key is set up in
  Admin → Settings.
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
