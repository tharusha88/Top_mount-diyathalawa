<?php
// Email: SMTP when configured, otherwise messages are written to data/outbox/ so
// nothing is lost while setting up. Booking emails are sent after the response has
// gone back to the browser, so guests never wait for the mail server.
declare(strict_types=1);

namespace Ydr;

final class Mailer
{
    private static array $queue = [];

    public static function enabled(): bool
    {
        $s = Config::get()['smtp'];
        // a mailbox login without its password isn't set up yet: keep emails in data/outbox/
        return $s['host'] !== '' && !($s['user'] !== '' && $s['pass'] === '');
    }

    private static function esc(mixed $s): string
    {
        return strtr($s === null ? '' : Js::str($s), ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']);
    }

    private static function money(mixed $n): string
    {
        return Config::get()['site']['currency'] . ' ' . Js::localeNumber(Js::truthy($n) ? $n : 0);
    }

    private static function niceDate(string $s): string
    {
        return (new \DateTimeImmutable($s . ' 00:00:00', new \DateTimeZone('UTC')))->format('D, j M Y');
    }

    /** Send now. Returns ['skipped'=>true] | ['outbox'=>file] | ['sent'=>true]; throws on SMTP failure. */
    public static function send(array $opts): array
    {
        $cfg = Config::get();
        $to = $opts['to'] ?? [];
        $recipients = array_values(array_filter(is_array($to) ? $to : [$to], fn ($r) => Js::truthy($r)));
        if (!$recipients) return ['skipped' => true];
        $toLine = implode(', ', array_map(fn ($r) => Js::str($r), $recipients));
        $subject = $opts['subject'];
        $html = $opts['html'];
        if (!self::enabled()) {
            $dir = $cfg['dataDir'] . '/outbox';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $stamp = str_replace([':', '.'], '-', Js::isoNow());
            $name = substr((string) preg_replace('/[^a-z0-9]+/i', '-', $subject), 0, 50);
            $file = "$dir/$stamp-$name.html";
            for ($i = 2; file_exists($file); $i++) $file = "$dir/$stamp-$name-$i.html"; // the same email to several admins at once
            file_put_contents($file, '<!-- To: ' . self::esc($toLine) . ' | Subject: ' . self::esc($subject) . " -->\n" . $html);
            $shown = str_starts_with($file, YDR_ROOT . '/') ? substr($file, strlen(YDR_ROOT) + 1) : $file;
            error_log('[mail] SMTP not configured — saved "' . $subject . '" for ' . $toLine . ' to ' . $shown);
            return ['outbox' => $file];
        }
        $smtp = $cfg['smtp'];
        $data = Smtp::buildMessage($smtp['from'], $recipients, $subject, $html, $opts['text'] ?? '', isset($opts['replyTo']) && Js::truthy($opts['replyTo']) ? Js::str($opts['replyTo']) : null);
        Smtp::send($smtp, $smtp['from'], array_map(fn ($r) => Js::str($r), $recipients), $data);
        return ['sent' => true];
    }

    /** Queue an email to be sent after the response (failures are only logged). */
    private static function sendQuietly(array $opts): void
    {
        self::$queue[] = $opts;
    }

    public static function hasQueue(): bool
    {
        return (bool) self::$queue;
    }

    public static function flushQueue(): void
    {
        while ($opts = array_shift(self::$queue)) {
            try {
                self::send($opts);
            } catch (\Throwable $e) {
                error_log('[mail] failed: "' . $opts['subject'] . '" ' . $e->getMessage());
            }
        }
    }

    // ---------- templates ----------
    private static function layout(string $title, string $body): string
    {
        $base = Config::get()['baseUrl'];
        $site = Site::info();
        $name = self::esc($site['name']);
        $address = self::esc($site['address']);
        $phone = self::esc($site['phone']);
        $email = self::esc($site['email']);
        return <<<HTML
<!doctype html><html><body style="margin:0;background:#efe8d8;font-family:Georgia,'Times New Roman',serif;color:#1f2a22">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#efe8d8;padding:28px 12px">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fbf8f1;border-radius:14px;overflow:hidden">
<tr><td style="background:#13261b;padding:26px 32px;text-align:center">
  <img src="{$base}/media/logo-light.png" width="150" alt="{$name}" style="display:inline-block;max-width:150px">
</td></tr>
<tr><td style="padding:32px 32px 8px">
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:normal;color:#13261b">{$title}</h1>
  {$body}
</td></tr>
<tr><td style="padding:22px 32px 30px;font-family:Arial,sans-serif;font-size:12px;color:#6b6a5e;border-top:1px solid #e6dcc6">
  {$name} · {$address}<br>
  {$phone} · <a href="mailto:{$email}" style="color:#3f6b3a">{$email}</a>
</td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    private static function isDayOut(array $b): bool
    {
        return $b['package'] === 'day_out';
    }

    /** What was booked, for subjects and plain-text lines: "Room 101 · Half board", "Full board for 4" or "Day out". */
    private static function what(array $b): string
    {
        if ($b['kind'] === 'room') return (string) ($b['place'] ?: $b['roomName']);
        return $b['packageLabel'] . ' for ' . ($b['adults'] + $b['children']);
    }

    private static function guests(array $b): string
    {
        if (!$b['adults'] && !$b['children']) return $b['capacity'] ? 'Up to ' . $b['capacity'] : '—';
        return $b['adults'] . ' adult' . ($b['adults'] === 1 ? '' : 's') . ($b['children'] ? ', ' . $b['children'] . ' child' . ($b['children'] === 1 ? '' : 'ren') : '');
    }

    /**
     * What to pay, where, and who to call (Admin → Booking setup): the booking's advance (a % of the
     * total, a fixed amount, or its own), or the total when no advance amount is set. A booking that
     * asks for no advance is told it pays at the resort.
     */
    private static function paymentBlock(array $b): string
    {
        $pay = Bookings::paymentInfo();
        $adv = $b['advance'];
        $phone = self::esc($pay['phone']);
        if (!$adv['required']) {
            return self::p("<strong>Your booking is pending.</strong> There is nothing to pay before you arrive: the total of <strong>" . self::money($b['totalAmount'])
                . "</strong> is paid at the resort. We will call you to confirm your booking; for any questions, call us on <strong>$phone</strong> quoting <strong>" . self::esc($b['ref']) . '</strong>.')
                . ($pay['note'] !== '' ? self::p(nl2br(self::esc($pay['note']))) : '');
        }
        $due = $adv['full'] ? 0 : $adv['amount']; // an advance smaller than the total
        $toPay = $due ?: $b['totalAmount'];
        $hasBank = $pay['accountNo'] !== '';
        $rows = array_filter([
            ['Total', self::money($b['totalAmount'])],
            [$due ? 'Advance to pay now' : 'Amount to pay', '<strong>' . self::money($toPay) . '</strong>' . ($due && $adv['pct'] !== null ? ' (' . $adv['pct'] . '% of the total)' : '')],
            $due ? ['Balance on arrival', self::money($b['totalAmount'] - $due)] : ['', ''],
            ['Bank', self::esc($pay['bank'])],
            ['Account name', self::esc($pay['accountName'])],
            ['Account number', $hasBank ? '<strong>' . self::esc($pay['accountNo']) . '</strong>' : ''],
            ['Branch', self::esc($pay['branch'])],
            ['Payment reference', '<strong>' . self::esc($b['ref']) . '</strong>'],
        ], fn ($r) => $r[1] !== '');
        $tr = '';
        foreach ($rows as [$k, $v]) $tr .= '<tr><td style="padding:7px 12px;color:#6b6a5e;width:42%">' . $k . '</td><td style="padding:7px 12px">' . $v . '</td></tr>';
        $amount = '<strong>' . self::money($toPay) . '</strong>';
        $ask = $hasBank
            ? "please pay $amount to the bank account below, then <a href=\"" . self::esc(Docs::invoiceUrl($b)) . '#slip" style="color:#3f6b3a;font-weight:bold">upload the payment slip on your invoice</a>'
                . " or call us on <strong>$phone</strong> to confirm your booking."
            : "please call us on <strong>$phone</strong> to confirm it and to arrange the payment of $amount.";
        return self::p("<strong>Your booking is pending.</strong> To confirm it, $ask We confirm your booking as soon as the payment is received.")
            . '<table role="presentation" width="100%" style="font-family:Arial,sans-serif;font-size:14px;border-collapse:collapse;margin:0 0 18px;background:#f5eedd;border-radius:10px">' . $tr . '</table>'
            . ($pay['note'] !== '' ? self::p(nl2br(self::esc($pay['note']))) : '');
    }

    /** "2026-11-14 → 2026-11-16 (2 nights)" or "2026-11-14 (day out)" */
    private static function when(array $b): string
    {
        if ($b['kind'] === 'event') return "{$b['checkIn']} (" . strtolower($b['packageLabel']) . ($b['arrivalTime'] !== '' ? ", {$b['arrivalTime']}" : '') . ')';
        return self::isDayOut($b) ? "{$b['checkIn']} (day out)" : "{$b['checkIn']} → {$b['checkOut']} ({$b['nights']} night" . ($b['nights'] === 1 ? '' : 's') . ')';
    }

    /** $forAdmin also shows the rooms given to a per-person group (they can still change). */
    private static function detailsTable(array $b, bool $forAdmin = false): string
    {
        $c = Site::info();
        $extras = implode(', ', array_filter([$b['partyArea'] ? 'Party area' : '', $b['jblSpeaker'] ? 'JBL speaker' : '']));
        $rows = [['Booking reference', '<strong>' . self::esc($b['ref']) . '</strong>']];
        if (!$forAdmin) $rows[] = ['Booked by', self::esc($b['guestName']) . ($b['guestIdNo'] !== '' ? ' <span style="color:#6b6a5e">(NIC / passport ' . self::esc($b['guestIdNo']) . ')</span>' : '')];
        if (self::isDayOut($b)) {
            $rows[] = ['Booking', self::esc($b['packageLabel'])];
            $rows[] = ['Date', self::niceDate($b['checkIn'])];
        } else {
            if ($b['kind'] === 'room') {
                $several = count($b['rooms'] ?? []) > 1;
                $rows[] = [$several ? 'Rooms' : 'Room', self::esc($b['roomName']) . ' <span style="color:#6b6a5e">(' . ($several ? count($b['rooms']) . ' rooms' : self::esc((string) $b['roomType'])) . ', sleeps ' . $b['capacity'] . ')</span>'];
                if (!empty($b['wholeCategory'])) $rows[] = ['Whole villa', self::esc($b['wholeCategory']['name'] . ' — ' . $b['wholeCategory']['label']) . ' <span style="color:#6b6a5e">(one price for all its rooms)</span>'];
                if (($b['mealPlan'] ?? '') !== '') $rows[] = ['Meal plan', self::esc($b['mealPlanLabel']) . ' <span style="color:#6b6a5e">(' . self::esc(strtolower(Bookings::MEAL_PLANS[$b['mealPlan']][1])) . ')</span>'];
            } else {
                $rows[] = ['Booking', self::esc($b['packageLabel']) . ' <span style="color:#6b6a5e">(per person)</span>'];
                if ($forAdmin) $rows[] = ['Rooms', $b['roomName'] ? self::esc($b['roomName']) : '— not assigned —'];
            }
            $rows[] = ['Check-in', self::niceDate($b['checkIn']) . ' · from ' . self::esc($c['checkInTime'])];
            $rows[] = ['Check-out', self::niceDate($b['checkOut']) . ' · by ' . self::esc($c['checkOutTime'])];
            $rows[] = ['Nights', (string) $b['nights']];
        }
        $rows[] = ['Guests', self::guests($b)];
        if ($extras !== '') $rows[] = ['Extras', $extras];
        if (!empty($b['couponDiscount'])) $rows[] = ['Coupon ' . self::esc($b['couponCode']), '− ' . self::money($b['couponDiscount']) . ' <span style="color:#6b6a5e">(off the room charge)</span>'];
        $rows[] = ['Total', '<strong>' . self::money($b['totalAmount']) . '</strong>'];
        $tr = '';
        foreach ($rows as [$k, $v]) $tr .= '<tr><td style="padding:9px 0;border-bottom:1px solid #ece4d2;color:#6b6a5e;width:42%">' . $k . '</td><td style="padding:9px 0;border-bottom:1px solid #ece4d2">' . $v . '</td></tr>';
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:Arial,sans-serif;font-size:14px;border-collapse:collapse;margin:8px 0 20px">' . "\n  " . $tr . "\n  </table>";
    }

    private static function p(string $html): string
    {
        return '<p style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;margin:0 0 14px">' . $html . '</p>';
    }

    private static function button(string $url, string $label): string
    {
        return '<p style="margin:0 0 22px"><a href="' . self::esc($url) . '" style="display:inline-block;background:#e8a33d;color:#13261b;text-decoration:none;font-family:Arial,sans-serif;font-weight:bold;padding:12px 20px;border-radius:999px">' . self::esc($label) . '</a></p>';
    }

    /** Email a booking's payment invoice (what to pay, where, by when) with its link. */
    public static function sendInvoice(array $b): bool
    {
        if ($b['guestEmail'] === '') return false;
        $site = Site::info();
        $a = Docs::amounts($b);
        $url = Docs::invoiceUrl($b);
        $hold = $b['status'] === 'pending' && $b['holdUntil'] ? ' We are holding it for you until <strong>' . self::niceDate($b['holdUntil']) . '</strong>.' : '';
        $html = self::layout('Your invoice ' . self::esc($b['ref']),
            "\n    " . self::p('Dear ' . self::esc($b['guestName']) . ', here is the invoice for your ' . ($b['status'] === 'pending' ? 'reservation' : 'booking') . ' at ' . self::esc($site['name']) . '.' . $hold)
            . "\n    " . ($b['status'] === 'pending' ? self::paymentBlock($b) : self::p(self::esc($a['dueLabel']) . ': <strong>' . self::money($a['due']) . '</strong>'))
            . "\n    " . self::button($url, 'View or print the invoice →')
            . "\n    " . self::detailsTable($b)
            . "\n    " . self::p('Warmly,<br><em>The Top Mountain team</em>'));
        self::sendQuietly(['to' => $b['guestEmail'], 'replyTo' => $site['email'], 'subject' => "Invoice {$b['ref']} · {$site['name']}", 'html' => $html,
            'text' => "Invoice {$b['ref']}: total " . self::money($b['totalAmount']) . ', ' . $a['dueLabel'] . ' ' . self::money($a['due']) . ". View it: $url"]);
        return true;
    }

    /** Email the receipt of a payment. */
    public static function sendReceipt(array $p, array $b): bool
    {
        if ($b['guestEmail'] === '') return false;
        $site = Site::info();
        $url = $p['receiptUrl'];
        $rows = [['Receipt', '<strong>' . self::esc($p['receiptNo']) . '</strong>'], ['Amount received', '<strong>' . self::money($p['amount']) . '</strong>'],
            ['Paid by', self::esc($p['methodLabel'])], ['Booking', self::esc($b['ref'])]];
        $tr = '';
        foreach ($rows as [$k, $v]) $tr .= '<tr><td style="padding:7px 0;border-bottom:1px solid #ece4d2;color:#6b6a5e;width:42%">' . $k . '</td><td style="padding:7px 0;border-bottom:1px solid #ece4d2">' . $v . '</td></tr>';
        $html = self::layout('Payment received — thank you',
            "\n    " . self::p('Dear ' . self::esc($b['guestName']) . ', we have received your payment of <strong>' . self::money($p['amount']) . '</strong>. Your receipt is below.')
            . "\n    " . '<table role="presentation" width="100%" style="font-family:Arial,sans-serif;font-size:14px;border-collapse:collapse;margin:0 0 20px">' . $tr . '</table>'
            . "\n    " . self::button($url, 'View or print the receipt →')
            . "\n    " . self::p('Warmly,<br><em>The Top Mountain team</em>'));
        self::sendQuietly(['to' => $b['guestEmail'], 'replyTo' => $site['email'], 'subject' => "Receipt {$p['receiptNo']} · {$site['name']}", 'html' => $html,
            'text' => "We received " . self::money($p['amount']) . " for booking {$b['ref']}. Receipt {$p['receiptNo']}: $url"]);
        return true;
    }

    /** Email the guest the link to check and confirm their booking (Confirmations). */
    public static function sendConfirmLink(array $b, string $url): bool
    {
        if (!Bookings::isEmail((string) $b['guestEmail'])) return false;
        $site = Site::info();
        $missing = Confirmations::missing($b);
        $ask = $missing ? ' fill in the few details we still need (' . self::esc(strtolower(implode(', ', array_map(fn ($k) => Confirmations::FIELDS[$k][2], $missing)))) . ')' : '';
        $name = Js::trim((string) $b['guestName']);
        $html = self::layout('Please confirm your booking',
            "\n    " . self::p(($name !== '' ? 'Dear ' . self::esc($name) . ', thank' : 'Thank') . ' you for booking with ' . self::esc($site['name']) . '. Please check the details of your booking <strong>' . self::esc($b['ref']) . '</strong>,' . $ask . ' and confirm it.')
            . "\n    " . self::button($url, 'Check and confirm your booking →')
            . "\n    " . self::detailsTable($b)
            . "\n    " . self::p('If anything is not right, simply reply to this email or call us on ' . self::esc($site['phone']) . '.')
            . "\n    " . self::p('Warmly,<br><em>The Top Mountain team</em>'));
        self::sendQuietly(['to' => $b['guestEmail'], 'replyTo' => $site['email'], 'subject' => "Please confirm your booking {$b['ref']} · {$site['name']}", 'html' => $html,
            'text' => "Please check and confirm your booking {$b['ref']} (" . self::what($b) . ', ' . self::when($b) . "): $url"]);
        return true;
    }

    /** A guest has confirmed their booking from its link: tell each admin address, and thank the guest (with how to pay, for a reservation). */
    public static function notifyGuestConfirmed(array $b, array $changes): void
    {
        $base = Config::get()['baseUrl'];
        $rows = '';
        foreach ($changes as $c) {
            $rows .= '<tr><td style="padding:7px 0;color:#6b6a5e;width:42%;vertical-align:top">' . self::esc($c['label']) . '</td><td style="padding:7px 0">'
                . ($c['from'] !== '' ? '<s style="color:#9a958a">' . self::esc($c['from']) . '</s> → ' : '') . str_replace("\n", '<br>', self::esc($c['to'])) . '</td></tr>';
        }
        $did = Confirmations::changesText($changes);
        $html = self::layout('Guest confirmed the booking ☑️', "\n    " . self::p(self::esc($b['guestName']) . ' has checked and confirmed booking <strong>' . self::esc($b['ref']) . '</strong> from the link you sent.'
                . ($b['status'] === 'pending' ? ' It is still a reservation: confirm it in the admin panel ' . ($b['advance']['required'] ? 'once the advance is paid.' : 'when you are ready.') : ''))
            . "\n    " . self::detailsTable($b, true)
            . "\n    " . '<h2 style="font-size:17px;font-weight:normal;margin:0 0 6px;color:#13261b">' . ($changes ? 'What the guest filled in' : 'The guest changed nothing') . '</h2>'
            . ($rows !== '' ? "\n    " . '<table role="presentation" width="100%" style="font-family:Arial,sans-serif;font-size:14px;border-collapse:collapse;margin-bottom:22px">' . $rows . '</table>' : '')
            . "\n    " . '<p style="margin:0 0 26px"><a href="' . $base . '/admin/#/bookings/' . $b['id'] . '" style="display:inline-block;background:#e8a33d;color:#13261b;text-decoration:none;font-family:Arial,sans-serif;font-weight:bold;padding:13px 22px;border-radius:999px">Open in admin panel →</a></p>');
        $text = "Guest confirmed booking {$b['ref']}: {$b['guestName']}, " . self::what($b) . ', ' . self::when($b) . ($did !== '' ? ". They $did" : '') . ".\n$base/admin/#/bookings/{$b['id']}";
        $admin = ['subject' => "Guest confirmed {$b['ref']} — {$b['guestName']}, {$b['checkIn']}", 'html' => $html, 'text' => $text];
        foreach (self::adminEmails() as $to) self::sendQuietly(['to' => $to, 'replyTo' => $b['guestEmail']] + $admin);
        if ($b['guestEmail'] === '') return;
        $mail = $b['status'] === 'confirmed' ? self::guestConfirmed($b) : self::guestReceived($b);
        self::sendQuietly(['to' => $b['guestEmail'], 'replyTo' => Site::info()['email']] + $mail);
    }

    private static function adminNewBooking(array $b): array
    {
        $base = Config::get()['baseUrl'];
        $or = fn (string $s) => $s !== '' ? $s : '—';
        $guest = [
            ['Name', self::esc($b['guestName'])],
            ['Email', '<a href="mailto:' . self::esc($b['guestEmail']) . '">' . self::esc($b['guestEmail']) . '</a>'],
            ['Phone', '<a href="tel:' . self::esc(preg_replace('/[^\d+]/', '', $b['guestPhone'])) . '">' . self::esc($b['guestPhone']) . '</a>'],
            ['NIC / passport', $or(self::esc($b['guestIdNo']))],
            ['Country', $or(self::esc($b['country']))],
            ['Arrival time', $or(self::esc($b['arrivalTime']))],
            ['Requests', $or(str_replace("\n", '<br>', self::esc($b['specialRequests'])))],
        ];
        $rows = '';
        foreach ($guest as [$k, $v]) $rows .= '<tr><td style="padding:7px 0;color:#6b6a5e;width:42%;vertical-align:top">' . $k . '</td><td style="padding:7px 0">' . $v . '</td></tr>';
        $html = self::layout('New booking request 🌿', "\n    " . self::p('A new booking just came in through the website and is waiting for your confirmation.')
            . "\n    " . self::detailsTable($b, true)
            . "\n    " . '<h2 style="font-size:17px;font-weight:normal;margin:0 0 6px;color:#13261b">Guest</h2>'
            . "\n    " . '<table role="presentation" width="100%" style="font-family:Arial,sans-serif;font-size:14px;border-collapse:collapse;margin-bottom:22px">'
            . "\n    " . $rows
            . "\n    " . '</table>'
            . "\n    " . '<p style="margin:0 0 26px"><a href="' . $base . '/admin/#/bookings/' . $b['id'] . '" style="display:inline-block;background:#e8a33d;color:#13261b;text-decoration:none;font-family:Arial,sans-serif;font-weight:bold;padding:13px 22px;border-radius:999px">Open in admin panel →</a></p>');
        $text = "New booking {$b['ref']}\n" . self::what($b) . ($b['kind'] === 'per_person' && $b['roomName'] ? " · rooms: {$b['roomName']}" : '') . "\n" . self::when($b) . "\nGuest: {$b['guestName']} / {$b['guestEmail']} / {$b['guestPhone']} / NIC-passport {$b['guestIdNo']}\nTotal: " . self::money($b['totalAmount']) . "\n$base/admin/#/bookings/{$b['id']}";
        return ['subject' => "New booking {$b['ref']} — " . self::what($b) . ", {$b['checkIn']}", 'html' => $html, 'text' => $text];
    }

    private static function guestReceived(array $b): array
    {
        $first = explode(' ', $b['guestName'])[0];
        $intro = self::isDayOut($b)
            ? 'We\'re looking forward to your <strong>' . self::esc(strtolower($b['packageLabel'])) . '</strong> on ' . self::niceDate($b['checkIn']) . '.'
            : ($b['kind'] === 'room'
                ? 'We have reserved <strong>' . self::esc($b['place'] ?: $b['roomName']) . '</strong> for you.'
                : 'We have reserved <strong>' . self::esc(strtolower($b['packageLabel'])) . '</strong> for your group of ' . ($b['adults'] + $b['children']) . ', with rooms ready for you on arrival.');
        $pay = Bookings::paymentInfo();
        $adv = $b['advance'];
        $due = $adv['amount'] ?: $b['totalAmount'];
        $html = self::layout('Thank you, ' . self::esc($first) . " — we've received your booking",
            "\n    " . self::p($intro . ' Your total is <strong>' . self::money($b['totalAmount']) . '</strong>.')
            . "\n    " . self::paymentBlock($b)
            . "\n    " . self::button(Docs::invoiceUrl($b), 'View or print your invoice →')
            . "\n    " . self::detailsTable($b)
            . "\n    " . self::p('If you have any questions, simply reply to this email or call us on ' . self::esc($pay['phone']) . '.')
            . "\n    " . self::p('See you in the hills,<br><em>The Top Mountain team</em>'));
        if (!$adv['required']) {
            return ['subject' => "Booking {$b['ref']} received — nothing to pay before you arrive", 'html' => $html,
                'text' => "Thank you! Booking {$b['ref']} for " . self::what($b) . ', ' . self::when($b) . '. Total ' . self::money($b['totalAmount'])
                    . ", paid at the resort: nothing to pay before you arrive. It is pending: we will call you to confirm it. Questions: call {$pay['phone']}."];
        }
        return ['subject' => "Booking {$b['ref']} received — please pay and call us to confirm", 'html' => $html,
            'text' => "Thank you! Booking {$b['ref']} for " . self::what($b) . ', ' . self::when($b) . '. Total ' . self::money($b['totalAmount']) . '. It is pending: please '
                . ($pay['accountNo'] !== '' ? 'pay ' . self::money($due) . " to {$pay['bank']} account {$pay['accountNo']} ({$pay['accountName']}), reference {$b['ref']}, then " : '')
                . "call {$pay['phone']} to confirm."];
    }

    private static function guestConfirmed(array $b): array
    {
        $s = Site::info();
        $loc = Config::location();
        $html = self::layout(self::isDayOut($b) ? 'Your day out is confirmed ✨' : 'Your stay is confirmed ✨',
            "\n    " . self::p('Dear ' . self::esc($b['guestName']) . ', your booking at ' . self::esc($s['name']) . " is <strong>confirmed</strong>. We can't wait to welcome you.")
            . "\n    " . self::detailsTable($b)
            . "\n    " . self::p('<strong>Getting here:</strong> ' . self::esc($s['address']) . '. '
                . '<a href="' . self::esc($loc['directions']) . '" style="color:#3f6b36;font-weight:bold">Directions on Google Maps</a>'
                . ($loc['plusCode'] !== '' ? ' (plus code ' . self::esc($loc['plusCode']) . ')' : '')
                . ". Let us know your arrival time and we'll have a cup of Ceylon tea waiting for you.")
            . "\n    " . self::p('Warmly,<br><em>The Top Mountain team</em>'));
        return ['subject' => "Booking confirmed — {$b['ref']} · {$s['name']}", 'html' => $html,
            'text' => "Your booking {$b['ref']} (" . self::what($b) . ', ' . self::when($b) . ') is confirmed. Directions: ' . $loc['directions']];
    }

    private static function guestCancelled(array $b): array
    {
        $phone = self::esc(Site::info()['phone']);
        $what = self::isDayOut($b)
            ? 'your ' . self::esc(strtolower($b['packageLabel'])) . ' on ' . self::niceDate($b['checkIn'])
            : self::esc($b['kind'] === 'room' ? (string) ($b['place'] ?: $b['roomName']) : $b['packageLabel']) . ' (' . self::niceDate($b['checkIn']) . ' – ' . self::niceDate($b['checkOut']) . ')';
        $html = self::layout('Your booking has been cancelled',
            "\n    " . self::p('Dear ' . self::esc($b['guestName']) . ', your booking <strong>' . self::esc($b['ref']) . "</strong> for $what has been cancelled.")
            . "\n    " . self::p("If this is unexpected or you'd like to choose new dates, please contact us on $phone or reply to this email."));
        return ['subject' => "Booking cancelled — {$b['ref']}", 'html' => $html, 'text' => "Your booking {$b['ref']} has been cancelled."];
    }

    public const MAX_ADMIN_EMAILS = 10;

    /**
     * Who is emailed about each new website booking: the list saved in Admin → Settings, or
     * ADMIN_NOTIFY_EMAILS from .env until a list has been saved there.
     */
    public static function adminEmails(): array
    {
        $saved = Settings::get('notify_emails');
        if ($saved === '') return Config::get()['admin']['notifyEmails'];
        $list = json_decode($saved, true);
        return is_array($list) ? array_values(array_filter($list, fn ($e) => is_string($e) && $e !== '')) : [];
    }

    /** For Admin → Settings: the addresses, and whether they still come from .env. */
    public static function adminEmailsInfo(): array
    {
        return ['emails' => self::adminEmails(), 'fromEnv' => Settings::get('notify_emails') === '', 'max' => self::MAX_ADMIN_EMAILS];
    }

    /** Replace the list of admin addresses (empty entries are skipped, repeats kept once). */
    public static function saveAdminEmails(mixed $in): array
    {
        if (!is_array($in)) throw new BookingError('Send the list of email addresses.');
        $out = [];
        foreach ($in as $e) {
            if (!is_string($e)) throw new BookingError('Each email address must be text.');
            $e = trim($e);
            if ($e === '') continue;
            if (strlen($e) > 254 || !filter_var($e, FILTER_VALIDATE_EMAIL)) throw new BookingError('"' . Js::slice($e, 60) . '" is not an email address.');
            $out[strtolower($e)] ??= $e;
        }
        if (count($out) > self::MAX_ADMIN_EMAILS) throw new BookingError('Up to ' . self::MAX_ADMIN_EMAILS . ' email addresses.');
        Settings::set(['notify_emails' => json_encode(array_values($out), JSON_UNESCAPED_UNICODE)]);
        return self::adminEmailsInfo();
    }

    /** The guest sent a payment slip from their invoice link: tell each admin address (the slip opens in the admin panel). */
    public static function notifyGuestSlip(array $b, array $slip): void
    {
        $base = Config::get()['baseUrl'];
        $paid = $slip['amount'] ? 'They say they paid <strong>' . self::money($slip['amount']) . '</strong>.' : 'They did not give the amount.';
        $next = $b['status'] === 'pending' ? 'Check it against the bank account, then confirm the booking with the advance — the slip is offered there.'
            : 'Check it against the bank account, then record the payment — the slip is offered there.';
        $html = self::layout('Payment slip received 🧾', "\n    " . self::p(self::esc($b['guestName']) . ' sent a payment slip for booking <strong>' . self::esc($b['ref']) . '</strong>. ' . $paid
                . ($slip['note'] !== '' ? ' Their note: “' . self::esc($slip['note']) . '”.' : '') . ' ' . $next)
            . "\n    " . self::detailsTable($b, true)
            . "\n    " . '<p style="margin:0 0 26px"><a href="' . $base . '/admin/#/bookings/' . $b['id'] . '" style="display:inline-block;background:#e8a33d;color:#13261b;text-decoration:none;font-family:Arial,sans-serif;font-weight:bold;padding:13px 22px;border-radius:999px">Open in admin panel →</a></p>');
        $text = "Payment slip received for {$b['ref']} from {$b['guestName']}" . ($slip['amount'] ? ' (' . number_format($slip['amount']) . ')' : '') . ".\n$base/admin/#/bookings/{$b['id']}";
        $admin = ['subject' => "Payment slip for {$b['ref']} — {$b['guestName']}", 'html' => $html, 'text' => $text];
        foreach (self::adminEmails() as $to) self::sendQuietly(['to' => $to, 'replyTo' => $b['guestEmail']] + $admin);
    }

    public static function notifyNewBooking(array $b): void
    {
        // one email per address, so a mistyped address can't stop the others getting theirs
        $admin = self::adminNewBooking($b);
        foreach (self::adminEmails() as $to) self::sendQuietly(['to' => $to, 'replyTo' => $b['guestEmail']] + $admin);
        if ($b['guestEmail'] !== '') self::sendQuietly(['to' => $b['guestEmail'], 'replyTo' => Site::info()['email']] + self::guestReceived($b));
    }

    public static function notifyStatus(array $b): bool
    {
        if ($b['guestEmail'] === '') return false;
        $replyTo = Site::info()['email'];
        if ($b['status'] === 'confirmed') { self::sendQuietly(['to' => $b['guestEmail'], 'replyTo' => $replyTo] + self::guestConfirmed($b)); return true; }
        if ($b['status'] === 'cancelled') { self::sendQuietly(['to' => $b['guestEmail'], 'replyTo' => $replyTo] + self::guestCancelled($b)); return true; }
        return false;
    }

    /** "See you in 2 days" / "tomorrow" / "today" for a reminder sent $days before $date. */
    private static function inDays(int $days): string
    {
        return $days <= 0 ? 'today' : ($days === 1 ? 'tomorrow' : "in $days days");
    }

    /** Reminder for a confirmed booking: arrival details a few days before check-in. */
    public static function sendArrivalReminder(array $b, int $daysLeft): bool
    {
        if ($b['guestEmail'] === '') return false;
        $s = Site::info();
        $loc = Config::location();
        $first = explode(' ', $b['guestName'])[0];
        $when = self::inDays($daysLeft);
        $html = self::layout('See you ' . $when . ', ' . self::esc($first) . ' 🌿',
            "\n    " . self::p('This is a friendly reminder that your booking at ' . self::esc($s['name']) . ' starts <strong>' . $when . '</strong> (' . self::niceDate($b['checkIn']) . ').')
            . "\n    " . self::detailsTable($b)
            . "\n    " . self::p('<strong>Getting here:</strong> ' . self::esc($s['address']) . '. <a href="' . self::esc($loc['directions']) . '" style="color:#3f6b36;font-weight:bold">Directions on Google Maps</a>'
                . ($loc['plusCode'] !== '' ? ' (plus code ' . self::esc($loc['plusCode']) . ')' : '') . '.')
            . "\n    " . self::p('If your plans change or you would like to tell us your arrival time, simply reply to this email or call us on ' . self::esc($s['phone']) . '.')
            . "\n    " . self::p('Warmly,<br><em>The Top Mountain team</em>'));
        self::sendQuietly(['to' => $b['guestEmail'], 'replyTo' => $s['email'], 'subject' => "Reminder: your stay starts $when · {$b['ref']}", 'html' => $html,
            'text' => "Reminder: booking {$b['ref']} (" . self::what($b) . ', ' . self::when($b) . ") starts $when. Directions: " . $loc['directions']]);
        return true;
    }

    /** Reminder for a pending reservation: the advance is due before the hold runs out. */
    public static function sendPaymentReminder(array $b, int $daysLeft): bool
    {
        if ($b['guestEmail'] === '') return false;
        $s = Site::info();
        $first = explode(' ', $b['guestName'])[0];
        $deadline = $b['holdUntil'] ? self::niceDate($b['holdUntil']) : '';
        $html = self::layout('Your reservation is waiting, ' . self::esc($first),
            "\n    " . self::p('We are holding your reservation at ' . self::esc($s['name']) . ($deadline !== '' ? ' until <strong>' . $deadline . '</strong>. After that day it is released so that others can book those dates.' : ' for you, and it is still waiting for the advance payment.'))
            . "\n    " . self::paymentBlock($b)
            . "\n    " . self::button(Docs::invoiceUrl($b), 'View or print your invoice →')
            . "\n    " . self::detailsTable($b)
            . "\n    " . self::p('If you have already paid, thank you: please ignore this reminder, or call us on ' . self::esc($s['phone']) . ' and we will check.')
            . "\n    " . self::p('Warmly,<br><em>The Top Mountain team</em>'));
        self::sendQuietly(['to' => $b['guestEmail'], 'replyTo' => $s['email'], 'subject' => ($deadline !== '' ? "Reminder: your reservation is held until $deadline" : 'Reminder: your reservation is waiting for payment') . " · {$b['ref']}", 'html' => $html,
            'text' => "Reminder: reservation {$b['ref']} is waiting for its payment" . ($deadline !== '' ? " (held until $deadline)" : '') . ". Please pay the advance and call " . Bookings::paymentInfo()['phone'] . ' to confirm it.']);
        return true;
    }

    /** Email an issued bill to the guest. Returns false when there is no email address. */
    public static function sendBill(array $bill): bool
    {
        $s = $bill['snapshot'];
        $to = (string) ($s['guest']['email'] ?? '');
        if ($to === '') return false;
        $site = Site::info();
        $td = 'padding:7px 0;border-bottom:1px solid #ece4d2';
        $row = fn (string $what, string $sub, int $amount) => '<tr><td style="' . $td . '">' . $what . ($sub !== '' ? '<br><span style="color:#6b6a5e;font-size:12px">' . $sub . '</span>' : '')
            . '</td><td style="' . $td . ';text-align:right;white-space:nowrap;vertical-align:top">' . self::money($amount) . '</td></tr>';
        $rows = '';
        foreach ($s['stays'] as $st) {
            $when = $st['package'] === 'day_out' ? self::niceDate($st['checkIn']) : self::niceDate($st['checkIn']) . ' – ' . self::niceDate($st['checkOut']) . ' · ' . $st['nights'] . ' night' . ($st['nights'] === 1 ? '' : 's');
            $rows .= $row(self::esc($st['place']), self::esc(implode(' · ', array_merge([$when], $st['extras']))), (int) $st['amount']);
        }
        foreach ($s['lines'] as $l) {
            $rows .= $row($l['qty'] . ' × ' . self::esc($l['name']), self::money($l['unitPrice']) . ' each', (int) $l['amount']);
        }
        $t = $s['totals'];
        if ($t['discount']) $rows .= $row('Discount' . (($t['discountLabel'] ?? '') !== '' ? ' (' . self::esc($t['discountLabel']) . ')' : ''), '', -$t['discount']);
        if (!empty($t['coupon'])) $rows .= $row('Coupon ' . self::esc($t['coupon']['code']), self::esc($t['coupon']['label']), -(int) $t['coupon']['discount']);
        if (!empty($t['service'])) $rows .= $row('Service charge' . (!empty($t['servicePct']) ? ' (' . (int) $t['servicePct'] . '%)' : ''), '', (int) $t['service']);
        $rows .= '<tr><td style="padding:10px 0"><strong>Total</strong></td><td style="padding:10px 0;text-align:right"><strong>' . self::money($t['total']) . '</strong></td></tr>';
        foreach ($bill['payments'] as $p) $rows .= $row('Paid · ' . self::esc($p['methodLabel']), '', -$p['amount']);
        $rows .= '<tr><td style="padding:10px 0"><strong>' . ($bill['balance'] > 0 ? 'Balance due' : 'Balance') . '</strong></td><td style="padding:10px 0;text-align:right"><strong>' . self::money($bill['balance']) . '</strong></td></tr>';
        $html = self::layout('Your bill ' . self::esc($bill['billNo']),
            "\n    " . self::p('Dear ' . self::esc($s['guest']['name']) . ', thank you for visiting ' . self::esc($site['name']) . '. Here is your bill.')
            . "\n    " . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:Arial,sans-serif;font-size:14px;border-collapse:collapse;margin:8px 0 20px">' . $rows . '</table>'
            . (!empty($bill['billUrl']) ? "\n    " . self::button($bill['billUrl'], 'View or print the bill →') : '')
            . "\n    " . self::p('We hope to see you in the hills again soon,<br><em>The Top Mountain team</em>'));
        self::sendQuietly(['to' => $to, 'replyTo' => $site['email'], 'subject' => "Your bill {$bill['billNo']} · {$site['name']}", 'html' => $html,
            'text' => "Bill {$bill['billNo']}: total " . self::money($t['total']) . ', balance ' . self::money($bill['balance']) . '.']);
        return true;
    }

    public static function testEmail(mixed $to): array
    {
        $name = Config::get()['site']['name'];
        return self::send(['to' => $to, 'subject' => "Test email from $name",
            'html' => self::layout('Email is working 🌿', self::p('This is a test message from your booking system. New booking alerts will arrive just like this.')),
            'text' => 'Email is working.']);
    }
}
