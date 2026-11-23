# Putting Top Mountain Resort online (PHP + MySQL shared hosting)

This package is the complete website, booking system and admin panel. It runs on **PHP** with a
**MySQL / MariaDB** database, which every Hostinger, cPanel, DirectAdmin and Plesk plan includes.

You need three files from the `dist` folder:

| File | What it is |
| --- | --- |
| `topmountain-php-hosting.zip` | the website, which goes into `public_html` |
| `topmountain-database.sql` | only if you are moving an existing database (rooms, bookings, admin account), imported with phpMyAdmin. Keep it off the web server. A new site doesn't need it: the tables and the 20 rooms are created the first time the site opens. |
| `DEPLOY.md` | this guide |

> Time needed: about 20–30 minutes, all in your hosting control panel.

---

## 0. What your hosting needs

| Requirement | Hostinger | cPanel |
| --- | --- | --- |
| **PHP 8.1 or newer** (8.2–8.5 fine) | Websites → Dashboard → *PHP Configuration* | *Select PHP Version* / *MultiPHP Manager* |
| PHP extensions **pdo_mysql** (required), **gd**, **openssl**, **exif**, **mbstring** | PHP Configuration → *PHP extensions* | Select PHP Version → *Extensions* |
| One **MySQL database** | *Databases → Management* | *MySQL Database Wizard* |
| SSL (https) | Free SSL is installed automatically | *SSL/TLS Status* → AutoSSL |

The site must live at the **root** of a domain or subdomain (e.g. `https://www.yourdomain.lk`), not in
a sub-folder like `yourdomain.lk/site`.

---

## 1. Create the MySQL database

**Hostinger:** hPanel → **Databases → Management** → *Create a new MySQL database and user*.
**cPanel:** **MySQL Database Wizard** → create the database, then a user, then tick *All Privileges*.

Write down the **database name**, **username** and **password** exactly as shown. They usually
start with your account prefix, e.g. `u123456789_topmountain`.

## 2. Import your existing data (phpMyAdmin)

1. Next to the new database, click **Enter phpMyAdmin** (cPanel: *Databases → phpMyAdmin*, then
   click the database name on the left).
2. Open the **Import** tab → *Choose file* → select **`topmountain-database.sql`** → **Import** (or *Go*).
3. You should see "Import has been successfully finished". The database now has the tables
   `rooms`, `bookings`, `admins` and `rate_hits`, with your 9 rooms, all bookings and your admin
   account, exactly as they were.

> Starting completely fresh instead? Skip this step. The website creates the tables and the
> 9 sample rooms by itself the first time it opens.
>
> The import is safe. If the database already has bookings, it stops with a "Duplicate entry"
> error and changes nothing.

---

## 3. Create an email account for booking emails

**Hostinger:** **Emails → Email Accounts** → create e.g. `bookings@yourdomain.com`.
**cPanel:** **Email Accounts** → *Create*. Note the password.

| Setting | Hostinger | cPanel |
| --- | --- | --- |
| `SMTP_HOST` | `smtp.hostinger.com` | `mail.yourdomain.com` |
| `SMTP_PORT` / `SMTP_SECURE` | `465` / `true` | `465` / `true` |

---

## 4. Upload the website

1. Open **File Manager** → **`public_html`**. Delete the host's placeholder (`default.php` on
   Hostinger) or any old site after backing it up.
2. **Upload** `topmountain-php-hosting.zip` → right-click → **Extract** into `public_html` itself.
   If it lands in a sub-folder, move everything inside it up into `public_html`.
3. Check that `index.php`, `.htaccess`, `.env`, `app`, `css`, `js`, `media`, `views` and `data` are
   there (hidden files may need *Show hidden files*). Delete the zip.

**Never upload `topmountain-database.sql` to `public_html`.** Import it with phpMyAdmin (step 2) and
keep it safe on your computer.

## 5. Fill in the settings file `.env`

In File Manager, right-click **`.env`** → **Edit**, and change the lines marked `CHANGE`.
If this package was built with your database login (`hosting/production.env`), the `DB_` lines
are already filled in, so just check they match step 1.

