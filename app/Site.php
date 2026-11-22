<?php
// What the admin can change on the home page (Admin → Website): the contact details and the map
// spot (Top Mountain has no photo slots; see SLOTS). Each contact detail overrides the one in .env (empty = use .env); each
// photo is a key from the photo library.
declare(strict_types=1);

namespace Ydr;

final class Site
{
    /** contact details: key in Config's site => settings name */
    public const CONTACT = [
        'phone' => 'site_phone', 'whatsapp' => 'site_whatsapp', 'email' => 'site_email', 'address' => 'site_address',
        'facebook' => 'site_facebook', 'instagram' => 'site_instagram',
        'mapCoords' => 'site_map_coords', 'plusCode' => 'site_plus_code', 'mapPlace' => 'site_map_place',
    ];

    /**
     * Single photos on the home page: slot => [default photo, where it shows, its description].
     * Top Mountain's home page takes its photos from the media build (tools/media.config.json), so
     * there are no slots, slideshow or gallery to manage here: Admin → Website edits the contact
     * details and the map pin only.
     */
    public const SLOTS = [];

    private const HERO = [];
    private const GALLERY = [];
    public const MAX_HERO = 8;
    public const MAX_GALLERY = 24;

    /** The resort's details from .env, with any changes made in Admin → Website. */
    public static function info(): array
    {
        $s = Config::get()['site'];
        try {
            $all = Settings::all();
        } catch (\Throwable $e) {
            return $s; // no database: .env only
        }
        foreach (self::CONTACT as $k => $name) if (($all[$name] ?? '') !== '') $s[$k] = $all[$name];
        return $s;
    }

    /** A WhatsApp number as digits with the country code: "077 736 8264" → "94777368264". */
    public static function waDigits(mixed $v): string
    {
        $d = (string) preg_replace('/\D/', '', Js::str($v ?? ''));
        if (strlen($d) === 10 && $d[0] === '0') $d = '94' . substr($d, 1); // a Sri Lankan number written locally
        elseif (strlen($d) === 9 && $d[0] === '7') $d = '94' . $d;
        return $d;
    }

    private static function json(string $name): mixed
    {
        $raw = Settings::get($name);
        return $raw === '' ? null : json_decode($raw, true);
    }

    /** slot => photo key (the default photo when none is chosen or it was deleted) */
    public static function images(): array
    {
        $saved = self::json('home_images');
        $out = [];
        foreach (self::SLOTS as $slot => [$def]) {
            $k = is_array($saved) ? ($saved[$slot] ?? null) : null;
            $out[$slot] = is_string($k) && Media::has($k) ? $k : $def;
        }
        return $out;
    }

    /** slot => description of the photo (a chosen photo gets a plain one) */
    public static function alts(): array
    {
        $out = [];
        foreach (self::images() as $slot => $key) {
            [$def, , $alt] = self::SLOTS[$slot];
            $out[$slot] = $key === $def ? $alt : ($alt === '' ? '' : 'A photo of ' . Config::get()['site']['name']);
        }
        return $out;
    }

    /** @return list<array{key:string, caption:string}> */
    private static function list(string $name, array $default): array
    {
        $saved = self::json($name);
        $list = [];
        if (is_array($saved)) {
            foreach ($saved as $x) {
                if (is_array($x) && is_string($x['key'] ?? null) && Media::has($x['key'])) $list[] = ['key' => $x['key'], 'caption' => Js::str($x['caption'] ?? '')];
            }
        }
        if ($list) return $list;
        return array_values(array_filter(array_map(fn ($d) => ['key' => $d[0], 'caption' => $d[1]], $default), fn ($x) => Media::has($x['key'])));
    }

    public static function hero(): array
    {
        return self::list('home_hero', self::HERO);
    }

    public static function gallery(): array
    {
        return self::list('home_gallery', self::GALLERY);
    }

    /** Everything Admin → Website shows. */
    public static function admin(): array
    {
        $env = Config::get()['site'];
        $contact = [];
        foreach (self::CONTACT as $k => $name) $contact[$k] = Settings::get($name);
        $images = self::images();
        return [
            'contact' => $contact,
            'defaults' => array_intersect_key($env, self::CONTACT),
            'slots' => array_map(fn ($slot, $def) => ['slot' => $slot, 'label' => $def[1], 'key' => $images[$slot], 'default' => $def[0]], array_keys(self::SLOTS), self::SLOTS),
            'hero' => self::hero(),
            'gallery' => self::gallery(),
            'max' => ['hero' => self::MAX_HERO, 'gallery' => self::MAX_GALLERY],
        ];
    }

