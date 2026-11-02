<?php

namespace App;

use JumPHP\Async\Task;
use JumPHP\Native\Dialog;
use JumPHP\Native\FilePicker;
use JumPHP\Tauri\App;
use JumPHP\Tauri\AppCli;
use JumPHP\Tauri\AppMain;
use JumPHP\Tauri\NativeEvent;
use JumPHP2Installer\Command;
use JumPHP2Installer\Runner;
use JumPHP2Installer\Session;
use JumPHP2Installer\Stats;
use JumPHP2Installer\Uninstaller;

/**
 * The installer. One executable for every package: what it installs comes from its server (Source: the address
 * and the package id written into it by the server, or the id in its file name), or a local script (authors,
 * tests). The page (ui/) is the wizard; PHP does the work (JumPHP2Installer\Session, Runner, Uninstaller), a little
 * at each frame, and tells the page how far it got ('progress').
 *
 * Copied into the install folder as its uninstaller: started with --uninstall (or from there), it removes the app.
 *
 * What happens is told to the package's statistics address (Stats): started, the pages seen ('wizard' from the
 * page), cancelled (where), failed (which step, why), installed, the app launched, uninstalled.
 */
final class Main implements AppMain, AppCli
{
    private App $app;
    private Source $source;
    private ?Session $session = null;
    private ?Runner $runner = null;
    private ?Uninstaller $uninstaller = null;
    /** @var list<Task> 'load' calls waiting for the package */
    private array $waiting = [];
    private bool $sentDone = false;
    private string $title = 'Setup';
    private Stats $stats;
    /** the outcome was told (installed, failed, cancelled, uninstalled): nothing more but 'run' */
    private bool $told = false;
    private bool $counted = false;
    private string $page = '';

    /** The command line: --help, --version, --silent installs and uninstalls, --check. Null: the window. */
    public static function cli(array $args, string $root): ?int
    {
        return (new Cli(Source::detect($args)))->run($args);
    }

    public function boot(App $app): void
    {
        $this->app = $app;
        $this->source = Source::detect(array_values(array_slice((array)($_SERVER['argv'] ?? []), 1)));
        $this->stats = Stats::off();
        $this->start();

        $app->command('load', fn (): Task|array => $this->load());
        $app->command('browse', fn (mixed $p): Task => FilePicker::folder(['directory' => is_array($p) ? (string)($p['folder'] ?? '') : '']));
        $app->command('check', fn (mixed $p): array => self::checkFolder(is_array($p) ? (string)($p['folder'] ?? '') : ''));
        $app->command('install', fn (mixed $p): bool => $this->install(is_array($p) ? $p : []));
        $app->command('uninstall', fn (): bool => $this->uninstall());
        $app->command('cancel', fn (): bool => $this->cancel());
        $app->command('finish', fn (mixed $p): bool => $this->finish(is_array($p) && !empty($p['run'])));
        $app->command('retry', function (): bool {
            $this->start();
            return true;
        });

        // the work, a little at each frame; the page hears of it every third frame (and of the end at once)
        $app->on('frame', function () use ($app): void {
            $this->tick();
            $this->report($app->frameCount() % 3 === 0);
        });

        // the page tells which page of the wizard it shows
        $app->on('page:wizard', function (mixed $p): void {
            $this->page = is_array($p) ? (string)($p['page'] ?? '') : '';
            !$this->told && $this->page !== '' && $this->stats->send('page', ['page' => $this->page]);
        });

        $app->window()->on('close-requested', function (NativeEvent $e): void {
            if ($this->busy()) {
                $e->preventDefault();
                $this->cancel();
                return;
            }
            $this->ending();
        });
        $app->on('quit', fn () => $this->ending());
    }

    /** The state (tests). */
    public function runner(): ?Runner
    {
        return $this->runner;
    }

    public function session(): ?Session
    {
        return $this->session;
    }

    private function start(): void
    {
        $this->session = null;
        $this->runner = null;
        $this->uninstaller = null;
        $this->sentDone = false;
        $this->counted = false;
        $this->told = false;
        if ($this->source->mode !== 'uninstall') {
            $this->session = $this->source->session();
        }
    }

    /** The statistics of this run, once its package is in (or of the install the uninstaller removes). */
    private function startStats(): void
    {
        [$configured, $refused] = Stats::configured(array_values(array_slice((array)($_SERVER['argv'] ?? []), 1)));
        $this->stats = $this->source->mode === 'uninstall'
            ? Cli::uninstallStats($this->source->manifest, $configured, $refused, 'window')
            : Cli::sessionStats($this->session, $configured, $refused, 'window');
        $this->source->mode !== 'uninstall' && $this->stats->send('start');
    }

    /** The window goes: a run that did not reach its end was cancelled there; what is on the way gets a moment. */
    private function ending(): void
    {
        if (!$this->told && $this->stats->isOn()) {
            $this->told = true;
            $this->stats->send('cancel', ['page' => $this->page, 'step' => $this->runner?->step()]);
        }
        $this->stats->flush(2.0);
    }

    private function tick(): void
    {
        $this->session?->tick();
        $this->countRun();
        $this->stats->tick();
        if ($this->session !== null && $this->session->tick() && $this->waiting !== []) {
            $answer = $this->answer();
            foreach ($this->waiting as $task) {
                $task->settle($answer);
            }
            $this->waiting = [];
        }
        $this->runner?->tick(10.0);
        $this->uninstaller?->tick(10.0);
    }

