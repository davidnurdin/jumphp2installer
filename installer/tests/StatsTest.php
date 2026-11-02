<?php

namespace JumPHP2Installer\Tests;

use JumPHP2Installer\Package;
use JumPHP2Installer\Script;
use JumPHP2Installer\Stats;
use JumPHP2Installer\Vars;
use PHPUnit\Framework\TestCase;

final class StatsTest extends TestCase
{
    private static function package(string $extra = '', string $server = 'https://jumphp.net/installers', string $id = 'k2q9x7a1'): Package
    {
        $yaml = "app: {name: My App}\nversion: 1.0.0\npages: [welcome, install]\nsteps: [{mkdir: '{folder}/x'}]\n" . $extra;
        return new Package($id, Script::parse($yaml), "$server/api/packages/$id/assets/", $server);
    }

    public function testAPackageOfAServerTellsThatServer(): void
    {
        self::assertSame('https://jumphp.net/installers/api/packages/k2q9x7a1/stats', Stats::address(self::package(), null, null));
    }

    public function testTheScriptChoosesItsOwnAddressOrNone(): void
    {
        $own = self::package("stats: https://example.com/stats\n");
        self::assertSame([], $own->script->problems());
        self::assertSame('https://example.com/stats', Stats::address($own, $own->script->toArray()['stats'], null));
        $none = self::package("stats: false\n");
        self::assertNull(Stats::address($none, $none->script->toArray()['stats'], 'https://other.example/s'));
        self::assertNotSame([], self::package("stats: ftp://nope\n")->script->problems());
    }

    public function testTheExecutablesAddressServesAPackageWithoutAServer(): void
    {
        $static = self::package('', 'github:me/app', 'static');
        self::assertNull(Stats::address($static, null, null));
        self::assertSame('https://mine.example/stats', Stats::address($static, null, 'https://mine.example/stats'));
    }

    public function testTheUserCanRefuse(): void
    {
        self::assertNull(Stats::address(self::package(), null, 'off'));
        self::assertNull(Stats::address(self::package("stats: https://example.com/stats\n"), 'https://example.com/stats', null, true));
    }

    public function testAnErrorLosesTheUsersFolders(): void
    {
        $vars = new Vars(['home' => '/home/ada', 'temp' => '/tmp', 'folder' => '/home/ada/.local/opt/My App']);
        self::assertSame('cannot write {folder}/x (disk full?), not {home}/y', Stats::clean('cannot write /home/ada/.local/opt/My App/x (disk full?), not /home/ada/y', $vars));
        self::assertSame('C:\\Users\\…\\AppData', Stats::clean('C:\\Users\\bob\\AppData', new Vars()));
    }

    public function testEventsArriveAtTheServerAsJson(): void
    {
        $file = sys_get_temp_dir() . '/j2i-stats-' . bin2hex(random_bytes(4));
        $server = new TestServer(['STATS_FILE' => $file]);
        $stats = new Stats("$server->url/api/packages/demo/stats", ['package' => 'demo', 'os' => 'linux', 'mode' => 'silent']);
        $stats->send('start');
        $stats->send('page', ['page' => 'welcome']);
        $stats->send('fail', ['step' => 'download', 'error' => 'HTTP 404']);
        $stats->send('nonsense');
        $stats->flush(5.0);
        $lines = array_map(static fn (string $l): array => json_decode($l, true), file($file, FILE_IGNORE_NEW_LINES) ?: []);
        @unlink($file);
        self::assertCount(3, $lines);
        $events = array_column($lines, 'event');
        sort($events);
        self::assertSame(['fail', 'page', 'start'], $events);
        foreach ($lines as $l) {
            self::assertSame($stats->install, $l['install']);
            self::assertSame('demo', $l['package']);
        }
    }

    public function testTheUninstallerTellsTheSameRun(): void
    {
        $stats = new Stats('/dev/null', ['package' => 'demo', 'mode' => 'window']);
        $again = Stats::fromManifest(['stats' => $stats->forManifest()], 'silent');
        self::assertSame($stats->install, $again->install);
        self::assertSame('/dev/null', $again->url);
        self::assertFalse(Stats::fromManifest(['stats' => $stats->forManifest()], 'silent', 'off')->isOn());
        self::assertFalse(Stats::fromManifest([], 'silent')->isOn());
    }
}
