<?php
// Database: MySQL / MariaDB (DB_CONNECTION=mysql) or SQLite (data/topmountain.db).
// Tables: rooms, bookings, admins, rate-limit counters, settings (day-out / extras
// prices) and the POS (pos_items, pos_charges, pos_payments, bills, pos_log). They are
// created automatically when missing, and older databases gain the newer columns;
// existing data is never removed.
declare(strict_types=1);

namespace Ydr;

use PDO;

final class DbUnavailable extends \RuntimeException {}

final class Db
{
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';

    public static function driver(): string
    {
        self::pdo();
        return self::$driver;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $cfg = Config::get();
        $db = $cfg['db'];
        if ($db['connection'] === 'mysql') {
            if (!extension_loaded('pdo_mysql')) throw new DbUnavailable('The PHP extension "pdo_mysql" is not enabled.');
            if ($db['database'] === '' || $db['username'] === '') throw new DbUnavailable('DB_DATABASE / DB_USERNAME are empty in .env.');
            $dsn = 'mysql:host=' . $db['host'] . ';port=' . $db['port'] . ';dbname=' . $db['database'] . ';charset=utf8mb4';
            try {
                $pdo = new PDO($dsn, $db['username'], $db['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                    PDO::ATTR_TIMEOUT => 5,
                ]);
            } catch (\PDOException $e) {
                throw new DbUnavailable(self::explainMysqlError($e), 0, $e);
            }
            // predictable behaviour on every host: strict values, UTC, read-committed reads
            $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $pdo->exec("SET time_zone = '+00:00'");
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            self::$driver = 'mysql';
        } else {
            if (!extension_loaded('pdo_sqlite')) throw new DbUnavailable('The PHP extension "pdo_sqlite" is not enabled.');
            $pdo = new PDO('sqlite:' . $cfg['dataDir'] . '/topmountain.db', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            try { $pdo->exec('PRAGMA journal_mode = WAL'); } catch (\Throwable $e) { /* some network filesystems refuse WAL — fine */ }
            $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
            self::$driver = 'sqlite';
        }
        self::$pdo = $pdo;
        self::migrate();
        return $pdo;
    }

    /** A helpful, non-secret explanation of a MySQL connection failure. */
    public static function explainMysqlError(\PDOException $e): string
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        if (!$code && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) $code = (int) $m[1];
        return match ($code) {
            1045 => 'MySQL refused the login — check DB_USERNAME and DB_PASSWORD in .env.',
            1044 => 'This MySQL user has no access to the database — in your hosting panel, add the user to the database with all privileges.',
            1049 => 'The MySQL database named in DB_DATABASE does not exist — check the exact name (on shared hosting it usually starts with your account prefix, e.g. u123456789_topmountain).',
            2002, 2003, 2005, 2006 => 'Cannot reach the MySQL server — check DB_HOST (on shared hosting it is usually "localhost") and DB_PORT.',
            default => 'Could not connect to MySQL (error ' . ($code ?: 'unknown') . ') — check the DB_ settings in .env.',
        };
    }

