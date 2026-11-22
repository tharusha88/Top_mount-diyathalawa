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
        'pay_advance_on' => '1',                // ask guests for an advance before arrival ('0' = nothing to pay before: they pay at the resort)
        'pay_advance_type' => 'pct',            // how the advance is worked out: pct (pay_advance_pct) | amount (pay_advance_amount)
        'pay_advance_pct' => '0',               // advance asked for, % of the total (0 = the whole total)
        'pay_advance_amount' => '0',            // a fixed advance (0 = the whole total; never more than the booking's total)
        'pay_note' => '',
        'service_charge_pct' => '10',           // service charge added to every bill, % (0 = none)
        'hold_days' => '7',                     // a reservation waits this many days for its advance, then is released (0 = never)
        'cancel_policy' => '',                  // cancellation policy at the bottom of the booking page ('' = not shown); plain text, "- " lines become a list
        'payment_fields' => '',                 // JSON: extra fields staff fill in when recording a payment ('' = the default "Received into")
        'print_paper' => 'A5',                  // paper for bills, invoices and receipts: A5 | A4
        'notify_emails' => '',                  // JSON list: who is emailed about new website bookings (Admin → Settings); '' = ADMIN_NOTIFY_EMAILS from .env
        'wa_alert_numbers' => '',               // JSON [{id, phone, name, apiKey}]: the WhatsApp numbers booking alerts go to; '' = wa_alert_phone + wa_alert_apikey below
        'wa_alert_phone' => '94777368264',      // the single alert number of earlier versions: the resort's hotline
        'wa_alert_apikey' => '',                // its key, from CallMeBot
        'wa_alert_on' => '1',                   // WhatsApp alerts at all
        'wa_alert_new' => '1',                  // … when a guest books on the website
        'wa_alert_confirmed' => '1',            // … when a booking is confirmed
        'reminders_on' => '1',                  // email guests a reminder before arrival and before a reservation's hold runs out
        'reminder_arrival_days' => '2',         // confirmed booking: remind this many days before check-in (0 = off)
        'reminder_payment_days' => '1',         // pending reservation: remind this many days before the hold date (0 = off)
        'reminders_last_run' => '',             // when the website last checked for reminders to send (YYYY-MM-DD HH)
        'ical_token' => '',                    // secret part of the Google Calendar link (made on first use)
        // the website's chatbot (Chatbot.php, Admin → Chatbot)
        'chat_web_on' => '0',                   // show the chat window on the website
        'chat_greeting' => '',                  // its first message ('' = a standard welcome)
        'chat_brand_id' => '',                  // the bot's brand id on the chatbot platform (kept on each read)
        'chat_bot_name' => '',                  // the bot's name, for the chat window
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