    private function report(bool $now): void
    {
        $work = $this->runner ?? $this->uninstaller;
        if ($work === null || $this->sentDone) {
            return;
        }
        $done = $work->isDone();
        if ($done && !$this->told) {
            $this->told = true;
            $this->tellOutcome($work);
        }
        if (!$now && !$done) {
            return;
        }
        $this->app->send('progress', [
            'progress' => round($work->progress(), 4),
            'label' => $work->label,
            'log' => array_slice($work->log, -200),
            'done' => $done,
            'error' => $work->error,
        ]);
        $this->sentDone = $done;
    }

    private function tellOutcome(Runner|Uninstaller $work): void
    {
        if ($work instanceof Uninstaller) {
            $this->stats->send('uninstall', ['seconds' => $this->stats->seconds()]);
        } elseif ($work->error === 'cancelled') {
            $this->stats->send('cancel', ['page' => 'install', 'step' => $work->step()]);
        } elseif ($work->error !== null) {
            $this->stats->send('fail', ['step' => $work->step(), 'error' => Stats::clean($work->error, $work->vars())]);
        } else {
            $this->stats->send('install', ['seconds' => $this->stats->seconds()]);
        }
    }

    /** What the page shows first: the package (when it is in), or the uninstall, or what went wrong. */
    private function load(): Task|array
    {
        if ($this->source->mode === 'uninstall') {
            $m = $this->source->manifest;
            if ($m === null) {
                return ['mode' => 'error', 'error' => 'Nothing to uninstall here (no ' . Runner::MANIFEST . ' next to this program).'];
            }
            $this->setTitle('Uninstall ' . ($m['app']['name'] ?? ''));
            return ['mode' => 'uninstall', 'app' => $m['app'] ?? [], 'folder' => $m['folder'] ?? ''];
        }
        if ($this->session === null) {
            return ['mode' => 'error', 'error' => $this->source->problem ?? 'No package to install.'];
        }
        if ($this->session->tick()) {
            return $this->answer();
        }
        $task = new Task();
        $this->waiting[] = $task;
        return $task;
    }

    /** The package is in: the run is counted, before the page hears of it (its first 'wizard' comes after). */
    private function countRun(): void
    {
        if ($this->session !== null && $this->session->isReady() && !$this->counted) {
            $this->counted = true;
            $this->startStats();
        }
    }

    private function answer(): array
    {
        $this->countRun();
        $s = $this->session;
        if ($s === null || $s->error !== null) {
            return ['mode' => 'error', 'error' => $s === null ? 'No package to install.' : (string)$s->error, 'retry' => in_array($this->source->mode, ['remote', 'static'], true)];
        }
        $view = $s->view();
        $this->setTitle(trim($view['app']['name'] . ' ' . $s->version) . ' Setup');
        return ['mode' => 'install'] + $view;
    }

    private function setTitle(string $title): void
    {
        $this->title = $title;
        $this->app->window()->setTitle($title);
    }

    /** @param array<string, mixed> $p {folder, options: {var: bool}} */
    private function install(array $p): bool
    {
        if ($this->session === null || !$this->session->isReady() || $this->busy()) {
            return false;
        }
        $folder = trim((string)($p['folder'] ?? '')) ?: $this->session->defaultFolder();
        $options = array_map('boolval', (array)($p['options'] ?? []));
        $this->runner = $this->session->install($folder, $options, Source::executable());
        $this->runner->stats = $this->stats->forManifest();
        $this->sentDone = false;
        return true;
    }

    private function uninstall(): bool
    {
        if ($this->source->manifest === null || $this->busy()) {
            return false;
        }
        $this->uninstaller = new Uninstaller($this->source->manifest, Source::executable());
        $this->startStats();
        $this->sentDone = false;
        return true;
    }

    private function cancel(): bool
    {
        if ($this->busy()) {
            Dialog::confirm('Stop now? What was already copied stays.', $this->title, 'Stop', 'Go on')
                ->then(fn (mixed $yes) => $yes ? $this->runner?->cancel() : null);
            return false;
        }
        $this->ending();
        $this->app->quit();
        return true;
    }

    /** The finish page: start the app if asked, then quit. */
    private function finish(bool $run): bool
    {
        $target = null;
        foreach ($this->session?->isReady() ? $this->session->view()['pages'] : [] as $page) {
            if ($page['type'] === 'finish' && $page['run'] !== null) {
                $target = $this->runner?->vars()->path($page['run']);
            }
        }
        if ($run && $target !== null && $this->runner?->error === null) {
            Command::detached($target);
            $this->stats->send('run');
        }
        $this->ending();
        $this->app->quit();
        return true;
    }

    private function busy(): bool
    {
        return ($this->runner !== null && !$this->runner->isDone()) || ($this->uninstaller !== null && !$this->uninstaller->isDone());
    }

    /** The folder page asks before going on: can it be written, is it already used, how much room is left. */
    public static function checkFolder(string $folder): array
    {
        $folder = rtrim(trim($folder), '/\\');
        if ($folder === '' || !Runner::isAbsolute($folder)) {
            return ['ok' => false, 'message' => 'Choose a full path (C:\\... or /...).'];
        }
        $existing = $folder;
        while (!is_dir($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }
        if (!is_writable($existing)) {
            return ['ok' => false, 'message' => "This folder cannot be written ($existing)."];
        }
        $free = @disk_free_space($existing);
        $files = is_dir($folder) ? count(array_diff(scandir($folder) ?: [], ['.', '..'])) : 0;
        return [
            'ok' => true,
            'free' => $free !== false ? Runner::size($free) : null,
            'warning' => is_file($folder . DIRECTORY_SEPARATOR . Runner::MANIFEST) ? 'already-installed' : ($files > 0 ? 'not-empty' : null),
        ];
    }
}
