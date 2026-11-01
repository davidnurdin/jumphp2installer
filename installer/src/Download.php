<?php

namespace JumPHP2Installer;

/**
 * One file downloaded without blocking: call tick() at every frame until it returns true; the bytes go straight to
 * the file (no size limit), the SHA-256 is computed on the way. HTTP / HTTPS (non-blocking sockets, TLS with the
 * system's certificates), redirects (8 at most), chunked answers, Content-Length for the progress. file:// and plain
 * paths are copied (tests, local packages).
 *
 *   $d = new Download('https://example.com/app.zip', '/tmp/app.zip');
 *   ... each frame: if ($d->tick()) { $d->error ?? 'done'; }   $d->received / $d->total
 */
final class Download
{
    public int $received = 0;
    public ?int $total = null;
    public ?string $error = null;
    public int $status = 0;
    public string $finalUrl;

    /** @var resource|null */
    private $socket = null;
    /** @var resource|null */
    private $out = null;
    private \HashContext $hash;
    private string $state = 'start';
    private string $buffer = '';
    private bool $chunked = false;
    private int $chunkLeft = 0;
    private int $redirects = 0;
    private float $deadline;
    private string $host = '';
    private bool $tls = false;
    /** @var resource|null */
    private $source = null;

    public function __construct(public readonly string $url, public readonly string $file, private readonly float $timeout = 60.0)
    {
        $this->finalUrl = $url;
        $this->hash = hash_init('sha256');
        $this->deadline = microtime(true) + $timeout;
    }

    public function isDone(): bool
    {
        return $this->state === 'done';
    }

    public function sha256(): string
    {
        return hash_final(hash_copy($this->hash));
    }

    /** 0..1, or null when the size is not known. */
    public function progress(): ?float
    {
        return $this->total ? min(1.0, $this->received / $this->total) : null;
    }

    /** Does some work (at most ~$budgetMs ms). Returns true when finished (then look at $error). */
    public function tick(float $budgetMs = 8.0): bool
    {
        if ($this->state === 'done') {
            return true;
        }
        $end = microtime(true) + $budgetMs / 1000;
        try {
            do {
                $progress = match ($this->state) {
                    'start' => $this->start(),
                    'copy' => $this->copyChunk(),
                    'connect' => $this->connect(),
                    'tls' => $this->handshake(),
                    'headers', 'body' => $this->read(),
                    default => false,
                };
                if ($this->state === 'done') {
                    return true;
                }
                if (microtime(true) > $this->deadline) {
                    throw new \RuntimeException('no answer for ' . (int)$this->timeout . ' s');
                }
            } while ($progress && microtime(true) < $end);
        } catch (\Throwable $e) {
            $this->finish($e->getMessage());
            return true;
        }
        return false;
    }

    // --- states -------------------------------------------------------------------------------------------------

    private function start(): bool
    {
        @mkdir(dirname($this->file), 0777, true);
        $this->out = @fopen($this->file, 'wb') ?: throw new \RuntimeException("cannot write $this->file");
        $url = $this->finalUrl;
        if (!preg_match('#^https?://#i', $url)) {
            $path = preg_replace('#^file://#', '', $url);
            $this->source = @fopen((string)$path, 'rb') ?: throw new \RuntimeException("cannot read $path");
            $this->total = (int)filesize((string)$path);
            $this->state = 'copy';
            return true;
        }
        $this->open($url);
        return true;
    }

    private function copyChunk(): bool
    {
        $data = fread($this->source, 262144);
        if ($data === false || $data === '') {
            fclose($this->source);
            $this->status = 200;
            $this->finish(null);
            return false;
        }
        $this->write($data);
        return true;
    }

