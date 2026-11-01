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
use JumPHP2Installer\Uninstaller;

/**
 * The installer. One executable for every package: what it installs comes from its server (Source: the address
 * and the package id written into it by the server, or the id in its file name), or a local script (authors,
 * tests). The page (ui/) is the wizard; PHP does the work (JumPHP2Installer\Session, Runner, Uninstaller), a little
 * at each frame, and tells the page how far it got ('progress').
 *
 * Copied into the install folder as its uninstaller: started with --uninstall (or from there), it removes the app.
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

    /** The command line: --help, --version, --silent installs and uninstalls, --check. Null: the window. */
    public static function cli(array $args, string $root): ?int
    {
        return (new Cli(Source::detect($args)))->run($args);
    }

    public function boot(App $app): void
    {
        $this->app = $app;
        $this->source = Source::detect(array_values(array_slice((array)($_SERVER['argv'] ?? []), 1)));
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

        $app->window()->on('close-requested', function (NativeEvent $e): void {
            if ($this->busy()) {
                $e->preventDefault();
                $this->cancel();
            }
        });
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
        if ($this->source->mode !== 'uninstall') {
            $this->session = $this->source->session();
        }
    }

    private function tick(): void
    {
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

    private function answer(): array
    {
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
        $this->sentDone = false;
        return true;
    }

    private function uninstall(): bool
    {
        if ($this->source->manifest === null || $this->busy()) {
            return false;
        }
        $this->uninstaller = new Uninstaller($this->source->manifest, Source::executable());
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
        }
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
