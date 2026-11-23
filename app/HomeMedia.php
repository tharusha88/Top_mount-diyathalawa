<?php
// The home page's photos and videos as the admin has changed them (Admin → Photos & videos).
//
// The media build (tools/media.config.json → js/data/media.js) and content.js stay the originals.
// The admin's choices are kept in the settings and laid over them:
//   home_photos  place => photo key, or a room => its photo keys
//   home_videos  slot => {main, phone: {src, w, h, duration, bytes}, poster, posterPhone, label}
// A photo is any key from the photo library: one of the website's own or an upload. Content::get()
// and Content::media() return the result to the server (the page drawn there, search tags), and
// boot() hands the same changes to the browser, where media.js applies them before anything draws.
declare(strict_types=1);

namespace Ydr;

final class HomeMedia
{
    /** The page's background videos and the film, in page order. phone: it has an upright version for phones. */
    public const VIDEOS = [
        'hero' => ['label' => 'Hero video', 'where' => 'The big video at the top of the home page, behind the title: the first thing guests see. Muted, on a loop. Its cover photo is also the picture shown when the site is shared.', 'phone' => true],
        'aerial' => ['label' => 'Discover the estate', 'where' => 'Plays behind the “From above” section. Muted, on a loop.', 'phone' => true],
        'film' => ['label' => 'The film', 'where' => '“Watch the film” plays it full screen, with sound. Its cover photo is the button.', 'phone' => false],
    ];

    /** content.js lists whose items show one photo each: list => [section title, where it is, the item's name field] */
    private const LISTS = [
        'highlights' => ['Highlights', 'The photo panels at the end of the welcome section', 'title'],
        'experiences' => ['Experiences', 'The “Things to do” cards', 'name'],
        'diningPlaces' => ['Dining', 'The dining cards', 'name'],
        'nearby' => ['Places nearby', 'The places within a day trip, near the end of the page', 'name'],
    ];

    /** The booking section's photo (views/index.html: {{media.book}}). */
    private const BOOK = 'cabana-hillside';

    public const MAX_ROOM_PHOTOS = 20;

    private static ?array $photos = null;
    private static ?array $videos = null;
    private static ?array $merged = null;

    // ---------- what has been chosen ----------

    private static function setting(string $name): array
    {
        try {
            $v = json_decode(Settings::get($name), true);
        } catch (\Throwable $e) {
            return []; // no database: the originals
        }
        return is_array($v) ? $v : [];
    }

    /** place => key or [keys], only those still in the library */
    private static function photos(): array
    {
        if (self::$photos !== null) return self::$photos;
        $out = [];
        foreach (self::setting('home_photos') as $place => $v) {
            if (is_string($v) && Media::has($v)) $out[(string) $place] = $v;
            elseif (is_array($v) && ($keys = array_values(array_filter($v, fn ($k) => is_string($k) && Media::has($k))))) $out[(string) $place] = $keys;
        }
        return self::$photos = $out;
    }

    private static function videos(): array
    {
        if (self::$videos !== null) return self::$videos;
        $out = [];
        foreach (self::setting('home_videos') as $slot => $v) {
            if (!isset(self::VIDEOS[$slot]) || !is_array($v)) continue;
            foreach (['poster', 'posterPhone'] as $k) if (isset($v[$k]) && (!is_string($v[$k]) || !Media::has($v[$k]))) unset($v[$k]);
            foreach (['main', 'phone'] as $k) if (isset($v[$k]) && (!is_array($v[$k]) || !self::fileThere($v[$k]['src'] ?? null))) unset($v[$k]);
            if ($v) $out[$slot] = $v;
        }
        return self::$videos = $out;
    }

    private static function fileThere(mixed $src): bool
    {
        return is_string($src) && str_starts_with($src, '/media/video/') && is_file(YDR_PUBLIC . $src);
    }

    private static function forget(): void
    {
        self::$photos = self::$videos = self::$merged = null;
    }

    // ---------- the places on the page ----------

