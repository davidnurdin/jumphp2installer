<?php

namespace JumPHP2Installer\Tests;

use JumPHP2Installer\Download;
use PHPUnit\Framework\TestCase;

final class DownloadTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/j2i-dl-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("$this->dir/*") ?: []);
        @rmdir($this->dir);
    }

    private static function finish(Download $d): Download
    {
        $frames = 0;
        while (!$d->tick(4.0)) {
            usleep(1000);
            if (++$frames > 20000) {
                self::fail('the download never finished');
            }
        }
        return $d;
    }

    public function testABigFileArrivesWholeAFewKilobytesAtATime(): void
    {
        $server = new TestServer();
        $d = self::finish(new Download("$server->url/bytes/3000001", "$this->dir/big.bin"));
        self::assertNull($d->error);
        self::assertSame(3000001, $d->received);
        self::assertSame(3000001, $d->total);
        self::assertSame(3000001, filesize("$this->dir/big.bin"));
        self::assertSame(hash_file('sha256', "$this->dir/big.bin"), $d->sha256());
        self::assertSame(1.0, $d->progress());
    }

    public function testARedirectIsFollowed(): void
    {
        $server = new TestServer();
        $d = self::finish(new Download("$server->url/redirect", "$this->dir/r.bin"));
        self::assertNull($d->error);
        self::assertSame("$server->url/bytes/1000", $d->finalUrl);
        self::assertSame(1000, filesize("$this->dir/r.bin"));
    }

    public function testAMissingFileIsAnErrorAndLeavesNothing(): void
    {
        $server = new TestServer();
        $d = self::finish(new Download("$server->url/missing", "$this->dir/m.bin"));
        self::assertStringContainsString('HTTP 404', (string)$d->error);
        self::assertFileDoesNotExist("$this->dir/m.bin");
    }

    public function testAChunkedAnswerIsPutBackTogether(): void
    {
        $server = new TestServer([], 'chunked');
        $d = self::finish(new Download("$server->url/", "$this->dir/c.txt"));
        self::assertNull($d->error);
        self::assertSame('Hello, ' . str_repeat('x', 5000) . ' world', file_get_contents("$this->dir/c.txt"));
    }

    public function testNobodyThereIsAnError(): void
    {
        $d = self::finish(new Download('http://127.0.0.1:1/x', "$this->dir/x", 2.0));
        self::assertNotNull($d->error);
    }

    public function testALocalFileIsCopied(): void
    {
        file_put_contents("$this->dir/src.txt", 'local');
        $d = self::finish(new Download("$this->dir/src.txt", "$this->dir/dst.txt"));
        self::assertNull($d->error);
        self::assertSame('local', file_get_contents("$this->dir/dst.txt"));
    }
}
