<?php
// Documents guests get: the payment invoice for a reservation (what to pay, where, and by
// when), a receipt for every payment and the bill from checkout. Each has a private link (a
// code made from the site's secret) the guest can open, print or save as a PDF; staff print
// them from the admin panel or the POS. All three use the same page, styles and print settings,
// on A5 paper by default (Admin → Settings → Printing).
declare(strict_types=1);

namespace Ydr;

final class Docs
{
    public const PAPERS = ['A5' => 'A5 (148 × 210 mm)', 'A4' => 'A4 (210 × 297 mm)'];

    public static function paper(): string
    {
        $p = Settings::get('print_paper');
        return isset(self::PAPERS[$p]) ? $p : 'A5';
    }

    public static function savePaper(mixed $p): string
    {
        if (!is_string($p) || !isset(self::PAPERS[$p])) throw new BookingError('Choose A5 or A4 paper.');
        Settings::set(['print_paper' => $p]);
        return $p;
    }

    // ---------- private links ----------
    private static function code(string $what): string
    {
        return substr(hash_hmac('sha256', $what, Config::get()['sessionSecret']), 0, 24);
    }

    public static function invoiceUrl(array $b): string
    {
        return Config::get()['baseUrl'] . self::invoicePath($b);
    }

    /** "/invoice/REF/code" (the guest's payment slips go to /api + this + /slip). */
    public static function invoicePath(array $b): string
    {
        return '/invoice/' . rawurlencode($b['ref']) . '/' . self::code("invoice|{$b['id']}|{$b['ref']}");
    }

    public static function receiptUrl(int $paymentId): string
    {
        return Config::get()['baseUrl'] . '/receipt/' . Pos::receiptNo($paymentId) . '/' . self::code("receipt|$paymentId");
    }

    public static function billUrl(int $billId): string
    {
        return Config::get()['baseUrl'] . '/bill/' . Pos::billNo($billId) . '/' . self::code("bill|$billId");
    }

    /** The bill of a bill link, or null. */
    public static function billByLink(string $no, string $code): ?array
    {
        if (!preg_match('/^INV-0*(\d+)$/i', $no, $m)) return null;
        $id = (int) $m[1];
        if (!hash_equals(self::code("bill|$id"), $code)) return null;
        return Pos::getBill($id);
    }

    /** The booking of an invoice link, or null when the link is wrong. */
    public static function invoiceBooking(string $ref, string $code): ?array
    {
        $b = Bookings::getByRef($ref);
        if (!$b || $b['source'] === 'block') return null;
        return hash_equals(self::code("invoice|{$b['id']}|{$b['ref']}"), $code) ? $b : null;
    }

    /** The payment of a receipt link, or null. */
    public static function receiptPayment(string $no, string $code): ?array
    {
        if (!preg_match('/^RCT-0*(\d+)$/i', $no, $m)) return null;
        $id = (int) $m[1];
        if (!hash_equals(self::code("receipt|$id"), $code)) return null;
        return Pos::payment($id);
    }

    // ---------- helpers ----------
    private static function esc(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function money(int $n): string
    {
        return Site::info()['currency'] . ' ' . number_format($n);
    }

    private static function date(string $d, string $fmt = 'D j M Y'): string
    {
        return (new \DateTimeImmutable($d . ' 00:00:00', new \DateTimeZone('UTC')))->format($fmt);
    }

    private static function dateTime(string $iso): string
    {
        return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone(Config::get()['timezone']))->format('j M Y, H:i');
    }

    private static function guests(array $b): string
    {
        if ($b['kind'] === 'event') return ($b['adults'] + $b['children']) . ' guests' . ($b['rooms'] ? ' · rooms held: ' . $b['roomName'] : '');
        if (!$b['adults'] && !$b['children']) return $b['capacity'] ? 'Sleeps ' . $b['capacity'] : '';
        return $b['adults'] . ' adult' . ($b['adults'] === 1 ? '' : 's') . ($b['children'] ? ', ' . $b['children'] . ' child' . ($b['children'] === 1 ? '' : 'ren') : '');
    }

    private static function when(array $b): string
    {
        if ($b['kind'] === 'event') return self::date($b['checkIn']) . ' · ' . strtolower($b['packageLabel']) . ($b['arrivalTime'] !== '' ? ' · ' . $b['arrivalTime'] : '');
        return $b['kind'] === 'day_out' ? self::date($b['checkIn']) . ' · day visit'
            : self::date($b['checkIn']) . ' → ' . self::date($b['checkOut']) . ' · ' . $b['nights'] . ' night' . ($b['nights'] === 1 ? '' : 's');
    }