    private function open(string $url): void
    {
        $p = parse_url($url);
        if (!isset($p['host'])) {
            throw new \RuntimeException("not an address: $url");
        }
        $this->tls = strtolower($p['scheme'] ?? 'http') === 'https';
        $this->host = $p['host'];
        $port = $p['port'] ?? ($this->tls ? 443 : 80);
        $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        $context = stream_context_create(['ssl' => ['peer_name' => $this->host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true]
            + (($ca = getenv('SSL_CERT_FILE')) ? ['cafile' => $ca] : [])]);
        $socket = @stream_socket_client("tcp://$this->host:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $context);
        if (!$socket) {
            throw new \RuntimeException("cannot reach $this->host ($errstr)");
        }
        stream_set_blocking($socket, false);
        $this->socket = $socket;
        $this->buffer = 'GET ' . $path . " HTTP/1.1\r\nHost: " . $this->host . ($p['port'] ?? null ? ':' . $port : '')
            . "\r\nUser-Agent: jumphp2installer\r\nAccept: */*\r\nAccept-Encoding: identity\r\nConnection: close\r\n\r\n";
        $this->state = 'connect';
    }

    private function connect(): bool
    {
        $r = null;
        $w = [$this->socket];
        $e = null;
        if (@stream_select($r, $w, $e, 0, 0) < 1) {
            return false;       // not connected yet: next frame
        }
        $this->state = $this->tls ? 'tls' : 'send';
        if (!$this->tls) {
            $this->send();
        }
        return true;
    }

    private function handshake(): bool
    {
        $ok = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($ok === false) {
            throw new \RuntimeException("TLS with $this->host failed (its certificate?)");
        }
        if ($ok === 0) {
            return false;       // the handshake goes on: next frame
        }
        $this->send();
        return true;
    }

    private function send(): void
    {
        while ($this->buffer !== '') {
            $n = @fwrite($this->socket, $this->buffer);
            if ($n === false) {
                throw new \RuntimeException("cannot send to $this->host");
            }
            $this->buffer = (string)substr($this->buffer, $n);
            if ($n === 0) {
                usleep(1000);
            }
        }
        $this->state = 'headers';
    }

    private function read(): bool
    {
        $data = @fread($this->socket, 262144);
        if ($data === false || ($data === '' && feof($this->socket))) {
            if ($this->state === 'body' && !$this->chunked && ($this->total === null || $this->received >= $this->total)) {
                $this->finish(null);
                return false;
            }
            throw new \RuntimeException("$this->host closed the connection" . ($this->total ? " at {$this->received} / {$this->total} bytes" : ''));
        }
        if ($data === '') {
            return false;
        }
        $this->deadline = microtime(true) + $this->timeout;
        if ($this->state === 'headers') {
            $this->buffer .= $data;
            $end = strpos($this->buffer, "\r\n\r\n");
            if ($end === false) {
                return true;
            }
            $head = substr($this->buffer, 0, $end);
            $rest = (string)substr($this->buffer, $end + 4);
            $this->buffer = '';
            if ($this->headers($head)) {
                return true;    // a redirect: started again
            }
            $this->state = 'body';
            $data = $rest;
        }
        $this->chunked ? $this->unchunk($data) : $this->write($data);
        if (!$this->chunked && $this->total !== null && $this->received >= $this->total) {
            $this->finish(null);
            return false;
        }
        return true;
    }

    /** Returns true for a redirect (followed). */
    private function headers(string $head): bool
    {
        $lines = explode("\r\n", $head);
        if (!preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $lines[0] ?? '', $m)) {
            throw new \RuntimeException("$this->host: not an HTTP answer");
        }
        $this->status = (int)$m[1];
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        if (in_array($this->status, [301, 302, 303, 307, 308], true) && isset($headers['location'])) {
            if (++$this->redirects > 8) {
                throw new \RuntimeException('too many redirects');
            }
            fclose($this->socket);
            $this->finalUrl = self::resolve($this->finalUrl, $headers['location']);
            $this->open($this->finalUrl);
            return true;
        }
        if ($this->status >= 400) {
            throw new \RuntimeException("HTTP $this->status for $this->finalUrl");
        }
        $this->chunked = str_contains(strtolower($headers['transfer-encoding'] ?? ''), 'chunked');
        $this->total = isset($headers['content-length']) ? (int)$headers['content-length'] : null;
        return false;
    }

    private function unchunk(string $data): void
    {
        $this->buffer .= $data;
        while ($this->buffer !== '') {
            if ($this->chunkLeft > 0) {
                $part = substr($this->buffer, 0, $this->chunkLeft);
                $this->write($part);
                $this->chunkLeft -= strlen($part);
                $this->buffer = (string)substr($this->buffer, strlen($part));
                if ($this->chunkLeft > 0) {
                    return;
                }
                $this->chunkLeft = -2;      // its CRLF next
            }
            if ($this->chunkLeft === -2) {
                if (strlen($this->buffer) < 2) {
                    return;
                }
                $this->buffer = (string)substr($this->buffer, 2);
                $this->chunkLeft = 0;
            }
            $eol = strpos($this->buffer, "\r\n");
            if ($eol === false) {
                return;
            }
            $size = hexdec(trim(explode(';', substr($this->buffer, 0, $eol))[0]));
            $this->buffer = (string)substr($this->buffer, $eol + 2);
            if ($size === 0) {
                $this->finish(null);
                return;
            }
            $this->chunkLeft = (int)$size;
        }
    }

    private function write(string $data): void
    {
        if ($data === '') {
            return;
        }
        if (fwrite($this->out, $data) !== strlen($data)) {
            throw new \RuntimeException("cannot write $this->file (disk full?)");
        }
        hash_update($this->hash, $data);
        $this->received += strlen($data);
    }

    private function finish(?string $error): void
    {
        $this->error = $error;
        $this->state = 'done';
        is_resource($this->socket) && @fclose($this->socket);
        is_resource($this->out) && @fclose($this->out);
        is_resource($this->source) && @fclose($this->source);
        if ($error !== null) {
            @unlink($this->file);
        }
    }

    private static function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($p['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        return $origin . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $location;
    }
}