    /**
     * Every photo place on the home page, in page order, grouped as Admin → Photos & videos shows them.
     * @return list<array{id:string, title:string, sub:string, places:list<array>}>
     */
    public static function groups(): array
    {
        $C = Content::raw();
        $groups = [];
        $one = fn (string $id, string $label, string $def, string $alt) => ['id' => $id, 'label' => $label, 'default' => $def, 'alt' => $alt, 'multi' => false];
        foreach (self::LISTS as $list => [$title, $sub, $field]) {
            $places = [];
            foreach (array_values(array_filter($C[$list] ?? [], 'is_array')) as $x) {
                $name = (string) ($x[$field] ?? '');
                if ($name === '' || empty($x['image'])) continue;
                $places[] = $one("$list:$name", $name, (string) $x['image'], $name);
            }
            $groups[$list] = ['id' => $list, 'title' => $title, 'sub' => $sub, 'places' => $places];
        }
        $rooms = [];
        foreach (array_values(array_filter($C['rooms'] ?? [], 'is_array')) as $r) {
            if (empty($r['id']) || empty($r['photos'])) continue;
            $name = (string) ($r['name'] ?? $r['id']);
            $rooms[] = ['id' => 'rooms:' . $r['id'], 'label' => $name, 'default' => array_values(array_map('strval', $r['photos'])), 'alt' => $name, 'multi' => true];
        }
        $book = ['id' => 'book', 'title' => 'Booking section', 'sub' => 'Beside the “Book your stay” form', 'places' => [$one('page:book', 'Booking section', self::BOOK, '')]];
        // page order: highlights, rooms, experiences, dining, booking, places nearby
        return array_values(array_filter([
            $groups['highlights'],
            ['id' => 'rooms', 'title' => 'Rooms gallery', 'sub' => 'The room tiles on the home page; the first photo is the tile\'s cover. (The booking page\'s room photos are in Admin → Rooms.)', 'places' => $rooms],
            $groups['experiences'], $groups['diningPlaces'], $book, $groups['nearby'],
        ], fn ($g) => $g['places']));
    }

    /** place id => the place, for checking what the admin sends */
    private static function placeIndex(): array
    {
        $out = [];
        foreach (self::groups() as $g) foreach ($g['places'] as $p) $out[$p['id']] = $p;
        return $out;
    }

    /** A place's photo now: the admin's choice, else the original. */
    private static function current(array $place): string|array
    {
        return self::photos()[$place['id']] ?? $place['default'];
    }

    /** The booking section's photo. */
    public static function book(): string
    {
        $k = self::photos()['page:book'] ?? null;
        return is_string($k) ? $k : self::BOOK;
    }

    // ---------- laid over the originals ----------

    /** content.js with the admin's photos in place (Content::get()). */
    public static function apply(array $C): array
    {
        $photos = self::photos();
        if (!$photos) return $C;
        foreach (self::LISTS as $list => [, , $field]) {
            foreach ($C[$list] ?? [] as $i => $x) {
                $k = is_array($x) ? ($photos["$list:" . ($x[$field] ?? '')] ?? null) : null;
                if (is_string($k)) $C[$list][$i]['image'] = $k;
            }
        }
        foreach ($C['rooms'] ?? [] as $i => $r) {
            $k = is_array($r) ? ($photos['rooms:' . ($r['id'] ?? '')] ?? null) : null;
            if (is_array($k)) $C['rooms'][$i]['photos'] = $k;
        }
        return $C;
    }

