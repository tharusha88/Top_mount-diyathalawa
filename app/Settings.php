<?php
// Settings edited in the admin panel (Booking setup), stored in the `settings` table.
// Anything not saved yet falls back to the default below.
declare(strict_types=1);

namespace Ydr;

final class Settings
{
    private const DEFAULTS = [
        'label_room' => 'Select room',          // the two booking categories on the website
        'label_person' => 'Per person',
        'hotel_capacity' => '0',                // guests per night; 0 = limited by the rooms only
        'room_priority' => '',                  // JSON room ids: the order per-person groups get rooms ('' = all rooms)
        'price_party_area' => '0',              // per night
        'price_jbl_speaker' => '0',             // per night (per day for a day visit)
        'pay_phone' => '',                      // '' = RESORT_PHONE
        'pay_bank' => '',
        'pay_account_name' => '',
        'pay_account_no' => '',
        'pay_branch' => '',
        'pay_advance_pct' => '0',               // advance asked for, % of the total (0 = no amount shown)
        'pay_note' => '',
        'service_charge_pct' => '10',           // service charge added to every bill, % (0 = none)
        'hold_days' => '7',                     // a reservation waits this many days for its advance, then is released (0 = never)
        'payment_fields' => '',                 // JSON: extra fields staff fill in when recording a payment ('' = the default "Received into")
        'print_paper' => 'A5',                  // paper for bills, invoices and receipts: A5 | A4
        'wa_alert_phone' => '94777368264',      // WhatsApp alert when a booking is confirmed (Admin → Settings): the resort's hotline
        'wa_alert_apikey' => '',                // from CallMeBot
        'wa_alert_on' => '1',
        'ical_token' => '',                     // secret part of the Google Calendar link (made on first use)
        'schema_version' => '0',
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = array_column(Db::all('SELECT name, value FROM settings'), 'value', 'name');
            self::$cache = array_map('strval', $rows) + self::DEFAULTS;
        }
        return self::$cache;
    }

    public static function get(string $name): string
    {
        return (string) (self::all()[$name] ?? '');
    }

    public static function int(string $name): int
    {
        return (int) self::get($name);
    }

    /** Save several settings at once (name => value). */
    public static function set(array $values): void
    {
        foreach ($values as $name => $value) {
            $value = (string) $value;
            if (Db::one('SELECT name FROM settings WHERE name = ?', [$name])) Db::run('UPDATE settings SET value = ? WHERE name = ?', [$value, $name]);
            else Db::run('INSERT INTO settings (name, value) VALUES (?, ?)', [$name, $value]);
        }
        self::$cache = null;
    }

    public static function forget(): void
    {
        self::$cache = null;
    }
}
