<?php

namespace JumPHP2Installer\Tests;

/** php -S on a free port with tests/fixtures/router.php, for the tests that download. */
final class TestServer
{
    /** @var resource */
    private $process;
    public readonly string $url;

    public function __construct(array $env = [], string $script = 'router')
    {
        $port = self::freePort();
        $command = $script === 'router'
            ? [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fixtures/router.php']
            : [PHP_BINARY, __DIR__ . "/fixtures/$script.php", (string)$port];
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env + getenv());
        $this->url = "http://127.0.0.1:$port";
        for ($i = 0; $i < 200; $i++) {
            if (@fsockopen('127.0.0.1', $port) || ($script !== 'router' && fgets($pipes[1]) === "ready\n")) {
                return;
            }
            usleep(10000);
        }
        throw new \RuntimeException('the test server did not start');
    }

    public function __destruct()
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr((string)stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        return $port;
    }
}