```
BASE_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=u123456789_topmountain        from step 1
DB_USERNAME=u123456789_topmountain        from step 1
DB_PASSWORD=the-database-password      from step 1

ADMIN_NOTIFY_EMAILS=owner@gmail.com    who gets new-booking emails (change or add more later in Admin → Settings)
SMTP_HOST=smtp.hostinger.com           see the table in step 3
SMTP_PORT=465
SMTP_SECURE=true
SMTP_USER=bookings@yourdomain.com
SMTP_PASS=the-mailbox-password
MAIL_FROM="Top Mountain Resort <bookings@yourdomain.com>"   same address as SMTP_USER

RESORT_PHONE / RESORT_WHATSAPP / RESORT_EMAIL                   the resort's real details
```

Save. Changes take effect immediately.

> `.env` contains passwords, so don't share it or the zip.

## 6. Run the hosting check

Open **`https://yourdomain.com/check.php`**. It tests PHP, the extensions, the **MySQL
connection** (with a plain-English hint if a name or password is wrong), your settings, the email
login, and that private files can't be downloaded. Fix anything red, then reload.

## 7. Sign in and go live

- `https://yourdomain.com/admin/`, username `admin`:
  - **If you imported your data (step 2):** use the admin password you already had. It came
    across with the database.
  - **If you started fresh:** use the `ADMIN_PASSWORD` shown in `.env`.
- In **Settings → Send test**, check that emails arrive, and change the password if needed.
- In **Staff logins**, give each member of staff their own login and name (the name prints on
  their bills and receipts). A **Front desk (POS only)** login opens only the POS screen.
- **POS computer:** to print bills and receipts without the print window, follow
  `hosting/POS-PRINTING.md` (Chrome kiosk printing; download the shortcut in Admin → Settings → Printing).
- In **Booking setup**, fill in the advance-payment details (bank, account number, % advance) and
  check the per-person prices, the hotel capacity and the room priority list.
- **Email:** in hPanel → Emails, create the mailbox the site sends from (e.g. `bookings@yourdomain.lk`).
  Then in File Manager edit `.env`: put the address after `SMTP_USER=` and in `MAIL_FROM=`, and its
  password after `SMTP_PASS=`. In **Settings → Booking notifications**, add every address that
  should hear about new bookings, press **Save emails**, then **Test** next to an address.
- **WhatsApp alerts:** in **Settings → WhatsApp alerts**, add every phone that should get booking
  alerts (the resort's 077 736 8264 is there to start with). Each phone sends one WhatsApp message to
  CallMeBot and gets its own key: paste it next to that number, press Save, then Test. Tick whether
  alerts go out when a guest books on the website, when a booking is confirmed, or both.
- **Rooms:** set each room's real type, price per night and how many it sleeps in **Admin → Rooms**
  (the 20 rooms start with sample values: Rs 8,000 a night, sleeps 2).
- **Website:** check the phone, WhatsApp, address and map pin in **Admin → Website**.
- Make one test booking on the website and see it appear in the admin panel.
- Turn on **Force HTTPS** (Hostinger: SSL section; cPanel: *Domains → Force HTTPS Redirect*).

---

## Day-to-day

- **Rooms, prices, photos:** Admin → Rooms. **Per-person options, capacity, advance details:**
  Admin → Booking setup.
- **POS:** `https://yourdomain.com/pos` on the front-desk tablet or phone (sign in with an admin
  account). Its menu is edited in Admin → POS menu.
- **Contact details / email settings:** edit `.env` in File Manager.
- **Home page photos and videos:** Admin → Photos & videos. Pick any photo from the library or upload
  one; videos are MP4 (H.264) and upload in 4 MB pieces, so the host's upload limit doesn't cap them.
- **Updating the site files later:** upload the changed files over the old ones. The bookings
  live in MySQL, so they are not affected, and the zip never contains `media/manifest.json`,
  `media/upload-*` or `media/video/`, so the photos and videos uploaded in the admin panel stay too.

## Search engines (Google, Bing) and AI assistants

The site already gives search engines and AI assistants (ChatGPT, Copilot, Gemini, Claude,
Perplexity) what they need: the home page's text, rooms, places nearby and contact details are in
the page itself (not only drawn by JavaScript), with structured data for the resort, its questions
and answers, `/sitemap.xml` (with the photos), `/robots.txt` and `/llms.txt`. Once the site is on
its own domain:

1. **Use the resort's own domain.** Set `BASE_URL=https://yourdomain.com` (every canonical link,
   the sitemap and the structured data use it). When it works, set `CANONICAL_REDIRECT=true` so
   the hosting's temporary address and `www.` (or the bare domain) move to it for good, and force
   HTTPS (cPanel → Domains → Force HTTPS Redirect, or the lines in `.htaccess`).