    /** 20000 → "Twenty thousand" (for "rupees only" on receipts). */
    public static function words(int $n): string
    {
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        $below1000 = function (int $n) use ($ones, $tens): string {
            $out = [];
            if ($n >= 100) { $out[] = $ones[intdiv($n, 100)] . ' hundred'; $n %= 100; }
            if ($n >= 20) { $out[] = $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : ''); }
            elseif ($n > 0) $out[] = $ones[$n];
            return implode(' and ', $out);
        };
        if ($n === 0) return 'Zero';
        $parts = [];
        foreach ([1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand'] as $unit => $name) {
            if ($n >= $unit) { $parts[] = $below1000(intdiv($n, $unit)) . " $name"; $n %= $unit; }
        }
        if ($n) $parts[] = $below1000($n);
        return ucfirst(implode(' ', $parts));
    }

    /** A CSS string: quotes and backslashes escaped, and < > too, since it sits inside a <style>. */
    private static function cssString(string $s): string
    {
        $s = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s);
        return '"' . strtr($s, ['\\' => '\\\\', '"' => '\\"', '<' => '\\3c ', '>' => '\\3e ']) . '"';
    }

    /**
     * The paper size, and what prints in the page margins: the date and time on the left and who
     * issued it on the right. Giving the margins our own content stops the browser printing its
     * header and footer (the page title, the link and page numbers). docs.js and staff.js redo this
     * when printing, with the time of printing.
     */
    public static function pageCss(string $paper, string $printedAt, string $issuedBy): string
    {
        $paper = isset(self::PAPERS[$paper]) ? $paper : 'A5';
        $box = 'font: 7.5pt/1.2 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #555;';
        return '@page { size: ' . $paper . '; margin: ' . ($paper === 'A5' ? '9mm' : '14mm') . ';'
            . ' @top-left { content: ' . self::cssString($printedAt) . "; $box }"
            . ' @top-right { content: ' . self::cssString($issuedBy !== '' ? "Issued by $issuedBy" : '') . "; $box }"
            . ' @bottom-center { content: ""; } }';
    }

    /** "17 Nov 2026, 2:43 pm" in the resort's time zone (as docs.js writes it). */
    public static function printedAt(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(Config::get()['timezone'])))->format('j M Y, g:i a');
    }

    /** The page around a document: print toolbar (not when shown inside the admin panel), A5 or A4. */
    private static function page(string $title, string $body, bool $embed, string $issuedBy = ''): string
    {
        $site = Site::info();
        $paper = self::paper();
        $bar = $embed ? '' : '<div class="doc-bar no-print"><span>' . self::esc($site['name']) . '</span>'
            . '<span class="doc-bar__btns"><button type="button" class="doc-btn" data-download>Download PDF</button>'
            . '<button type="button" class="doc-btn doc-btn--ghost" data-print>Print</button></span></div>';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>' . self::esc($title . ' — ' . $site['name']) . '</title>'
            . '<link rel="icon" href="/media/icon-32.png" sizes="32x32"><link rel="stylesheet" href="/css/docs.css">'
            . '<style id="doc-page">' . self::pageCss($paper, self::printedAt(), $issuedBy) . '</style></head>'
            . '<body class="doc-body paper-' . strtolower($paper) . ($embed ? ' is-embed' : '') . '" data-paper="' . $paper . '"'
            . ' data-issued-by="' . self::esc($issuedBy) . '" data-tz="' . self::esc(Config::get()['timezone']) . '">' . $bar . $body
            . ($embed ? '' : '<script src="/js/pdf.js"></script>') . '<script src="/js/docs.js"></script></body></html>';
    }

    private static function head(string $kind, string $no, string $date): string
    {
        $s = Site::info();
        return '<header class="doc__head"><div class="doc__brand"><img src="/media/logo.png" alt="" width="150"><div><b>' . self::esc($s['name']) . '</b>'
            . '<span>' . self::esc($s['address']) . '</span><span>' . self::esc($s['phone']) . ' · ' . self::esc($s['email']) . '</span></div></div>'
            . '<div class="doc__no"><small>' . self::esc($kind) . '</small><b>' . self::esc($no) . '</b><span>' . self::esc($date) . '</span></div></header>';
    }

    // ---------- the payment invoice ----------
    /**
     * What the guest owes now: ['paid', 'due', 'dueLabel', 'advance' (an advance smaller than the
     * total, 0 = none), 'atResort' (a booking that asks for no advance: it is all paid at the resort)]
     */
    public static function amounts(array $b): array
    {
        $paid = 0;
        foreach (Pos::bookingPayments($b['id']) as $p) if (!$p['voided']) $paid += $p['amount'];
        $adv = $b['advance'];
        $balance = max(0, $b['totalAmount'] - $paid);
        if (!$adv['required'] && in_array($b['status'], ['pending', 'confirmed'], true)) {
            return ['paid' => $paid, 'due' => $balance, 'dueLabel' => 'To pay at the resort', 'advance' => 0, 'atResort' => true];
        }
        $advance = $adv['full'] ? 0 : $adv['amount'];
        if ($b['status'] === 'pending' && $advance > 0 && $paid < $advance) {
            $label = 'Advance to pay now' . ($adv['pct'] !== null ? ' (' . $adv['pct'] . '%)' : '');
            return ['paid' => $paid, 'due' => $advance - $paid, 'dueLabel' => $label, 'advance' => $advance, 'atResort' => false];
        }
        return ['paid' => $paid, 'due' => $balance, 'dueLabel' => $b['status'] === 'pending' ? 'Amount to pay' : 'Balance due', 'advance' => $advance, 'atResort' => false];
    }

    public static function invoiceHtml(array $b, bool $embed = false): string
    {
        $site = Site::info();
        $pay = Bookings::paymentInfo();
        $a = self::amounts($b);
        $cancelled = $b['status'] === 'cancelled';
        $title = $b['status'] === 'pending' ? 'Reservation invoice' : 'Invoice';
        $status = match ($b['status']) {
            'pending' => $a['atResort'] ? 'Reservation · nothing to pay before you arrive · we call you to confirm it'
                : 'Reservation' . ($b['holdUntil'] ? ' · held until <b>' . self::esc(self::date($b['holdUntil'])) . '</b>' : '') . ' · confirmed once the advance is paid',
            'confirmed' => 'Confirmed booking',
            'checked_in' => 'Staying with us now',
            'checked_out' => 'Stay completed',
            default => 'Cancelled',
        };
        $rows = '';
        foreach (Bookings::priceLines($b) as $l) {
            $detail = $l['unit'] ? self::money($l['unit']) . ' × ' . $l['detail'] : $l['detail'];
            $rows .= '<tr><td>' . self::esc($l['label']) . '<span class="sub">' . self::esc($detail) . '</span></td><td class="num">' . self::money($l['amount']) . '</td></tr>';
        }
        if ($b['couponDiscount']) {
            $rows .= '<tr><td>Coupon ' . self::esc($b['couponCode']) . '<span class="sub">Off the room charge</span></td><td class="num">− ' . self::money($b['couponDiscount']) . '</td></tr>';
        }
        $who = '<div><small>Billed to</small><b>' . self::esc($b['guestName']) . '</b>'
            . ($b['guestPhone'] !== '' ? '<span>' . self::esc($b['guestPhone']) . '</span>' : '')
            . ($b['guestEmail'] !== '' ? '<span>' . self::esc($b['guestEmail']) . '</span>' : '')
            . ($b['guestIdNo'] !== '' ? '<span>NIC / passport ' . self::esc($b['guestIdNo']) . '</span>' : '') . '</div>';
        $what = $b['kind'] === 'room' ? (string) ($b['place'] ?: $b['roomName']) : $b['packageLabel'];
        $stay = '<div><small>' . ($b['kind'] === 'day_out' ? 'Visit' : ($b['kind'] === 'event' ? 'Party' : 'Stay')) . '</small><b>' . self::esc($what) . '</b><span>' . self::esc(self::when($b)) . '</span>'
            . (self::guests($b) !== '' ? '<span>' . self::esc(self::guests($b)) . '</span>' : '') . '</div>';
        $advanceNow = !$cancelled && $b['status'] === 'pending' && $a['advance'] > 0 && $a['paid'] < $a['advance'];
        $totals = '<div class="is-total"><span>Total</span><b>' . self::money($b['totalAmount']) . '</b></div>'
            . ($a['paid'] ? '<div><span>Paid so far</span><span>− ' . self::money($a['paid']) . '</span></div>' : '')
            . (!$cancelled ? '<div class="is-due"><span>' . self::esc($a['dueLabel']) . '</span><b>' . self::money($a['due']) . '</b></div>' : '')
            . ($advanceNow ? '<div><span>Balance on arrival</span><span>' . self::money(max(0, $b['totalAmount'] - $a['paid'] - $a['due'])) . '</span></div>' : '');
        $service = Pos::servicePct();
        $note = ($service ? "A $service% service charge is added to the final bill at the resort. " : '') . 'Food and drinks ordered during the stay are billed separately.';
        $hasBank = $pay['accountNo'] !== '';
        // the guest sends their payment slip from here (on screen only: not inside the admin panel, never printed)
        $toTransfer = !$cancelled && !$a['atResort'] && $a['due'] > 0 && in_array($b['status'], ['pending', 'confirmed'], true);
        $sent = Pos::guestSlipsPublic($b['id']);
        $slipBox = !$embed && Pos::takesGuestSlips($b) && ($toTransfer || $sent) ? self::slipBox($b, $sent, $toTransfer ? $a['due'] : 0) : '';
        $payBox = '';
        if (!$cancelled && $a['atResort']) {
            // no advance: nothing to transfer, it is all settled at the resort
            $payBox = '<section class="doc__pay"><h3>Payment</h3><p>Nothing to pay before you arrive: '
                . ($a['due'] > 0 ? 'the <b>' . self::money($a['due']) . '</b> is paid at the resort.' : 'there is nothing left to pay.')
                . ' For any questions, or if your plans change, call <b>' . self::esc($pay['phone']) . '</b> quoting <b>' . self::esc($b['ref']) . '</b>.</p>'
                . ($pay['note'] !== '' ? '<p class="doc__small">' . nl2br(self::esc($pay['note'])) . '</p>' : '') . '</section>';
        } elseif (!$cancelled && $a['due'] > 0 && in_array($b['status'], ['pending', 'confirmed'], true)) {
            $dl = '';
            foreach ([['Bank', $pay['bank']], ['Account name', $pay['accountName']], ['Account number', $pay['accountNo']], ['Branch', $pay['branch']], ['Payment reference', $b['ref']]] as [$k, $v]) {
                if ($v !== '') $dl .= '<dt>' . $k . '</dt><dd>' . self::esc($v) . '</dd>';
            }
            $by = $b['status'] === 'pending' && $b['holdUntil'] ? ' by <b>' . self::esc(self::date($b['holdUntil'], 'l j F Y')) . '</b>' : '';
            $ask = $hasBank
                ? "Please pay <b>" . self::money($a['due']) . "</b>$by to the account below, quoting <b>" . self::esc($b['ref']) . '</b>, then '
                    . ($slipBox !== '' ? '<span class="no-print">send us the payment slip below, or </span>' : '') . 'call <b>' . self::esc($pay['phone']) . '</b> to confirm.'
                : 'Please call <b>' . self::esc($pay['phone']) . "</b>$by to arrange the payment of <b>" . self::money($a['due']) . '</b>.';
            $release = $b['status'] === 'pending' && $b['holdUntil'] ? ' If the advance isn\'t received by then, the dates are released.' : '';
            $payBox = '<section class="doc__pay"><h3>How to pay</h3><p>' . $ask . $release . '</p>' . ($hasBank ? '<dl>' . $dl . '</dl>' : '')
                . ($pay['note'] !== '' ? '<p class="doc__small">' . nl2br(self::esc($pay['note'])) . '</p>' : '') . '</section>';
        }
        $body = '<article class="doc' . ($cancelled ? ' is-void' : '') . '">'
            . self::head($title, $b['ref'], self::date(Bookings::today(), 'j M Y'))
            . '<p class="doc__status">' . $status . '</p>'
            . '<div class="doc__to">' . $who . $stay . '</div>'
            . '<table class="doc__tbl"><thead><tr><th>Description</th><th class="num">Amount</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<div class="doc__totals">' . $totals . '</div>'
            . '<p class="doc__small">' . self::esc($note) . '</p>'
            . $payBox
            . $slipBox
            . '<footer class="doc__foot">Thank you for choosing ' . self::esc($site['name']) . ' · ' . self::esc($site['phone']) . '</footer>'
            . ($cancelled ? '<div class="doc__void" aria-hidden="true">CANCELLED</div>' : '')
            . '</article>';
        // issued by: the staff member who made the booking (website bookings: nobody); staff printing it put their own name
        $by = (string) ($b['createdBy'] ?? 'guest');
        $issuedBy = str_starts_with($by, 'admin:') ? Auth::nameOf(substr($by, 6)) : '';
        return self::page("$title {$b['ref']}", $body, $embed, $issuedBy);
    }

    /** One slip the guest sent, as the invoice lists it (docs.js writes the same after an upload). */
    private static function slipItem(array $s): string
    {
        return '<li><b>Sent ' . self::esc(self::dateTime($s['uploadedAt'])) . '</b>' . ($s['amount'] ? ' · ' . self::money($s['amount']) : '')
            . ($s['note'] !== '' ? ' · ' . self::esc($s['note']) : '')
            . ' <span class="' . ($s['recorded'] ? 'is-ok">Payment recorded ✓' : 'is-wait">We are checking it') . '</span></li>';
    }

    /** "Paid already? Send us the payment slip": the slips sent so far and the upload form ($due: the amount to suggest). */
    private static function slipBox(array $b, array $sent, int $due): string
    {
        $types = 'image/jpeg,image/png,image/webp,image/gif,application/pdf';
        $cur = (string) (Site::info()['currency'] ?? 'LKR');
        $then = $b['status'] === 'pending' ? 'confirm your booking' : 'record your payment';
        return '<section class="doc__slip no-print" id="slip" data-slip-form data-url="' . self::esc('/api' . self::invoicePath($b) . '/slip') . '"'
            . ' data-currency="' . self::esc($cur) . '">'
            . '<h3>' . ($sent ? 'Your payment slips' : 'Paid already? Send us the payment slip') . '</h3>'
            . '<p>Upload a photo or PDF of the bank slip or transfer receipt. We check it and ' . $then . ' — you don\'t need to call.</p>'
            . '<ul class="doc__slips" data-slip-list>' . implode('', array_map([self::class, 'slipItem'], $sent)) . '</ul>'
            . '<form class="doc__slip-form" data-slip-upload novalidate>'
            . '<label class="doc__fld"><span>' . ($sent ? 'Another payment slip' : 'Payment slip') . '</span><input type="file" name="slip" accept="' . $types . '"></label>'
            . '<div class="doc__fields">'
            . '<label class="doc__fld"><span>Amount paid (' . self::esc($cur) . ') <small>optional</small></span><input type="number" name="amount" min="1" step="1" inputmode="numeric"' . ($due > 0 ? ' value="' . $due . '"' : '') . '></label>'
            . '<label class="doc__fld"><span>Note <small>optional</small></span><input name="note" maxlength="255" placeholder="e.g. Paid from BOC, ref 4411"></label>'
            . '</div>'
            . '<div class="doc__slip-foot"><button class="doc-btn" type="submit">Send the slip</button><p class="doc__slip-msg" role="status" aria-live="polite" data-slip-msg></p></div>'
            . '</form></section>';
    }

    // ---------- receipts ----------
    /** The totals a receipt shows: what the payment was towards and what is left. */
    private static function receiptTotals(array $p, array $b): array
    {
        if ($p['billId'] !== null) {
            $bill = Pos::getBill($p['billId']);
            if ($bill) {
                $paid = 0;
                foreach ($bill['payments'] as $x) if ($x['id'] <= $p['id']) $paid += $x['amount'];
                return ['for' => 'Bill ' . $bill['billNo'], 'total' => $bill['total'], 'paid' => $paid, 'balance' => $bill['total'] - $paid];
            }
        }
        $folio = Pos::folio($b['id']);
        $total = in_array($b['status'], ['checked_in', 'checked_out'], true) ? $folio['totals']['total'] : $b['totalAmount'];
        $paid = 0;
        foreach (Pos::bookingPayments($b['id']) as $x) if (!$x['voided'] && $x['billId'] === null && $x['id'] <= $p['id']) $paid += $x['amount'];
        return ['for' => in_array($b['status'], ['pending', 'confirmed'], true) ? 'Advance for booking ' . $b['ref'] : 'Payment for booking ' . $b['ref'], 'total' => $total, 'paid' => $paid, 'balance' => $total - $paid];
    }

    public static function receiptHtml(array $p, bool $embed = false): string
    {
        $site = Site::info();
        $b = Bookings::getBooking($p['bookingId']);
        $t = self::receiptTotals($p, $b);
        $what = $b['kind'] === 'room' ? (string) ($b['place'] ?: $b['roomName']) : $b['packageLabel'];
        $rupees = $site['currency'] === 'LKR' ? ' rupees only' : ' only';
        $kv = [
            ['Received from', self::esc($b['guestName']) . ($b['guestIdNo'] !== '' ? ' <span class="sub-in">(NIC / passport ' . self::esc($b['guestIdNo']) . ')</span>' : '')],
            ['For', self::esc($t['for']) . '<span class="sub">' . self::esc($what . ' · ' . self::when($b)) . '</span>'],
            ['Paid by', self::esc($p['methodLabel'])],
        ];
        foreach ($p['details'] as $d) $kv[] = [self::esc($d['label']), self::esc($d['value'])];
        if ($p['note'] !== '') $kv[] = ['Note', self::esc($p['note'])];
        $dl = '';
        foreach ($kv as [$k, $v]) $dl .= '<dt>' . $k . '</dt><dd>' . $v . '</dd>';
        $body = '<article class="doc doc--receipt' . ($p['voided'] ? ' is-void' : '') . '">'
            . self::head('Receipt', $p['receiptNo'], self::dateTime($p['createdAt']))
            . '<div class="doc__amount"><small>Amount received</small><b>' . self::money($p['amount']) . '</b><span>' . self::esc(self::words($p['amount']) . $rupees) . '</span></div>'
            . '<dl class="doc__kv">' . $dl . '</dl>'
            . '<div class="doc__totals"><div><span>' . self::esc(str_starts_with($t['for'], 'Bill') ? 'Bill total' : 'Booking total') . '</span><span>' . self::money($t['total']) . '</span></div>'
            . '<div><span>Paid to date</span><span>' . self::money($t['paid']) . '</span></div>'
            . '<div class="is-due"><span>' . ($t['balance'] > 0 ? 'Balance due' : 'Balance') . '</span><b>' . self::money(max(0, $t['balance'])) . '</b></div></div>'
            . '<div class="doc__sign"><span>Received by ' . self::esc($p['createdByName']) . '</span><span class="doc__line">Signature</span></div>'
            . '<footer class="doc__foot">Thank you · ' . self::esc($site['name']) . ' · ' . self::esc($site['phone']) . '</footer>'
            . ($p['voided'] ? '<div class="doc__void" aria-hidden="true">VOID</div>' : '')
            . '</article>';
        return self::page('Receipt ' . $p['receiptNo'], $body, $embed, $p['createdByName']);
    }

    // ---------- bills (POS checkout) ----------
    private const LEGACY_CATEGORIES = ['food' => 'Food', 'beverage' => 'Beverages', 'other' => 'Other']; // bills from before categories were editable

    /** A bill from checkout (Pos::getBill), drawn from what was saved when it was issued. */
    public static function billHtml(array $bill, bool $embed = false): string
    {
        $site = Site::info();
        $s = $bill['snapshot'];
        $t = $s['totals'] ?? [];
        $void = $bill['status'] === 'void';
        $stays = $s['stays'] ?? [];
        $multi = count($stays) > 1;
        $plural = fn (int $n, string $one, string $many = '') => $n . ' ' . ($n === 1 ? $one : ($many !== '' ? $many : $one . 's'));
        // item categories in menu order, with the totals saved on the bill
        $cats = [];
        $raw = $t['categories'] ?? [];
        if (Js::isList($raw)) { foreach ($raw as $c) $cats[] = ['name' => (string) $c['name'], 'amount' => (int) $c['amount']]; }
        else { foreach ($raw as $k => $v) if ($v) $cats[] = ['name' => self::LEGACY_CATEGORIES[$k] ?? (string) $k, 'amount' => (int) $v]; }
        $catOf = fn (array $l) => self::LEGACY_CATEGORIES[$l['category'] ?? ''] ?? (string) ($l['category'] ?? '');
        $row = fn (string $what, string $sub, string $qty, string $price, string $amount) => '<tr><td>' . $what . ($sub !== '' ? '<span class="sub">' . $sub . '</span>' : '') . '</td>'
            . '<td class="num">' . $qty . '</td><td class="num">' . $price . '</td><td class="num">' . $amount . '</td></tr>';

        $rows = '<tr class="doc__group"><td colspan="4">' . ($multi ? 'Stays' : 'Stay') . '</td></tr>';
        foreach ($stays as $x) {
            $when = ($x['package'] ?? '') === 'day_out' ? self::date($x['checkIn']) : self::date($x['checkIn']) . ' – ' . self::date($x['checkOut']) . ' · ' . $plural((int) $x['nights'], 'night');
            $guests = (int) ($x['adults'] ?? 0) || (int) ($x['children'] ?? 0)
                ? $plural((int) $x['adults'], 'adult') . ((int) ($x['children'] ?? 0) ? ', ' . $plural((int) $x['children'], 'child', 'children') : '') : '';
            $bits = array_filter([$when, $guests, ...($x['extras'] ?? []),
                !empty($x['couponCode']) ? 'coupon ' . $x['couponCode'] . ' −' . self::money((int) $x['couponDiscount']) : '',
                $multi ? $x['guestName'] . ' · ' . $x['ref'] : $x['ref']], fn ($v) => $v !== '' && $v !== null);
            $rows .= $row('<b>' . self::esc($x['place']) . '</b>', self::esc(implode(' · ', $bits)), '', '', self::money((int) $x['amount']));
        }
        $names = array_column($cats, 'name');
        foreach ($s['lines'] ?? [] as $l) if (!in_array($catOf($l), $names, true)) $names[] = $catOf($l);
        foreach ($names as $name) {
            $lines = array_values(array_filter($s['lines'] ?? [], fn ($l) => $catOf($l) === $name));
            if (!$lines) continue;
            $rows .= '<tr class="doc__group"><td colspan="4">' . self::esc($name) . '</td></tr>';
            foreach ($lines as $l) {
                $sub = array_filter([!empty($l['date']) ? self::dateTime($l['date']) : '', $multi ? (string) ($l['place'] ?? '') : '', (string) ($l['note'] ?? '')], fn ($v) => $v !== '');
                $rows .= $row(self::esc($l['name']), self::esc(implode(' · ', $sub)), (string) (int) $l['qty'], self::money((int) $l['unitPrice']), self::money((int) $l['amount']));
            }
        }

        $line = fn (string $label, string $amount, string $cls = '') => '<div' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '><span>' . $label . '</span>' . $amount . '</div>';
        $totals = $line('Stay', '<span>' . self::money((int) ($t['stay'] ?? 0)) . '</span>');
        foreach ($cats as $c) if ($c['amount']) $totals .= $line(self::esc($c['name']), '<span>' . self::money($c['amount']) . '</span>');
        if (!empty($t['discount'])) $totals .= $line('Discount' . (!empty($t['discountLabel']) ? ' (' . self::esc($t['discountLabel']) . ')' : ''), '<span>− ' . self::money((int) $t['discount']) . '</span>');
        if (!empty($t['coupon'])) $totals .= $line('Coupon ' . self::esc($t['coupon']['code']) . ' <small>(room charge)</small>', '<span>− ' . self::money((int) $t['coupon']['discount']) . '</span>');
        if (!empty($t['service'])) $totals .= $line('Service charge' . (!empty($t['servicePct']) ? ' (' . (int) $t['servicePct'] . '%)' : ''), '<span>' . self::money((int) $t['service']) . '</span>');
        $totals .= $line('Total', '<b>' . self::money((int) ($t['total'] ?? 0)) . '</b>', 'is-total');
        if ($void) {
            $totals .= $line('Void — not payable', '<b></b>', 'is-due');
        } else {
            foreach ($bill['payments'] as $p) {
                $totals .= $line('Paid · ' . self::esc($p['methodLabel']) . '<span class="sub">' . self::esc(self::dateTime($p['createdAt']) . ' · ' . $p['receiptNo']) . '</span>', '<span>− ' . self::money($p['amount']) . '</span>');
            }
            $label = $bill['balance'] > 0 ? 'Balance due' : ($bill['balance'] < 0 ? 'Change / refund' : 'Paid in full');
            $totals .= $line($label, '<b>' . self::money(abs($bill['balance'])) . '</b>', 'is-due');
        }

        $g = $s['guest'] ?? [];
        $who = '<div><small>Billed to</small><b>' . self::esc($g['name'] ?? $bill['guestName']) . '</b>'
            . (!empty($g['phone']) ? '<span>' . self::esc($g['phone']) . '</span>' : '')
            . (!empty($g['email']) ? '<span>' . self::esc($g['email']) . '</span>' : '')
            . (!empty($g['idNo']) ? '<span>NIC / passport ' . self::esc($g['idNo']) . '</span>' : '') . '</div>';
        $refs = '<div><small>Booking' . ($multi ? 's' : '') . '</small>' . implode('', array_map(fn ($x) => '<span>' . self::esc($x['ref']) . '</span>', $stays)) . '</div>';
        $issuedBy = Auth::nameOf((string) ($s['issuedBy'] ?? $bill['createdBy'] ?? ''));
        $body = '<article class="doc doc--bill' . ($void ? ' is-void' : '') . '">'
            . self::head('Bill', (string) $bill['billNo'], self::dateTime((string) ($s['issuedAt'] ?? $bill['createdAt'])))
            . '<div class="doc__to">' . $who . $refs . '</div>'
            . '<table class="doc__tbl"><thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<div class="doc__totals">' . $totals . '</div>'
            . ((string) ($bill['note'] ?? '') !== '' ? '<p class="doc__small">' . self::esc($bill['note']) . '</p>' : '')
            . '<footer class="doc__foot">Thank you for visiting ' . self::esc($site['name']) . ' · ' . self::esc($site['phone']) . '</footer>'
            . ($void ? '<div class="doc__void" aria-hidden="true">VOID</div>' : '')
            . '</article>';
        return self::page('Bill ' . $bill['billNo'], $body, $embed, $issuedBy);
    }

    // ---------- the finance summary (Admin → Finance) ----------
    /** "1 – 30 Nov 2026" */
    public static function periodText(array $p): string
    {
        $f = self::date($p['from'], 'j M Y');
        $t = self::date($p['to'], 'j M Y');
        if ($p['from'] === $p['to']) return $f;
        if (substr($p['from'], 0, 7) === substr($p['to'], 0, 7)) return self::date($p['from'], 'j') . ' – ' . $t;
        return $f . ' – ' . $t;
    }

    /** Finance::summary() as a page to print or save as a PDF. */
    public static function summaryHtml(array $s, bool $embed, string $issuedBy): string
    {
        $m = fn (int $n) => self::money($n);
        $table = function (string $title, array $rows, ?array $total = null) {
            if (!$rows && !$total) return '';
            $body = '';
            foreach ($rows as [$label, $amount, $sub]) {
                $body .= '<tr><td>' . $label . ($sub !== '' ? '<span class="sub">' . $sub . '</span>' : '') . '</td><td class="num">' . $amount . '</td></tr>';
            }
            if ($total) $body .= '<tr class="is-sum"><td>' . $total[0] . '</td><td class="num">' . $total[1] . '</td></tr>';
            return '<section class="sum-sec"><h3>' . $title . '</h3><table class="doc__tbl"><tbody>' . $body . '</tbody></table></section>';
        };
        $none = fn (string $what) => [['<span class="sub-in">' . $what . '</span>', '', '']];
        $r = $s['received'];
        $sales = $s['sales'];
        $kpis = '<div class="sum-kpis">'
            . '<div><small>Money received</small><b>' . $m($r['net']) . '</b><span>reached your accounts' . ($r['fees'] ? ' · guests paid ' . $m($r['total']) . ', ' . $m($r['fees']) . ' kept by agents or banks' : '') . '</span></div>'
            . '<div><small>Spent</small><b>' . $m($s['expenseTotal'] + $s['staffTotal']) . '</b><span>expenses ' . $m($s['expenseTotal']) . ' · staff ' . $m($s['staffTotal']) . '</span></div>'
            . '<div class="is-net"><small>Net</small><b>' . $m($s['net']) . '</b><span>money received, less spending</span></div></div>';
        $body = '<article class="doc doc--summary">'
            . self::head('Finance summary', self::periodText($s['period']), $s['period']['days'] . ' day' . ($s['period']['days'] === 1 ? '' : 's'))
            . $kpis
            . $table('Money received — into each account', $r['accounts'] ? array_map(fn ($a) => [self::esc($a['account']), $m($a['amount']),
                $a['kept'] ? 'guests paid ' . $m($a['paid']) . ' · ' . $m($a['kept']) . ' kept by agents or banks' : ''], $r['accounts']) : $none('No payments'),
                $r['accounts'] ? ['Total', '<b>' . $m($r['net']) . '</b>'] : null)
            . $table('Money received — by how it was paid', array_map(fn ($a) => [self::esc($a['label']), $m($a['amount']), ''], $r['methods']))
            . $table('Bills issued (' . $sales['bills'] . ')', $sales['bills'] ? array_values(array_filter([
                ['Stays', $m($sales['stay']), ''], ['Food, drinks and other items', $m($sales['items']), ''],
                $sales['service'] ? ['Service charge', $m($sales['service']), 'shared with the staff who served'] : null,
                $sales['discount'] ? ['Discounts', '− ' . $m($sales['discount']), ''] : null,
                $sales['coupon'] ? ['Coupons', '− ' . $m($sales['coupon']), ''] : null,
            ])) : $none('No bills'), $sales['bills'] ? ['Billed', '<b>' . $m($sales['total']) . '</b>'] : null)
            . $table('Expenses', $s['expenses'] ? array_map(fn ($e) => [self::esc($e['category']), $m($e['amount']), $e['count'] . ' entr' . ($e['count'] === 1 ? 'y' : 'ies')], $s['expenses']) : $none('No expenses'),
                $s['expenses'] ? ['Total', '<b>' . $m($s['expenseTotal']) . '</b>'] : null)
            . $table('Paid to staff', $s['staff'] ? array_map(fn ($x) => [self::esc($x['label']), $m($x['amount']), ''], $s['staff']) : $none('Nothing paid'),
                $s['staff'] ? ['Total', '<b>' . $m($s['staffTotal']) . '</b>'] : null)
            . $table('Paid into bank accounts', $s['bank'] ? array_map(fn ($x) => [self::esc($x['account']), $m($x['amount']), $x['count'] . ' deposit' . ($x['count'] === 1 ? '' : 's')], $s['bank']) : $none('No deposits noted'),
                $s['bank'] ? ['Total', '<b>' . $m($s['bankTotal']) . '</b>'] : null)
            . $table('Commissions', array_values(array_filter([['Earned by agents and websites', $m($s['commissions']['earned']), 'on stays arriving in this period'],
                ['Kept by agents from payments', $m($s['commissions']['kept']), ''], ['Paid to them by you', $m($s['commissions']['paid']), 'expenses in the Commission category'],
                ['Still to pay', '<b>' . $m($s['commissions']['toPay']) . '</b>', 'all bookings so far'],
                $s['commissions']['charges'] ? ['Bank or card charges', $m($s['commissions']['charges']), 'taken off payments'] : null])))
            . $table('Rooms', [['Room nights sold', (string) $s['rooms']['nights'], ''], ['Occupancy', $s['rooms']['occupancy'] . '%', ''],
                ['Room revenue', $m($s['rooms']['revenue']), 'stays, shared over their nights'], ['Average rate a night', $m($s['rooms']['adr']), ''], ['Day visits', (string) $s['rooms']['dayVisits'], ''],
                ['Outdoor parties', (string) $s['rooms']['parties'], $s['rooms']['parties'] ? $m($s['rooms']['partyRevenue']) : '']])
            . $table('Food and drinks', array_merge([['Sold', $m($s['food']['total']), '']],
                array_map(fn ($i) => [self::esc($i['name']), $m($i['amount']), $i['qty'] . ' × · ' . self::esc($i['category'])], $s['food']['top'])))
            . $table('Stock', array_merge([['Bought in this period', $m($s['stockBought']), '']],
                array_map(fn ($i) => [self::esc($i['name']) . ' — low', Finance::qtyText($i['onHand']) . ' ' . self::esc($i['unit']), 'reorder at ' . Finance::qtyText($i['reorderLevel'])], $s['lowStock'])))
            . '<footer class="doc__foot">' . self::esc(Site::info()['name']) . ' · finance summary · ' . self::esc(self::periodText($s['period'])) . '</footer>'
            . '</article>';
        return self::page('Finance summary ' . self::periodText($s['period']), $body, $embed, $issuedBy);
    }

    /** "Invoice / receipt not found" — a wrong or old link. */
    public static function notFound(): string
    {
        return self::page('Not found', '<article class="doc"><h1 class="doc__missing">This link isn\'t valid</h1><p>Please ask ' . self::esc(Site::info()['name']) . ' for a new link.</p></article>', true);
    }
}
