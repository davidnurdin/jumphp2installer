<?php

namespace JumPHP2Installer;

/**
 * The servers' side of the statistics (Stats): what is kept of an event that arrives (accept()), and the
 * dashboard of a package from its events (build()). The jumphp2installer server and jumphp.net both use it; another
 * server can too (docs/installer/stats.md).
 *
 * A kept event (a row): event, install, os, arch, version, installer, mode, page, step, error, seconds, day (Y-m-d).
 * The server adds its own 'download' rows (an installer generated and downloaded, os = its platform) and, for a
 * download, install = ''.
 */
final class StatsReport
{
    public const KEPT = ['event', 'install', 'os', 'arch', 'version', 'installer', 'mode', 'page', 'step', 'error', 'seconds'];

    /**
     * An event as a server keeps it, or null when it is not one (a wrong body, an unknown event).
     *
     * @param mixed $body the JSON body, decoded
     * @return ?array<string, string|float|null>
     */
    public static function accept(mixed $body): ?array
    {
        if (!is_array($body) || !in_array($body['event'] ?? null, Stats::EVENTS, true) || !is_string($body['install'] ?? null)
            || !preg_match('/^[0-9a-f]{32}$/', $body['install'])) {
            return null;
        }
        $text = static function (mixed $v, int $max, string $pattern = '/^[\w.+-]*$/'): ?string {
            if (!is_scalar($v) || (string)$v === '') {
                return null;
            }
            $v = mb_substr(trim((string)$v), 0, $max);
            return preg_match($pattern, $v) ? $v : null;
        };
        $error = is_string($body['error'] ?? null) ? mb_substr(trim((string)preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $body['error'])), 0, 300) : null;
        $seconds = is_numeric($body['seconds'] ?? null) ? max(0.0, min(86400.0, round((float)$body['seconds'], 1))) : null;
        return [
            'event' => $body['event'],
            'install' => $body['install'],
            'os' => in_array($body['os'] ?? null, Script::SYSTEMS, true) ? $body['os'] : 'other',
            'arch' => $text($body['arch'] ?? null, 16) ?? '',
            'version' => $text($body['version'] ?? null, 40) ?? '',
            'installer' => $text($body['installer'] ?? null, 16) ?? '',
            'mode' => ($body['mode'] ?? null) === 'silent' ? 'silent' : 'window',
            'page' => in_array($body['page'] ?? null, Script::PAGES, true) ? $body['page'] : null,
            'step' => in_array($body['step'] ?? null, Script::STEPS, true) ? $body['step'] : null,
            'error' => $error !== '' ? $error : null,
            'seconds' => $seconds,
        ];
    }

    /**
     * The dashboard of a package.
     *
     * @param iterable<array<string, mixed>> $rows its events (accept()'s fields + day), downloads included
     * @param list<string> $pages the pages of its script, in order (the funnel)
     * @param int $days how many days the daily table shows (ending $today)
     * @return array{totals: array, funnel: list<array>, cancelled: list<array>, failed: list<array>, errors: list<array>,
     *               systems: list<array>, versions: list<array>, daily: list<array>}
     */
    public static function build(iterable $rows, array $pages = [], int $days = 30, ?string $today = null): array
    {
        $runs = [];             // install id => what it did
        $downloads = [];        // os => n
        $daily = [];
        foreach ($rows as $r) {
            $event = (string)($r['event'] ?? '');
            $day = (string)($r['day'] ?? '');
            $os = (string)($r['os'] ?? 'other');
            if ($event === 'download') {
                $downloads[$os] = ($downloads[$os] ?? 0) + 1;
                $daily[$day]['download'] = ($daily[$day]['download'] ?? 0) + 1;
                continue;
            }
            $id = (string)($r['install'] ?? '');
            $run = &$runs[$id];
            $run ??= ['os' => $os, 'version' => (string)($r['version'] ?? ''), 'mode' => (string)($r['mode'] ?? 'window'), 'day' => $day,
                'events' => [], 'pages' => [], 'cancel' => null, 'fail' => null, 'seconds' => null];
            $run['events'][$event] = true;
            if ($event === 'start') {
                $run['day'] = $day;
                $run['os'] = $os;
                $run['version'] = (string)($r['version'] ?? '') ?: $run['version'];
            }
            if (($r['page'] ?? null) !== null && $event === 'page') {
                $run['pages'][(string)$r['page']] = true;
            }
            if ($event === 'cancel') {
                $run['cancel'] ??= ['page' => (string)($r['page'] ?? ''), 'step' => (string)($r['step'] ?? '')];
            }
            if ($event === 'fail') {
                $run['fail'] ??= ['step' => (string)($r['step'] ?? ''), 'error' => (string)($r['error'] ?? '')];
            }
            if ($event === 'install' && isset($r['seconds'])) {
                $run['seconds'] = (float)$r['seconds'];
            }
            unset($run);
        }

        $count = static fn (array $list, string $event): int => count(array_filter($list, static fn (array $run): bool => isset($run['events'][$event])));
        $started = array_filter($runs, static fn (array $run): bool => isset($run['events']['start']));
        $starts = count($started);
        $installs = $count($runs, 'install');
        $times = array_values(array_filter(array_column($runs, 'seconds'), static fn ($s): bool => $s !== null));
        sort($times);
        $totals = [
            'downloads' => array_sum($downloads),
            'downloadsBySystem' => $downloads,
            'starts' => $starts,
            'installs' => $installs,
            'cancels' => $count($runs, 'cancel'),
            'fails' => $count($runs, 'fail'),
            'runs' => $count($runs, 'run'),
            'uninstalls' => $count($runs, 'uninstall'),
            'silent' => count(array_filter($started, static fn (array $run): bool => $run['mode'] === 'silent')),
            'success' => $starts > 0 ? round(100 * $installs / $starts, 1) : null,
            'medianSeconds' => $times !== [] ? $times[intdiv(count($times), 2)] : null,
        ];

        // the funnel: of the runs that started, how many saw each page (in the script's order)
        $seen = [];
        foreach ($started as $run) {
            foreach (array_keys($run['pages']) as $p) {
                $seen[$p] = ($seen[$p] ?? 0) + 1;
            }
        }
        $order = array_values(array_unique(array_merge($pages, array_keys($seen))));
        $funnel = [];
        foreach ($order as $p) {
            $n = $seen[$p] ?? 0;
            $funnel[] = ['page' => $p, 'runs' => $n, 'percent' => $starts > 0 ? round(100 * $n / $starts, 1) : 0.0];
        }

        $group = static function (array $items): array {
            $out = [];
            foreach ($items as $key) {
                $out[$key] = ($out[$key] ?? 0) + 1;
            }
            arsort($out);
            return $out;
        };
        $cancelled = [];
        foreach ($group(array_map(static fn (array $c): string => $c['page'] . '|' . $c['step'], array_filter(array_column($runs, 'cancel')))) as $key => $n) {
            [$page, $step] = explode('|', (string)$key);
            $cancelled[] = ['page' => $page, 'step' => $step, 'runs' => $n];
        }
        $fails = array_filter(array_column($runs, 'fail'));
        $failed = [];
        foreach ($group(array_map(static fn (array $f): string => $f['step'], $fails)) as $step => $n) {
            $failed[] = ['step' => (string)$step, 'runs' => $n];
        }
        $errors = [];
        foreach (array_slice($group(array_map(static fn (array $f): string => $f['error'], $fails)), 0, 10, true) as $error => $n) {
            $errors[] = ['error' => (string)$error, 'runs' => $n];
        }

        $by = static function (string $field) use ($runs, $downloads): array {
            $out = [];
            foreach ($runs as $run) {
                $k = $run[$field] !== '' ? $run[$field] : '?';
                $out[$k] ??= ['key' => $k, 'downloads' => 0, 'starts' => 0, 'installs' => 0, 'cancels' => 0, 'fails' => 0, 'uninstalls' => 0];
                foreach (['start' => 'starts', 'install' => 'installs', 'cancel' => 'cancels', 'fail' => 'fails', 'uninstall' => 'uninstalls'] as $e => $col) {
                    $out[$k][$col] += isset($run['events'][$e]) ? 1 : 0;
                }
            }
            if ($field === 'os') {
                foreach ($downloads as $platform => $n) {
                    $os = explode('-', $platform)[0];
                    $out[$os] ??= ['key' => $os, 'downloads' => 0, 'starts' => 0, 'installs' => 0, 'cancels' => 0, 'fails' => 0, 'uninstalls' => 0];
                    $out[$os]['downloads'] += $n;
                }
            }
            uasort($out, static fn (array $a, array $b): int => [$b['starts'], $b['downloads']] <=> [$a['starts'], $a['downloads']]);
            return array_values($out);
        };

        foreach ($runs as $run) {
            foreach (['start' => 'start', 'install' => 'install', 'cancel' => 'cancel', 'fail' => 'fail'] as $e => $col) {
                if (isset($run['events'][$e]) && $run['day'] !== '') {
                    $daily[$run['day']][$col] = ($daily[$run['day']][$col] ?? 0) + 1;
                }
            }
        }
        $table = [];
        $end = new \DateTimeImmutable($today ?? 'today');
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $end->modify("-$i days")->format('Y-m-d');
            $table[] = ['day' => $day] + array_merge(['download' => 0, 'start' => 0, 'install' => 0, 'cancel' => 0, 'fail' => 0], $daily[$day] ?? []);
        }

        return [
            'totals' => $totals,
            'funnel' => $funnel,
            'cancelled' => $cancelled,
            'failed' => $failed,
            'errors' => $errors,
            'systems' => $by('os'),
            'versions' => $by('version'),
            'daily' => $table,
        ];
    }

    /** The pages of a script, in order (the funnel's rows). */
    public static function pagesOf(string $yaml): array
    {
        return array_values(array_unique(array_column(Script::parse($yaml)->toArray()['pages'] ?? [], 'type')));
    }
}
