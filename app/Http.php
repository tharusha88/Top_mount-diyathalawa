<?php
// Tiny request / response objects.
declare(strict_types=1);

namespace Ydr;

final class HttpError extends \RuntimeException
{
    public function __construct(public int $status, string $message)
    {
        parent::__construct($message);
    }
}

final class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $headers = [];
    public string $raw = '';
    /** Parsed JSON body (assoc array), [] when absent */
    public array $body = [];

    public static function fromGlobals(): self
    {
        $r = new self();
        $r->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $r->path = (string) (parse_url('http://x' . $uri, PHP_URL_PATH) ?? '/');
        if ($r->path === '') $r->path = '/';
        $r->query = $_GET;
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) $r->headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
        }
        if (isset($_SERVER['CONTENT_TYPE'])) $r->headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] !== '') $r->headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        return $r;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function q(string $key): mixed
    {
        return $this->query[$key] ?? null;
    }

    public function mime(): string
    {
        return strtolower(trim(explode(';', $this->header('content-type') ?? '')[0]));
    }

    /** type-is "has body": Transfer-Encoding present or a numeric Content-Length (even 0). */
    public function typeisHasBody(): bool
    {
        $cl = $this->header('content-length');
        return $this->header('transfer-encoding') !== null || ($cl !== null && is_numeric($cl));
    }

    public function isJson(): bool
    {
        return $this->typeisHasBody() && $this->mime() === 'application/json';
    }

    public function isImage(): bool
    {
        return $this->typeisHasBody() && str_starts_with($this->mime(), 'image/');
    }

    public function contentLength(): int
    {
        return (int) ($this->header('content-length') ?? 0);
    }

    public function readRaw(int $limit): string
    {
        if ($this->contentLength() > $limit) throw new HttpError(413, 'Request too large');
        $raw = (string) file_get_contents('php://input', false, null, 0, $limit + 1);
        if (strlen($raw) > $limit) throw new HttpError(413, 'Request too large');
        return $raw;
    }

    public function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (Config::get()['trustProxy']) {
            $xff = $this->header('x-forwarded-for');
            if ($xff !== null && $xff !== '') {
                $parts = array_map('trim', explode(',', $xff));
                return end($parts) ?: $remote;
            }
        }
        return $remote;
    }
}

final class Response
{
    public int $status = 200;
    /** @var array<string, array{0:string,1:string[]}> lower-name => [display name, values] */
    private array $headers = [];
    public string $body = '';
    public bool $done = false;

    public function header(string $name, string $value, bool $append = false): self
    {
        $k = strtolower($name);
        if ($append || $k === 'set-cookie') {
            $this->headers[$k] ??= [$name, []];
            $this->headers[$k][1][] = $value;
        } else {
            $this->headers[$k] = [$name, [$value]];
        }
        return $this;
    }

    public function json(mixed $data, ?int $status = null): self
    {
        if ($status !== null) $this->status = $status;
        $this->header('Content-Type', 'application/json; charset=utf-8');
        $this->body = Js::json($data);
        $this->done = true;
        return $this;
    }

    public function send(string $body, string $type, ?int $status = null): self
    {
        if ($status !== null) $this->status = $status;
        $this->header('Content-Type', $type);
        $this->body = $body;
        $this->done = true;
        return $this;
    }

    public function redirect(int $status, string $location): self
    {
        $this->status = $status;
        $this->header('Location', $location);
        return $this->send('Moved Permanently. Redirecting to ' . $location, 'text/plain; charset=utf-8');
    }

    /** Write the response and let the browser go before any slow after-work (emails). */
    public function emit(bool $closeEarly): void
    {
        if (function_exists('header_remove')) header_remove('X-Powered-By');
        http_response_code($this->status);
        foreach ($this->headers as [$name, $values]) {
            foreach ($values as $i => $v) header("$name: $v", $i === 0);
        }
        header('Content-Length: ' . strlen($this->body));
        if ($closeEarly) {
            ignore_user_abort(true);
            header('Connection: close');
        }
        echo $this->body;
        if ($closeEarly) {
            while (ob_get_level() > 0) @ob_end_flush();
            @flush();
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        }
    }
}
