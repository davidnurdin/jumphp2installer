<?php

namespace JumPHP2Installer;

/**
 * Removes what an install made, from its manifest ({folder}/uninstall.json, Runner): its files, then its folders
 * when they are empty (a file the user put there stays), its shortcuts, its uninstall entry (Windows' registry).
 * The uninstaller itself (the installer copied there, now running) goes last: Linux / macOS remove it at once,
 * Windows a moment after it quits (a running .exe cannot be deleted).
 */
final class Uninstaller
{
    public ?string $error = null;
    public string $label = '';
    /** @var list<string> */
    public array $log = [];

    /** @var list<array{string, string}> what is left: [kind, path] */
    private array $todo = [];
    private int $total;
    private bool $done = false;
    /** @var list<Command> */
    private array $commands = [];

    /** @param array<string, mixed> $manifest */
    public function __construct(public readonly array $manifest, private readonly ?string $self = null)
    {
        foreach ((array)($manifest['shortcuts'] ?? []) as $p) {
            $this->todo[] = ['file', (string)$p];
        }
        foreach ((array)($manifest['files'] ?? []) as $p) {
            $this->todo[] = ['file', (string)$p];
        }
        foreach (PHP_OS_FAMILY === 'Windows' ? (array)($manifest['path'] ?? []) : [] as $dir) {
            $this->todo[] = ['path', (string)$dir];
        }
        foreach (PHP_OS_FAMILY === 'Windows' ? (array)($manifest['runvalues'] ?? []) : [] as $id) {
            $this->todo[] = ['runvalue', (string)$id];
        }
        if (($manifest['registry'] ?? null) && PHP_OS_FAMILY === 'Windows') {
            $this->todo[] = ['registry', (string)$manifest['registry']];
        }
        $folder = (string)($manifest['folder'] ?? '');
        if ($folder !== '') {
            $this->todo[] = ['file', $folder . DIRECTORY_SEPARATOR . Runner::MANIFEST];
            $uninstaller = (string)($manifest['uninstaller'] ?? '');
            if ($uninstaller !== '' && !$this->isSelf($uninstaller)) {
                $this->todo[] = [is_dir($uninstaller) ? 'tree' : 'file', $uninstaller];
            }
        }
        foreach ($this->dirs() as $p) {
            $this->todo[] = ['dir', $p];
        }
        $this->total = max(1, count($this->todo));
    }

    /** The manifest of the install whose folder holds $file (the uninstaller: its own executable), or null. */
    public static function find(string $file): ?array
    {
        $dir = is_dir($file) ? $file : dirname($file);
        // macOS: <folder>/Uninstall X.app/Contents/MacOS/<exe>
        if (preg_match('#^(.*)/[^/]+\.app/Contents/MacOS$#', $dir, $m)) {
            $dir = $m[1];
        }
        $json = @file_get_contents($dir . DIRECTORY_SEPARATOR . Runner::MANIFEST);
        $data = is_string($json) ? json_decode($json, true) : null;
        return is_array($data) && ($data['format'] ?? null) === 1 ? $data : null;
    }

    public function name(): string
    {
        return (string)($this->manifest['app']['name'] ?? '');
    }

    public function progress(): float
    {
        return $this->done ? 1.0 : 1 - count($this->todo) / $this->total;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function tick(float $budgetMs = 12.0): bool
    {
        if ($this->done) {
            return true;
        }
        $end = microtime(true) + $budgetMs / 1000;
        foreach ($this->commands as $c) {
            if (!$c->isDone()) {
                return false;
            }
        }
        $this->commands = [];
        while ($this->todo !== [] && microtime(true) < $end) {
            [$kind, $path] = array_shift($this->todo);
            $this->label = 'Removing ' . basename($path);
            switch ($kind) {
                case 'file':
                    if (is_file($path) || is_link($path)) {
                        @unlink($path) || $this->log[] = "  kept $path (in use?)";
                    }
                    break;
                case 'tree':
                    Runner::remove($path);
                    break;
                case 'dir':
                    is_dir($path) && @rmdir($path);     // not empty: said at the end, if it still is
                    break;
                case 'registry':
                    $this->commands[] = new Command(['reg.exe', 'delete', $path, '/f']);
                    return false;
                case 'runvalue':    // an autostart step's value of HKCU\...\Run
                    $this->commands[] = new Command(['reg.exe', 'delete', Runner::RUN_KEY, '/v', $path, '/f']);
                    return false;
                case 'path':        // the folder a `command` step put in the user's Path
                    $this->commands[] = new Command(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', Runner::pathScript($path, false)]);
                    return false;
            }
        }
        if ($this->todo !== []) {
            return false;
        }
        $this->removeSelf();
        foreach ($this->dirs() as $dir) {
            if (is_dir($dir) && !(PHP_OS_FAMILY === 'Windows' && $this->isSelf($dir))) {     // Windows: the .exe goes after
                $this->log[] = "  kept $dir (files that were not installed are in it)";
            }
        }
        $this->label = 'Done';
        $this->done = true;
        return true;
    }

    public function runAll(): bool
    {
        while (!$this->tick(50.0)) {
            usleep(2000);
        }
        return $this->error === null;
    }

    private function removeSelf(): void
    {
        $uninstaller = (string)($this->manifest['uninstaller'] ?? '');
        if ($uninstaller === '' || !$this->isSelf($uninstaller)) {
            return;
        }
        $folder = (string)($this->manifest['folder'] ?? '');
        if (PHP_OS_FAMILY === 'Windows') {
            // after this process quits: wait ~2 s, delete the .exe, then the folder if it is empty
            $cmd = 'ping 127.0.0.1 -n 3 > nul & del /f /q "' . $uninstaller . '"' . ($folder !== '' ? ' & rmdir "' . $folder . '"' : '');
            @pclose(@popen('start "" /B cmd /C "' . $cmd . '"', 'r'));
            return;
        }
        is_dir($uninstaller) ? Runner::remove($uninstaller) : @unlink($uninstaller);
        foreach ($this->dirs() as $dir) {
            @rmdir($dir);       // the folders it kept for the uninstaller, now empty
        }
    }

    /** The install's folders, the deepest first, the install folder among them. @return list<string> */
    private function dirs(): array
    {
        $dirs = array_map('strval', (array)($this->manifest['dirs'] ?? []));
        $folder = (string)($this->manifest['folder'] ?? '');
        if ($folder !== '' && !in_array($folder, $dirs, true)) {
            $dirs[] = $folder;
        }
        usort($dirs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        return array_values(array_unique($dirs));
    }

    /** The uninstaller entry is the program running now (the executable, or the .app holding it). */
    private function isSelf(string $path): bool
    {
        if ($this->self === null) {
            return false;
        }
        $self = str_replace('\\', '/', strtolower($this->self));
        $path = str_replace('\\', '/', strtolower($path));
        return $self === $path || str_starts_with($self, rtrim($path, '/') . '/');
    }
}