    // ---------- schema ----------
    /** The MySQL / MariaDB tables (also written at the top of the exported .sql file). */
    public static function mysqlSchema(): string
    {
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS rooms (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
  name          VARCHAR(255) NOT NULL,
  type          VARCHAR(255) NOT NULL,
  tagline       VARCHAR(255) NOT NULL DEFAULT '',
  description   TEXT NOT NULL,
  price         INT NOT NULL COMMENT 'per room per night, whole currency units',
  max_adults    INT NOT NULL DEFAULT 2,
  max_children  INT NOT NULL DEFAULT 0,
  capacity      INT NOT NULL DEFAULT 2 COMMENT 'guests the room sleeps',
  beds          VARCHAR(255) NOT NULL DEFAULT '',
  size_sqm      INT NOT NULL DEFAULT 0,
  features      TEXT NOT NULL COMMENT 'JSON array',
  images        TEXT NOT NULL COMMENT 'JSON array of media keys',
  active        TINYINT(1) NOT NULL DEFAULT 1,
  sort          INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rooms_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ref              VARCHAR(20) COLLATE utf8mb4_bin NOT NULL,
  room_id          INT UNSIGNED NULL COMMENT 'the room of a room booking (per-person groups use booking_rooms)',
  package          VARCHAR(20) COLLATE utf8mb4_bin NOT NULL DEFAULT 'room' COMMENT 'room|per_person|day_out',
  package_id       INT UNSIGNED NULL COMMENT 'per-person option (packages.id)',
  package_name     VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'option name when booked',
  check_in         DATE NOT NULL COMMENT 'arrival (the day itself for a day out)',
  check_out        DATE NOT NULL COMMENT 'departure, exclusive (the next day for a day out)',
  nights           INT NOT NULL COMMENT '0 for a day out',
  adults           INT NOT NULL DEFAULT 1,
  children         INT NOT NULL DEFAULT 0,
  party_area       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'party area reserved for every night of the stay',
  jbl_speaker      TINYINT(1) NOT NULL DEFAULT 0,
  guest_name       VARCHAR(255) NOT NULL,
  guest_email      VARCHAR(255) NOT NULL DEFAULT '',
  guest_phone      VARCHAR(64) NOT NULL DEFAULT '',
  guest_id_no      VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'NIC or passport number',
  country          VARCHAR(100) NOT NULL DEFAULT '',
  arrival_time     VARCHAR(64) NOT NULL DEFAULT '',
  special_requests TEXT NOT NULL,
  total_amount     INT NOT NULL DEFAULT 0 COMMENT 'the stay price after any coupon (bills add items and the service charge)',
  rate_room        INT NULL COMMENT 'agreed room rate per night (NULL = the room price)',
  rate_adult       INT NULL COMMENT 'agreed per-person rate for an adult (NULL = the option price)',
  rate_child       INT NULL COMMENT 'agreed per-person rate for a child (NULL = the option price)',
  coupon_code      VARCHAR(40) COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'coupon used when booking',
  coupon_discount  INT NOT NULL DEFAULT 0 COMMENT 'taken off the stay price (already out of total_amount)',
  commission_type  VARCHAR(10) COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'percent|amount, empty = none',
  commission_value INT NOT NULL DEFAULT 0,
  commission_to    VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'the agent or website that takes the commission',
  hold_until       DATE NULL COMMENT 'a pending reservation is released after this day if no advance is paid',
  event_slot       VARCHAR(10) COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'an outdoor party: day|night|full',
  event_others     VARCHAR(10) COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'during a party, other guests: open|rooms (no other stays)|private',
  status           VARCHAR(20) COLLATE utf8mb4_bin NOT NULL DEFAULT 'pending' COMMENT 'pending|confirmed|checked_in|checked_out|cancelled',
  bill_id          INT UNSIGNED NULL COMMENT 'set once the stay is billed (bills.id)',
  source           VARCHAR(20) COLLATE utf8mb4_bin NOT NULL DEFAULT 'website' COMMENT 'website|phone|whatsapp|walk_in|booking_com|agoda|airbnb|expedia|travel_agent|other|block',
  admin_notes      TEXT NOT NULL,
  created_by       VARCHAR(255) NOT NULL DEFAULT 'guest',
  created_at       DATETIME(3) NOT NULL COMMENT 'UTC',
  updated_at       DATETIME(3) NOT NULL COMMENT 'UTC',
  PRIMARY KEY (id),
  UNIQUE KEY uq_bookings_ref (ref),
  KEY idx_bookings_room_dates (room_id, check_in, check_out),
  KEY idx_bookings_status (status),
  KEY idx_bookings_bill (bill_id),
  KEY idx_bookings_hold (status, hold_until),
  KEY idx_bookings_coupon (coupon_code),
  CONSTRAINT fk_bookings_room FOREIGN KEY (room_id) REFERENCES rooms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
  password_hash VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
  name          VARCHAR(80) NOT NULL DEFAULT '' COMMENT 'shown on bills and receipts (empty = the username)',
  role          VARCHAR(20) COLLATE utf8mb4_bin NOT NULL DEFAULT 'admin' COMMENT 'admin|manager|pos',
  active        TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = cannot sign in',
  last_login    DATETIME(3) NULL COMMENT 'UTC',
  created_at    DATETIME(3) NOT NULL COMMENT 'UTC',
  PRIMARY KEY (id),
  UNIQUE KEY uq_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_hits (
  id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket VARCHAR(32) COLLATE utf8mb4_bin NOT NULL,
  ip     VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
  ts     DOUBLE NOT NULL COMMENT 'milliseconds since 1970',
  PRIMARY KEY (id),
  KEY idx_rate_hits (bucket, ip, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  name  VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
  value TEXT NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packages (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  kind        VARCHAR(10) COLLATE utf8mb4_bin NOT NULL DEFAULT 'stay' COMMENT 'stay (per person per night) | day (a day visit, per person)',
  price_adult INT NOT NULL DEFAULT 0,
  price_child INT NOT NULL DEFAULT 0,
  active      TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'offered on the website',
  sort        INT NOT NULL DEFAULT 0,
  created_at  DATETIME(3) NOT NULL COMMENT 'UTC',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(60) NOT NULL,
  sort       INT NOT NULL DEFAULT 0,
  active     TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'shown on the POS',
  created_at DATETIME(3) NOT NULL COMMENT 'UTC',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_items (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(120) NOT NULL,
  category_id INT UNSIGNED NULL COMMENT 'pos_categories.id',
  category   VARCHAR(20) COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'unused since categories became editable',
  price      INT NOT NULL DEFAULT 0 COMMENT 'whole currency units',
  active     TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'shown on the POS menu',
  sort       INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL COMMENT 'UTC',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_charges (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id  INT UNSIGNED NOT NULL COMMENT 'the room bill the line is on',
  item_id     INT UNSIGNED NULL COMMENT 'menu item, NULL for a custom line',
  name        VARCHAR(160) NOT NULL,
  category    VARCHAR(80) NOT NULL COMMENT 'POS category name when charged',
  unit_price  INT NOT NULL,
  qty         INT NOT NULL,
  amount      INT NOT NULL COMMENT 'unit_price x qty',
  note        VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME(3) NOT NULL COMMENT 'UTC',
  created_by  VARCHAR(255) NOT NULL DEFAULT '',
  moved_from  INT UNSIGNED NULL COMMENT 'booking the line was last moved from',
  moved_at    DATETIME(3) NULL,
  moved_by    VARCHAR(255) NULL,
  voided_at   DATETIME(3) NULL COMMENT 'removed from the bill (kept for the history)',
  voided_by   VARCHAR(255) NULL,
  void_reason VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_pos_charges_booking (booking_id),
  CONSTRAINT fk_pos_charges_booking FOREIGN KEY (booking_id) REFERENCES bookings (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_payments (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT UNSIGNED NOT NULL,
  bill_id    INT UNSIGNED NULL COMMENT 'NULL while it is an advance on an open room bill',
  amount     INT NOT NULL,
  method     VARCHAR(20) COLLATE utf8mb4_bin NOT NULL COMMENT 'cash|card|bank|online|other',
  note       VARCHAR(255) NOT NULL DEFAULT '',
  fee        INT NOT NULL DEFAULT 0 COMMENT 'commission or charges taken off before the money reached the resort',
  details    VARCHAR(2000) NOT NULL DEFAULT '' COMMENT 'JSON: the extra payment fields (account received into, reference…)',
  created_at DATETIME(3) NOT NULL COMMENT 'UTC',
  created_by VARCHAR(255) NOT NULL DEFAULT '',
  voided_at  DATETIME(3) NULL,
  voided_by  VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_pos_payments_booking (booking_id),
  KEY idx_pos_payments_bill (bill_id),
  CONSTRAINT fk_pos_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bills (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  bill_no     VARCHAR(20) COLLATE utf8mb4_bin NULL,
  status      VARCHAR(10) COLLATE utf8mb4_bin NOT NULL DEFAULT 'issued' COMMENT 'issued|void',
  booking_id  INT UNSIGNED NOT NULL COMMENT 'main booking, the guest the bill is made out to',
  guest_name  VARCHAR(255) NOT NULL,
  stay_total  INT NOT NULL DEFAULT 0,
  items_total INT NOT NULL DEFAULT 0,
  discount    INT NOT NULL DEFAULT 0,
  coupon_code VARCHAR(40) COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'coupon used at checkout',
  coupon_discount INT NOT NULL DEFAULT 0 COMMENT 'taken off the stays by that coupon',
  total       INT NOT NULL DEFAULT 0,
  snapshot    MEDIUMTEXT NOT NULL COMMENT 'JSON: the bill exactly as issued',
  note        TEXT NOT NULL,
  created_at  DATETIME(3) NOT NULL COMMENT 'UTC',
  created_by  VARCHAR(255) NOT NULL DEFAULT '',
  voided_at   DATETIME(3) NULL,
  voided_by   VARCHAR(255) NULL,
  void_reason VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_bills_no (bill_no),
  KEY idx_bills_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT UNSIGNED NOT NULL,
  action     VARCHAR(20) COLLATE utf8mb4_bin NOT NULL COMMENT 'add|void|transfer_in|transfer_out|payment|payment_void|checkin|rooms|bill|bill_void',
  detail     VARCHAR(500) NOT NULL DEFAULT '',
  amount     INT NULL,
  created_at DATETIME(3) NOT NULL COMMENT 'UTC',
  created_by VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_pos_log_booking (booking_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_rooms (
  booking_id INT UNSIGNED NOT NULL COMMENT 'a per-person booking',
  room_id    INT UNSIGNED NOT NULL COMMENT 'a room given to that group',
  PRIMARY KEY (booking_id, room_id),
  KEY idx_booking_rooms_room (room_id),
  CONSTRAINT fk_booking_rooms_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
  CONSTRAINT fk_booking_rooms_room FOREIGN KEY (room_id) REFERENCES rooms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupons (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(40) COLLATE utf8mb4_bin NOT NULL COMMENT 'upper case',
  type       VARCHAR(10) COLLATE utf8mb4_bin NOT NULL DEFAULT 'percent' COMMENT 'percent|amount, off the room charge only',
  value      INT NOT NULL,
  max_uses   INT NOT NULL DEFAULT 1 COMMENT '0 = no limit',
  valid_from DATE NULL,
  valid_to   DATE NULL,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  batch      VARCHAR(60) NOT NULL DEFAULT '' COMMENT 'the batch it was generated in',
  note       VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME(3) NOT NULL COMMENT 'UTC',
  created_by VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_coupons_code (code),
  KEY idx_coupons_batch (batch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance (version 7): staff and their pay, service charge shares, expenses, money into the bank, stock
CREATE TABLE IF NOT EXISTS employees (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120) NOT NULL,
  position      VARCHAR(80) NOT NULL DEFAULT '' COMMENT 'e.g. waiter, cook, room boy',
  phone         VARCHAR(40) NOT NULL DEFAULT '',
  id_no         VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'NIC',
  joined_on     DATE NULL,
  salary        INT NOT NULL DEFAULT 0 COMMENT 'monthly',
  service_share TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = can get a share of the service charge',
  active        TINYINT(1) NOT NULL DEFAULT 1,
  note          VARCHAR(255) NOT NULL DEFAULT '',
  created_at    DATETIME(3) NOT NULL COMMENT 'UTC',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_payments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT UNSIGNED NOT NULL,
  kind        VARCHAR(10) COLLATE utf8mb4_bin NOT NULL COMMENT 'salary|service|advance|bonus',
  period      CHAR(7) NOT NULL DEFAULT '' COMMENT 'YYYY-MM: the month a salary is for',
  amount      INT NOT NULL,
  paid_on     DATE NOT NULL,
  account     VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'paid from',
  note        VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME(3) NOT NULL COMMENT 'UTC',
  created_by  VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_staff_payments_employee (employee_id),
  KEY idx_staff_payments_paid (paid_on),
  CONSTRAINT fk_staff_payments_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_shares (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  bill_id     INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  amount      INT NOT NULL COMMENT 'their part of the bill service charge',
  created_at  DATETIME(3) NOT NULL COMMENT 'UTC',
  created_by  VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_service_shares (bill_id, employee_id),
  KEY idx_service_shares_employee (employee_id),
  CONSTRAINT fk_service_shares_bill FOREIGN KEY (bill_id) REFERENCES bills (id),
  CONSTRAINT fk_service_shares_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  spent_on    DATE NOT NULL,
  category    VARCHAR(60) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  payee       VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'shop, supplier or agent paid',
  amount      INT NOT NULL,
  account     VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'paid from',
  note        VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME(3) NOT NULL COMMENT 'UTC',
  created_by  VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_expenses_spent (spent_on),
  KEY idx_expenses_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_moves (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  moved_on     DATE NOT NULL,
  account      VARCHAR(120) NOT NULL COMMENT 'the bank account the money went into',
  from_account VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'where it came from, e.g. cash in hand',
  amount       INT NOT NULL,
  reference    VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'slip or transfer number',
  note         VARCHAR(255) NOT NULL DEFAULT '',
  created_at   DATETIME(3) NOT NULL COMMENT 'UTC',
  created_by   VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_bank_moves_moved (moved_on),
  KEY idx_bank_moves_account (account)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120) NOT NULL,
  category      VARCHAR(60) NOT NULL DEFAULT '',
  unit          VARCHAR(20) NOT NULL DEFAULT 'pcs' COMMENT 'kg, bottles, pcs',
  reorder_level DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'warn when the stock falls to this',
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME(3) NOT NULL COMMENT 'UTC',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_moves (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id    INT UNSIGNED NOT NULL,
  moved_on   DATE NOT NULL,
  qty        DECIMAL(12,2) NOT NULL COMMENT 'more than 0 = in (bought), less than 0 = out (used)',
  unit_cost  INT NOT NULL DEFAULT 0 COMMENT 'price paid for one, for stock in',
  note       VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME(3) NOT NULL COMMENT 'UTC',
  created_by VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_stock_moves_item (item_id),
  KEY idx_stock_moves_moved (moved_on),
  CONSTRAINT fk_stock_moves_item FOREIGN KEY (item_id) REFERENCES stock_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- days the whole resort is closed (version 8)
CREATE TABLE IF NOT EXISTS closures (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  starts_on  DATE NOT NULL COMMENT 'first closed day',
  ends_on    DATE NOT NULL COMMENT 'last closed day (included)',
  reason     VARCHAR(20) COLLATE utf8mb4_bin NOT NULL DEFAULT 'closed' COMMENT 'private|repairs|closed',
  note       VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'for staff only',
  created_at DATETIME(3) NOT NULL COMMENT 'UTC',
  created_by VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_closures_dates (starts_on, ends_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    private static function sqliteSchema(): string
    {
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS rooms (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  slug          TEXT NOT NULL UNIQUE,
  name          TEXT NOT NULL,
  type          TEXT NOT NULL,
  tagline       TEXT NOT NULL DEFAULT '',
  description   TEXT NOT NULL DEFAULT '',
  price         INTEGER NOT NULL,           -- per room per night, whole currency units
  max_adults    INTEGER NOT NULL DEFAULT 2,
  max_children  INTEGER NOT NULL DEFAULT 0,
  capacity      INTEGER NOT NULL DEFAULT 2, -- guests the room sleeps
  beds          TEXT NOT NULL DEFAULT '',
  size_sqm      INTEGER NOT NULL DEFAULT 0,
  features      TEXT NOT NULL DEFAULT '[]', -- JSON array
  images        TEXT NOT NULL DEFAULT '[]', -- JSON array of media keys
  active        INTEGER NOT NULL DEFAULT 1,
  sort          INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS bookings (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  ref              TEXT NOT NULL UNIQUE,
  room_id          INTEGER REFERENCES rooms(id),  -- the room of a room booking (per-person groups use booking_rooms)
  package          TEXT NOT NULL DEFAULT 'room',  -- room|per_person|day_out
  package_id       INTEGER,                       -- per-person option (packages.id)
  package_name     TEXT NOT NULL DEFAULT '',      -- option name when booked
  check_in         TEXT NOT NULL,           -- YYYY-MM-DD (arrival; the day itself for a day out)
  check_out        TEXT NOT NULL,           -- YYYY-MM-DD (departure, exclusive; the next day for a day out)
  nights           INTEGER NOT NULL,        -- 0 for a day out
  adults           INTEGER NOT NULL DEFAULT 1,
  children         INTEGER NOT NULL DEFAULT 0,
  party_area       INTEGER NOT NULL DEFAULT 0, -- party area reserved for every night of the stay
  jbl_speaker      INTEGER NOT NULL DEFAULT 0,
  guest_name       TEXT NOT NULL,
  guest_email      TEXT NOT NULL DEFAULT '',
  guest_phone      TEXT NOT NULL DEFAULT '',
  guest_id_no      TEXT NOT NULL DEFAULT '',            -- NIC or passport number
  country          TEXT NOT NULL DEFAULT '',
  arrival_time     TEXT NOT NULL DEFAULT '',
  special_requests TEXT NOT NULL DEFAULT '',
  total_amount     INTEGER NOT NULL DEFAULT 0,       -- the stay price after any coupon
  rate_room        INTEGER,                          -- agreed room rate per night (NULL = the room price)
  rate_adult       INTEGER,                          -- agreed per-person rates (NULL = the option price)
  rate_child       INTEGER,
  coupon_code      TEXT NOT NULL DEFAULT '',         -- coupon used when booking
  coupon_discount  INTEGER NOT NULL DEFAULT 0,       -- taken off the stay price (already out of total_amount)
  commission_type  TEXT NOT NULL DEFAULT '',         -- percent|amount, '' = none
  commission_value INTEGER NOT NULL DEFAULT 0,
  commission_to    TEXT NOT NULL DEFAULT '',         -- the agent or website that takes it
  hold_until       TEXT,                             -- YYYY-MM-DD: a pending reservation is released after this day
  event_slot       TEXT NOT NULL DEFAULT '',          -- an outdoor party: day|night|full
  event_others     TEXT NOT NULL DEFAULT '',          -- during a party, other guests: open|rooms (no other stays)|private
  status           TEXT NOT NULL DEFAULT 'pending',  -- pending|confirmed|checked_in|checked_out|cancelled
  bill_id          INTEGER,                          -- set once the stay is billed (bills.id)
  source           TEXT NOT NULL DEFAULT 'website',  -- website|phone|whatsapp|walk_in|booking_com|agoda|airbnb|expedia|travel_agent|other|block
  admin_notes      TEXT NOT NULL DEFAULT '',
  created_by       TEXT NOT NULL DEFAULT 'guest',
  created_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  updated_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);
CREATE INDEX IF NOT EXISTS idx_bookings_room_dates ON bookings(room_id, check_in, check_out);
CREATE INDEX IF NOT EXISTS idx_bookings_status ON bookings(status);
CREATE INDEX IF NOT EXISTS idx_bookings_hold ON bookings(status, hold_until);
CREATE INDEX IF NOT EXISTS idx_bookings_coupon ON bookings(coupon_code);

CREATE TABLE IF NOT EXISTS admins (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  name          TEXT NOT NULL DEFAULT '',          -- shown on bills and receipts (empty = the username)
  role          TEXT NOT NULL DEFAULT 'admin',     -- admin|manager|pos
  active        INTEGER NOT NULL DEFAULT 1,        -- 0 = cannot sign in
  last_login    TEXT,
  created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

CREATE TABLE IF NOT EXISTS rate_hits (
  bucket TEXT NOT NULL,
  ip     TEXT NOT NULL,
  ts     REAL NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_rate_hits ON rate_hits(bucket, ip, ts);

CREATE TABLE IF NOT EXISTS settings (
  name  TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_bookings_bill ON bookings(bill_id);

CREATE TABLE IF NOT EXISTS packages (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  kind        TEXT NOT NULL DEFAULT 'stay',   -- stay (per person per night) | day (a day visit, per person)
  price_adult INTEGER NOT NULL DEFAULT 0,
  price_child INTEGER NOT NULL DEFAULT 0,
  active      INTEGER NOT NULL DEFAULT 1,     -- offered on the website
  sort        INTEGER NOT NULL DEFAULT 0,
  created_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pos_categories (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL,
  sort       INTEGER NOT NULL DEFAULT 0,
  active     INTEGER NOT NULL DEFAULT 1,      -- shown on the POS
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pos_items (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL,
  category_id INTEGER,                        -- pos_categories.id
  category   TEXT NOT NULL DEFAULT '',        -- unused since categories became editable
  price      INTEGER NOT NULL DEFAULT 0,
  active     INTEGER NOT NULL DEFAULT 1,      -- shown on the POS menu
  sort       INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pos_charges (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  booking_id  INTEGER NOT NULL REFERENCES bookings(id), -- the room bill the line is on
  item_id     INTEGER,                                   -- menu item, NULL for a custom line
  name        TEXT NOT NULL,
  category    TEXT NOT NULL,
  unit_price  INTEGER NOT NULL,
  qty         INTEGER NOT NULL,
  amount      INTEGER NOT NULL,
  note        TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL,
  created_by  TEXT NOT NULL DEFAULT '',
  moved_from  INTEGER,                                   -- booking the line was last moved from
  moved_at    TEXT,
  moved_by    TEXT,
  voided_at   TEXT,                                      -- removed from the bill (kept for the history)
  voided_by   TEXT,
  void_reason TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_pos_charges_booking ON pos_charges(booking_id);

CREATE TABLE IF NOT EXISTS pos_payments (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  booking_id INTEGER NOT NULL REFERENCES bookings(id),
  bill_id    INTEGER,                                    -- NULL while it is an advance on an open room bill
  amount     INTEGER NOT NULL,
  method     TEXT NOT NULL,                              -- cash|card|bank|online|other
  note       TEXT NOT NULL DEFAULT '',
  fee        INTEGER NOT NULL DEFAULT 0,                 -- commission or charges taken off before it reached the resort
  details    TEXT NOT NULL DEFAULT '',                   -- JSON: the extra payment fields
  created_at TEXT NOT NULL,
  created_by TEXT NOT NULL DEFAULT '',
  voided_at  TEXT,
  voided_by  TEXT
);
CREATE INDEX IF NOT EXISTS idx_pos_payments_booking ON pos_payments(booking_id);
CREATE INDEX IF NOT EXISTS idx_pos_payments_bill ON pos_payments(bill_id);

CREATE TABLE IF NOT EXISTS bills (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  bill_no     TEXT UNIQUE,
  status      TEXT NOT NULL DEFAULT 'issued',            -- issued|void
  booking_id  INTEGER NOT NULL,                          -- main booking, the guest the bill is made out to
  guest_name  TEXT NOT NULL,
  stay_total  INTEGER NOT NULL DEFAULT 0,
  items_total INTEGER NOT NULL DEFAULT 0,
  discount    INTEGER NOT NULL DEFAULT 0,
  coupon_code TEXT NOT NULL DEFAULT '',                  -- coupon used at checkout
  coupon_discount INTEGER NOT NULL DEFAULT 0,            -- taken off the stays by that coupon
  total       INTEGER NOT NULL DEFAULT 0,
  snapshot    TEXT NOT NULL,                             -- JSON: the bill exactly as issued
  note        TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL,
  created_by  TEXT NOT NULL DEFAULT '',
  voided_at   TEXT,
  voided_by   TEXT,
  void_reason TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_bills_created ON bills(created_at);

CREATE TABLE IF NOT EXISTS pos_log (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  booking_id INTEGER NOT NULL,
  action     TEXT NOT NULL,     -- add|void|transfer_in|transfer_out|payment|payment_void|checkin|rooms|bill|bill_void
  detail     TEXT NOT NULL DEFAULT '',
  amount     INTEGER,
  created_at TEXT NOT NULL,
  created_by TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_pos_log_booking ON pos_log(booking_id, id);

CREATE TABLE IF NOT EXISTS booking_rooms (
  booking_id INTEGER NOT NULL REFERENCES bookings(id), -- a per-person booking
  room_id    INTEGER NOT NULL REFERENCES rooms(id),    -- a room given to that group
  PRIMARY KEY (booking_id, room_id)
);
CREATE INDEX IF NOT EXISTS idx_booking_rooms_room ON booking_rooms(room_id);

CREATE TABLE IF NOT EXISTS coupons (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  code       TEXT NOT NULL UNIQUE,              -- upper case
  type       TEXT NOT NULL DEFAULT 'percent',   -- percent|amount, off the room charge only
  value      INTEGER NOT NULL,
  max_uses   INTEGER NOT NULL DEFAULT 1,        -- 0 = no limit
  valid_from TEXT,                              -- YYYY-MM-DD
  valid_to   TEXT,
  active     INTEGER NOT NULL DEFAULT 1,
  batch      TEXT NOT NULL DEFAULT '',          -- the batch it was generated in
  note       TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  created_by TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_coupons_batch ON coupons(batch);

-- finance (version 7): staff and their pay, service charge shares, expenses, money into the bank, stock
CREATE TABLE IF NOT EXISTS employees (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name          TEXT NOT NULL,
  position      TEXT NOT NULL DEFAULT '',
  phone         TEXT NOT NULL DEFAULT '',
  id_no         TEXT NOT NULL DEFAULT '',
  joined_on     TEXT,
  salary        INTEGER NOT NULL DEFAULT 0,      -- monthly
  service_share INTEGER NOT NULL DEFAULT 1,      -- 1 = can get a share of the service charge
  active        INTEGER NOT NULL DEFAULT 1,
  note          TEXT NOT NULL DEFAULT '',
  created_at    TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS staff_payments (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id INTEGER NOT NULL REFERENCES employees(id),
  kind        TEXT NOT NULL,                     -- salary|service|advance|bonus
  period      TEXT NOT NULL DEFAULT '',          -- YYYY-MM: the month a salary is for
  amount      INTEGER NOT NULL,
  paid_on     TEXT NOT NULL,
  account     TEXT NOT NULL DEFAULT '',
  note        TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL,
  created_by  TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_staff_payments_employee ON staff_payments(employee_id);
CREATE INDEX IF NOT EXISTS idx_staff_payments_paid ON staff_payments(paid_on);
CREATE TABLE IF NOT EXISTS service_shares (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  bill_id     INTEGER NOT NULL REFERENCES bills(id),
  employee_id INTEGER NOT NULL REFERENCES employees(id),
  amount      INTEGER NOT NULL,
  created_at  TEXT NOT NULL,
  created_by  TEXT NOT NULL DEFAULT '',
  UNIQUE (bill_id, employee_id)
);
CREATE INDEX IF NOT EXISTS idx_service_shares_employee ON service_shares(employee_id);
CREATE TABLE IF NOT EXISTS expenses (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  spent_on    TEXT NOT NULL,
  category    TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  payee       TEXT NOT NULL DEFAULT '',
  amount      INTEGER NOT NULL,
  account     TEXT NOT NULL DEFAULT '',
  note        TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL,
  created_by  TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_expenses_spent ON expenses(spent_on);
CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses(category);
CREATE TABLE IF NOT EXISTS bank_moves (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  moved_on     TEXT NOT NULL,
  account      TEXT NOT NULL,
  from_account TEXT NOT NULL DEFAULT '',
  amount       INTEGER NOT NULL,
  reference    TEXT NOT NULL DEFAULT '',
  note         TEXT NOT NULL DEFAULT '',
  created_at   TEXT NOT NULL,
  created_by   TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_bank_moves_moved ON bank_moves(moved_on);
CREATE INDEX IF NOT EXISTS idx_bank_moves_account ON bank_moves(account);
CREATE TABLE IF NOT EXISTS stock_items (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name          TEXT NOT NULL,
  category      TEXT NOT NULL DEFAULT '',
  unit          TEXT NOT NULL DEFAULT 'pcs',
  reorder_level REAL NOT NULL DEFAULT 0,
  active        INTEGER NOT NULL DEFAULT 1,
  created_at    TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS stock_moves (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  item_id    INTEGER NOT NULL REFERENCES stock_items(id),
  moved_on   TEXT NOT NULL,
  qty        REAL NOT NULL,                      -- more than 0 = in (bought), less than 0 = out (used)
  unit_cost  INTEGER NOT NULL DEFAULT 0,
  note       TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  created_by TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_stock_moves_item ON stock_moves(item_id);
CREATE INDEX IF NOT EXISTS idx_stock_moves_moved ON stock_moves(moved_on);

-- days the whole resort is closed (version 8)
CREATE TABLE IF NOT EXISTS closures (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  starts_on  TEXT NOT NULL,                     -- first closed day
  ends_on    TEXT NOT NULL,                     -- last closed day (included)
  reason     TEXT NOT NULL DEFAULT 'closed',    -- private|repairs|closed
  note       TEXT NOT NULL DEFAULT '',          -- for staff only
  created_at TEXT NOT NULL,
  created_by TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_closures_dates ON closures(starts_on, ends_on);
SQL;
    }

    private static function migrate(): void
    {
        if (self::$driver === 'mysql') {
            $tables = self::$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('rate_hits', $tables, true)) self::createMysqlTables();
            else {
                if (!in_array('settings', $tables, true)) self::upgradeMysql();
                if (!in_array('pos_log', $tables, true)) self::upgradeMysqlPos();
                if (!in_array('booking_rooms', $tables, true)) self::upgradeMysqlOptions();
            }
        } else {
            self::upgradeSqlite(); // before the schema below, which only creates what is missing
            self::$pdo->exec(self::sqliteSchema());
        }
        self::seed();
        // data changes that run once each (settings.schema_version)
        $ver = self::one("SELECT value FROM settings WHERE name = 'schema_version'");
        $ver = $ver ? (int) $ver['value'] : 0;
        if ($ver < 3) self::migrateOptionsData();
        if ($ver < 4) self::migrateGuestIdNo();
        if ($ver < 5) self::migrateV5();
        if ($ver < 6) self::migrateV6();
        if ($ver < 7) self::migrateV7();
        if ($ver < 8) self::migrateV8();
        if ($ver < 9) self::migrateV9();
        if ($ver < 10) self::migrateV10();
    }

    private static function setSchemaVersion(int $v): void
    {
        if (self::one("SELECT name FROM settings WHERE name = 'schema_version'")) self::run("UPDATE settings SET value = ? WHERE name = 'schema_version'", [(string) $v]);
        else self::run("INSERT INTO settings (name, value) VALUES ('schema_version', ?)", [(string) $v]);
    }

    /** Version 4: bookings keep the guest's NIC or passport number. */
    private static function migrateGuestIdNo(): void
    {
        if (self::$driver === 'mysql') {
            if (!self::$pdo->query("SHOW COLUMNS FROM bookings LIKE 'guest_id_no'")->fetch()) {
                self::$pdo->exec("ALTER TABLE bookings ADD COLUMN guest_id_no VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'NIC or passport number' AFTER guest_phone");
            }
        } else {
            $cols = array_column(self::$pdo->query('PRAGMA table_info(bookings)')->fetchAll(), 'name');
            if (!in_array('guest_id_no', $cols, true)) self::$pdo->exec("ALTER TABLE bookings ADD COLUMN guest_id_no TEXT NOT NULL DEFAULT ''");
        }
        self::setSchemaVersion(4);
    }

    /** Sample rooms, prices, booking options and POS menu, for whatever is still empty. */
    private static function seed(): void
    {
        $now = self::toDbTime(Js::isoNow());
        if ((int) self::one('SELECT COUNT(*) AS n FROM rooms')['n'] === 0) {
            foreach (RoomsSeed::all() as $r) {
                self::run(
                    'INSERT INTO rooms (slug, name, type, tagline, description, price, max_adults, max_children, capacity, beds, size_sqm, features, images, sort) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$r['slug'], $r['name'], $r['type'], $r['tagline'], $r['description'], $r['price'], $r['maxAdults'], $r['maxChildren'], $r['capacity'],
                        $r['beds'], $r['size'], Js::json($r['features']), Js::json($r['images']), $r['sort']]
                );
            }
            error_log('[db] seeded ' . count(RoomsSeed::all()) . ' sample rooms');
        }
        if ((int) self::one('SELECT COUNT(*) AS n FROM settings')['n'] === 0) {
            foreach (RoomsSeed::extras() as $name => $value) self::run('INSERT INTO settings (name, value) VALUES (?, ?)', [$name, (string) $value]);
        }
        if ((int) self::one('SELECT COUNT(*) AS n FROM packages')['n'] === 0) {
            $dayOut = self::one("SELECT value FROM settings WHERE name = 'price_day_out'"); // the old day-out price, if any
            foreach (RoomsSeed::packages() as $i => [$name, $desc, $kind, $adult, $child, $active]) {
                if ($kind === 'day' && $dayOut) $adult = (int) $dayOut['value'];
                self::run('INSERT INTO packages (name, description, kind, price_adult, price_child, active, sort, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$name, $desc, $kind, $adult, $child, $active ? 1 : 0, $i + 1, $now]);
            }
        }
        // POS categories and menu (hidden, never deleted, so this runs once)
        if ((int) self::one('SELECT COUNT(*) AS n FROM pos_categories')['n'] === 0) {
            foreach (RoomsSeed::posCategories() as $i => $name) self::run('INSERT INTO pos_categories (name, sort, active, created_at) VALUES (?, ?, 1, ?)', [$name, $i + 1, $now]);
        }
        if ((int) self::one('SELECT COUNT(*) AS n FROM pos_items')['n'] === 0) self::insertPosItems(RoomsSeed::posItems());
    }

    private static function insertPosItems(array $items): void
    {
        $now = self::toDbTime(Js::isoNow());
        $cats = array_column(self::all('SELECT id, name FROM pos_categories'), 'id', 'name');
        $sort = (int) self::one('SELECT COALESCE(MAX(sort), 0) AS n FROM pos_items')['n'];
        foreach ($items as [$name, $category, $price]) {
            self::run('INSERT INTO pos_items (name, category_id, category, price, active, sort, created_at) VALUES (?, ?, ?, ?, 1, ?, ?)',
                [$name, isset($cats[$category]) ? (int) $cats[$category] : null, '', $price, ++$sort, $now]);
        }
    }

    /**
     * Data written before booking options became editable: old package keys become a room
     * or per-person booking, POS lines get category names, and the hotel capacity is set.
     * Runs once (settings.schema_version).
     */
    private static function migrateOptionsData(): void
    {
        $pkg = fn (string $name) => self::one('SELECT id FROM packages WHERE name = ?', [$name]);
        self::run("UPDATE bookings SET package = 'room' WHERE package IN ('room_only', '')");
        foreach (['bed_breakfast' => 'Bed & breakfast', 'half_board' => 'Half board', 'full_board' => 'Full board'] as $old => $name) {
            // a per-person stay booked for one room keeps that room as its assigned room
            foreach (self::all('SELECT id, room_id FROM bookings WHERE package = ? AND room_id IS NOT NULL', [$old]) as $b) {
                self::run('INSERT INTO booking_rooms (booking_id, room_id) VALUES (?, ?)', [(int) $b['id'], (int) $b['room_id']]);
            }
            $p = $pkg($name);
            self::run("UPDATE bookings SET package = 'per_person', package_id = ?, package_name = ?, room_id = NULL WHERE package = ?", [$p ? (int) $p['id'] : null, $name, $old]);
        }
        $p = $pkg('Day out');
        self::run("UPDATE bookings SET package_id = ?, package_name = 'Day out' WHERE package = 'day_out' AND package_id IS NULL", [$p ? (int) $p['id'] : null]);
        // POS: the three fixed categories became editable ones
        foreach (['food' => 'Food', 'beverage' => 'Beverages', 'other' => 'Other'] as $key => $name) {
            $c = self::one('SELECT id FROM pos_categories WHERE name = ?', [$name]);
            if ($c) self::run("UPDATE pos_items SET category_id = ?, category = '' WHERE category_id IS NULL AND category = ?", [(int) $c['id'], $key]);
            self::run('UPDATE pos_charges SET category = ? WHERE category = ?', [$name, $key]);
        }
        $bite = self::one("SELECT id FROM pos_categories WHERE name = 'Bite'");
        if ($bite && !self::one('SELECT id FROM pos_items WHERE category_id = ?', [(int) $bite['id']])) self::insertPosItems(RoomsSeed::biteItems());
        // the hotel's capacity starts as what all the rooms sleep
        if (!self::one("SELECT name FROM settings WHERE name = 'hotel_capacity'")) {
            $cap = (int) self::one('SELECT COALESCE(SUM(capacity), 0) AS n FROM rooms WHERE active = 1')['n'];
            self::run("INSERT INTO settings (name, value) VALUES ('hotel_capacity', ?)", [(string) $cap]);
        }
        self::setSchemaVersion(3);
        error_log('[db] booking options, room capacity and POS categories are ready');
    }

    private static function createMysqlTables(): void
    {
        foreach (array_filter(array_map('trim', explode(';', self::mysqlSchema()))) as $sql) self::$pdo->exec($sql);
    }

    /** Databases created before packages (room only / board / day out) and extras existed. */
    private static function upgradeMysql(): void
    {
        $has = fn (string $table, string $col) => (bool) self::$pdo->query("SHOW COLUMNS FROM $table LIKE '$col'")->fetch();
        if (!$has('bookings', 'package')) {
            self::$pdo->exec("ALTER TABLE bookings
                MODIFY room_id INT UNSIGNED NULL COMMENT 'NULL for a day out',
                ADD COLUMN package VARCHAR(20) COLLATE utf8mb4_bin NOT NULL DEFAULT 'room_only' COMMENT 'room_only|bed_breakfast|half_board|full_board|day_out' AFTER room_id,
                ADD COLUMN party_area TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'party area reserved for every night of the stay' AFTER children,
                ADD COLUMN jbl_speaker TINYINT(1) NOT NULL DEFAULT 0 AFTER party_area");
        }
        self::createMysqlTables(); // adds the settings table (the others already exist)
        error_log('[db] upgraded the database for packages and extras');
    }

    /** Databases created before the POS (room bills, bills and their history) existed. */
    private static function upgradeMysqlPos(): void
    {
        if (!self::$pdo->query("SHOW COLUMNS FROM bookings LIKE 'bill_id'")->fetch()) {
            self::$pdo->exec("ALTER TABLE bookings
                ADD COLUMN bill_id INT UNSIGNED NULL COMMENT 'set once the stay is billed (bills.id)' AFTER status,
                ADD KEY idx_bookings_bill (bill_id)");
        }
        self::createMysqlTables(); // adds the POS tables
        error_log('[db] upgraded the database for the POS');
    }

    /** Databases created before booking options, room capacity and POS categories were editable. */
    private static function upgradeMysqlOptions(): void
    {
        $has = fn (string $table, string $col) => (bool) self::$pdo->query("SHOW COLUMNS FROM $table LIKE '$col'")->fetch();
        if (!$has('rooms', 'capacity')) {
            self::$pdo->exec("ALTER TABLE rooms ADD COLUMN capacity INT NOT NULL DEFAULT 2 COMMENT 'guests the room sleeps' AFTER max_children");
            self::$pdo->exec('UPDATE rooms SET capacity = GREATEST(max_adults, 1)');
        }
        if (!$has('bookings', 'package_id')) {
            self::$pdo->exec("ALTER TABLE bookings
                MODIFY package VARCHAR(20) COLLATE utf8mb4_bin NOT NULL DEFAULT 'room' COMMENT 'room|per_person|day_out',
                ADD COLUMN package_id INT UNSIGNED NULL COMMENT 'per-person option (packages.id)' AFTER package,
                ADD COLUMN package_name VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'option name when booked' AFTER package_id");
        }
        if (!$has('pos_items', 'category_id')) {
            self::$pdo->exec("ALTER TABLE pos_items ADD COLUMN category_id INT UNSIGNED NULL COMMENT 'pos_categories.id' AFTER name");
        }
        self::$pdo->exec("ALTER TABLE pos_charges MODIFY category VARCHAR(80) NOT NULL COMMENT 'POS category name when charged'");
        self::createMysqlTables(); // adds packages, pos_categories and booking_rooms
        error_log('[db] upgraded the database for booking options');
    }

    private static function upgradeSqlite(): void
    {
        $cols = fn (string $table) => array_column(self::$pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        $rooms = $cols('rooms');
        if ($rooms && !in_array('capacity', $rooms, true)) {
            self::$pdo->exec('ALTER TABLE rooms ADD COLUMN capacity INTEGER NOT NULL DEFAULT 2');
            self::$pdo->exec('UPDATE rooms SET capacity = MAX(max_adults, 1)');
        }
        $items = $cols('pos_items');
        if ($items && !in_array('category_id', $items, true)) self::$pdo->exec('ALTER TABLE pos_items ADD COLUMN category_id INTEGER');
        $bookings = $cols('bookings');
        if ($bookings && !in_array('package', $bookings, true)) {
            // SQLite cannot drop NOT NULL from room_id, so the table is rebuilt.
            $list = implode(', ', $bookings);
            self::$pdo->exec('PRAGMA foreign_keys = OFF');
            self::$pdo->exec('BEGIN IMMEDIATE');
            try {
                self::$pdo->exec('ALTER TABLE bookings RENAME TO bookings_old');
                self::$pdo->exec(self::sqliteSchema());
                self::$pdo->exec("INSERT INTO bookings ($list) SELECT $list FROM bookings_old");
                self::$pdo->exec('DROP TABLE bookings_old');
                self::$pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                self::$pdo->exec('ROLLBACK');
                throw $e;
            } finally {
                self::$pdo->exec('PRAGMA foreign_keys = ON');
            }
            $bookings = $cols('bookings');
        }
        if ($bookings && !in_array('bill_id', $bookings, true)) self::$pdo->exec('ALTER TABLE bookings ADD COLUMN bill_id INTEGER');
        if ($bookings && !in_array('package_id', $bookings, true)) {
            self::$pdo->exec('ALTER TABLE bookings ADD COLUMN package_id INTEGER');
            self::$pdo->exec("ALTER TABLE bookings ADD COLUMN package_name TEXT NOT NULL DEFAULT ''");
        }
        // version 5 and 6 columns (before the schema below, which indexes some of them)
        foreach (array_merge_recursive(self::V5_COLUMNS, self::V6_COLUMNS, self::V9_COLUMNS) as $table => $list) {
            $have = $cols($table);
            if (!$have) continue; // a new database: the schema creates the table whole
            foreach ($list as $col => [$sqliteDef]) if (!in_array($col, $have, true)) self::$pdo->exec("ALTER TABLE $table ADD COLUMN $col $sqliteDef");
        }
    }

    /** Version 5: reservations held for a few days, agreed rates, coupons, commission, payment fees and details. [sqlite, mysql] */
    private const V5_COLUMNS = [
        'bookings' => [
            'rate_room' => ['INTEGER', "INT NULL COMMENT 'agreed room rate per night (NULL = the room price)' AFTER total_amount"],
            'rate_adult' => ['INTEGER', "INT NULL COMMENT 'agreed per-person rate for an adult (NULL = the option price)' AFTER rate_room"],
            'rate_child' => ['INTEGER', "INT NULL COMMENT 'agreed per-person rate for a child (NULL = the option price)' AFTER rate_adult"],
            'coupon_code' => ["TEXT NOT NULL DEFAULT ''", "VARCHAR(40) COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'coupon used when booking' AFTER rate_child"],
            'coupon_discount' => ['INTEGER NOT NULL DEFAULT 0', "INT NOT NULL DEFAULT 0 COMMENT 'taken off the stay price (already out of total_amount)' AFTER coupon_code"],
            'commission_type' => ["TEXT NOT NULL DEFAULT ''", "VARCHAR(10) COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'percent|amount, empty = none' AFTER coupon_discount"],
            'commission_value' => ['INTEGER NOT NULL DEFAULT 0', 'INT NOT NULL DEFAULT 0 AFTER commission_type'],
            'commission_to' => ["TEXT NOT NULL DEFAULT ''", "VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'the agent or website that takes the commission' AFTER commission_value"],
            'hold_until' => ['TEXT', "DATE NULL COMMENT 'a pending reservation is released after this day if no advance is paid' AFTER commission_to"],
        ],
        'pos_payments' => [
            'fee' => ['INTEGER NOT NULL DEFAULT 0', "INT NOT NULL DEFAULT 0 COMMENT 'commission or charges taken off before the money reached the resort' AFTER note"],
            'details' => ["TEXT NOT NULL DEFAULT ''", "VARCHAR(2000) NOT NULL DEFAULT '' COMMENT 'JSON: the extra payment fields (account received into, reference…)' AFTER fee"],
        ],
        'bills' => [
            'coupon_code' => ["TEXT NOT NULL DEFAULT ''", "VARCHAR(40) COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'coupon used at checkout' AFTER discount"],
            'coupon_discount' => ['INTEGER NOT NULL DEFAULT 0', "INT NOT NULL DEFAULT 0 COMMENT 'taken off the stays by that coupon' AFTER coupon_code"],
        ],
    ];

    /** Version 5 on MySQL: the new columns, their indexes and the coupons table (SQLite gets them in upgradeSqlite). */
    private static function migrateV5(): void
    {
        if (self::$driver === 'mysql') {
            foreach (self::V5_COLUMNS as $table => $list) {
                $have = array_column(self::$pdo->query("SHOW COLUMNS FROM $table")->fetchAll(), 'Field');
                $add = [];
                foreach ($list as $col => [, $mysqlDef]) if (!in_array($col, $have, true)) $add[] = "ADD COLUMN $col $mysqlDef";
                if ($add) self::$pdo->exec("ALTER TABLE $table " . implode(', ', $add));
            }
            $keys = array_column(self::$pdo->query('SHOW INDEX FROM bookings')->fetchAll(), 'Key_name');
            if (!in_array('idx_bookings_hold', $keys, true)) self::$pdo->exec('ALTER TABLE bookings ADD KEY idx_bookings_hold (status, hold_until)');
            if (!in_array('idx_bookings_coupon', $keys, true)) self::$pdo->exec('ALTER TABLE bookings ADD KEY idx_bookings_coupon (coupon_code)');
            self::createMysqlTables(); // adds the coupons table
        }
        self::setSchemaVersion(5);
        error_log('[db] upgraded the database for reservations, coupons and payment details');
    }

    /** Version 6: staff logins with a name, a role (what they may use) and an on/off switch. [sqlite, mysql] */
    private const V6_COLUMNS = [
        'admins' => [
            'name' => ["TEXT NOT NULL DEFAULT ''", "VARCHAR(80) NOT NULL DEFAULT '' COMMENT 'shown on bills and receipts (empty = the username)' AFTER password_hash"],
            'role' => ["TEXT NOT NULL DEFAULT 'admin'", "VARCHAR(20) COLLATE utf8mb4_bin NOT NULL DEFAULT 'admin' COMMENT 'admin|manager|pos' AFTER name"],
            'active' => ['INTEGER NOT NULL DEFAULT 1', "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = cannot sign in' AFTER role"],
            'last_login' => ['TEXT', "DATETIME(3) NULL COMMENT 'UTC' AFTER active"],
        ],
    ];

    /** Version 6 on MySQL: the staff columns (SQLite gets them in upgradeSqlite). Existing logins stay admins. */
    private static function migrateV6(): void
    {
        if (self::$driver === 'mysql') {
            foreach (self::V6_COLUMNS as $table => $list) {
                $have = array_column(self::$pdo->query("SHOW COLUMNS FROM $table")->fetchAll(), 'Field');
                $add = [];
                foreach ($list as $col => [, $mysqlDef]) if (!in_array($col, $have, true)) $add[] = "ADD COLUMN $col $mysqlDef";
                if ($add) self::$pdo->exec("ALTER TABLE $table " . implode(', ', $add));
            }
        }
        self::setSchemaVersion(6);
        error_log('[db] upgraded the database for staff logins and roles');
    }

    /** Version 7: the finance tables (MySQL; SQLite creates them from its schema on every start). */
    private static function migrateV7(): void
    {
        if (self::$driver === 'mysql') self::createMysqlTables(); // only adds the tables that are missing
        self::setSchemaVersion(7);
        error_log('[db] added the finance tables');
    }

    /** Version 8: days the whole resort is closed (MySQL; SQLite creates the table from its schema). */
    private static function migrateV8(): void
    {
        if (self::$driver === 'mysql') self::createMysqlTables(); // only adds what is missing
        self::setSchemaVersion(8);
        error_log('[db] added closed days');
    }

    /** Version 9: outdoor parties — when (day / night) and what other guests can book then. [sqlite, mysql] */
    private const V9_COLUMNS = [
        'bookings' => [
            'event_slot' => ["TEXT NOT NULL DEFAULT ''", "VARCHAR(10) COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'an outdoor party: day|night|full' AFTER hold_until"],
            'event_others' => ["TEXT NOT NULL DEFAULT ''", "VARCHAR(10) COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'during a party, other guests: open|rooms (no other stays)|private' AFTER event_slot"],
        ],
    ];

    private static function migrateV9(): void
    {
        if (self::$driver === 'mysql') {
            foreach (self::V9_COLUMNS as $table => $list) {
                $have = array_column(self::$pdo->query("SHOW COLUMNS FROM $table")->fetchAll(), 'Field');
                $add = [];
                foreach ($list as $col => [, $mysqlDef]) if (!in_array($col, $have, true)) $add[] = "ADD COLUMN $col $mysqlDef";
                if ($add) self::$pdo->exec("ALTER TABLE $table " . implode(', ', $add));
            }
        }
        self::setSchemaVersion(9);
        error_log('[db] added outdoor parties');
    }

    /** Version 10: a record of every reminder sent, so no guest is reminded twice. */
    private static function migrateV10(): void
    {
        if (self::$driver === 'mysql') {
            self::$pdo->exec("CREATE TABLE IF NOT EXISTS booking_reminders (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                booking_id INT UNSIGNED NOT NULL,
                kind       VARCHAR(20) COLLATE utf8mb4_bin NOT NULL COMMENT 'arrival|payment',
                sent_at    DATETIME(3) NOT NULL,
                UNIQUE KEY uq_reminder (booking_id, kind)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            self::$pdo->exec("CREATE TABLE IF NOT EXISTS booking_reminders (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                booking_id INTEGER NOT NULL,
                kind       TEXT NOT NULL,
                sent_at    TEXT NOT NULL,
                UNIQUE (booking_id, kind)
            )");
        }
        self::setSchemaVersion(10);
        error_log('[db] added booking reminders');
    }

    // ---------- timestamps ----------
    /** ISO "2026-10-09T09:06:00.803Z" → the value stored in the database. */
    public static function toDbTime(string $iso): string
    {
        if (self::driver() !== 'mysql') return $iso;
        return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    /** Stored value → ISO "2026-10-09T09:06:00.803Z" (what the site always shows). */
    public static function fromDbTime(?string $v): ?string
    {
        if ($v === null || self::driver() !== 'mysql') return $v;
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $v, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v, new \DateTimeZone('UTC'));
        return $d ? $d->format('Y-m-d\TH:i:s.v\Z') : $v;
    }

    // ---------- queries ----------
    private static function stmt(string $sql, array $params): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            if (is_int($p)) $st->bindValue($i++, $p, PDO::PARAM_INT);
            elseif (is_bool($p)) $st->bindValue($i++, $p ? 1 : 0, PDO::PARAM_INT);
            elseif ($p === null) $st->bindValue($i++, null, PDO::PARAM_NULL);
            else $st->bindValue($i++, (string) $p, PDO::PARAM_STR);
        }
        $st->execute();
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::stmt($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::stmt($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Runs a statement; returns the last insert id. */
    public static function run(string $sql, array $params = []): int
    {
        self::stmt($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** Run $fn inside a write transaction. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if (self::$driver === 'mysql') $pdo->beginTransaction();
        else $pdo->exec('BEGIN IMMEDIATE'); // SQLite: take the write lock up front
        try {
            $out = $fn();
            if (self::$driver === 'mysql') $pdo->commit(); else $pdo->exec('COMMIT');
            return $out;
        } catch (\Throwable $e) {
            if (self::$driver === 'mysql') { if ($pdo->inTransaction()) $pdo->rollBack(); }
            else $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Inside a transaction: one booking change at a time, so rooms, per-person groups, the
     * party area and the hotel's capacity are always checked against the latest bookings.
     */
    public static function lockBookings(): void
    {
        if (self::$driver !== 'mysql') return; // SQLite already holds the write lock
        if (self::one("SELECT name FROM settings WHERE name = 'lock_bookings' FOR UPDATE")) return;
        self::run("INSERT IGNORE INTO settings (name, value) VALUES ('lock_bookings', '')");
        self::one("SELECT name FROM settings WHERE name = 'lock_bookings' FOR UPDATE");
    }

    /** Inside a transaction: lock a booking's room bill (POS charges, payments, checkout). */
    public static function lockBooking(int $bookingId): void
    {
        if (self::$driver === 'mysql') self::one('SELECT id FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
    }

    /** Inside a transaction: lock an issued bill (later payments, voiding). */
    public static function lockBill(int $billId): void
    {
        if (self::$driver === 'mysql') self::one('SELECT id FROM bills WHERE id = ? FOR UPDATE', [$billId]);
    }

    /** Inside a transaction: lock a coupon while its uses are counted (two guests can't take the last one). */
    public static function lockCoupon(int $couponId): void
    {
        if (self::$driver === 'mysql') self::one('SELECT id FROM coupons WHERE id = ? FOR UPDATE', [$couponId]);
    }
}
