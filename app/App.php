<?php
// The application: security headers, CSRF guard, pages and the JSON API.
// Routes match Express-style rules: case-insensitive, optional trailing slash.
declare(strict_types=1);

namespace Ydr;

final class App
{
    private const JSON_LIMIT = 51200;        // 50 KB max for JSON request bodies
    private const UPLOAD_LIMIT = 15728640;   // 15 MB max for photo uploads

    private static string $nonce = '';

    public static function run(): void
    {
        $req = Request::fromGlobals();
        $res = new Response();
        try {
            self::securityHeaders($res);
            self::handle($req, $res);
        } catch (BookingError $e) {
            $res->json(['error' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        } catch (HttpError $e) {
            $res->json(['error' => $e->getMessage()], $e->status);
        } catch (DbUnavailable $e) {
            error_log('[db] ' . $e->getMessage());
            $res->header('Retry-After', '120');
            if (preg_match('#^/api(?=/|$)#i', $req->path)) {
                $res->json(['error' => 'The booking system is temporarily unavailable. Please try again in a few minutes.'], 503);
            } else {
                $res->send(self::renderUnavailable(), 'text/html; charset=utf-8', 503);
            }
        } catch (\Throwable $e) {
            error_log('[error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $res->json(['error' => 'Something went wrong. Please try again.'], 500);
        }
        // emails and WhatsApp alerts go out after the browser has its answer
        $res->emit(Mailer::hasQueue() || Whatsapp::hasQueue());
        Mailer::flushQueue();
        Whatsapp::flushQueue();
        // fallback for hosting without cron: send any due booking reminders (at most once an hour)
        if (PHP_SAPI !== 'cli') {
            Reminders::runHourly();
            Mailer::flushQueue();
        }
    }

    // ---------- security headers ----------
    private static function securityHeaders(Response $res): void
    {
        self::$nonce = base64_encode(random_bytes(16));
        $res->header('X-Content-Type-Options', 'nosniff');
        $res->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        $res->header('X-Frame-Options', 'SAMEORIGIN');
        $res->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $res->header('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-" . self::$nonce . "' https://cdnjs.cloudflare.com", // cdnjs: GSAP for the home page's motion
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: blob:",
            "media-src 'self'",
            "frame-src 'self' https://www.google.com https://maps.google.com", // 'self': invoice and receipt previews in the admin panel
            "connect-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]));
        if (Config::get()['isProd']) $res->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    /** Express-style path match: case-insensitive, optional trailing slash, :params. */
    private static function route(string $pattern, string $path, ?array &$params = null): bool
    {
        $re = '#^' . preg_replace('#:(\w+)#', '(?P<$1>[^/]+)', str_replace('.', '\.', $pattern)) . '/?$#i';
        if (!preg_match($re, $path, $m)) return false;
        $params = [];
        foreach ($m as $k => $v) if (is_string($k)) $params[$k] = rawurldecode($v);
        return true;
    }

    private static function isGet(Request $req): bool
    {
        return $req->method === 'GET' || $req->method === 'HEAD';
    }

    private static function handle(Request $req, Response $res): void
    {
        Auth::ensureAdmin();

        // parse JSON bodies for every request that sends one
        if ($req->isJson()) {
            $raw = $req->readRaw(self::JSON_LIMIT);
            $trim = ltrim($raw, " \t\n\r");
            if ($trim !== '') {
                if ($trim[0] !== '{' && $trim[0] !== '[') throw new HttpError(400, 'Invalid JSON');
                $data = json_decode($raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
                if (json_last_error() !== JSON_ERROR_NONE) throw new HttpError(400, 'Invalid JSON');
                $req->body = is_array($data) ? $data : [];
            }
        }

        $path = $req->path;
        // reservations whose hold date passed without an advance free their dates
        if (preg_match('#^/(api|calendar|invoice|receipt|confirm)(?=/|$)#i', $path)) {
            try {
                Bookings::releaseExpired();
            } catch (DbUnavailable $e) {
                throw $e;
            } catch (\Throwable $e) {
                error_log('[bookings] could not release expired reservations: ' . $e->getMessage());
            }
        }
        if (preg_match('#^/api(?=/|$)#i', $path)) {
            self::api($req, $res, substr($path, 4) ?: '/');
            if (!$res->done) $res->json(['error' => 'Not found'], 404);
            return;
        }
        self::pages($req, $res);
        if (!$res->done) $res->send(self::render404(), 'text/html; charset=utf-8', 404);
    }

    // ---------- API ----------
    private static function api(Request $req, Response $res, string $sub): void
    {
        // CSRF guard: state-changing API calls must be JSON and come from our own origin.
        if (!in_array($req->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $isUpload = ($sub === '/admin/media' && $req->isImage())
                || ((strtolower(rtrim($sub, '/')) === '/admin/pos/slips' || preg_match('#^/invoice/[^/]+/[^/]+/slip/?$#i', $sub))
                    && $req->typeisHasBody() && ($req->isImage() || $req->mime() === 'application/pdf'));
            $hasBody = (int) ($req->header('content-length') ?? 0) > 0 || $req->header('transfer-encoding') !== null;
            if (($hasBody || $req->method === 'POST') && !$isUpload && !$req->isJson()) { $res->json(['error' => 'Expected JSON'], 415); return; }
            $origin = $req->header('origin');
            if ($origin !== null) {
                $host = strtolower($req->header('x-forwarded-host') ?? $req->header('host') ?? '');
                $u = parse_url($origin);
                if (!is_array($u) || empty($u['scheme']) || empty($u['host'])) { $res->json(['error' => 'Bad origin'], 403); return; }
                $default = ['http' => 80, 'https' => 443][strtolower($u['scheme'])] ?? null;
                $oHost = strtolower($u['host']) . (isset($u['port']) && $u['port'] !== $default ? ':' . $u['port'] : '');
                if ($oHost !== $host) { $res->json(['error' => 'Cross-origin request blocked'], 403); return; }
            }
        }

        if (self::publicApi($req, $res, $sub)) return;
        if (preg_match('#^/admin(?=/|$)#i', $sub)) self::adminApi($req, $res, substr($sub, 6) ?: '/');
    }

    private static function publicRoom(array $r): array
    {
        unset($r['active'], $r['sort']);
        return $r;
    }

    /** [meal plan, whole category or null] from a query (?mealPlan=HB&wholeCategory=3); a bad plan is room only. */
    private static function planQuery(Request $req): array
    {
        try { $plan = Bookings::planOf($req->q('mealPlan')); } catch (BookingError $e) { $plan = 'RO'; }
        $cat = Js::truthy($req->q('wholeCategory')) ? Bookings::getCategory($req->q('wholeCategory')) : null;
        return [$plan, $cat && $cat['whole'] ? $cat : null];
    }

    private static function publicApi(Request $req, Response $res, string $p): bool
    {
        if (self::isGet($req) && self::route('/rooms', $p)) {
            $res->json(['rooms' => array_map([self::class, 'publicRoom'], Bookings::listRooms())]);
            return true;
        }

        // Which nights can't be booked? ?kind=room (&room=slug for one room), ?kind=per_person&guests=N,
        // or ?kind=day_out. Rooms, per-person groups and the hotel's capacity all count.
        if (self::isGet($req) && self::route('/calendar', $p)) {
            $from = Bookings::isDate($req->q('from')) ? $req->q('from') : Bookings::today();
            $to = Bookings::isDate($req->q('to')) && strcmp($req->q('to'), $from) > 0 ? $req->q('to') : Bookings::addDays($from, 120);
            if (Bookings::nightsBetween($from, $to) > 600) { $res->json(['error' => 'Range too large'], 400); return true; }
            $kind = in_array($req->q('kind'), ['room', 'per_person', 'day_out'], true) ? $req->q('kind') : 'room';
            $room = null;
            if ($kind === 'room' && Js::truthy($req->q('room'))) {
                $room = Bookings::getRoom($req->q('room'));
                if (!$room || !$room['active']) { $res->json(['error' => 'Room not found'], 404); return true; }
            }
            $guests = max(1, min(Bookings::MAX_GROUP_ADULTS + Bookings::MAX_GROUP_CHILDREN, Js::parseInt($req->q('guests')) ?: 1));
            $res->json(['today' => Bookings::today(), 'unavailable' => Bookings::unavailableNights($kind, $room, $guests, $from, $to)]);
            return true;
        }

        // All rooms at once for the availability grid: ?from&to → each room with its taken nights.
        if (self::isGet($req) && self::route('/room-calendar', $p)) {
            $from = Bookings::isDate($req->q('from')) ? $req->q('from') : Bookings::today();
            if (strcmp($from, Bookings::today()) < 0) $from = Bookings::today();
            $to = Bookings::isDate($req->q('to')) && strcmp($req->q('to'), $from) > 0 ? $req->q('to') : Bookings::addDays($from, 14);
            if (Bookings::nightsBetween($from, $to) > 62) { $res->json(['error' => 'Range too large'], 400); return true; }
            $res->json(['today' => Bookings::today(), 'rooms' => Bookings::roomGrid($from, $to)]);
            return true;
        }

        // ?kind=room&checkIn&checkOut → the rooms; ?kind=per_person|day_out&packageId&checkIn(&checkOut)&adults&children → space and price
        if (self::isGet($req) && self::route('/availability', $p)) {
            $kind = in_array($req->q('kind'), ['per_person', 'day_out'], true) ? $req->q('kind') : 'room';
            if ($kind === 'room') {
                $rooms = Bookings::searchRooms($req->q('checkIn'), $req->q('checkOut'));
                $res->json(['rooms' => array_map([self::class, 'publicRoom'], $rooms), 'partyArea' => Bookings::partyAreaStatus($req->q('checkIn'), $req->q('checkOut')),
                    'advanceOn' => Bookings::advanceRule()['on']]); // the home page says whether bookings wait for an advance
                return true;
            }
            $pkg = Bookings::getPackage($req->q('packageId'));
            if (!$pkg || !$pkg['active']) { $res->json(['error' => 'Please choose an option.'], 400); return true; }
            $adults = max(1, min(Bookings::MAX_GROUP_ADULTS, Js::parseInt($req->q('adults')) ?: 2));
            $children = max(0, min(Bookings::MAX_GROUP_CHILDREN, Js::parseInt($req->q('children')) ?: 0));
            if ($pkg['kind'] === 'day') {
                Bookings::validateDayOut($req->q('checkIn'));
                Closures::assertOpen($req->q('checkIn'), Bookings::addDays($req->q('checkIn'), 1), 'day_out');
                $q = Bookings::quote('day_out', null, $pkg, 0, $adults, $children, false, false);
                $res->json(['available' => true, 'nights' => 0, 'total' => $q['total'], 'lines' => $q['lines']]);
                return true;
            }
            $nights = Bookings::validateStay($req->q('checkIn'), $req->q('checkOut'));
            Closures::assertOpen($req->q('checkIn'), $req->q('checkOut'));
            $group = Bookings::checkGroup($req->q('checkIn'), $req->q('checkOut'), $adults + $children);
            $q = Bookings::quote('per_person', null, $pkg, $nights, $adults, $children, false, false);
            $res->json(['available' => $group['available'], 'nights' => $nights, 'total' => $q['total'], 'lines' => $q['lines'],
                'partyArea' => Bookings::partyAreaStatus($req->q('checkIn'), $req->q('checkOut'))]);
            return true;
        }

        // what a coupon takes off the room charge of a stay being booked:
        // code, kind, room (slug) | packageId, checkIn, checkOut, adults, children
        if (self::isGet($req) && self::route('/coupon', $p)) {
            if (!RateLimit::hit($req, $res, 'coupon', 10 * 60 * 1000, 30, 'Too many coupon tries — please wait a few minutes.')) return true;
            $kind = in_array($req->q('kind'), ['per_person', 'day_out'], true) ? $req->q('kind') : 'room';
            $room = null;
            $pkg = null;
            $rooms = [];
            if ($kind === 'room') {
                // one room (room=slug) or several (rooms=slug,slug)
                $slugs = is_string($req->q('rooms')) && $req->q('rooms') !== '' ? array_slice(explode(',', $req->q('rooms')), 0, Bookings::MAX_ROOMS) : [$req->q('room')];
                foreach ($slugs as $sl) {
                    $r = Bookings::getRoom($sl);
                    if (!$r || !$r['active']) { $res->json(['error' => 'Choose a room first.'], 400); return true; }
                    $rooms[$r['id']] = $r;
                }
                $rooms = array_values($rooms);
                $room = $rooms[0];
            } else {
                $pkg = Bookings::getPackage($req->q('packageId'));
                if (!$pkg || !$pkg['active']) { $res->json(['error' => 'Choose an option first.'], 400); return true; }
                $kind = $pkg['kind'] === 'day' ? 'day_out' : 'per_person';
            }
            $nights = $kind === 'day_out' ? 0 : Bookings::validateStay($req->q('checkIn'), $req->q('checkOut'));
            $adults = $kind === 'room' ? 0 : max(1, min(Bookings::MAX_GROUP_ADULTS, Js::parseInt($req->q('adults')) ?: 1));
            $children = $kind === 'room' ? 0 : max(0, min(Bookings::MAX_GROUP_CHILDREN, Js::parseInt($req->q('children')) ?: 0));
            [$plan, $whole] = $kind === 'room' ? self::planQuery($req) : ['RO', null];
            $q = Bookings::quote($kind, $room, $pkg, $nights, $adults, $children, false, false, [], $rooms, $plan, $whole);
            $c = Coupons::check($req->q('code'), $q['stay']);
            $res->json(['coupon' => ['code' => $c['code'], 'label' => $c['label'], 'type' => $c['type'], 'value' => $c['value'], 'discount' => $c['discount']]]);
            return true;
        }

        if ($req->method === 'POST' && self::route('/bookings', $p)) {
            if (!RateLimit::hit($req, $res, 'booking', 10 * 60 * 1000, 6, 'Too many booking attempts — please wait a few minutes or contact us directly.')) return true;
            $body = $req->body;
            // honeypot: real people never fill the hidden "website" field
            if (Js::truthy($body['website'] ?? null)) { $res->json(['error' => 'Invalid submission.'], 400); return true; }
            if (!Js::truthy($body['agree'] ?? null)) { $res->json(['error' => 'Please accept the booking terms.'], 400); return true; }

            $b = Bookings::createBooking($body);
            Mailer::notifyNewBooking($b);
            Whatsapp::newBooking($b);
            $res->json(['booking' => [
                'ref' => $b['ref'],
                'kind' => $b['kind'],
                'roomName' => $b['kind'] === 'room' ? $b['roomName'] : null,
                'roomType' => $b['roomType'],
                'place' => $b['place'],
                'mealPlan' => $b['mealPlan'],
                'mealPlanLabel' => $b['mealPlanLabel'],
                'wholeCategory' => $b['wholeCategory'],
                'capacity' => $b['kind'] === 'room' ? $b['capacity'] : null,
                'package' => $b['package'],
                'packageLabel' => $b['packageLabel'],
                'advance' => $b['advance'], // what to pay before arrival (Bookings::advanceOf)
                'checkIn' => $b['checkIn'],
                'checkOut' => $b['checkOut'],
                'nights' => $b['nights'],
                'adults' => $b['adults'],
                'children' => $b['children'],
                'partyArea' => $b['partyArea'],
                'jblSpeaker' => $b['jblSpeaker'],
                'totalAmount' => $b['totalAmount'],
                'couponCode' => $b['couponCode'],
                'couponDiscount' => $b['couponDiscount'],
                'holdUntil' => $b['holdUntil'],
                'invoiceUrl' => Docs::invoiceUrl($b), // their own invoice, to view or print
                'guestName' => $b['guestName'],
                'guestEmail' => $b['guestEmail'],
                'status' => $b['status'],
            ]], 201);
            return true;
        }

        // A guest sends their payment slip from their invoice link: the photo or PDF is the body;
        // X-Slip-Amount and X-Slip-Note (URI-encoded) are optional. Staff are emailed and sent a WhatsApp alert.
        if ($req->method === 'POST' && self::route('/invoice/:ref/:code/slip', $p, $params)) {
            if (!RateLimit::hit($req, $res, 'slip', 60 * 60 * 1000, 10, 'Too many uploads — please wait a while, or send the slip to us on WhatsApp.')) return true;
            $b = Docs::invoiceBooking($params['ref'], $params['code']);
            if (!$b) { $res->json(['error' => 'This link isn\'t valid. Please ask us for a new one.'], 404); return true; }
            $bytes = $req->isImage() || $req->mime() === 'application/pdf' ? $req->readRaw(Pos::SLIP_LIMIT) : '';
            $slip = Pos::addGuestSlip($b, $bytes, rawurldecode((string) ($req->header('x-slip-amount') ?? '')), rawurldecode((string) ($req->header('x-slip-note') ?? '')));
            Mailer::notifyGuestSlip($b, $slip);
            Whatsapp::guestSlip($b, $slip);
            $res->json(['slips' => Pos::guestSlipsPublic($b['id'])], 201);
            return true;
        }

        // A guest confirms a booking staff made, from its private link: the details they filled in + agree
        if ($req->method === 'POST' && self::route('/confirm/:ref/:code', $p, $params)) {
            if (!RateLimit::hit($req, $res, 'confirm', 10 * 60 * 1000, 10, 'Too many tries — please wait a few minutes or call us.')) return true;
            $body = $req->body;
            if (Js::truthy($body['website'] ?? null)) { $res->json(['error' => 'Invalid submission.'], 400); return true; } // honeypot
            $b = Confirmations::byLink($params['ref'], $params['code']);
            if (!$b) { $res->json(['error' => 'This link isn\'t valid. Please ask us for a new one.'], 404); return true; }
            $r = Confirmations::confirm($b, $body);
            Confirmations::notify($r['booking'], $r['changes']);
            $res->json(['confirm' => Confirmations::page($r['booking'])]);
            return true;
        }

        // The website's chat window: a guest's message (+ the conversation so far, kept in their browser)
        // goes to the chatbot through Chatbot.php; the browser never talks to the chatbot itself.
        if ($req->method === 'POST' && self::route('/chat', $p)) {
            if (!Chatbot::webOn()) { $res->json(['error' => 'The chat is not available.'], 404); return true; }
            if (!RateLimit::hit($req, $res, 'chat', 10 * 60 * 1000, 40, 'Too many messages — please wait a few minutes, or call or WhatsApp us.')) return true;
            $body = $req->body;
            if (Js::truthy($body['website'] ?? null)) { $res->json(['error' => 'Invalid message.'], 400); return true; } // honeypot
            $r = Chatbot::reply(Js::str($body['message'] ?? ''), is_array($body['history'] ?? null) ? $body['history'] : []);
            $res->json(['reply' => $r['reply'], 'parts' => $r['parts']]);
            return true;
        }
        return false;
    }

    private static function adminApi(Request $req, Response $res, string $p): void
    {
        $cfg = Config::get();

        // ---------- session ----------
        if ($req->method === 'POST' && self::route('/login', $p)) {
            if (!RateLimit::hit($req, $res, 'login', 15 * 60 * 1000, 10, 'Too many sign-in attempts. Try again in 15 minutes.')) return;
            $admin = Auth::login($req->body['username'] ?? null, $req->body['password'] ?? null);
            if (!$admin) { $res->json(['error' => 'Wrong username or password.'], 401); return; }
            if (!(int) ($admin['active'] ?? 1)) { $res->json(['error' => 'This login is switched off. Ask an admin to switch it on.'], 403); return; }
            Auth::touchLogin($admin);
            Auth::setSessionCookie($res, Auth::issueToken($admin));
            $res->json(['username' => $admin['username'], 'role' => Auth::role($admin), 'perms' => Auth::perms($admin)]);
            return;
        }
        if ($req->method === 'POST' && self::route('/logout', $p)) {
            Auth::clearSessionCookie($res);
            $res->json(['ok' => true]);
            return;
        }

        $admin = Auth::current();
        if (!$admin) { $res->json(['error' => 'Please sign in.'], 401); return; }
        $need = self::permFor($req->method, $p);
        if ($need && !array_intersect($need, Auth::perms($admin))) { $res->json(['error' => 'Your login can\'t do this. Ask an admin.'], 403); return; }
        $get = self::isGet($req);
        $m = $req->method;
        $body = $req->body;
        $params = [];

        if ($get && self::route('/me', $p)) {
            $res->json([
                'username' => $admin['username'],
                'name' => Auth::displayName($admin),
                'role' => Auth::role($admin),
                'roleLabel' => Auth::ROLES[Auth::role($admin)]['label'],
                'perms' => Auth::perms($admin),
                'bankReady' => Bookings::paymentInfo()['accountNo'] !== '',
                'emailEnabled' => Mailer::enabled(),
                'mailFrom' => (string) preg_replace('/^.*<([^>]+)>.*$/', '$1', $cfg['smtp']['from']),
                'notifyEmails' => Mailer::adminEmails(),
                'currency' => $cfg['site']['currency'],
                'siteName' => $cfg['site']['name'],
                'mealPlans' => Bookings::mealPlans(),
                'today' => Bookings::today(),
                'statuses' => Bookings::STATUSES,
                'sources' => Bookings::SOURCES,
                'kinds' => Bookings::KINDS,
                'labels' => Bookings::labels(),
                'packages' => Bookings::listPackages(true),
                'prices' => Bookings::prices(),
                'capacity' => Bookings::hotelCapacity(),
                'methods' => Pos::METHODS,
                'paymentFields' => Pos::paymentFields(),
                'advancePct' => Bookings::paymentInfo()['advancePct'],
                'advanceRule' => Bookings::advanceRule(), // Booking setup's advance: on, type (pct | amount), pct, amount
                'holdDays' => Bookings::holdDays(),
                'printPaper' => Docs::paper(),
                'papers' => Docs::PAPERS,
            ]);
            return;
        }

        // ---------- days the whole resort is closed ----------
        if ($get && self::route('/closures', $p)) { $res->json(['closures' => Closures::upcoming(), 'reasons' => Closures::REASONS]); return; }
        if ($m === 'POST' && self::route('/closures', $p)) { $res->json(Closures::create($body, $admin['username']), 201); return; }
        if ($m === 'DELETE' && self::route('/closures/:id', $p, $params)) { Closures::delete(Bookings::int($params['id'], 1, PHP_INT_MAX) ?? 0); $res->json(['ok' => true]); return; }

        // ---------- staff logins (admins only) ----------
        if ($get && self::route('/users', $p)) { $res->json(['users' => Users::list(), 'roles' => Users::roles(), 'minPassword' => Users::MIN_PASSWORD]); return; }
        if ($m === 'POST' && self::route('/users', $p)) { $res->json(['user' => Users::create($body)], 201); return; }
        if (self::route('/users/:id', $p, $params)) {
            $id = Bookings::int($params['id'], 1, PHP_INT_MAX) ?? 0;
            if ($m === 'PATCH') { $res->json(['user' => Users::update($id, $body, $admin)]); return; }
            if ($m === 'DELETE') { Users::delete($id, $admin); $res->json(['ok' => true]); return; }
        }

        // ---------- printing: A5 or A4 for bills, invoices and receipts ----------
        if ($m === 'PATCH' && self::route('/printing', $p)) { $res->json(['paper' => Docs::savePaper($body['paper'] ?? null)]); return; }

        // ---------- coupons ----------
        if ($get && self::route('/coupons', $p)) { $res->json(['coupons' => Coupons::list($req->query), 'batches' => Coupons::batches()]); return; }
        if ($get && self::route('/coupons.csv', $p)) {
            $list = Coupons::list(['batch' => $req->q('batch') ?? '']);
            $cell = fn ($v) => '"' . str_replace('"', '""', (string) ($v ?? '')) . '"';
            $lines = ['"Code","Discount","Usage limit","Used","Valid from","Valid until","Active","Batch"'];
            foreach ($list as $c) $lines[] = implode(',', array_map($cell, [$c['code'], $c['label'], $c['maxUses'] ?: 'No limit', $c['uses'], $c['validFrom'], $c['validTo'], $c['active'] ? 'Yes' : 'No', $c['batch']]));
            $name = preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($req->q('batch') ?: 'all')) ?: 'all';
            $res->header('Content-Disposition', 'attachment; filename="coupons-' . trim($name, '-') . '.csv"');
            $res->send("\u{FEFF}" . implode("\r\n", $lines), 'text/csv; charset=utf-8');
            return;
        }
        if ($m === 'POST' && self::route('/coupons', $p)) { $res->json(['coupon' => Coupons::create($body, $admin['username'])], 201); return; }
        if ($m === 'POST' && self::route('/coupons/batch', $p)) { $res->json(Coupons::generate($body, $admin['username']), 201); return; }
        if ($m === 'POST' && self::route('/coupons/batch-active', $p)) {
            $n = Coupons::setBatchActive(is_string($body['batch'] ?? null) ? $body['batch'] : '', Js::truthy($body['active'] ?? null));
            $res->json(['ok' => true, 'count' => $n]);
            return;
        }
        if (self::route('/coupons/:id', $p, $params) || self::route('/coupons/:id/uses', $p, $params)) {
            $id = Bookings::int($params['id'], 1, PHP_INT_MAX) ?? 0;
            $c = $id ? Coupons::get($id) : null;
            if (!$c) { $res->json(['error' => 'Coupon not found'], 404); return; }
            if ($get && str_ends_with(strtolower($p), '/uses')) { $res->json(['uses' => Coupons::usedBy($c['code'])]); return; }
            if ($m === 'PATCH') { $res->json(['coupon' => Coupons::update($id, $body)]); return; }
            if ($m === 'DELETE') { Coupons::delete($id); $res->json(['ok' => true]); return; }
        }

        // ---------- the home page: contact details, map and photos ----------
        if ($get && self::route('/site', $p)) { $res->json(['site' => Site::admin()]); return; }
        if ($m === 'PATCH' && self::route('/site', $p)) { $res->json(['site' => Site::save($body)]); return; }

        // ---------- who hears about new bookings: admin emails ----------
        if ($get && self::route('/notify-emails', $p)) { $res->json(['notify' => Mailer::adminEmailsInfo()]); return; }
        if ($m === 'PUT' && self::route('/notify-emails', $p)) { $res->json(['notify' => Mailer::saveAdminEmails($body['emails'] ?? null)]); return; }

        // ---------- WhatsApp alerts ----------
        if ($get && self::route('/whatsapp', $p)) { $res->json(['whatsapp' => Whatsapp::admin()]); return; }
        if ($m === 'PATCH' && self::route('/whatsapp', $p)) { $res->json(['whatsapp' => Whatsapp::save($body)]); return; }
        // {id?}: test one number, or every number on the list
        if ($m === 'POST' && self::route('/whatsapp/test', $p)) {
            $only = Js::truthy($body['id'] ?? null) ? Js::str($body['id']) : null;
            $r = Whatsapp::send("👋 Test from the {$cfg['site']['name']} booking system. New bookings and confirmations will arrive here.", true, $only);
            $results = $r['results'] ?? [];
            if (!$results) { $res->json(['error' => $only === null ? 'Add a WhatsApp number first.' : 'Save this number first, then send the test.'], 400); return; }
            $failed = array_values(array_filter($results, fn ($x) => isset($x['error'])));
            if (count($failed) === count($results)) {
                $res->json(['error' => 'WhatsApp failed: ' . (count($failed) === 1 ? $failed[0]['error'] : implode('; ', array_map(fn ($x) => "+{$x['phone']}: {$x['error']}", $failed)))], 502);
                return;
            }
            $res->json(['ok' => true, 'results' => array_map(fn ($x) => ['phone' => $x['phone'], 'name' => $x['name'], 'sent' => !empty($x['sent']), 'outbox' => !empty($x['outbox']), 'error' => $x['error'] ?? null], $results)]);
            return;
        }

        // ---------- the website's chatbot: its knowledge base, the chat window switch, and a test chat ----------
        if ($get && self::route('/chatbot', $p)) { $res->json(['chatbot' => Chatbot::admin()]); return; }
        if ($m === 'POST' && self::route('/chatbot/create', $p)) { Chatbot::create(is_array($body['seed'] ?? null) ? $body['seed'] : []); $res->json(['chatbot' => Chatbot::admin()], 201); return; }
        if ($m === 'PUT' && self::route('/chatbot/kb', $p)) { Chatbot::save($body); $res->json(['chatbot' => Chatbot::admin()]); return; }
        if ($m === 'PATCH' && self::route('/chatbot/web', $p)) { Chatbot::saveWeb($body); $res->json(['chatbot' => Chatbot::admin()]); return; }
        if ($m === 'POST' && self::route('/chatbot/test', $p)) {
            // an admin trying the bot: the same answer a guest would get, without needing the website switch on
            $res->json(Chatbot::reply(Js::str($body['message'] ?? ''), is_array($body['history'] ?? null) ? $body['history'] : []));
            return;
        }

        // ---------- booking reminders: settings, what is due, and sending them now ----------
        if ($get && self::route('/reminders', $p)) { $res->json(['reminders' => Reminders::admin()]); return; }
        if ($m === 'PATCH' && self::route('/reminders', $p)) { $res->json(['reminders' => Reminders::save($body)]); return; }
        if ($m === 'POST' && self::route('/reminders/send', $p)) {
            $sent = Reminders::sendDueNow();
            $res->json(['ok' => true, 'sent' => $sent, 'outbox' => !Mailer::enabled(), 'reminders' => Reminders::admin()]);
            return;
        }

        // ---------- Google Calendar link ----------
        if ($get && self::route('/calendar-feed', $p)) { $res->json(['feed' => Ical::admin()]); return; }
        if ($m === 'POST' && self::route('/calendar-feed/reset', $p)) { $res->json(['feed' => Ical::reset()]); return; }

        // ---------- booking setup ----------
        if ($get && self::route('/setup', $p)) { $res->json(['setup' => Bookings::setup()]); return; }
        if ($m === 'PATCH' && self::route('/setup', $p)) { $res->json(['setup' => Bookings::saveSetup($body)]); return; }
        if ($m === 'POST' && self::route('/packages', $p)) { $res->json(['package' => Bookings::savePackage(null, $body)], 201); return; }
        if ($m === 'PATCH' && self::route('/packages/:id', $p, $params)) {
            $id = Bookings::int($params['id'], 1, PHP_INT_MAX);
            if (!$id) { $res->json(['error' => 'Option not found'], 404); return; }
            $res->json(['package' => Bookings::savePackage($id, $body)]);
            return;
        }

        if ($m === 'POST' && self::route('/password', $p)) {
            $r = Auth::changePassword($admin['username'], $body['current'] ?? null, $body['next'] ?? null);
            if (!$r['ok']) { $res->json(['error' => $r['error']], 400); return; }
            Auth::setSessionCookie($res, Auth::issueToken($r['admin']));
            $res->json(['ok' => true]);
            return;
        }

        if ($m === 'POST' && self::route('/test-email', $p)) {
            $to = Js::truthy($body['to'] ?? null) ? $body['to'] : Mailer::adminEmails();
            try {
                $r = Mailer::testEmail($to);
                $res->json(['ok' => true, 'outbox' => !empty($r['outbox']), 'skipped' => !empty($r['skipped'])]);
            } catch (\Throwable $e) {
                $res->json(['error' => 'Email failed: ' . $e->getMessage()], 502);
            }
            return;
        }

        // ---------- finance (admins) ----------
        if (preg_match('#^/finance(?=/|$)#i', $p)) {
            self::financeApi($req, $res, substr($p, 8) ?: '/', $admin['username']);
            return;
        }

        // ---------- point of sale ----------
        if (preg_match('#^/pos(?=/|$)#i', $p)) {
            self::posApi($req, $res, substr($p, 4) ?: '/', $admin['username']);
            return;
        }

        // ---------- dashboard ----------
        if ($get && self::route('/stats', $p)) {
            $t = Bookings::today();
            $arrivals = array_values(array_filter(
                Bookings::listBookings(['status' => 'active', 'from' => $t, 'to' => Bookings::addDays($t, 7)]),
                fn ($b) => strcmp($b['checkIn'], $t) >= 0 && $b['source'] !== 'block'
            ));
            $res->json([
                'stats' => Bookings::stats(),
                'arrivals' => array_slice($arrivals, 0, 12),
                'recent' => Bookings::listBookings(['sort' => 'created', 'limit' => 8]),
            ]);
            return;
        }

        if ($get && self::route('/calendar', $p)) {
            $from = Bookings::isDate($req->q('from')) ? $req->q('from') : Bookings::today();
            $days = max(7, min(62, Js::parseInt($req->q('days')) ?: 21));
            $to = Bookings::addDays($from, $days);
            $res->json([
                'from' => $from, 'to' => $to, 'days' => $days,
                'rooms' => Bookings::listRooms(true),
                'bookings' => array_merge(
                    Bookings::listBookings(['status' => 'active', 'from' => $from, 'to' => $to]),
                    Bookings::listBookings(['status' => 'checked_out', 'from' => $from, 'to' => $to])
                ),
                'capacity' => Bookings::hotelCapacity(),
                'guests' => array_map(fn ($n) => $n['guests'], Bookings::occupancy($from, $to)),
                'closures' => Closures::between($from, $to),
            ]);
            return;
        }

        // ---------- rooms ----------
        if ($get && self::route('/rooms', $p)) {
            $res->json(['rooms' => Bookings::listRooms(true), 'categories' => Bookings::listCategories(), 'mealPlans' => Bookings::mealPlans()]);
            return;
        }
        // ---------- the rate sheet: room categories, and each room's type, guests and price per meal plan ----------
        if ($get && self::route('/rates', $p)) { $res->json(Bookings::rateSheet()); return; }
        if ($m === 'PUT' && self::route('/rates', $p)) { $res->json(Bookings::saveRateSheet($body)); return; }
        if ($m === 'POST' && self::route('/categories', $p)) { $res->json(['category' => Bookings::saveCategory(null, $body)], 201); return; }
        if ($m === 'DELETE' && self::route('/categories/:id', $p, $params)) { Bookings::deleteCategory($params['id']); $res->json(['ok' => true]); return; }
        if ($m === 'PATCH' && self::route('/rooms/:id', $p, $params)) {
            $res->json(['room' => Bookings::updateRoom(Js::number($params['id']), $body)]);
            return;
        }

        // ---------- photo library ----------
        if ($get && self::route('/media', $p)) {
            $list = [];
            foreach (Media::get() as $key => $v) {
                $list[] = ['key' => (string) $key, 'width' => $v['width'] ?? null, 'height' => $v['height'] ?? null, 'sizes' => $v['sizes'] ?? [], 'uploaded' => !empty($v['uploaded']), 'base' => $v['base'] ?? null];
            }
            $res->json(['media' => $list]);
            return;
        }
        if ($m === 'POST' && self::route('/media', $p)) {
            $bytes = $req->isImage() ? $req->readRaw(self::UPLOAD_LIMIT) : '';
            if ($bytes === '') { $res->json(['error' => 'No image received.'], 400); return; }
            try {
                $key = Media::addUpload($bytes, rawurldecode((string) ($req->header('x-filename') ?? 'photo')));
                $res->json(['key' => $key, 'item' => Media::get()[$key]], 201);
            } catch (\RuntimeException $e) {
                $res->json(['error' => $e->getMessage()], 400);
            }
            return;
        }
        if ($m === 'DELETE' && self::route('/media/:key', $p, $params)) {
            $key = $params['key'];
            $inUse = array_column(array_filter(Bookings::listRooms(true), fn ($r) => in_array($key, $r['images'], true)), 'name');
            if ($inUse) { $res->json(['error' => 'This photo is used by: ' . implode(', ', $inUse) . '. Remove it from those rooms first.'], 409); return; }
            if (Site::uses($key)) { $res->json(['error' => 'This photo is on the home page. Change it in Admin → Website first.'], 409); return; }
            if (!Media::removeUpload($key)) { $res->json(['error' => 'Only photos you uploaded can be deleted.'], 400); return; }
            $res->json(['ok' => true]);
            return;
        }

        // ---------- bookings ----------
        if ($get && self::route('/bookings', $p)) {
            // each with guestConfirm: whether its confirmation link was sent, and whether the guest has confirmed
            // and slipWaiting: payment slips the guest sent that are not on a payment yet
            $res->json(['bookings' => Pos::withGuestSlips(Confirmations::decorate(Bookings::listBookings($req->query)))]);
            return;
        }

        if ($get && self::route('/bookings.csv', $p)) {
            $rows = array_map(fn ($b) => ['partyArea' => $b['partyArea'] ? 'Yes' : 'No', 'jblSpeaker' => $b['jblSpeaker'] ? 'Yes' : 'No',
                'checkOut' => $b['package'] === 'day_out' ? '' : $b['checkOut'],
                'commissionAmount' => $b['commission']['amount'], 'commissionTo' => $b['commission']['to']] + $b,
                Bookings::listBookings(array_merge($req->query, ['limit' => 2000])));
            $cols = ['ref', 'status', 'sourceLabel', 'packageLabel', 'roomName', 'checkIn', 'checkOut', 'nights', 'adults', 'children', 'partyArea', 'jblSpeaker',
                'guestName', 'guestEmail', 'guestPhone', 'guestIdNo', 'country', 'couponCode', 'couponDiscount', 'totalAmount', 'commissionAmount', 'commissionTo', 'netAmount',
                'holdUntil', 'specialRequests', 'adminNotes', 'createdAt'];
            $cell = function ($v) {
                $s = $v === null ? '' : Js::str($v);
                if (preg_match('/^[=+\-@\t\r]/', $s)) $s = "'" . $s; // avoid spreadsheet formula injection
                return '"' . str_replace('"', '""', $s) . '"';
            };
            $lines = [implode(',', $cols)];
            foreach ($rows as $r) $lines[] = implode(',', array_map(fn ($c) => $cell($r[$c] ?? null), $cols));
            $res->header('Content-Disposition', 'attachment; filename="topmountain-bookings-' . Bookings::today() . '.csv"');
            $res->send("\u{FEFF}" . implode("\r\n", $lines), 'text/csv; charset=utf-8');
            return;
        }

        if ($get && self::route('/bookings/:id', $p, $params)) {
            $b = Bookings::getBooking(Js::number($params['id']));
            if (!$b) { $res->json(['error' => 'Booking not found'], 404); return; }
            $res->json(['booking' => $b, 'guestSlips' => Pos::guestSlips($b['id'])]); // guestSlips: payment slips the guest sent
            return;
        }

        if ($m === 'POST' && self::route('/bookings', $p)) {
            $b = Bookings::createBooking($body, true, $admin['username']);
            $emailed = false;
            if (Js::truthy($body['notifyGuest'] ?? null) && $b['status'] === 'confirmed') $emailed = Mailer::notifyStatus($b);
            if ($b['status'] === 'confirmed') Whatsapp::bookingConfirmed($b);
            $res->json(['booking' => $b, 'emailed' => $emailed], 201);
            return;
        }

        if ($m === 'PATCH' && self::route('/bookings/:id', $p, $params)) {
            $id = Js::number($params['id']);
            $before = Bookings::getBooking($id);
            $b = Bookings::updateBooking($id, $body);
            $emailed = false;
            if (Js::truthy($body['notifyGuest'] ?? null) && $before && $before['status'] !== $b['status']) $emailed = Mailer::notifyStatus($b);
            if ($before && $before['status'] !== 'confirmed' && $b['status'] === 'confirmed') Whatsapp::bookingConfirmed($b);
            $res->json(['booking' => $b, 'emailed' => $emailed]);
            return;
        }

        // confirm a pending booking once the advance is received:
        // {rooms?, advance?: {amount, method, note, fee, details}, notifyGuest}
        if ($m === 'POST' && self::route('/bookings/:id/confirm', $p, $params)) {
            $advance = !empty($body['advance']) && Js::truthy($body['advance']['amount'] ?? null) ? Pos::paymentInput($body['advance']) : null; // checked before anything changes
            $b = Bookings::confirmBooking($params['id'], $body);
            $paymentId = null;
            if ($advance) {
                $raw = $body['advance'];
                if (Js::trim(Js::str($raw['note'] ?? '')) === '') $raw['note'] = 'Advance';
                $paymentId = Pos::addPayment($b['id'], $raw, $admin['username'])['paymentId'];
            }
            $emailed = Js::truthy($body['notifyGuest'] ?? null) && Mailer::notifyStatus($b);
            Whatsapp::bookingConfirmed($b);
            $res->json(['booking' => $b, 'emailed' => $emailed, 'advance' => $advance ? $advance['amount'] : 0, 'payment' => $paymentId ? Pos::payment($paymentId) : null]);
            return;
        }

        // email the payment invoice to the guest
        if ($m === 'POST' && self::route('/bookings/:id/invoice/email', $p, $params)) {
            $b = Bookings::getBooking(Js::number($params['id']));
            if (!$b || $b['source'] === 'block') { $res->json(['error' => 'Booking not found'], 404); return; }
            if (!Mailer::sendInvoice($b)) { $res->json(['error' => 'This guest has no email address. Add one to the booking, or share the link on WhatsApp.'], 400); return; }
            $res->json(['ok' => true, 'to' => $b['guestEmail'], 'outbox' => !Mailer::enabled()]);
            return;
        }

        // the booking's confirmation link for the guest: GET what it asks for and where it stands;
        // POST {via: link | whatsapp | email} notes that it was sent (email: sends it)
        if (($get || $m === 'POST') && self::route('/bookings/:id/confirm-link', $p, $params)) {
            $b = Bookings::getBooking(Js::number($params['id']));
            if (!$b || $b['source'] === 'block') { $res->json(['error' => 'Booking not found'], 404); return; }
            if ($get) { $res->json(['link' => Confirmations::forAdmin($b)]); return; }
            $via = (string) ($body['via'] ?? 'link');
            if ($via === 'email' && !Mailer::sendConfirmLink($b, Confirmations::url($b))) {
                $res->json(['error' => 'This guest has no email address. Add one to the booking, or send the link on WhatsApp.'], 400);
                return;
            }
            $res->json(['link' => Confirmations::markSent($b, $admin['username'], $via), 'to' => $via === 'email' ? $b['guestEmail'] : null, 'outbox' => $via === 'email' && !Mailer::enabled()]);
            return;
        }

        // send this booking's reminder now (arrival for a confirmed booking, payment for a pending one)
        if ($m === 'POST' && self::route('/bookings/:id/reminder', $p, $params)) {
            $b = Bookings::getBooking(Js::number($params['id']));
            if (!$b) { $res->json(['error' => 'Booking not found'], 404); return; }
            $kind = Reminders::sendFor($b);
            $res->json(['ok' => true, 'kind' => $kind, 'to' => $b['guestEmail'], 'outbox' => !Mailer::enabled()]);
            return;
        }

        // the rooms a per-person group can be given, and giving them
        if ($get && self::route('/bookings/:id/rooms', $p, $params)) { $res->json(['rooms' => Bookings::roomChoices($params['id'])]); return; }
        if ($m === 'POST' && self::route('/bookings/:id/rooms', $p, $params)) { $res->json(['booking' => Bookings::assignRooms($params['id'], $body['rooms'] ?? null)]); return; }

        // availability + price check used by the admin booking forms:
        // kind, packageId, roomId, rooms (comma ids), checkIn, checkOut, adults, children, partyArea, jblSpeaker, exclude
        if ($get && self::route('/check', $p)) {
            // an outdoor party: one date — is the party area free, are the rooms it holds free, and who else is booked then
            if ($req->q('kind') === 'event') {
                $date = $req->q('checkIn');
                if (!Bookings::isDate($date)) { $res->json(['ok' => false, 'reason' => 'dates']); return; }
                $end = Bookings::addDays($date, 1);
                $ex = Js::number($req->q('exclude'));
                $exclude = (is_nan($ex) || $ex == 0) ? 0 : (int) $ex;
                $occ = Bookings::occupancy($date, $end, $exclude);
                $busy = [];
                foreach ($occ as $night) $busy += $night['rooms'];
                $ids = is_string($req->q('rooms')) && $req->q('rooms') !== '' ? array_map('intval', explode(',', $req->q('rooms'))) : [];
                $party = Bookings::partyAreaStatus($date, $end, $exclude);
                $others = in_array($req->q('eventOthers'), ['rooms', 'private'], true) ? $req->q('eventOthers') : 'open';
                $res->json(['ok' => !array_intersect($ids, array_keys($busy)), 'kind' => 'event', 'nights' => 0, 'lines' => [], 'total' => 0, 'stay' => 0, 'couponDiscount' => 0,
                    'listRates' => [], 'partyArea' => $party, 'capacity' => null, 'rooms' => $ids, 'busy' => array_map('intval', array_keys($busy)),
                    'closed' => Closures::clashes($date, $end, 'event', $exclude),
                    // when the party closes the date, the bookings already on it
                    'alreadyBooked' => $others === 'open' ? [] : array_map(fn ($b) => ['id' => $b['id'], 'ref' => $b['ref'], 'guestName' => $b['guestName'], 'what' => $b['place'] ?: $b['packageLabel']],
                        array_values(array_filter(Closures::affected($date, $date, $exclude, $others === 'private'), fn ($b) => $b['kind'] !== 'event')))]);
                return;
            }
            $kind = in_array($req->q('kind'), ['per_person', 'day_out'], true) ? $req->q('kind') : 'room';
            $pkg = $kind === 'room' ? null : Bookings::getPackage($req->q('packageId'));
            if ($kind !== 'room' && !$pkg) { $res->json(['ok' => false, 'reason' => 'package']); return; }
            if ($pkg) $kind = $pkg['kind'] === 'day' ? 'day_out' : 'per_person';
            $dayOut = $kind === 'day_out';
            $checkIn = $req->q('checkIn');
            $checkOut = $dayOut && Bookings::isDate($checkIn) ? Bookings::addDays($checkIn, 1) : $req->q('checkOut');
            if (!Bookings::isDate($checkIn) || !Bookings::isDate($checkOut) || strcmp($checkOut, $checkIn) <= 0) { $res->json(['ok' => false, 'reason' => 'dates']); return; }
            // a room booking's rooms: several (rooms=1,2,3) or one (roomId=1); the first is its main room
            $roomList = [];
            if ($kind === 'room') {
                $wanted = is_string($req->q('rooms')) && $req->q('rooms') !== '' ? array_slice(explode(',', $req->q('rooms')), 0, Bookings::MAX_ROOMS) : [$req->q('roomId')];
                foreach ($wanted as $w) { $r = Bookings::getRoom($w); if ($r) $roomList[$r['id']] = $r; }
                $roomList = array_values($roomList);
            }
            $room = $roomList[0] ?? null;
            $ex = Js::number($req->q('exclude'));
            $exclude = (is_nan($ex) || $ex == 0) ? 0 : (int) $ex;
            if ($kind === 'room' && !$room) {
                // no room chosen yet: which rooms are taken on these dates, and by whom (the New booking room picker)
                $taken = Bookings::takenRooms($checkIn, $checkOut, $exclude);
                $res->json(['ok' => false, 'reason' => 'room', 'nights' => Bookings::nightsBetween($checkIn, $checkOut), 'busy' => array_keys($taken), 'busyRefs' => (object) $taken,
                    'closed' => Closures::clashes($checkIn, $checkOut, $kind, $exclude)]);
                return;
            }
            $n = $dayOut ? 0 : Bookings::nightsBetween($checkIn, $checkOut);
            $adults = max(0, min(100, Js::parseInt($req->q('adults')) ?? 0));
            $children = max(0, min(100, Js::parseInt($req->q('children')) ?? 0));
            $party = !$dayOut && Js::truthy($req->q('partyArea'));
            // rates agreed with the guest (empty = the price list)
            $rate = fn (string $k) => is_string($req->q($k)) && $req->q($k) !== '' && ctype_digit($req->q($k)) ? (int) $req->q($k) : null;
            $rates = $kind === 'room' ? ['room' => $rate('rateRoom')] : ['adult' => $rate('rateAdult'), 'child' => $rate('rateChild')];
            // a room booking's meal plan, and a whole category (its rooms join the booking, at its one price)
            [$plan, $whole] = $kind === 'room' ? self::planQuery($req) : ['RO', null];
            if ($whole) {
                $all = [];
                foreach (array_merge(Bookings::categoryRooms($whole['id']), $roomList) as $r) $all[$r['id']] ??= $r;
                $roomList = array_values($all);
                $room = $roomList[0] ?? $room;
            }
            $quote = Bookings::quote($kind, $room, $pkg, $n, $adults, $children, $party, Js::truthy($req->q('jblSpeaker')), $rates, $roomList, $plan, $whole);
            // editing a booking that used a coupon: it comes off the new room charge again
            $couponOff = 0;
            $eb = $exclude ? Bookings::getBooking($exclude) : null;
            if ($eb && $eb['couponCode'] !== '') {
                $cp = Coupons::find($eb['couponCode']);
                $couponOff = min($quote['total'], $cp ? Coupons::discountOn($cp['type'], (int) $cp['value'], $quote['stay']) : $eb['couponDiscount']);
            }
            $out = ['ok' => true, 'kind' => $kind, 'nights' => $n, 'lines' => $quote['lines'], 'total' => $quote['total'] - $couponOff, 'stay' => $quote['stay'], 'couponDiscount' => $couponOff,
                // several rooms: the room rate is a night for all of them together
                'listRates' => $kind === 'room' ? ['room' => Bookings::roomsPrice($roomList, $plan, $whole)] : ['adult' => $pkg['priceAdult'], 'child' => $pkg['priceChild']],
                'mealPlan' => $kind === 'room' ? $plan : '', 'wholeRooms' => $whole ? array_column($roomList, 'id') : [],
                'partyArea' => $party ? Bookings::partyAreaStatus($checkIn, $checkOut, $exclude) : null, 'capacity' => null, 'rooms' => [], 'busy' => [],
                'closed' => Closures::clashes($checkIn, $checkOut, $kind, $exclude)]; // closed days and private parties (a warning: staff can still book)
            if (!$dayOut) {
                $occ = Bookings::occupancy($checkIn, $checkOut, $exclude);
                $left = Bookings::capacityLeft($occ);
                $need = $kind === 'room' ? array_sum(array_column($roomList, 'capacity')) : $adults + $children;
                $out['capacity'] = ['left' => $left, 'needed' => $need, 'over' => $left !== null && $left < $need];
                $busy = [];
                foreach ($occ as $night) $busy += $night['rooms'];
                $out['busy'] = array_map('intval', array_keys($busy)); // rooms taken by other bookings
                $out['busyRefs'] = (object) Bookings::refsByRoom($busy); // and the booking that has each
                if ($kind === 'room') {
                    $ids = array_column($roomList, 'id');
                    $out['ok'] = !array_intersect($ids, array_keys($busy));
                    $out['rooms'] = $ids;
                } else {
                    $ids = is_string($req->q('rooms')) && $req->q('rooms') !== '' ? array_map('intval', explode(',', $req->q('rooms'))) : null;
                    if ($ids) {
                        $out['ok'] = !array_intersect($ids, array_keys($busy));
                        $out['rooms'] = $ids;
                    } else {
                        $alloc = Bookings::allocate($adults + $children, $occ);
                        $out['ok'] = $alloc !== null;
                        $out['rooms'] = $alloc ?? [];
                    }
                }
            }
            $res->json($out);
            return;
        }
    }

    /**
     * The permissions (any one of them) an /api/admin call needs (Auth::ROLES); [] = any signed-in login.
     * Checked before the call runs, so a front-desk login can't reach the rest even by typing URLs.
     */
    public static function permFor(string $method, string $path): array
    {
        $p = strtolower(rtrim($path, '/')) ?: '/';
        $read = in_array($method, ['GET', 'HEAD'], true);
        if (in_array($p, ['/me', '/password'], true)) return [];
        if (preg_match('#^/users(/|$)#', $p)) return ['users'];
        if (preg_match('#^/finance(/|$)#', $p)) return ['finance'];
        if (preg_match('#^/coupons(\.csv)?(/|$)#', $p)) return ['coupons'];
        if (preg_match('#^/(site|media|whatsapp|notify-emails|reminders|chatbot|calendar-feed|test-email)(/|$)#', $p)) return ['site'];
        if (preg_match('#^/(setup|packages|printing)(/|$)#', $p)) return ['setup'];
        if (preg_match('#^/rooms(/|$)#', $p)) return $read ? ['bookings'] : ['setup'];
        if (preg_match('#^/(rates|categories)(/|$)#', $p)) return ['setup'];
        if (preg_match('#^/closures(/|$)#', $p)) return $read ? ['bookings', 'pos'] : ['setup'];
        if (preg_match('#^/pos/(categories|items)(/|$)#', $p)) return ['menu'];
        if (preg_match('#^/pos/(bills|payments)/[^/]+/void$#', $p)) return ['void'];
        // payment slips: attached when confirming a booking or taking a payment, seen wherever payments are listed
        if ($p === '/pos/slips') return ['bookings', 'pos'];
        if ((preg_match('#^/pos/payments/\d+/slip$#', $p) || preg_match('#^/pos/guest-slips/\d+$#', $p)) && $read) return ['bookings', 'pos', 'finance'];
        if (preg_match('#^/pos(/|$)#', $p)) return ['pos'];
        // the POS opens a booking to check it in, gives a group its rooms and prices walk-ins
        if (preg_match('#^/bookings/\d+$#', $p) && $read) return ['bookings', 'pos'];
        if (preg_match('#^/bookings/\d+/rooms$#', $p) || $p === '/check') return ['bookings', 'pos'];
        return ['bookings'];
    }

    /** /api/admin/pos/… — room bills, items, transfers, checkout and bill history. */
    private static function posApi(Request $req, Response $res, string $p, string $by): void
    {
        $get = self::isGet($req);
        $post = $req->method === 'POST';
        $body = $req->body;
        $params = [];

        if ($get && self::route('/', $p)) {
            $res->json([
                'today' => Bookings::today(),
                'rooms' => array_map(fn ($r) => ['id' => $r['id'], 'name' => $r['name'], 'type' => $r['type'], 'capacity' => $r['capacity'], 'active' => $r['active']], Bookings::listRooms(true)),
                'folios' => Pos::openFolios(),
                'items' => Pos::listItems(),
                'categories' => Pos::listCategories(),
                'methods' => Pos::METHODS,
                'serviceCharge' => Pos::servicePct(),
            ]);
            return;
        }
        // check-in, and walk-ins without a booking
        if ($get && self::route('/arrivals', $p)) { $res->json(['bookings' => Pos::arrivals($req->q('q'))]); return; }
        if ($post && self::route('/checkin/:id', $p, $params)) { $res->json(['folio' => Pos::checkIn($params['id'], $body, $by)]); return; }
        if ($post && self::route('/walkin', $p)) { $res->json(['folio' => Pos::walkIn($body, $by)], 201); return; }
        // menu: categories and items
        if ($get && self::route('/categories', $p)) { $res->json(['categories' => Pos::listCategories(true)]); return; }
        if ($post && self::route('/categories', $p)) { $res->json(['category' => Pos::saveCategory(null, $body)], 201); return; }
        if ($req->method === 'PATCH' && self::route('/categories/:id', $p, $params)) {
            $id = Bookings::int($params['id'], 1, PHP_INT_MAX);
            if (!$id) { $res->json(['error' => 'Category not found'], 404); return; }
            $res->json(['category' => Pos::saveCategory($id, $body)]);
            return;
        }
        if ($get && self::route('/items', $p)) { $res->json(['items' => Pos::listItems(true)]); return; }
        if ($post && self::route('/items', $p)) { $res->json(['item' => Pos::saveItem(null, $body)], 201); return; }
        if ($req->method === 'PATCH' && self::route('/items/:id', $p, $params)) {
            $id = Bookings::int($params['id'], 1, PHP_INT_MAX);
            if (!$id) { $res->json(['error' => 'Item not found'], 404); return; }
            $res->json(['item' => Pos::saveItem($id, $body)]);
            return;
        }
        // room bills
        if ($get && self::route('/folios/:id', $p, $params)) { $res->json(['folio' => Pos::folio($params['id'])]); return; }
        if ($post && self::route('/folios/:id/charges', $p, $params)) { $res->json(['folio' => Pos::addCharges($params['id'], $body['lines'] ?? null, $by)], 201); return; }
        if ($post && self::route('/folios/:id/payments', $p, $params)) { $res->json(['folio' => Pos::addPayment($params['id'], $body, $by)], 201); return; }
        if ($post && self::route('/charges/:id/void', $p, $params)) { $res->json(['folio' => Pos::voidCharge($params['id'], $body['reason'] ?? null, $by, $body['qty'] ?? null)]); return; }
        if ($post && self::route('/payments/:id/void', $p, $params)) { $res->json(['folio' => Pos::voidPayment($params['id'], $by)]); return; }
        // payment slips: upload the photo or PDF first, then send its name as the payment's 'slip'
        if ($post && self::route('/slips', $p)) {
            $bytes = $req->isImage() || $req->mime() === 'application/pdf' ? $req->readRaw(Pos::SLIP_LIMIT) : '';
            $res->json(['slip' => Pos::saveSlip($bytes)], 201);
            return;
        }
        if ($get && (self::route('/payments/:id/slip', $p, $params) || self::route('/guest-slips/:gid', $p, $params))) {
            $slip = isset($params['gid']) ? Pos::guestSlipFile($params['gid']) : Pos::slipOf($params['id']);
            if (!$slip) { $res->json(['error' => 'Slip not found.'], 404); return; }
            [$bytes, $type, $name] = $slip;
            $res->header('Content-Disposition', 'inline; filename="' . $name . '"');
            $res->header('Cache-Control', 'private, max-age=3600');
            $res->send($bytes, $type);
            return;
        }
        // receipts: email one to the guest
        if ($post && self::route('/payments/:id/receipt/email', $p, $params)) {
            $pay = Pos::payment($params['id']);
            if (!$pay) { $res->json(['error' => 'Payment not found'], 404); return; }
            $b = Bookings::getBooking($pay['bookingId']);
            if (!$b || !Mailer::sendReceipt($pay, $b)) { $res->json(['error' => 'This guest has no email address. Add one to the booking, or share the link on WhatsApp.'], 400); return; }
            $res->json(['ok' => true, 'to' => $b['guestEmail'], 'outbox' => !Mailer::enabled()]);
            return;
        }
        // staff who can share a bill's service charge
        if ($get && self::route('/staff', $p)) { $res->json(['staff' => Finance::serviceStaff()]); return; }
        // what a coupon takes off at checkout: ?code&bookingIds=1,2
        if ($get && self::route('/coupon', $p)) { $res->json(['coupon' => Pos::couponPreview($req->q('code'), $req->q('bookingIds'))]); return; }
        if ($post && self::route('/transfer', $p)) { $res->json(Pos::transfer($body['from'] ?? null, $body['to'] ?? null, $body['lines'] ?? null, $by)); return; }
        // bills
        if ($post && self::route('/checkout', $p)) {
            $bill = Pos::checkout($body['bookingIds'] ?? null, $body, $by);
            $emailed = Js::truthy($body['emailGuest'] ?? null) && Mailer::sendBill($bill);
            $res->json(['bill' => $bill, 'emailed' => $emailed], 201);
            return;
        }
        if ($get && self::route('/bills', $p)) { $res->json(['bills' => Pos::listBills($req->query)]); return; }
        if (self::route('/bills/:id', $p, $params) || self::route('/bills/:id/:action', $p, $params)) {
            $bill = Pos::getBill($params['id']);
            if (!$bill) { $res->json(['error' => 'Bill not found'], 404); return; }
            $action = $params['action'] ?? '';
            if ($get && $action === '') { $res->json(['bill' => $bill]); return; }
            if ($post && $action === 'payments') { $res->json(['bill' => Pos::addBillPayment($bill['id'], $body, $by)], 201); return; }
            if ($post && $action === 'void') { $res->json(['bill' => Pos::voidBill($bill['id'], $body['reason'] ?? null, $by)]); return; }
            // who served: the service charge shared among them ({employeeIds, amounts?: {id: amount}})
            if ($get && $action === 'service') { $res->json(['service' => $bill['serviceCharge'], 'shares' => $bill['serviceShares'], 'staff' => Finance::serviceStaff()]); return; }
            if ($post && $action === 'service') { $res->json(Finance::setShares($bill['id'], $body['employeeIds'] ?? null, $body['amounts'] ?? null, $by)); return; }
            if ($post && $action === 'email') {
                if ($bill['status'] !== 'issued') { $res->json(['error' => 'This bill is void.'], 409); return; }
                if (!Mailer::sendBill($bill)) { $res->json(['error' => 'This guest has no email address.'], 400); return; }
                $res->json(['ok' => true]);
                return;
            }
        }
    }

    /** /api/admin/finance/… — expenses, staff and their pay, service charge shares, bank, stock, commissions, figures. */
    private static function financeApi(Request $req, Response $res, string $p, string $by): void
    {
        $get = self::isGet($req);
        $m = $req->method;
        $body = $req->body;
        $params = [];
        $period = fn () => Finance::period($req->q('from'), $req->q('to'));
        $id = function () use (&$params): int { return Bookings::int($params['id'] ?? null, 1, PHP_INT_MAX) ?? 0; }; // route() fills $params

        if ($get && self::route('/meta', $p)) {
            $res->json(['accounts' => Finance::accounts(), 'accountLabel' => Finance::accountField()['label'], 'expenseCategories' => Finance::expenseCategories(),
                'payKinds' => Finance::PAY_KINDS, 'employees' => Finance::employees(), 'period' => Finance::period(null, null)]);
            return;
        }
        if ($get && self::route('/summary', $p)) { $res->json(['summary' => Finance::summary($period())]); return; }
        // every money movement: ?type=in|out|move&account&q
        $txOpts = fn () => ['type' => (string) ($req->q('type') ?? ''), 'account' => (string) ($req->q('account') ?? ''), 'q' => (string) ($req->q('q') ?? '')];
        if ($get && self::route('/transactions', $p)) { $res->json(['transactions' => Finance::transactions($period(), $txOpts())]); return; }
        if ($get && self::route('/transactions.csv', $p)) {
            $pr = $period();
            $t = Finance::transactions($pr, $txOpts());
            $cell = function ($v) {
                $s = $v === null ? '' : Js::str($v);
                if (preg_match('/^[=+\-@\t\r]/', $s)) $s = "'" . $s; // no spreadsheet formulas
                return '"' . str_replace('"', '""', $s) . '"';
            };
            $lines = ['"Date","Time","Type","What","Details","Account","In","Kept by agent or bank","Out","Into the bank","By"'];
            foreach ($t['rows'] as $r) $lines[] = implode(',', array_map($cell, [$r['day'], $r['time'], $r['kind'], $r['what'], trim($r['detail'] . ($r['keptBy'] ? ' · ' . $r['keptBy'] : ''), ' ·'), $r['account'],
                $r['in'] ?: '', $r['kept'] ?: '', $r['out'] ?: '', $r['moved'] ?: '', $r['by']]));
            $res->header('Content-Disposition', 'attachment; filename="topmountain-transactions-' . $pr['from'] . '-to-' . $pr['to'] . '.csv"');
            $res->send("\u{FEFF}" . implode("\r\n", $lines), 'text/csv; charset=utf-8');
            return;
        }
        // expenses
        if ($get && self::route('/expenses', $p)) { $pr = $period(); $res->json(['period' => $pr, 'expenses' => Finance::expenses($pr, (string) ($req->q('category') ?? ''))]); return; }
        if ($m === 'POST' && self::route('/expenses', $p)) { $res->json(['expense' => Finance::saveExpense(null, $body, $by)], 201); return; }
        if (self::route('/expenses/:id', $p, $params)) {
            if ($m === 'PATCH') { $res->json(['expense' => Finance::saveExpense($id(), $body, $by)]); return; }
            if ($m === 'DELETE') { Finance::deleteExpense($id()); $res->json(['ok' => true]); return; }
        }
        // employees and their pay
        if ($get && self::route('/employees', $p)) { $res->json(['employees' => Finance::employees()]); return; }
        if ($m === 'POST' && self::route('/employees', $p)) { $res->json(['employee' => Finance::saveEmployee(null, $body)], 201); return; }
        if ($m === 'PATCH' && self::route('/employees/:id', $p, $params)) { $res->json(['employee' => Finance::saveEmployee($id(), $body)]); return; }
        if ($get && self::route('/staff-payments', $p)) { $pr = $period(); $res->json(['period' => $pr, 'payments' => Finance::staffPayments(['from' => $pr['from'], 'to' => $pr['to'], 'employeeId' => $req->q('employeeId'), 'kind' => $req->q('kind')])]); return; }
        if ($m === 'POST' && self::route('/staff-payments', $p)) { $res->json(['payment' => Finance::addStaffPayment($body, $by)], 201); return; }
        if ($m === 'DELETE' && self::route('/staff-payments/:id', $p, $params)) { Finance::deleteStaffPayment($id()); $res->json(['ok' => true]); return; }
        if ($get && self::route('/salaries', $p)) { $res->json(['salaries' => Finance::salaryMonth((string) ($req->q('month') ?? ''))]); return; }
        // the service charge shared among the staff who served
        if ($get && self::route('/service', $p)) { $pr = $period(); $res->json(['period' => $pr, 'bills' => Finance::serviceBills($pr), 'balances' => Finance::serviceBalances($pr), 'staff' => Finance::serviceStaff()]); return; }
        if ($m === 'POST' && self::route('/service/:id', $p, $params)) { $res->json(Finance::setShares($id(), $body['employeeIds'] ?? null, $body['amounts'] ?? null, $by)); return; }
        // money into each bank account
        if ($get && self::route('/bank', $p)) { $pr = $period(); $res->json(['period' => $pr, 'moves' => Finance::bankMoves($pr), 'received' => Finance::received($pr)]); return; }
        if ($m === 'POST' && self::route('/bank', $p)) { $res->json(['move' => Finance::saveBankMove($body, $by)], 201); return; }
        if ($m === 'DELETE' && self::route('/bank/:id', $p, $params)) { Finance::deleteBankMove($id()); $res->json(['ok' => true]); return; }
        // stock
        if ($get && self::route('/stock', $p)) { $pr = $period(); $res->json(['period' => $pr, 'items' => Finance::stockItems(), 'moves' => Finance::stockMoves($pr)]); return; }
        if ($m === 'POST' && self::route('/stock/items', $p)) { $res->json(['item' => Finance::saveStockItem(null, $body)], 201); return; }
        if ($m === 'PATCH' && self::route('/stock/items/:id', $p, $params)) { $res->json(['item' => Finance::saveStockItem($id(), $body)]); return; }
        if ($m === 'POST' && self::route('/stock/moves', $p)) { $res->json(['item' => Finance::addStockMove($body, $by)], 201); return; }
        // commissions and figures
        if ($get && self::route('/commissions', $p)) { $res->json(['commissions' => Finance::commissions($period())]); return; }
        if ($get && self::route('/analytics', $p)) { $res->json(['analytics' => Finance::analytics($period())]); return; }
    }

    // ---------- pages (tiny token templating so resort details live in .env) ----------
    private static function pages(Request $req, Response $res): void
    {
        if (!self::isGet($req)) return;
        $p = $req->path;
        $cfg = Config::get();

        $booking = fn () => [
            'rooms' => array_map([self::class, 'publicRoom'], Bookings::listRooms()),
            // the rate sheet's categories (with any whole-category option) and the meal plans
            'categories' => array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'whole' => $c['whole']], Bookings::rateSheet(true)['categories']),
            'mealPlans' => Bookings::mealPlans(),
            'labels' => Bookings::labels(),
            'packages' => array_map(fn ($o) => array_diff_key($o, ['active' => 0, 'sort' => 0]), Bookings::listPackages()),
            'prices' => Bookings::prices(),
            'payment' => Bookings::paymentInfo(),
            'serviceCharge' => Pos::servicePct(),
            'groupMax' => ['adults' => Bookings::MAX_GROUP_ADULTS, 'children' => Bookings::MAX_GROUP_CHILDREN],
        ];
        // the home page loads its own photos (js/data/media.js); its booking form asks /api/availability
        if ($p === '/') { self::renderPage($res, 'index.html', ['media' => [], 'mapLink' => Config::location()['place'], 'chat' => Chatbot::webBoot()]); return; }
        if (self::route('/book', $p)) {
            $b = $booking();
            $keys = array_merge(['cabana-hillside', 'estate-aerial', 'dining-deck'], array_map(fn ($r) => $r['images'][0] ?? '', $b['rooms']));
            self::renderPage($res, 'book.html', $b + ['media' => Media::pick($keys), 'chat' => Chatbot::webBoot()]);
            return;
        }
        if (self::route('/admin', $p)) {
            if (!str_ends_with($p, '/')) { $res->redirect(301, '/admin/'); return; }
            $res->header('X-Robots-Tag', 'noindex, nofollow');
            self::renderPage($res, 'admin.html', []);
            return;
        }
        // the POS screen (signed-in staff; it talks to the admin API)
        if (self::route('/pos', $p)) {
            $res->header('X-Robots-Tag', 'noindex, nofollow');
            self::renderPage($res, 'pos.html', []);
            return;
        }
        // a booking's confirmation page: the guest checks it, fills in what is missing and confirms (Confirmations)
        if (self::route('/confirm/:ref/:code', $p, $params)) {
            $res->header('X-Robots-Tag', 'noindex, nofollow');
            $res->header('Cache-Control', 'no-store');
            $res->header('Referrer-Policy', 'no-referrer');
            $b = Confirmations::byLink($params['ref'], $params['code']);
            if (!$b) { $res->send(self::versionAssets(Docs::notFound()), 'text/html; charset=utf-8', 404); return; }
            $c = Confirmations::page($b);
            $c['api'] = '/api' . Confirmations::path($b);
            self::renderPage($res, 'confirm.html', ['confirm' => $c, 'media' => Media::pick(array_values(array_filter(['cabana-hillside', $c['image']])))]);
            return;
        }
        // a booking's payment invoice, payment receipts and bills, at private links (?embed=1: inside the admin panel or the POS)
        if (self::route('/invoice/:ref/:code', $p, $params) || self::route('/receipt/:no/:code', $p, $params) || self::route('/bill/:bill/:code', $p, $params)) {
            $res->header('X-Robots-Tag', 'noindex, nofollow');
            $res->header('Cache-Control', 'no-store');
            $res->header('Referrer-Policy', 'no-referrer');
            $embed = $req->q('embed') === '1';
            if (isset($params['ref'])) {
                $b = Docs::invoiceBooking($params['ref'], $params['code']);
                $html = $b ? Docs::invoiceHtml($b, $embed) : null;
            } elseif (isset($params['bill'])) {
                $bill = Docs::billByLink($params['bill'], $params['code']);
                $html = $bill ? Docs::billHtml($bill, $embed) : null;
            } else {
                $pay = Docs::receiptPayment($params['no'], $params['code']);
                $html = $pay ? Docs::receiptHtml($pay, $embed) : null;
            }
            $res->send(self::versionAssets($html ?? Docs::notFound()), 'text/html; charset=utf-8', $html ? 200 : 404);
            return;
        }
        // the finance summary for a period, to print or save as a PDF (signed-in admins)
        if (self::route('/finance/summary', $p)) {
            $who = Auth::current();
            if (!$who || !Auth::can($who, 'finance')) return; // → 404
            $res->header('X-Robots-Tag', 'noindex, nofollow');
            $res->header('Cache-Control', 'no-store');
            $html = Docs::summaryHtml(Finance::summary(Finance::period($req->q('from'), $req->q('to'))), $req->q('embed') === '1', Auth::displayName($who));
            $res->send(self::versionAssets($html), 'text/html; charset=utf-8');
            return;
        }
        // the bookings for Google Calendar (Admin → Settings): the secret link is the only key
        if (self::route('/calendar/:token.ics', $p, $params)) {
            if (!Ical::matches($params['token'])) return; // → 404
            $res->header('Cache-Control', 'no-cache, private');
            $res->header('X-Robots-Tag', 'noindex, nofollow');
            $res->header('Content-Disposition', 'inline; filename="topmountain-bookings.ics"');
            $res->send(Ical::feed(), 'text/calendar; charset=utf-8');
            return;
        }
        if (self::route('/robots.txt', $p)) {
            $res->send("User-agent: *\nDisallow: /admin/\nDisallow: /pos\nDisallow: /api/\nSitemap: {$cfg['baseUrl']}/sitemap.xml\n", 'text/plain; charset=utf-8');
            return;
        }
        if (self::route('/sitemap.xml', $p)) {
            $res->send('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>' . $cfg['baseUrl'] . '/</loc></url><url><loc>' . $cfg['baseUrl'] . '/book</loc></url></urlset>', 'application/xml; charset=utf-8');
            return;
        }
    }

    private static function esc(mixed $s): string
    {
        return strtr($s === null ? '' : Js::str($s), ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']);
    }

    private static function renderPage(Response $res, string $name, array $boot): void
    {
        $cfg = Config::get();
        $s = Site::info(); // .env, with the changes made in Admin → Website
        $wa = Site::waDigits($s['whatsapp']);
        $nonce = self::$nonce;
        $loc = Config::location();
        // the photo library: the page's own list when it gives one (the full library is large)
        $media = $boot['media'] ?? Media::get();
        unset($boot['media']);
        $bootJson = str_replace('<', '\\u003c', Js::json([
            'site' => ['name' => $s['name'], 'phone' => $s['phone'], 'whatsapp' => $wa, 'email' => $s['email'], 'currency' => $s['currency'], 'checkIn' => $s['checkInTime'], 'checkOut' => $s['checkOutTime'], 'heroVideo' => $s['heroVideo']],
            'media' => (object) $media,
            'today' => Bookings::today(),
        ] + $boot));
        $tokens = [
            'site.name' => self::esc($s['name']),
            'site.phone' => self::esc($s['phone']),
            'site.phoneHref' => self::esc(preg_replace('/[^\d+]/', '', $s['phone'])),
            'site.whatsapp' => self::esc($wa),
            'site.email' => self::esc($s['email']),
            'site.address' => self::esc($s['address']),
            'site.facebook' => self::esc($s['facebook']),
            'site.instagram' => self::esc($s['instagram']),
            'site.poweredBy' => $s['poweredBy'] === '' ? '' : 'Powered by ' . (preg_match('#^https?://#', $s['poweredByUrl'])
                ? '<a class="powered" href="' . self::esc($s['poweredByUrl']) . '" target="_blank" rel="noopener">' . self::esc($s['poweredBy']) . '</a>'
                : '<b class="powered">' . self::esc($s['poweredBy']) . '</b>'),
            'site.mapEmbed' => self::esc($loc['embed']),
            'site.mapLink' => self::esc($loc['place']),
            'site.directions' => self::esc($loc['directions']),
            'site.coords' => self::esc($loc['coords']),
            'site.plusCode' => self::esc($loc['plusCode']),
            // for the page's structured data: the exact spot and the map listing
            'site.ldGeo' => $loc['lat'] === null ? '' : ',' . substr(str_replace('<', '\\u003c', Js::json([
                'geo' => ['@type' => 'GeoCoordinates', 'latitude' => $loc['lat'], 'longitude' => $loc['lng']], 'hasMap' => $loc['place'],
            ])), 1, -1),
            'site.checkIn' => self::esc($s['checkInTime']),
            'site.checkOut' => self::esc($s['checkOutTime']),
            'baseUrl' => self::esc($cfg['baseUrl']),
            'year' => date('Y'),
            'nonce' => $nonce,
            'boot' => '<script nonce="' . $nonce . '">window.BOOT=' . $bootJson . ';</script>',
        ];
        if ($name === 'index.html') {
            // the home page's photos (Admin → Website)
            $alts = Site::alts();
            foreach (Site::images() as $slot => $key) {
                $tokens["img.$slot"] = self::esc($key);
                $tokens["alt.$slot"] = self::esc($alts[$slot]);
            }
            $hero = Site::hero();
            $tokens['home.heroPreload'] = $hero ? self::heroPreload($hero[0]['key']) : '';
            $tokens['home.heroCaption'] = self::esc($hero[0]['caption'] ?? '');
        }
        // the cancellation policy under the booking steps (Admin → Booking setup); nothing when it's empty
        if ($name === 'book.html') $tokens['book.policy'] = self::policySection(Bookings::cancellationPolicy());
        // what the booking page promises: confirmed once the advance is paid, or (no advance) nothing to pay before arrival
        if ($name === 'book.html') {
            $adv = Bookings::advanceRule()['on'];
            $tokens['book.agree'] = $adv ? 'it is confirmed once ' . self::esc($s['name']) . ' receives the advance payment.'
                : 'there is nothing to pay now: ' . self::esc($s['name']) . ' calls me to confirm it, and I pay at the resort.';
            $tokens['book.perk'] = $adv ? 'Confirmed once the advance is paid' : 'Nothing to pay now — pay at the resort';
        }
        $tpl = (string) file_get_contents(YDR_ROOT . '/views/' . $name);
        $html = preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', fn ($m) => array_key_exists($m[1], $tokens) ? $tokens[$m[1]] : $m[0], $tpl);
        $html = self::versionAssets((string) $html);
        $res->header('Cache-Control', $name === 'confirm.html' ? 'no-store' : 'no-cache'); // a guest's confirmation page holds their details
        $res->send((string) $html, 'text/html; charset=utf-8');
    }

    /**
     * Add ?v=<file date> to the page's /js and /css links. Browsers keep these files for a week
     * (.htaccess), so without it they would run the old scripts for days after an upload.
     */
    public static function versionAssets(string $html): string
    {
        return (string) preg_replace_callback('#\b(src|href)="/((?:js|css)/(?:[\w-]+/)*[\w.-]+\.(?:js|css))"#', function ($m) {
            $t = @filemtime(YDR_PUBLIC . '/' . $m[2]);
            return $t ? $m[1] . '="/' . $m[2] . '?v=' . $t . '"' : $m[0];
        }, $html);
    }

    /**
     * The booking page's cancellation policy as HTML. The admin types plain text: a blank line
     * starts a new paragraph, and lines starting with "-", "*" or "•" become a bulleted list.
     * Everything is escaped. admin-setup.js previews it the same way.
     */
    public static function policySection(string $text): string
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($text === '') return '';
        $html = '';
        $para = [];
        $items = [];
        $flush = function () use (&$html, &$para, &$items) {
            if ($para) $html .= '<p>' . implode('<br>', $para) . '</p>';
            if ($items) $html .= '<ul>' . implode('', array_map(fn ($i) => "<li>$i</li>", $items)) . '</ul>';
            $para = [];
            $items = [];
        };
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') { $flush(); continue; }
            if (preg_match('/^[-*\x{2022}]\s*(.+)$/u', $line, $m)) {
                if ($para) $flush();
                $items[] = self::esc($m[1]);
            } else {
                if ($items) $flush();
                $para[] = self::esc($line);
            }
        }
        $flush();
        return '<section class="bk-policy" id="cancellation-policy" aria-labelledby="policy-title">'
            . '<h2 id="policy-title">Cancellation policy</h2><div class="bk-policy__body">' . $html . '</div></section>';
    }

    /** Load the first slideshow photo early, at the size that suits the screen. */
    private static function heroPreload(string $key): string
    {
        $m = Media::get()[$key] ?? null;
        if (!$m || empty($m['sizes'])) return '';
        $sizes = array_map('intval', $m['sizes']);
        sort($sizes);
        $href = array_reduce($sizes, fn ($best, $w) => abs($w - 1920) < abs($best - 1920) ? $w : $best, $sizes[0]);
        $k = self::esc($key);
        $set = implode(', ', array_map(fn ($w) => "/media/$k-$w.webp {$w}w", $sizes));
        return '<link rel="preload" as="image" href="/media/' . $k . '-' . $href . '.webp" imagesrcset="' . $set . '" imagesizes="max(120vw, 167vh)">';
    }

    /** A short page in the website's look: the 404 and "back soon" pages. */
    private static function plainPage(string $title, string $heading, string $text, string $button): string
    {
        $name = self::esc(Config::get()['site']['name']);
        return self::versionAssets('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . $title . ' · ' . $name . '</title><link rel="icon" href="/assets/icons/favicon.ico" sizes="48x48">'
            . '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">'
            . '<link rel="stylesheet" href="/css/style.css"></head><body class="plain-page"><main class="plain">'
            . '<a class="plain__brand" href="/" aria-label="' . $name . '"><span class="logo" aria-hidden="true"></span></a>'
            . '<h1 class="display">' . $heading . '</h1><p>' . $text . '</p><a class="btn btn--solid" href="/">' . $button . '</a></main></body></html>');
    }

    private static function renderUnavailable(): string
    {
        return self::plainPage('Back soon', 'Back in a <em>moment</em>', 'The website is being updated. Please try again in a few minutes.', 'Try again');
    }

    private static function render404(): string
    {
        return self::plainPage('Page not found', 'Lost in the <em>hills</em>', 'This path doesn’t lead anywhere, but the valley view is still where we left it.', 'Back to Top Mountain');
    }
}
