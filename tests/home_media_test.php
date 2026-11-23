<?php
// Admin → Photos & videos (app/HomeMedia.php, app/Media.php): photos chosen for the home page,
// laid over content.js and media.js on the server and for the browser; videos uploaded in pieces,
// checked and put on the page; everything back to the original. Runs on a THROWAWAY SQLite
// database and a throwaway copy of the web root (uploads never touch public/media):
//   php tests/home_media_test.php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/topmountain-test-' . bin2hex(random_bytes(4));
$web = "$tmp/public";
$real = dirname(__DIR__) . '/public';
foreach (['js/data', 'media', 'assets/media/video'] as $d) mkdir("$web/$d", 0777, true);
copy("$real/js/data/content.js", "$web/js/data/content.js");
copy("$real/js/data/media.js", "$web/js/data/media.js");
// the original film (37 MB) stands in as a short clip: only its length is read
copy("$real/assets/media/video/aerial-720.mp4", "$web/assets/media/video/film-1080.mp4");
define('YDR_PUBLIC', $web);
putenv("DATA_DIR=$tmp/data");
putenv('YDR_TEST_SEED=' . __DIR__ . '/seed.php');
putenv('SMTP_HOST=');
putenv('DB_CONNECTION=sqlite');
putenv('BASE_URL=https://resort.test');
require dirname(__DIR__) . '/app/bootstrap.php';

use Ydr\App;
use Ydr\BookingError;
use Ydr\Content;
use Ydr\HomeMedia;
use Ydr\Js;
use Ydr\Media;
use Ydr\Request;
use Ydr\Response;
use Ydr\Site;