2. **Pin the map.** In **Admin → Website**, set the map location (latitude, longitude, from Google
   Maps: right-click the resort's pin) and the plus code. It goes into the structured data, which
   is what local search and "near me" answers use.
3. **Google Search Console** (search.google.com/search-console): *Add property → URL prefix →
   HTML tag*. Put the `content="…"` value in `GOOGLE_SITE_VERIFICATION`, upload `.env`, press
   *Verify*. Then *Sitemaps → add* `sitemap.xml`.
4. **Bing Webmaster Tools** (bing.com/webmasters): you can *import from Google Search Console*, or
   add the site with the *HTML Meta Tag* option and put its `content="…"` value in
   `BING_SITE_VERIFICATION`. Submit `sitemap.xml` there too. Bing's index also feeds ChatGPT
   search and Copilot.
5. **IndexNow** (Bing re-reads the site within minutes): put a key in `INDEXNOW_KEY` (any 8 to
   128 letters, digits or dashes, e.g. from `php -r "echo bin2hex(random_bytes(16));"`), upload
   `.env`, and after each change to the website run `php scripts/indexnow.php` (from Hostinger /
   cPanel → Terminal, or `--dry-run` to see what it sends).
6. **Google Business Profile** (business.google.com): claim or create the resort's listing with
   exactly the same name, address and phone number as the website, and link it to the website.
   Do the same in Bing Places (it can import from Google). Matching details are a strong local
   signal.
7. **Check it.** Test the home page in Google's Rich Results Test (search.google.com/test/rich-results)
   and the Schema Markup Validator (validator.schema.org).

Real guest reviews (copied word for word with permission) go in `content.js` → `reviews`; the
reviews section appears by itself once there is one. Never add reviews or ratings that aren't real.

## Backups (important)

All bookings are in the MySQL database. Back it up regularly with **phpMyAdmin → select the
database → Export → Go** (keep the `.sql` file safe), or use your host's backup tool (Hostinger:
*Files → Backups*, which includes databases). Photos uploaded in the admin panel are in `media/`
(files starting with `upload-`, listed in `media/manifest.json`) and videos in `media/video/`: back
these up too.

## Troubleshooting

| What you see | What to do |
| --- | --- |
| "Back in a moment" page / `check.php` shows a MySQL error | Follow the hint shown: wrong `DB_USERNAME`/`DB_PASSWORD`, wrong `DB_DATABASE` name, or (cPanel) the user isn't added to the database with All Privileges. `DB_HOST` is almost always `localhost`. |
| phpMyAdmin: "Duplicate entry" | The database already contains bookings, so nothing was changed. Import into a new empty database instead. |
| *500 Internal Server Error* (blank page, even on `/check.php`) | Something in `.htaccess` is refused by the host, usually `php_value` lines (this package has none). Remove any `php_value` / `php_flag` lines from `.htaccess` and set upload limits in the PHP options instead. Also check the PHP version is 8.1 or newer. |
| Home page works but `/book` or `/admin/` shows 404 | `.htaccess` is missing. Re-upload it (it is a hidden file). |
| No emails arrive | `/check.php` tests the email login. Try `SMTP_PORT=587` with `SMTP_SECURE=false`. While `SMTP_HOST` is empty, emails are saved in `data/outbox/`. |
| Photo upload fails / "too large" | PHP options: `upload_max_filesize` and `post_max_size` = 20M, `memory_limit` = 256M. |
| Video upload fails / "No video arrived" | The PHP option `post_max_size` must be at least 8M (each piece is 4 MB). The `data/` folder must be writable (pieces wait in `data/uploads/`) and so must `media/`. |
| Admin login keeps logging out | Use **https://** (`APP_ENV=production` requires it). |
| You turned on a CDN (Hostinger CDN, Cloudflare) | Set `TRUST_PROXY=true` in `.env`. |
