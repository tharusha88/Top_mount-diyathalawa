<?php
// Small helpers that reproduce JavaScript semantics (truthiness, Number(), parseInt(),
// JSON.stringify(), encodeURIComponent()) so the server validates input exactly like
// the JavaScript front end expects.
declare(strict_types=1);

namespace Ydr;

final class Js
{
    /** JavaScript truthiness ("0" and [] are truthy in JS). */
    public static function truthy(mixed $v): bool
    {
        if ($v === null || $v === false || $v === '') return false;
        if (is_int($v)) return $v !== 0;
        if (is_float($v)) return $v != 0.0 && !is_nan($v);
        return true;
    }

    private const WS = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    /** String.prototype.trim() */
    public static function trim(string $s): string
    {
        return (string) preg_replace('/^' . self::WS . '+|' . self::WS . '+$/u', '', $s);
    }

    /** Number(v) — returns NAN where JavaScript would. */
    public static function number(mixed $v): float
    {
        if ($v === null) return 0.0;
        if (is_bool($v)) return $v ? 1.0 : 0.0;
        if (is_int($v) || is_float($v)) return (float) $v;
        if (is_array($v)) {
            if (!$v) return 0.0;
            if (self::isList($v) && count($v) === 1 && !is_array($v[0])) return self::number(is_bool($v[0]) || $v[0] === null ? '' : (string) $v[0]);
            return NAN;
        }
        if (!is_string($v)) return NAN;
        $s = self::trim($v);
        if ($s === '') return 0.0;
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $s)) return (float) $s;
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/', $s, $m)) return (float) hexdec($m[1]);
        if (preg_match('/^0[oO]([0-7]+)$/', $s, $m)) return (float) octdec($m[1]);
        if (preg_match('/^0[bB]([01]+)$/', $s, $m)) return (float) bindec($m[1]);
        if ($s === 'Infinity' || $s === '+Infinity') return INF;
        if ($s === '-Infinity') return -INF;
        return NAN;
    }

    /** parseInt(v, 10) — null stands for NaN. */
    public static function parseInt(mixed $v): ?int
    {
        if (is_array($v)) $v = $v ? (is_array(reset($v)) ? '' : (string) reset($v)) : '';
        if ($v === null) return null;
        if (is_bool($v)) return null;
        if (is_float($v) || is_int($v)) $v = (string) $v;
        if (preg_match('/^' . self::WS . '*([+-]?\d+)/u', (string) $v, $m)) return (int) $m[1];
        return null;
    }

    /** String(v) for scalars. */
    public static function str(mixed $v): string
    {
        if ($v === null) return 'null';
        if (is_bool($v)) return $v ? 'true' : 'false';
        if (is_float($v)) {
            if (is_nan($v)) return 'NaN';
            if (floor($v) == $v && abs($v) < 1e21) return (string) (int) $v;
            return (string) $v;
        }
        if (is_array($v)) return self::isList($v) ? implode(',', array_map(fn ($x) => is_array($x) || $x === null ? '' : self::str($x), $v)) : '[object Object]';
        return (string) $v;
    }

    /** "Is this a JSON array (as opposed to a JSON object)?" */
    public static function isList(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $_) { if ($k !== $i++) return false; }
        return true;
    }

    /** JSON.stringify(v) */
    public static function json(mixed $v): string
    {
        return (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** encodeURIComponent(s) */
    public static function encodeURIComponent(string $s): string
    {
        return strtr(rawurlencode($s), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
    }

    /** String.prototype.slice(0, n) on (roughly) characters */
    public static function slice(string $s, int $n): string
    {
        if ($n <= 0) return '';
        if (strlen($s) <= $n) return $s; // UTF-8 bytes ≥ UTF-16 units, so nothing to cut
        // count like JavaScript: characters outside the BMP (emoji) take two units
        $out = '';
        $units = 0;
        preg_match_all('/./us', $s, $m);
        foreach ($m[0] as $ch) {
            $w = strlen($ch) === 4 ? 2 : 1;
            if ($units + $w > $n) break;
            $out .= $ch;
            $units += $w;
        }
        return $out;
    }

    /** JS string length (UTF-16 code units) */
    public static function length(string $s): int
    {
        if (function_exists('mb_convert_encoding')) return intdiv(strlen((string) mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
        return (int) preg_match_all('/./us', $s) + (int) preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $s);
    }

    /** Number.prototype.toLocaleString('en-US') for whole numbers */
    public static function localeNumber(mixed $n): string
    {
        $f = self::number($n);
        if (is_nan($f)) return 'NaN';
        if (floor($f) == $f) return number_format($f, 0, '.', ',');
        return rtrim(rtrim(number_format($f, 3, '.', ','), '0'), '.');
    }

    /** new Date().toISOString() */
    public static function isoNow(): string
    {
        $d = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $d->format('Y-m-d\TH:i:s.v\Z');
    }
}