$pass = 0;
$fail = 0;
function test(string $name, callable $fn): void
{
    global $pass, $fail;
    try { $fn(); $pass++; echo "✔ $name\n"; }
    catch (Throwable $e) { $fail++; echo "✖ $name\n    " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n"; }
}
function eq($a, $b, string $msg = ''): void
{
    if ($a !== $b) throw new Exception(($msg ? "$msg: " : '') . 'expected ' . var_export($b, true) . ', got ' . var_export($a, true));
}
function ok(bool $v, string $msg): void
{
    if (!$v) throw new Exception($msg);
}
function throws(callable $fn, string $needle, string $msg): void
{
    try { $fn(); } catch (Throwable $e) {
        ok(str_contains($e->getMessage(), $needle), "$msg: the message was \"{$e->getMessage()}\"");
        return;
    }
    throw new Exception("$msg: nothing was refused");
}

/** A GET request through the app: [status, body]. */
function get(string $path): array
{
    $req = new Request();
    $req->method = 'GET';
    $req->path = $path;
    $req->query = [];
    $req->headers = ['host' => 'resort.test'];
    $_SERVER['REQUEST_URI'] = $path;
    $res = new Response();
    (new ReflectionMethod(App::class, 'handle'))->invoke(null, $req, $res);
    return [$res->status, $res->body];
}

/** window.BOOT from a page. */
function boot(string $html): array
{
    ok((bool) preg_match('#window\.BOOT=(\{.*?\});</script>#s', $html, $m), 'no window.BOOT on the page');
    return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
}

/** A JPEG photo, as a phone or camera would send it. */
function jpeg(int $w = 1600, int $h = 1000): string
{
    $im = imagecreatetruecolor($w, $h);
    for ($x = 0; $x < $w; $x += 20) imagefilledrectangle($im, $x, 0, $x + 19, $h, imagecolorallocate($im, 40 + $x % 120, 90, 60));
    ob_start();
    imagejpeg($im, null, 85);
    return (string) ob_get_clean();
}

/** Send a file the way admin-media.js does: in pieces; returns the upload id. */
function sendVideo(string $file, int $piece = 1048576): string
{
    $id = bin2hex(random_bytes(16));
    $bytes = (string) file_get_contents($file);
    for ($at = 0; $at < strlen($bytes); $at += $piece) eq(Media::addVideoPart($id, $at, substr($bytes, $at, $piece)), min(strlen($bytes), $at + $piece), 'bytes received');
    return $id;
}

$aerial = "$real/assets/media/video/aerial-720.mp4";
$upright = "$real/assets/media/video/aerial-portrait.mp4";
$hero = "$real/assets/media/video/hero-720.mp4";
$shared = [];

test('nothing changed: the originals, and nothing extra for the browser', function () {
    eq(HomeMedia::boot(), null, 'homeMedia');
    [$status, $html] = get('/');
    eq($status, 200, 'home page');
    ok(!preg_match('/\{\{\s*[\w.]+\s*\}\}/', $html), 'a {{token}} was left in the page');
    ok(str_contains($html, '<span class="dfilm__media" data-img="estate-golden"'), 'the film button shows the film\'s own cover');
    ok(str_contains($html, '<span class="sr-only">, </span>0:10</span>'), 'the film\'s length is read from the film');
    ok(str_contains($html, '<div class="book__media" data-img="cabana-hillside"'), 'the booking section\'s photo');
    eq(boot($html)['homeMedia'], null, 'BOOT.homeMedia');
    eq(HomeMedia::poster('hero'), 'hero-poster', 'the hero\'s cover');
});

test('a photo uploaded to the library: WebP sizes, a 1200 px JPEG and its colour; a cover frame is not graded', function () use (&$shared) {
    $k = Media::addUpload(jpeg(), 'Terrace view.jpg');
    $m = Media::get()[$k];
    eq($m['sizes'], [640, 1280, 1600], 'sizes');
    eq($m['jpeg'], 1200, 'JPEG width');
    ok((bool) preg_match('/^#[0-9a-f]{6}$/', $m['color']), 'average colour');
    foreach ([640, 1280, 1600] as $w) ok(is_file(YDR_PUBLIC . "/media/$k-$w.webp"), "$w px WebP");
    ok(is_file(YDR_PUBLIC . "/media/$k-1200.jpg"), 'the JPEG');
    $p = Media::pageImage($k, 'A terrace');
    eq([$p['base'], $p['widths'], $p['fallback'], $p['w'], $p['alt']], ["/media/$k", [640, 1280, 1600], "/media/$k-1200.jpg", 1600, 'A terrace'], 'media.js shape');
    eq(Media::pageImage('hero-poster'), null, 'the website\'s own photos are in media.js already');

    // a frame from a video keeps its colours (graded photos are brighter and more saturated)
    $bytes = jpeg(800, 450);
    $graded = Media::addUpload($bytes, 'graded');
    $plain = Media::addUpload($bytes, 'cover', false);
    ok(Media::get()[$graded]['color'] !== Media::get()[$plain]['color'], 'the cover frame was colour-graded');
    $shared += ['photo' => $k, 'frame' => $plain];
});

test('photos chosen for the page: laid over content.js, drawn by the server, handed to the browser', function () use (&$shared) {
    $k = $shared['photo'];
    $C = Content::raw();
    $ella = array_search('Ella Rock', array_column($C['nearby'], 'name'), true);
    $admin = HomeMedia::save(['photos' => [
        'highlights:The valley terrace' => $k,
        'rooms:101' => [$k, 'room-101-02'],
        'page:book' => $k,
        'nearby:Ella Rock' => 'near-adisham', // a photo of the website's own
    ]]);
    $place = fn (string $id) => array_values(array_filter(array_merge(...array_column($admin['groups'], 'places')), fn ($p) => $p['id'] === $id))[0];
    eq([$place('highlights:The valley terrace')['key'], $place('highlights:The valley terrace')['changed']], [$k, true], 'admin: the highlight');
    eq($place('rooms:101')['key'], [$k, 'room-101-02'], 'admin: room 101');
    eq($place('rooms:102')['changed'], false, 'admin: room 102 untouched');

    $G = Content::get();
    eq($G['highlights'][0]['image'], $k, 'content: the highlight');
    eq($G['rooms'][0]['photos'], [$k, 'room-101-02'], 'content: room 101');
    eq($G['nearby'][$ella]['image'], 'near-adisham', 'content: Ella Rock');
    eq(Content::raw()['highlights'][0]['image'], 'hl-valley-terrace', 'content.js itself is untouched');
    eq(HomeMedia::book(), $k, 'the booking section');
    ok(HomeMedia::uses($k) && Site::uses($k), 'a photo on the page is in use (so the library keeps it)');

    [, $html] = get('/');
    ok(str_contains($html, "<div class=\"book__media\" data-img=\"$k\""), 'the booking section shows the upload');
    ok(str_contains($html, "<img src=\"/media/$k-1200.jpg\""), 'the page drawn on the server shows the upload (readers without JavaScript)');
    $b = boot($html)['homeMedia'];
    eq($b['content']['highlights'][0] ?? $b['content']['highlights']['0'] ?? null, $k, 'browser: the highlight by position');
    eq($b['content']['rooms'][0] ?? $b['content']['rooms']['0'] ?? null, [$k, 'room-101-02'], 'browser: room 101');
    eq($b['content']['nearby'][(string) $ella] ?? $b['content']['nearby'][$ella] ?? null, 'near-adisham', 'browser: Ella Rock by its place in content.js');
    eq(array_keys($b['images']), [$k], 'browser: only the uploads are added to TMR_MEDIA');
    eq($b['images'][$k]['alt'], 'The valley terrace', 'browser: the upload is described by the first place it is in');

    [, $book] = get('/book');
    ok(str_contains($book, "og:image\" content=\"https://resort.test/media/$k-1200.jpg\""), 'the booking page\'s social image follows the booking section');
});

test('photo changes are checked; "Use the original" puts the original back', function () use (&$shared) {
    $k = $shared['photo'];
    throws(fn () => HomeMedia::save(['photos' => ['highlights:No such thing' => $k]]), 'no longer there', 'an unknown place');
    throws(fn () => HomeMedia::save(['photos' => ['page:book' => 'upload-gone-000000']]), 'no longer in the library', 'a photo not in the library');
    throws(fn () => HomeMedia::save(['photos' => ['rooms:101' => []]]), 'at least one photo', 'a room with no photos');
    throws(fn () => HomeMedia::save(['photos' => ['rooms:101' => array_fill(0, 21, $k)]]), 'up to 20', 'a room with too many photos');
    throws(fn () => HomeMedia::save(['photos' => ['rooms:101' => 'room-101-01']]), 'at least one photo', 'a room given one key instead of a list');

    HomeMedia::save(['photos' => ['page:book' => '', 'rooms:101' => Content::raw()['rooms'][0]['photos']]]);
    eq(HomeMedia::book(), 'cabana-hillside', 'the booking section is back to the original');
    $saved = json_decode(Ydr\Settings::get('home_photos'), true);
    ok(!isset($saved['page:book']) && !isset($saved['rooms:101']), 'an original is not kept as a change');
    ok(isset($saved['highlights:The valley terrace']), 'the other changes stay');
    HomeMedia::save(['photos' => ['highlights:The valley terrace' => '', 'nearby:Ella Rock' => '']]);
    eq(Ydr\Settings::get('home_photos'), '', 'nothing left');
    eq(HomeMedia::boot(), null, 'nothing for the browser');
    ok(!Site::uses($k), 'the photo can leave the library again');
});

test('a video arrives in pieces and is checked: MP4 with H.264 only, in order', function () use ($aerial) {
    $id = bin2hex(random_bytes(16));
    throws(fn () => Media::addVideoPart($id, 0, 'GIF89a not a video at all'), 'MP4', 'a file that is not an MP4');
    throws(fn () => Media::addVideoPart('../../etc', 0, 'x'), 'Invalid upload', 'an upload id that is a path');
    $bytes = (string) file_get_contents($aerial);
    Media::addVideoPart($id, 0, substr($bytes, 0, 1000));
    throws(fn () => Media::addVideoPart($id, 5000, substr($bytes, 5000, 1000)), 'interrupted', 'a piece out of order');
    throws(fn () => HomeMedia::setVideo('aerial', 'main', bin2hex(random_bytes(16)), null), 'did not arrive', 'an upload that never came');
    throws(fn () => HomeMedia::setVideo('film', 'phone', $id, null), 'Unknown video', 'the film has no phone version');

    // a phone's HEVC video: the same file with its codec renamed
    $at = strpos($bytes, 'avc1', (int) strpos($bytes, 'stsd'));
    ok($at !== false, 'the test video has an H.264 sample description');
    $hevc = substr_replace($bytes, 'hvc1', $at, 4);
    $file = sys_get_temp_dir() . '/tm-hevc-' . bin2hex(random_bytes(3)) . '.mp4';
    file_put_contents($file, $hevc);
    $id = sendVideo($file);
    @unlink($file);
    throws(fn () => HomeMedia::setVideo('aerial', 'main', $id, null), 'HEVC (H.265)', 'an HEVC video');
    ok(!is_file(Ydr\Config::get()['dataDir'] . "/uploads/$id.part"), 'a refused upload is not kept');
    eq(HomeMedia::poster('aerial'), 'aerial-poster', 'the page still has its original video');
});

test('a new video replaces the original, its upright phone version too; then a phone version of its own', function () use (&$shared, $aerial, $upright) {
    $poster = $shared['frame'];
    $admin = HomeMedia::setVideo('aerial', 'main', sendVideo($aerial), $poster);
    $v = Content::media()['videos']['aerial'];
    eq(count($v['sources']), 1, 'one file: the original 1080p, 720p and phone files are all replaced');
    eq([$v['sources'][0]['role'], $v['sources'][0]['w'], $v['sources'][0]['h']], ['landscape', 1280, 720], 'the new file');
    ok((bool) preg_match('#^/media/video/aerial-[0-9a-f]{8}\.mp4$#', $v['sources'][0]['src']) && is_file(YDR_PUBLIC . $v['sources'][0]['src']), 'saved in media/video/');
    eq($v['poster'], $poster, 'its cover photo');
    ok(!isset($v['posterPortrait']), 'no upright cover without an upright video');
    eq($v['label'], Content::rawMedia()['videos']['aerial']['label'], 'the description stays');
    $a = array_values(array_filter($admin['videos'], fn ($x) => $x['slot'] === 'aerial'))[0];
    eq([$a['changed'], $a['main']['custom'], $a['main']['duration'], $a['phoneVideo']], [true, true, 10.23, null], 'admin');
    $b = boot(get('/')[1])['homeMedia'];
    eq($b['videos']['aerial']['sources'][0]['src'], $v['sources'][0]['src'], 'browser: the new video');
    ok(isset($b['images'][$poster]), 'browser: its cover photo');
    ok(Site::uses($poster), 'its cover photo is in use');
    $shared['aerialMain'] = $v['sources'][0]['src'];

    $phonePoster = Media::addUpload(jpeg(608, 1080), 'phone cover', false);
    HomeMedia::setVideo('aerial', 'phone', sendVideo($upright), $phonePoster);
    $v = Content::media()['videos']['aerial'];
    eq(array_column($v['sources'], 'role'), ['landscape', 'portrait'], 'the main video and the phone version');
    eq([$v['sources'][1]['w'], $v['sources'][1]['h']], [608, 1080], 'the phone version is upright');
    eq($v['posterPortrait'], $phonePoster, 'its own cover photo');
    $shared['aerialPhone'] = $v['sources'][1]['src'];

    HomeMedia::save(['videos' => ['aerial' => ['phone' => null]]]);
    eq(array_column(Content::media()['videos']['aerial']['sources'], 'role'), ['landscape'], 'phone version removed');
    ok(!is_file(YDR_PUBLIC . $shared['aerialPhone']), 'and its file deleted');

    HomeMedia::save(['videos' => ['aerial' => ['label' => 'Our new drone flight', 'poster' => 'estate-golden']]]);
    eq([Content::media()['videos']['aerial']['label'], HomeMedia::poster('aerial')], ['Our new drone flight', 'estate-golden'], 'description and cover photo changed');
    throws(fn () => HomeMedia::save(['videos' => ['aerial' => ['poster' => 'upload-gone-000000']]]), 'no longer in the library', 'a cover photo not in the library');
});

test('the film: its button shows the new cover and its length; search engines get the new file', function () use (&$shared, $hero) {
    HomeMedia::setVideo('film', 'main', sendVideo($hero, 4 * 1048576), $shared['frame']);
    [, $html] = get('/');
    ok(str_contains($html, '<span class="dfilm__media" data-img="' . $shared['frame'] . '"'), 'the button shows the new cover');
    ok(str_contains($html, '<span class="sr-only">, </span>0:32</span>'), 'and the new film\'s length');
    $src = Content::media()['videos']['film']['sources'][0]['src'];
    ok(str_contains($html, '"contentUrl":"https://resort.test' . $src . '"'), 'the VideoObject points at the new film');
    eq(HomeMedia::filmLength(), '0:32', 'filmLength');
});

test('the hero video: every original file replaced; the early-loaded cover and the shared picture follow it', function () use (&$shared, $aerial, $upright) {
    $orig = Content::rawMedia()['videos']['hero'];
    eq(count($orig['sources']), 6, 'the original hero has six files (AV1 and H.264, three sizes)');
    $poster = $shared['frame']; // 800 × 450
    $admin = HomeMedia::setVideo('hero', 'main', sendVideo($aerial), $poster);
    eq(array_values(array_filter($admin['videos'], fn ($x) => $x['slot'] === 'hero'))[0]['label'], 'Hero video', 'admin names it');
    $v = Content::media()['videos']['hero'];
    eq(array_column($v['sources'], 'role'), ['landscape'], 'one file: the AV1, 1080p, 720p and phone files are all replaced');
    eq([$v['poster'], isset($v['posterPortrait'])], [$poster, false], 'the new cover, no upright one');
    $shared['heroMain'] = $v['sources'][0]['src'];

    [, $html] = get('/');
    preg_match_all('#<link rel="preload" as="image"[^>]*>#', $html, $pre);
    eq(count($pre[0]), 1, 'one cover is loaded early (no upright cover without an upright video)');
    ok(str_contains($pre[0][0], "imagesrcset=\"/media/$poster-640.webp 640w, /media/$poster-800.webp 800w\"") && !str_contains($pre[0][0], ' media="'), 'the early-loaded cover is the new one, for every screen');
    ok(str_contains($html, "<meta property=\"og:image\" content=\"https://resort.test/media/$poster-800.jpg\">"), 'the picture shown when the site is shared');
    ok(str_contains($html, "\"contentUrl\":\"https://resort.test/media/$poster-800.jpg\""), 'the page\'s main image for search engines');
    $b = boot($html)['homeMedia'];
    eq($b['videos']['hero']['sources'][0]['src'], $shared['heroMain'], 'browser: the new hero');

    $phonePoster = Media::addUpload(jpeg(608, 1080), 'hero phone cover', false);
    HomeMedia::setVideo('hero', 'phone', sendVideo($upright), $phonePoster);
    eq(array_column(Content::media()['videos']['hero']['sources'], 'role'), ['landscape', 'portrait'], 'with its phone version');
    [, $html] = get('/');
    preg_match_all('#<link rel="preload" as="image"[^>]*>#', $html, $pre);
    eq(count($pre[0]), 2, 'a cover for wide screens and one for upright phones');
    ok(str_contains($pre[0][0], "/media/$poster-640.webp") && str_contains($pre[0][0], 'media="(min-aspect-ratio: 10/11)"'), 'wide screens: the cover');
    ok(str_contains($pre[0][1], "/media/$phonePoster-608.webp") && str_contains($pre[0][1], 'media="(max-aspect-ratio: 10/11)"'), 'upright phones: the phone cover');
});

test('"Use the original" for a video: its files are deleted and the build\'s own comes back', function () use (&$shared) {
    $film = Content::media()['videos']['film']['sources'][0]['src'];
    $hero = Content::media()['videos']['hero']['sources'];
    HomeMedia::save(['videos' => ['aerial' => null, 'film' => null, 'hero' => null]]);
    foreach ($hero as $s) ok(!is_file(YDR_PUBLIC . $s['src']), 'the hero\'s uploaded files are deleted');
    eq(HomeMedia::poster('hero'), 'hero-poster', 'the hero is back');
    eq(Content::media()['videos'], Content::rawMedia()['videos'], 'all original');
    ok(!is_file(YDR_PUBLIC . $shared['aerialMain']) && !is_file(YDR_PUBLIC . $film), 'the uploaded videos are deleted');
    eq(Ydr\Settings::get('home_videos'), '', 'nothing left');
    eq(HomeMedia::boot(), null, 'nothing for the browser');
    ok(!Site::uses($shared['frame']), 'the cover photos can leave the library');
    throws(fn () => HomeMedia::save(['videos' => ['nope' => null]]), 'Unknown video', 'an unknown video');
});

test('the hosting zip leaves out the admin panel\'s uploads', function () {
    $src = (string) file_get_contents(dirname(__DIR__) . '/scripts/build.php');
    ok((bool) preg_match('/manifest\\\\\.json\$\|upload-\|video\//', $src), 'build.php skips media/manifest.json, media/upload-* and media/video/');
});

echo "\n$pass passed, $fail failed  (test database and web root: $tmp)\n";
exit($fail ? 1 : 0);