    private static function url(mixed $v, string $what): string
    {
        $s = Bookings::str($v, 255) ?? '';
        if ($s !== '' && !preg_match('#^https?://[^\s<>"]+$#i', $s)) throw new BookingError("The $what must be a web address starting with https://");
        return $s;
    }

    private static function photos(mixed $v, int $max, string $what): array
    {
        if (!is_array($v) || !Js::isList($v)) throw new BookingError("Invalid $what.");
        if (!$v) throw new BookingError("The $what needs at least one photo.");
        if (count($v) > $max) throw new BookingError("The $what can have up to $max photos.");
        $out = [];
        foreach ($v as $x) {
            $key = is_array($x) ? ($x['key'] ?? null) : null;
            if (!is_string($key) || !Media::has($key)) throw new BookingError('One of those photos is no longer in the library.');
            $caption = Bookings::str($x['caption'] ?? '', 120);
            if ($caption === null) throw new BookingError('Please check the captions.');
            $out[] = ['key' => $key, 'caption' => $caption];
        }
        return $out;
    }

    /** Save part of the home page: contact, images {slot: key|''}, hero [{key, caption}], gallery [...]. */
    public static function save(array $in): array
    {
        $set = [];
        if (isset($in['contact']) && is_array($in['contact'])) {
            $c = $in['contact'];
            $has = fn (string $k) => array_key_exists($k, $c);
            if ($has('phone')) {
                $v = Bookings::str($c['phone'], 40);
                if ($v === null || ($v !== '' && strlen((string) preg_replace('/\D/', '', $v)) < 7)) throw new BookingError('Please check the phone number.');
                $set['site_phone'] = $v;
            }
            if ($has('whatsapp')) {
                $v = self::waDigits($c['whatsapp']);
                if ($v !== '' && (strlen($v) < 8 || strlen($v) > 15)) throw new BookingError('Please check the WhatsApp number (with the country code, e.g. 94777368264).');
                $set['site_whatsapp'] = $v;
            }
            if ($has('email')) {
                $v = Bookings::str($c['email'], 160) ?? '';
                if ($v !== '' && !Bookings::isEmail($v)) throw new BookingError('Please check the email address.');
                $set['site_email'] = $v;
            }
            if ($has('address')) {
                $v = Bookings::str($c['address'], 255);
                if ($v === null) throw new BookingError('Please check the address.');
                $set['site_address'] = $v;
            }
            if ($has('facebook')) $set['site_facebook'] = self::url($c['facebook'], 'Facebook page');
            if ($has('instagram')) $set['site_instagram'] = self::url($c['instagram'], 'Instagram page');
            if ($has('mapCoords')) {
                $v = trim((string) (Bookings::str($c['mapCoords'], 60) ?? ''));
                if ($v !== '') {
                    if (!preg_match('/^(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)$/', $v, $m) || abs((float) $m[1]) > 90 || abs((float) $m[2]) > 180) {
                        throw new BookingError('The map location must be "latitude, longitude", e.g. 6.8134639, 81.1103526');
                    }
                    $v = $m[1] . ',' . $m[2];
                }
                $set['site_map_coords'] = $v;
            }
            if ($has('plusCode')) {
                $v = Bookings::str($c['plusCode'], 40);
                if ($v === null) throw new BookingError('Please check the plus code.');
                $set['site_plus_code'] = $v;
            }
            if ($has('mapPlace')) $set['site_map_place'] = self::url($c['mapPlace'], 'Google Maps link');
        }
        if (isset($in['images']) && is_array($in['images'])) {
            $cur = self::json('home_images');
            $cur = is_array($cur) ? $cur : [];
            foreach ($in['images'] as $slot => $key) {
                if (!isset(self::SLOTS[$slot])) continue;
                if ($key === '' || $key === null || $key === self::SLOTS[$slot][0]) { unset($cur[$slot]); continue; } // back to the default photo
                if (!is_string($key) || !Media::has($key)) throw new BookingError('One of those photos is no longer in the library.');
                $cur[$slot] = $key;
            }
            $set['home_images'] = $cur ? Js::json($cur) : '';
        }
        if (array_key_exists('hero', $in)) $set['home_hero'] = Js::json(self::photos($in['hero'], self::MAX_HERO, 'slideshow'));
        if (array_key_exists('gallery', $in)) $set['home_gallery'] = Js::json(self::photos($in['gallery'], self::MAX_GALLERY, 'gallery'));
        Settings::set($set);
        return self::admin();
    }

    /** Is a photo used on the home page? (so it isn't deleted from the library) */
    public static function uses(string $key): bool
    {
        if (in_array($key, self::images(), true)) return true;
        foreach ([self::hero(), self::gallery()] as $list) foreach ($list as $x) if ($x['key'] === $key) return true;
        return false;
    }
}