    /**
     * What the page needs on top of js/data/media.js: the uploads it shows (in media.js's shape, each
     * described by the first place it is in) and the videos the admin changed (whole entries).
     * @return array{images: array<string, array>, videos: array<string, array>}
     */
    public static function media(): array
    {
        if (self::$merged !== null) return self::$merged;
        $images = [];
        $add = function (string $key, string $alt) use (&$images) {
            if (!isset($images[$key]) && ($m = Media::pageImage($key, $alt))) $images[$key] = $m;
        };
        $siteName = (string) Config::get()['site']['name'];
        foreach (self::placeIndex() as $p) {
            foreach ((array) self::current($p) as $k) $add($k, $p['alt'] !== '' ? $p['alt'] : "A photo of $siteName");
        }
        $orig = Content::rawMedia()['videos'];
        $videos = [];
        foreach (self::videos() as $slot => $o) {
            if (!isset($orig[$slot])) continue;
            $videos[$slot] = $v = self::merge($orig[$slot], $o);
            foreach (['poster', 'posterPortrait'] as $k) if (!empty($v[$k])) $add($v[$k], '');
        }
        return self::$merged = ['images' => $images, 'videos' => $videos];
    }

    /**
     * A video with the admin's files in it. A new main video replaces every original file, the
     * upright phone one too (or phones would still play the old video); a new phone video replaces
     * only the upright one.
     */
    private static function merge(array $v, array $o): array
    {
        $src = fn (array $f, string $role) => ['src' => $f['src'], 'role' => $role, 'w' => (int) $f['w'], 'h' => (int) $f['h'], 'type' => 'video/mp4'];
        $sources = $v['sources'] ?? [];
        if (isset($o['main'])) {
            $sources = [$src($o['main'], 'landscape')];
            unset($v['posterPortrait']);
        }
        if (isset($o['phone'])) {
            $sources = array_values(array_filter($sources, fn ($s) => ($s['role'] ?? '') !== 'portrait'));
            $sources[] = $src($o['phone'], 'portrait');
        }
        $v['sources'] = $sources;
        if (!empty($o['poster'])) $v['poster'] = $o['poster'];
        // the upright cover photo: only while there is an upright video for it to cover
        if (!empty($o['posterPhone']) && (isset($o['phone']) || !empty($v['posterPortrait']))) $v['posterPortrait'] = $o['posterPhone'];
        if (($o['label'] ?? '') !== '') $v['label'] = (string) $o['label'];
        return $v;
    }

    /**
     * The changes for the browser (window.BOOT.homeMedia, applied by media.js): images and videos to
     * add to TMR_MEDIA, and content.js photos by list and position. null when nothing is changed.
     */
    public static function boot(): ?array
    {
        $m = self::media();
        $photos = self::photos();
        if (!$photos && !$m['videos']) return null;
        $C = Content::raw();
        $content = [];
        foreach (self::LISTS as $list => [, , $field]) {
            foreach (array_values($C[$list] ?? []) as $i => $x) {
                $k = is_array($x) ? ($photos["$list:" . ($x[$field] ?? '')] ?? null) : null;
                if (is_string($k)) $content[$list][(string) $i] = $k;
            }
        }
        foreach (array_values($C['rooms'] ?? []) as $i => $r) {
            $k = is_array($r) ? ($photos['rooms:' . ($r['id'] ?? '')] ?? null) : null;
            if (is_array($k)) $content['rooms'][(string) $i] = $k;
        }
        return ['images' => (object) $m['images'], 'videos' => (object) $m['videos'], 'content' => (object) $content];
    }

    /** A video's cover photo now: the hero's is the page's main photo for search engines and link previews. */
    public static function poster(string $slot): string
    {
        return (string) (Content::media()['videos'][$slot]['poster'] ?? '');
    }

