<?php
// Sliding-window rate limiter; counters are kept in the database
// because PHP has no long-running process to hold them in memory.
declare(strict_types=1);

namespace Ydr;

final class RateLimit
{
    /** Returns true if allowed; on refusal sends 429 with Retry-After. */
    public static function hit(Request $req, Response $res, string $bucket, int $windowMs, int $max, string $message): bool
    {
        $now = microtime(true) * 1000;
        $cutoff = $now - $windowMs;
        $ip = substr($req->ip(), 0, 255);
        if (mt_rand(1, 20) === 1) Db::run('DELETE FROM rate_hits WHERE ts <= ?', [$cutoff - 86400000]); // tidy up now and then
        $n = (int) Db::one('SELECT COUNT(*) n FROM rate_hits WHERE bucket = ? AND ip = ? AND ts > ?', [$bucket, $ip, $cutoff])['n'];
        if ($n >= $max) {
            $res->header('Retry-After', (string) (int) ceil($windowMs / 1000));
            $res->json(['error' => $message], 429);
            return false;
        }
        Db::run('INSERT INTO rate_hits (bucket, ip, ts) VALUES (?, ?, ?)', [$bucket, $ip, $now]);
        return true;
    }
}
