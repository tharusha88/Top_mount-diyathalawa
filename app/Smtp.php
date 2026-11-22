<?php
// Minimal SMTP client (SSL on 465, STARTTLS on 587/25, AUTH PLAIN/LOGIN) so the
// site needs no extra libraries on shared hosting.
declare(strict_types=1);

namespace Ydr;

final class Smtp
{
    /** @var resource */
    private $sock;
    private string $last = '';

    private function __construct(private array $cfg) {}

    /** Parse "Name <addr@x>" or "addr@x". */
    public static function parseAddress(string $s): array
    {
        $s = trim($s);
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/', $s, $m)) return [trim($m[1]), trim($m[2])];
        return ['', $s];
    }

    private static function clean(string $s): string
    {
        return str_replace(["\r", "\n"], ' ', $s);
    }

    private static function encodeHeader(string $s): string
    {
        $s = self::clean($s);
        if (!preg_match('/[^\x20-\x7E]/', $s)) return $s;
        if (function_exists('mb_encode_mimeheader')) {
            $prev = mb_internal_encoding();
            mb_internal_encoding('UTF-8');
            $out = mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n");
            mb_internal_encoding($prev);
            return $out;
        }
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }

    private static function formatAddress(string $name, string $addr): string
    {
        $addr = self::clean($addr);
        if ($name === '') return $addr;
        $n = preg_match('/[^\x20-\x7E]/', $name) ? self::encodeHeader($name) : '"' . addcslashes(self::clean($name), '"\\') . '"';
        return "$n <$addr>";
    }

    /** Build a multipart/alternative message (text + HTML). */
    public static function buildMessage(string $from, array $to, string $subject, string $html, string $text, ?string $replyTo): string
    {
        [$fromName, $fromAddr] = self::parseAddress($from);
        $domain = substr(strrchr($fromAddr, '@') ?: '@localhost', 1);
        $boundary = 'ydr-' . bin2hex(random_bytes(12));
        $h = [];
        $h[] = 'Date: ' . date(DATE_RFC2822);
        $h[] = 'From: ' . self::formatAddress($fromName, $fromAddr);
        $h[] = 'To: ' . implode(', ', array_map(fn ($a) => self::clean($a), $to));
        if ($replyTo) $h[] = 'Reply-To: ' . self::clean($replyTo);
        $h[] = 'Subject: ' . self::encodeHeader($subject);
        $h[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . self::clean($domain) . '>';
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = "--$boundary\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n")
            . "--$boundary\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . "--$boundary--\r\n";
        return implode("\r\n", $h) . "\r\n\r\n" . $body;
    }

    /** Send a prepared message. Throws on any SMTP error. */
    public static function send(array $cfg, string $from, array $to, string $data): void
    {
        $c = new self($cfg);
        $c->connect();
        try {
            [, $fromAddr] = self::parseAddress($from);
            $c->cmd('MAIL FROM:<' . self::clean($fromAddr) . '>', [250]);
            foreach ($to as $rcpt) {
                [, $addr] = self::parseAddress($rcpt);
                $c->cmd('RCPT TO:<' . self::clean($addr) . '>', [250, 251]);
            }
            $c->cmd('DATA', [354]);
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], ["\n", "\n"], $data));
            $data = str_replace("\n", "\r\n", (string) $data);
            $c->write($data . (str_ends_with($data, "\r\n") ? '' : "\r\n") . ".\r\n");
            $c->expect([250]);
            $c->cmd('QUIT', [221]);
        } finally {
            @fclose($c->sock);
        }
    }

    /** Connect + log in only (used by the hosting check). */
    public static function verify(array $cfg): void
    {
        $c = new self($cfg);
        $c->connect();
        try { $c->cmd('QUIT', [221]); } finally { @fclose($c->sock); }
    }

    private function connect(): void
    {
        $host = $this->cfg['host'];
        $port = (int) ($this->cfg['port'] ?: 587);
        $secure = (bool) $this->cfg['secure'];
        $ctx = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
        $sock = @stream_socket_client(($secure ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) throw new \RuntimeException("Could not connect to $host:$port — $errstr");
        stream_set_timeout($sock, 20);
        $this->sock = $sock;
        $this->expect([220]);
        $ehlo = $this->ehlo();
        if (!$secure && preg_match('/^250[ -]STARTTLS/mi', $ehlo)) {
            $this->cmd('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : 0))) {
                throw new \RuntimeException('Could not start TLS with the mail server');
            }
            $ehlo = $this->ehlo();
        }
        if ($this->cfg['user'] !== '') {
            if (preg_match('/^250[ -]AUTH[ =](.*)$/mi', $ehlo, $m) && stripos($m[1], 'PLAIN') !== false) {
                $this->cmd('AUTH PLAIN ' . base64_encode("\0" . $this->cfg['user'] . "\0" . $this->cfg['pass']), [235]);
            } else {
                $this->cmd('AUTH LOGIN', [334]);
                $this->cmd(base64_encode($this->cfg['user']), [334]);
                $this->cmd(base64_encode($this->cfg['pass']), [235]);
            }
        }
    }

    private function ehlo(): string
    {
        $name = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost')) ?: 'localhost';
        $this->write("EHLO $name\r\n");
        return $this->expect([250]);
    }

    private function cmd(string $line, array $ok): string
    {
        $this->write($line . "\r\n");
        return $this->expect($ok);
    }

    private function write(string $s): void
    {
        $len = strlen($s);
        for ($done = 0; $done < $len;) {
            $n = @fwrite($this->sock, substr($s, $done, 65536));
            if ($n === false || $n === 0) throw new \RuntimeException('Lost connection to the mail server');
            $done += $n;
        }
    }

    private function expect(array $codes): string
    {
        $resp = '';
        while (($line = fgets($this->sock, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        $this->last = trim($resp);
        $code = (int) substr($resp, 0, 3);
        if (!in_array($code, $codes, true)) {
            $msg = $this->last !== '' ? $this->last : 'no response (timeout)';
            if ($code === 535) $msg = 'Invalid login — check SMTP_USER / SMTP_PASS (' . $msg . ')';
            throw new \RuntimeException($msg);
        }
        return $resp;
    }
}