    /** The film's length as the "Watch the film" button shows it ("1:40"), '' when unknown. */
    public static function filmLength(): string
    {
        $v = Content::media()['videos']['film'] ?? null;
        $src = is_array($v) ? ($v['sources'][0]['src'] ?? null) : null;
        if (!is_string($src)) return '';
        $o = self::videos()['film']['main'] ?? null;
        $sec = $o ? (float) ($o['duration'] ?? 0) : 0.0;
        if (!$o) {
            try {
                $sec = Media::videoInfo(YDR_PUBLIC . '/' . ltrim($src, '/'))['duration'];
            } catch (\Throwable $e) {
                return '';
            }
        }
        $s = (int) round($sec);
        return $s > 0 ? intdiv($s, 60) . ':' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) : '';
    }

    /** Is a photo on the home page? (so it isn't deleted from the library) */
    public static function uses(string $key): bool
    {
        foreach (self::photos() as $v) if (in_array($key, (array) $v, true)) return true;
        foreach (self::videos() as $v) if (($v['poster'] ?? null) === $key || ($v['posterPhone'] ?? null) === $key) return true;
        return false;
    }

    // ---------- Admin → Photos & videos ----------

    public static function admin(): array
    {
        $orig = Content::rawMedia()['videos'];
        $now = Content::media()['videos'];
        $saved = self::videos();
        $videos = [];
        foreach (self::VIDEOS as $slot => $d) {
            if (!isset($orig[$slot])) continue;
            $o = $saved[$slot] ?? [];
            $v = $now[$slot];
            $file = fn (string $role) => array_values(array_filter($v['sources'] ?? [], fn ($s) => (($s['role'] ?? '') === 'portrait') === ($role === 'portrait') && ($s['codec'] ?? '') !== 'av1'));
            $pick = function (array $list) {
                usort($list, fn ($a, $b) => abs(($a['w'] ?? 0) - 1280) <=> abs(($b['w'] ?? 0) - 1280));
                return $list[0] ?? null;
            };
            $main = $pick($file('landscape'));
            $phone = $pick($file('portrait'));
            $videos[] = $d + [
                'slot' => $slot,
                'changed' => $o !== [],
                'main' => $main ? ['src' => '/' . ltrim((string) $main['src'], '/'), 'w' => $main['w'] ?? 0, 'h' => $main['h'] ?? 0, 'custom' => isset($o['main'])] + (isset($o['main']) ? ['duration' => $o['main']['duration'] ?? 0, 'bytes' => $o['main']['bytes'] ?? 0] : []) : null,
                'phoneVideo' => $phone ? ['src' => '/' . ltrim((string) $phone['src'], '/'), 'w' => $phone['w'] ?? 0, 'h' => $phone['h'] ?? 0, 'custom' => isset($o['phone'])] + (isset($o['phone']) ? ['duration' => $o['phone']['duration'] ?? 0, 'bytes' => $o['phone']['bytes'] ?? 0] : []) : null,
                'poster' => (string) ($v['poster'] ?? ''),
                'posterPhone' => (string) ($v['posterPortrait'] ?? ''),
                'posterChanged' => isset($o['poster']),
                'description' => (string) ($v['label'] ?? ''),
                'descriptionChanged' => ($o['label'] ?? '') !== '',
            ];
        }
        $groups = self::groups();
        foreach ($groups as &$g) {
            $g['places'] = array_map(function ($p) {
                $cur = self::current($p);
                return $p + ['key' => $cur, 'changed' => $cur !== $p['default']];
            }, $g['places']);
        }
        unset($g);
        return ['videos' => $videos, 'groups' => $groups, 'maxRoomPhotos' => self::MAX_ROOM_PHOTOS, 'videoMax' => Media::VIDEO_MAX];
    }

    /**
     * Save changes. photos: {place: key | [keys] (a room) | '' (the original)};
     * videos: {slot: null (all original) | {poster, posterPhone: key | '', phone: null, label: text}}.
     */
    public static function save(array $in): array
    {
        $set = [];
        if (isset($in['photos']) && is_array($in['photos'])) {
            $places = self::placeIndex();
            $cur = self::setting('home_photos');
            foreach ($in['photos'] as $id => $v) {
                $p = $places[$id] ?? null;
                if (!$p) throw new BookingError('That part of the page is no longer there. Reload the page and try again.');
                if ($v === '' || $v === null || $v === $p['default']) { unset($cur[$id]); continue; } // back to the original
                if ($p['multi']) {
                    if (!is_array($v) || !Js::isList($v) || !$v) throw new BookingError("{$p['label']} needs at least one photo.");
                    if (count($v) > self::MAX_ROOM_PHOTOS) throw new BookingError("{$p['label']} can have up to " . self::MAX_ROOM_PHOTOS . ' photos.');
                    $v = array_values(array_unique($v, SORT_REGULAR));
                    foreach ($v as $k) if (!is_string($k) || !Media::has($k)) throw new BookingError('One of those photos is no longer in the library.');
                    if ($v === $p['default']) { unset($cur[$id]); continue; }
                } elseif (!is_string($v) || !Media::has($v)) {
                    throw new BookingError('That photo is no longer in the library.');
                }
                $cur[$id] = $v;
            }
            $set['home_photos'] = $cur ? Js::json($cur) : '';
        }
        $remove = [];
        if (isset($in['videos']) && is_array($in['videos'])) {
            $cur = self::setting('home_videos');
            foreach ($in['videos'] as $slot => $v) {
                if (!isset(self::VIDEOS[$slot])) throw new BookingError('Unknown video.');
                $o = is_array($cur[$slot] ?? null) ? $cur[$slot] : [];
                if ($v === null) { // everything back to the original
                    $remove[] = $o['main']['src'] ?? null;
                    $remove[] = $o['phone']['src'] ?? null;
                    unset($cur[$slot]);
                    continue;
                }
                if (!is_array($v)) throw new BookingError('Invalid video change.');
                foreach (['poster', 'posterPhone'] as $k) {
                    if (!array_key_exists($k, $v)) continue;
                    if ($v[$k] === '' || $v[$k] === null) { unset($o[$k]); continue; }
                    if (!is_string($v[$k]) || !Media::has($v[$k])) throw new BookingError('That photo is no longer in the library.');
                    $o[$k] = $v[$k];
                }
                if (array_key_exists('phone', $v) && $v['phone'] === null) {
                    $remove[] = $o['phone']['src'] ?? null;
                    unset($o['phone'], $o['posterPhone']);
                }
                if (array_key_exists('label', $v)) {
                    $label = Bookings::str($v['label'], 200);
                    if ($label === null) throw new BookingError('Please check the description.');
                    if ($label === '') unset($o['label']); else $o['label'] = $label;
                }
                if ($o) $cur[$slot] = $o; else unset($cur[$slot]);
            }
            $set['home_videos'] = $cur ? Js::json($cur) : '';
        }
        if ($set) Settings::set($set);
        foreach ($remove as $src) Media::removeVideo($src);
        self::forget();
        return self::admin();
    }

    /**
     * Put a finished upload on the page: role main (the video) or phone (the upright version), with
     * the cover photo made from its first frame. The video it replaces is deleted.
     */
    public static function setVideo(string $slot, string $role, string $upload, ?string $poster): array
    {
        if (!isset(self::VIDEOS[$slot])) throw new BookingError('Unknown video.');
        if (!in_array($role, ['main', 'phone'], true) || ($role === 'phone' && !self::VIDEOS[$slot]['phone'])) throw new BookingError('Unknown video.');
        if ($poster !== null && $poster !== '' && !Media::has($poster)) throw new BookingError('The cover photo did not arrive.');
        try {
            $file = Media::finishVideo($upload, $slot);
        } catch (\RuntimeException $e) {
            throw new BookingError($e->getMessage());
        }
        $cur = self::setting('home_videos');
        $o = is_array($cur[$slot] ?? null) ? $cur[$slot] : [];
        $old = $o[$role]['src'] ?? null;
        $o[$role] = $file;
        $posterKey = $role === 'main' ? 'poster' : 'posterPhone';
        if ($poster) $o[$posterKey] = $poster; else unset($o[$posterKey]);
        if ($role === 'main' && !isset($o['phone'])) unset($o['posterPhone']);
        $cur[$slot] = $o;
        Settings::set(['home_videos' => Js::json($cur)]);
        Media::removeVideo($old);
        self::forget();
        return self::admin();
    }
}
