<?php

namespace JumPHP2Installer\Tests;

use JumPHP2Installer\StatsReport;
use PHPUnit\Framework\TestCase;

final class StatsReportTest extends TestCase
{
    private static function runOf(string $id, string $day, array $events, string $os = 'windows', string $version = '1.0'): array
    {
        $rows = [];
        foreach ($events as $e) {
            [$event, $extra] = is_array($e) ? $e : [$e, []];
            $rows[] = ['event' => $event, 'install' => str_pad($id, 32, '0'), 'os' => $os, 'version' => $version, 'mode' => 'window', 'day' => $day] + $extra;
        }
        return $rows;
    }

    public function testABodyFromAnInstallerIsKeptCleanAndAnythingElseRefused(): void
    {
        $row = StatsReport::accept(['event' => 'fail', 'install' => str_repeat('a', 32), 'os' => 'linux', 'step' => 'download',
            'error' => "HTTP 404\nfor x", 'page' => '<script>', 'seconds' => '12.34', 'version' => '1.2.0', 'extra' => 'dropped']);
        self::assertSame('fail', $row['event']);
        self::assertSame('download', $row['step']);
        self::assertNull($row['page']);
        self::assertSame('HTTP 404 for x', $row['error']);
        self::assertSame(12.3, $row['seconds']);
        self::assertArrayNotHasKey('extra', $row);
        self::assertNull(StatsReport::accept(['event' => 'hack', 'install' => str_repeat('a', 32)]));
        self::assertNull(StatsReport::accept(['event' => 'start', 'install' => 'short']));
        self::assertNull(StatsReport::accept('nope'));
        self::assertNull($row['download']);
        self::assertSame('k3x9p2qa7m4z', StatsReport::accept(['event' => 'start', 'install' => str_repeat('a', 32), 'download' => 'k3x9p2qa7m4z'])['download']);
        self::assertNull(StatsReport::accept(['event' => 'start', 'install' => str_repeat('a', 32), 'download' => '../x'])['download']);
    }

    public function testTheDashboardTellsWhichDownloadsWereRunAndInstalled(): void
    {
        $rows = array_merge(
            [['event' => 'download', 'download' => 'aaaaaaaaaaa1', 'os' => 'windows-x86_64', 'install' => '', 'day' => '2026-11-02'],
             ['event' => 'download', 'download' => 'aaaaaaaaaaa2', 'os' => 'windows-x86_64', 'install' => '', 'day' => '2026-11-02'],
             ['event' => 'download', 'download' => 'aaaaaaaaaaa3', 'os' => 'linux-x86_64', 'install' => '', 'day' => '2026-11-02'],
             ['event' => 'fetch', 'download' => 'aaaaaaaaaaa1', 'os' => 'windows-x86_64', 'install' => '', 'day' => '2026-11-02'],
             ['event' => 'fetch', 'download' => 'aaaaaaaaaaa1', 'os' => 'windows-x86_64', 'install' => '', 'day' => '2026-11-02'],
             ['event' => 'fetch', 'download' => 'aaaaaaaaaaa2', 'os' => 'windows-x86_64', 'install' => '', 'day' => '2026-11-02']],
            self::runOf('1', '2026-11-02', ['start', ['install', ['download' => 'aaaaaaaaaaa1']]]),
        );
        $r = StatsReport::build($rows, [], 1, '2026-11-02');
        self::assertSame(3, $r['totals']['downloads']);
        self::assertSame(2, $r['totals']['downloadsRun']);
        self::assertSame(1, $r['totals']['downloadsInstalled']);
        self::assertSame(1, $r['totals']['starts']);
    }

    public function testTheDashboardCountsRunsWhereTheyStoppedAndWhy(): void
    {
        $rows = array_merge(
            [['event' => 'download', 'os' => 'windows-x86_64', 'install' => '', 'day' => '2026-11-01'],
             ['event' => 'download', 'os' => 'windows-x86_64', 'install' => '', 'day' => '2026-11-02'],
             ['event' => 'download', 'os' => 'linux-x86_64', 'install' => '', 'day' => '2026-11-02']],
            self::runOf('1', '2026-11-01', ['start', ['page', ['page' => 'welcome']], ['page', ['page' => 'license']], ['page', ['page' => 'install']], ['install', ['seconds' => 20.0]], 'run']),
            self::runOf('2', '2026-11-02', ['start', ['page', ['page' => 'welcome']], ['page', ['page' => 'welcome']], ['cancel', ['page' => 'welcome']]]),
            self::runOf('3', '2026-11-02', ['start', ['page', ['page' => 'welcome']], ['page', ['page' => 'license']], ['page', ['page' => 'install']],
                ['fail', ['step' => 'download', 'error' => 'HTTP 404']]], 'linux', '1.1'),
            self::runOf('1', '2026-11-02', ['uninstall']),
        );
        $r = StatsReport::build($rows, ['welcome', 'license', 'install', 'finish'], 3, '2026-11-02');
        self::assertSame(3, $r['totals']['downloads']);
        self::assertSame(3, $r['totals']['starts']);
        self::assertSame(1, $r['totals']['installs']);
        self::assertSame(1, $r['totals']['cancels']);
        self::assertSame(1, $r['totals']['fails']);
        self::assertSame(1, $r['totals']['runs']);
        self::assertSame(1, $r['totals']['uninstalls']);
        self::assertSame(33.3, $r['totals']['success']);
        self::assertSame(20.0, $r['totals']['medianSeconds']);
        self::assertSame([['welcome', 3], ['license', 2], ['install', 2], ['finish', 0]], array_map(static fn (array $f): array => [$f['page'], $f['runs']], $r['funnel']));
        self::assertSame([['page' => 'welcome', 'step' => '', 'runs' => 1]], $r['cancelled']);
        self::assertSame([['step' => 'download', 'runs' => 1]], $r['failed']);
        self::assertSame([['error' => 'HTTP 404', 'runs' => 1]], $r['errors']);
        $windows = $r['systems'][0];
        self::assertSame(['windows', 2, 2, 1, 1], [$windows['key'], $windows['downloads'], $windows['starts'], $windows['installs'], $windows['cancels']]);
        self::assertSame(['2026-10-31', '2026-11-01', '2026-11-02'], array_column($r['daily'], 'day'));
        self::assertSame([1, 1, 1], [$r['daily'][1]['download'], $r['daily'][1]['start'], $r['daily'][1]['install']]);
        self::assertSame([2, 2, 1, 1], [$r['daily'][2]['download'], $r['daily'][2]['start'], $r['daily'][2]['cancel'], $r['daily'][2]['fail']]);
        self::assertSame(['1.0', '1.1'], array_column($r['versions'], 'key'));
    }
}
