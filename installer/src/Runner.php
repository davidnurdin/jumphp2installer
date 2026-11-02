<?php

namespace JumPHP2Installer;

/**
 * Runs the steps of an install script, a little at each frame (tick()): the window stays alive, the progress bar
 * moves. What it creates (files, folders, shortcuts, the uninstall entry) goes into a manifest written at the end,
 * {folder}/uninstall.json: the uninstaller removes exactly that (Uninstaller).
 *
 *   $runner = new Runner($package, $vars->with(['folder' => $dir, 'version' => '1.2.0']), self: JUMPHP_EXECUTABLE);
 *   each frame: if ($runner->tick()) { $runner->error ?? 'installed'; }   $runner->progress(), $runner->label
 */
final class Runner
{
    public const MANIFEST = 'uninstall.json';

    public ?string $error = null;
    public string $label = '';
    /** @var list<string> */
    public array $log = [];
    /** @var ?array{url: string, install: string, base: array<string, string>} kept in uninstall.json: the uninstaller tells it too (Stats) */
    public ?array $stats = null;

    /** @var list<array<string, mixed>> the steps this computer runs (if: / os: applied) */
    private array $steps = [];
    private int $index = 0;
    private float $fraction = 0.0;
    private bool $done = false;
    private string $work;

    /** @var array{files: list<string>, dirs: list<string>, shortcuts: list<string>, registry: ?string, uninstaller: ?string} */
    private array $manifest = ['files' => [], 'dirs' => [], 'shortcuts' => [], 'registry' => null, 'uninstaller' => null];

    // the step under way
    private ?Download $download = null;
    private ?\Iterator $entries = null;
    private int $entriesDone = 0;
    private int $entriesTotal = 0;
    private string $archiveBase = '';
    /** @var array<string, int> a zip's Unix modes by entry name (PharData does not read them) */
    private array $modes = [];
    /** @var list<Command> */
    private array $commands = [];
    private bool $started = false;

    public function __construct(
        private readonly Package $package,
        private Vars $vars,
        ?string $work = null,
        private readonly ?string $self = null,
    ) {
        $this->work = $work ?? $vars->get('temp', sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'jumphp2installer-' . getmypid() . '-' . bin2hex(random_bytes(3));
        foreach ($package->script->toArray()['steps'] ?? [] as $step) {
            if ($vars->allows($step)) {
                $this->steps[] = $step;
            }
        }
    }

    public function vars(): Vars
    {
        return $this->vars;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    /** 0..1 over all the steps. */
    public function progress(): float
    {
        $count = max(1, count($this->steps));
        return $this->done ? 1.0 : min(1.0, ($this->index + $this->fraction) / $count);
    }

    /** @return array<string, mixed> what was created (written to {folder}/uninstall.json at the end) */
    public function manifest(): array
    {
        $app = $this->package->script->toArray()['app'] ?? [];
        return [
            'format' => 1,
            'app' => ['name' => $app['name'] ?? '', 'id' => $app['id'] ?? '', 'publisher' => $app['publisher'] ?? '', 'version' => $this->vars->get('version')],
            'package' => $this->package->id,
            'server' => $this->package->server,
            'folder' => $this->vars->path('{folder}'),
            'installed' => date('c'),
        ] + ($this->stats !== null ? ['stats' => $this->stats] : []) + $this->manifest;
    }

    /** Some work (~$budgetMs ms). True when finished: then $error is null (installed) or says what failed. */
    public function tick(float $budgetMs = 12.0): bool
    {
        if ($this->done) {
            return true;
        }
        $end = microtime(true) + $budgetMs / 1000;
        try {
            while (!$this->done && microtime(true) < $end) {
                if ($this->index >= count($this->steps)) {
                    $this->complete();
                    break;
                }
                $step = $this->steps[$this->index];
                if (!$this->started) {
                    $this->label = self::label($step, $this->vars);
                    $this->log[] = $this->label;
                    $this->started = true;
                    $this->fraction = 0.0;
                }
                $finished = $this->runStep($step, $end);
                if (!$finished) {
                    break;      // waits for the network / a program: next frame
                }
                $this->index++;
                $this->started = false;
                $this->fraction = 0.0;
            }
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
        return $this->done;
    }

    /** Runs everything at once (the command line). */
    /** @param callable|null $always called at every turn (the statistics' sending: their POSTs time out when nobody moves them) */
    public function runAll(?callable $onStep = null, ?callable $always = null): bool
    {
        $last = '';
        while (!$this->tick(50.0)) {
            if ($onStep && $this->label !== $last) {
                $onStep($last = $this->label);
            }
            $always && $always();
            usleep(2000);
        }
        return $this->error === null;
    }

    /** The action of the step running (or that failed): download, extract... null before the first / after the last. */
    public function step(): ?string
    {
        return $this->steps[$this->index]['action'] ?? null;
    }

    public function cancel(): void
    {
        if (!$this->done) {
            $this->fail('cancelled');
        }
    }

    // --- the steps ---------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $step true when the step is over */
    private function runStep(array $step, float $end): bool
    {
        return match ($step['action']) {
            'download' => $this->stepDownload($step),
            'extract' => $this->stepExtract($step, $end),
            'copy' => $this->stepCopy($step),
            'mkdir' => $this->stepMkdir($step),
            'write' => $this->stepWrite($step),
            'executable' => $this->stepExecutable($step),
            'delete' => $this->stepDelete($step),
            'shortcut' => $this->stepShortcut($step),
            'run' => $this->stepRun($step),
            'uninstaller' => $this->stepUninstaller($step),
            default => throw new \RuntimeException("unknown step {$step['action']}"),
        };
    }

    private function stepDownload(array $step): bool
    {
        if ($this->download === null) {
            $url = $this->package->asset($this->vars->expand($step['url']));
            $name = $step['name'] !== null ? $this->vars->expand($step['name']) : basename((string)parse_url($url, PHP_URL_PATH));
            $name = trim((string)preg_replace('/[^\w.+-]+/', '_', urldecode($name)), '_') ?: 'download';
            $this->download = new Download($url, $this->work . DIRECTORY_SEPARATOR . 'dl' . $this->index . DIRECTORY_SEPARATOR . $name);
        }
        $d = $this->download;
        if (!$d->tick(8.0)) {
            $this->fraction = $d->progress() ?? 0.0;
            return false;
        }
        $this->download = null;
        if ($d->error !== null) {
            throw new \RuntimeException("download of $d->url: $d->error");
        }
        if ($step['sha256'] !== null && !hash_equals($step['sha256'], $d->sha256())) {
            @unlink($d->file);
            throw new \RuntimeException("download of $d->url: its SHA-256 is not the one the script expects (a damaged or changed file)");
        }
        $this->log[] = sprintf('  %s (%s)', basename($d->file), self::size($d->received));
        $this->vars = $this->vars->with(['download' => $d->file]);
        return true;
    }

    /** "phar:///private/var/x/a.zip/dir/f" -> "dir/f", the archive being known as phar:///var/x/a.zip */
    public static function insideArchive(string $path, string $base): string
    {
        if (str_starts_with($path, $base . '/')) {
            return substr($path, strlen($base) + 1);
        }
        $name = '/' . basename($base) . '/';
        $at = strpos($path, $name);
        return $at === false ? ltrim(substr($path, 7), '/') : substr($path, $at + strlen($name));
    }

    private function stepExtract(array $step, float $end): bool
    {
        $into = $this->vars->path($step['into']);
        if ($this->entries === null) {
            $archive = $this->archive($this->vars->path($step['archive']));
            $phar = new \PharData($archive);
            $this->archiveBase = 'phar://' . str_replace('\\', '/', $archive);
            $this->entriesTotal = max(1, $phar->count());
            $this->entriesDone = 0;
            $this->modes = str_ends_with($archive, '.zip') ? self::zipModes($archive) : [];
            $this->entries = new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST);
            $this->entries->rewind();
            $this->makeDir($into);
        }
        $strip = (int)$step['strip'];
        while ($this->entries->valid()) {
            /** @var \PharFileInfo $entry */
            $entry = $this->entries->current();
            // phar:// paths name the archive by its real path (/private/var on macOS, the long or short name on
            // Windows): what follows the archive's own name is the entry's path in it
            $path = str_replace('\\', '/', $entry->getPathname());
            $relative = self::insideArchive($path, $this->archiveBase);
            $parts = array_values(array_filter(explode('/', $relative), static fn (string $p): bool => $p !== '' && $p !== '.'));
            if (in_array('..', $parts, true)) {
                throw new \RuntimeException("the archive holds a path that goes up ($relative): refused");
            }
            $parts = array_slice($parts, $strip);
            if ($parts !== []) {
                $to = $into . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
                if ($entry->isDir()) {
                    $this->makeDir($to);
                } else {
                    $this->makeDir(dirname($to));
                    if (!@copy($entry->getPathname(), $to)) {
                        throw new \RuntimeException("cannot write $to");
                    }
                    $this->addFile($to);
                    $mode = $this->modes[$relative] ?? $entry->getPerms();
                    if (PHP_OS_FAMILY !== 'Windows' && ($mode & 0111 || preg_match('#\.app/Contents/MacOS/[^/]+$#', $relative))) {
                        @chmod($to, 0755);
                    }
                }
            }
            $this->entries->next();
            $this->entriesDone++;
            $this->fraction = min(0.99, $this->entriesDone / $this->entriesTotal);
            if (microtime(true) > $end) {
                return false;
            }
        }
        $this->log[] = sprintf('  %d files in %s', $this->entriesDone, $into);
        $this->entries = null;
        return true;
    }

    private function stepCopy(array $step): bool
    {
        $from = $this->vars->path($step['from']);
        $to = $this->vars->path($step['to']);
        $local = self::isAbsolute($from);
        if (str_ends_with($to, '/') || str_ends_with($to, '\\') || is_dir($to)) {
            $to = rtrim($to, '/\\') . DIRECTORY_SEPARATOR . basename($from);
        }
        if ($local) {
            if (is_dir($from)) {
                $this->copyTree($from, $to);
                return true;
            }
            $this->makeDir(dirname($to));
            if (!@copy($from, $to)) {
                throw new \RuntimeException("cannot copy $from to $to");
            }
            $this->addFile($to);
            return true;
        }
        // a file of the package
        if ($this->download === null) {
            $this->makeDir(dirname($to));
            $this->download = new Download($this->package->asset($this->vars->expand($step['from'])), $to);
        }
        if (!$this->download->tick(8.0)) {
            return false;
        }
        $d = $this->download;
        $this->download = null;
        if ($d->error !== null) {
            throw new \RuntimeException("copy of $step[from]: $d->error");
        }
        $this->addFile($to);
        return true;
    }

    private function stepMkdir(array $step): bool
    {
        $this->makeDir($this->vars->path($step['path']));
        return true;
    }

    private function stepWrite(array $step): bool
    {
        $file = $this->vars->path($step['file']);
        $this->makeDir(dirname($file));
        if (@file_put_contents($file, $this->vars->expand($step['text'])) === false) {
            throw new \RuntimeException("cannot write $file");
        }
        $this->addFile($file);
        return true;
    }

    private function stepExecutable(array $step): bool
    {
        $path = $this->vars->path($step['path']);
        if (!file_exists($path)) {
            throw new \RuntimeException("executable: no file $path");
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($path, 0755);
        }
        return true;
    }

    private function stepDelete(array $step): bool
    {
        $path = $this->vars->path($step['path']);
        $this->guard($path);
        self::remove($path);
        return true;
    }

    private function stepRun(array $step): bool
    {
        if ($this->commands === []) {
            $this->commands[] = new Command($this->vars->expand($step['command']), is_dir($this->vars->path('{folder}')) ? $this->vars->path('{folder}') : null);
            if (!$step['wait']) {
                $this->commands = [];
                return true;
            }
        }
        if (!$this->commands[0]->isDone()) {
            return false;
        }
        $c = array_shift($this->commands);
        if (!$c->ok()) {
            throw new \RuntimeException("run: $step[command] ended with $c->exitCode" . (trim($c->output) !== '' ? ': ' . trim(substr($c->output, -400)) : ''));
        }
        return true;
    }

    private function stepShortcut(array $step): bool
    {
        if ($this->fraction === 0.0) {
            $this->fraction = 0.01;
            $target = $this->vars->path($step['target']);
            $name = (string)preg_replace('#[\\\\/:*?"<>|]+#', '-', $this->vars->expand($step['name']));
            $icon = $step['icon'] !== null ? $this->vars->path($step['icon']) : null;
            $places = match ($step['where']) {
                'desktop' => ['desktop'],
                'menu' => ['menu'],
                default => ['desktop', 'menu'],
            };
            foreach ($places as $place) {
                $dir = $this->vars->get($place);
                if ($dir === '') {
                    continue;   // macOS: no menu (the app is in Applications)
                }
                $this->createShortcut($dir, $name, $target, $icon);
            }
        }
        foreach ($this->commands as $c) {
            if (!$c->isDone()) {
                return false;
            }
        }
        foreach ($this->commands as $c) {
            if (!$c->ok()) {
                $this->log[] = '  a shortcut could not be made: ' . trim(substr($c->output, -300));
            }
        }
        $this->commands = [];
        return true;
    }

    private function createShortcut(string $dir, string $name, string $target, ?string $icon): void
    {
        $this->makeDir($dir, false);
        switch (Vars::os()) {
            case 'windows':
                $lnk = $dir . '\\' . $name . '.lnk';
                $q = static fn (string $s): string => "'" . str_replace("'", "''", $s) . "'";
                $ps = '$s = (New-Object -ComObject WScript.Shell).CreateShortcut(' . $q($lnk) . '); $s.TargetPath = ' . $q($target)
                    . '; $s.WorkingDirectory = ' . $q(dirname($target)) . ($icon !== null ? '; $s.IconLocation = ' . $q($icon) : '') . '; $s.Save()';
                $this->commands[] = new Command(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $ps]);
                $this->manifest['shortcuts'][] = $lnk;
                break;
            case 'macos':
                $link = $dir . '/' . $name;
                @unlink($link);
                if (@symlink($target, $link)) {
                    $this->manifest['shortcuts'][] = $link;
                } else {
                    $this->log[] = "  could not make the shortcut $link";
                }
                break;
            default:
                $file = $dir . '/' . ($dir === $this->vars->get('desktop') ? $name : 'jumphp2installer-' . ($this->vars->get('id') ?: 'app') . '-' . substr(md5($name), 0, 6)) . '.desktop';
                $exec = '"' . str_replace(['\\', '"', '`', '$'], ['\\\\', '\\"', '\\`', '\\$'], $target) . '"';
                $text = "[Desktop Entry]\nType=Application\nName=$name\nExec=$exec\nPath=" . dirname($target) . "\n"
                    . ($icon !== null ? "Icon=$icon\n" : '') . "Terminal=false\nCategories=Utility;\n";
                if (@file_put_contents($file, $text) === false) {
                    throw new \RuntimeException("cannot write the shortcut $file");
                }
                @chmod($file, 0755);
                $this->manifest['shortcuts'][] = $file;
                if ($dir === $this->vars->get('desktop')) {
                    $this->commands[] = new Command(['sh', '-c', 'command -v gio >/dev/null && gio set "$0" metadata::trusted true; exit 0', $file]);
                }
        }
    }

    private function stepUninstaller(array $step): bool
    {
        if ($this->fraction === 0.0) {
            $this->fraction = 0.01;
            $folder = $this->vars->path('{folder}');
            $this->makeDir($folder);
            $self = $this->self;
            $exe = null;
            if ($self !== null && is_file($self)) {
                $bundle = preg_match('#^(.*\.app)/Contents/MacOS/[^/]+$#', $self, $m) ? $m[1] : null;
                if ($bundle !== null) {
                    $exe = $folder . '/Uninstall ' . $this->vars->get('app') . '.app';
                    self::remove($exe);
                    $this->copyTree(Binary::readable($bundle), $exe, false);
                } else {
                    $exe = $folder . DIRECTORY_SEPARATOR . 'uninstall' . $this->vars->get('exe');
                    if (!@copy(Binary::readable($self), $exe)) {
                        throw new \RuntimeException("cannot write $exe");
                    }
                    PHP_OS_FAMILY !== 'Windows' && @chmod($exe, 0755);
                }
            } else {
                $this->log[] = '  (no installer executable to copy: the uninstall entry is written without it)';
            }
            $this->manifest['uninstaller'] = $exe ?? '';
            $name = $this->vars->expand($step['name']);
            $command = $exe !== null ? '"' . $exe . '" --uninstall' : '';
            if (Vars::os() === 'windows') {
                $key = 'HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\jumphp2installer-' . $this->vars->get('id');
                $this->manifest['registry'] = $key;
                $values = [
                    'DisplayName' => ['REG_SZ', $this->vars->get('app')],
                    'DisplayVersion' => ['REG_SZ', $this->vars->get('version')],
                    'Publisher' => ['REG_SZ', $this->vars->get('publisher')],
                    'InstallLocation' => ['REG_SZ', $folder],
                    'UninstallString' => ['REG_SZ', $command],
                    'QuietUninstallString' => ['REG_SZ', $command . ' --silent'],
                    'DisplayIcon' => ['REG_SZ', (string)($exe ?? '')],
                    'URLInfoAbout' => ['REG_SZ', $this->vars->get('website')],
                    'NoModify' => ['REG_DWORD', '1'],
                    'NoRepair' => ['REG_DWORD', '1'],
                ];
                foreach ($values as $value => [$type, $data]) {
                    if ($data !== '') {
                        $this->commands[] = new Command(['reg.exe', 'add', $key, '/v', $value, '/t', $type, '/d', $data, '/f']);
                    }
                }
            } elseif (Vars::os() === 'linux' && $exe !== null && $this->vars->get('menu') !== '') {
                $file = $this->vars->get('menu') . '/jumphp2installer-' . ($this->vars->get('id') ?: 'app') . '-uninstall.desktop';
                $this->makeDir(dirname($file), false);
                @file_put_contents($file, "[Desktop Entry]\nType=Application\nName=$name\nExec=\"$exe\" --uninstall\nTerminal=false\nNoDisplay=false\nCategories=Utility;\n");
                $this->manifest['shortcuts'][] = $file;
            }
            if ($this->commands === []) {
                return true;
            }
            $this->fraction = 0.5;
        }
        foreach ($this->commands as $c) {
            if (!$c->isDone()) {
                return false;
            }
        }
        foreach ($this->commands as $c) {
            if (!$c->ok()) {
                $this->log[] = '  the uninstall entry: ' . trim(substr($c->output, -300));
            }
        }
        $this->commands = [];
        return true;
    }

    // --- helpers ----------------------------------------------------------------------------------------------

    private function complete(): void
    {
        $folder = $this->vars->path('{folder}');
        if ($folder !== '' && $folder !== '{folder}' && is_dir($folder)) {
            $file = $folder . DIRECTORY_SEPARATOR . self::MANIFEST;
            @file_put_contents($file, json_encode($this->manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        self::remove($this->work);
        $this->label = 'Done';
        $this->done = true;
    }

    private function fail(string $message): void
    {
        $this->error = $message;
        $this->log[] = 'Error: ' . $message;
        foreach ($this->commands as $c) {
            $c->isDone();
        }
        $this->commands = [];
        $this->download = null;
        $this->entries = null;
        self::remove($this->work);
        $this->done = true;
    }

    /** The archive with a name PharData knows (it goes by the extension). */
    private function archive(string $file): string
    {
        if (!is_file($file)) {
            throw new \RuntimeException("extract: no file $file");
        }
        $head = (string)file_get_contents($file, false, null, 0, 512);
        $ext = match (true) {
            str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06") => '.zip',
            str_starts_with($head, "\x1f\x8b") => '.tar.gz',
            substr($head, 257, 5) === 'ustar' => '.tar',
            default => throw new \RuntimeException('extract: ' . basename($file) . ' is not a zip, tar or tar.gz archive'),
        };
        if (str_ends_with(strtolower($file), $ext)) {
            return $file;
        }
        $copy = $this->work . DIRECTORY_SEPARATOR . 'archive' . $this->index . $ext;
        $this->makeDir($this->work, false);
        if (str_starts_with($file, $this->work) ? !@rename($file, $copy) : !@copy($file, $copy)) {
            throw new \RuntimeException("cannot read $file");
        }
        return $copy;
    }

    /**
     * The Unix modes of a zip's entries (its central directory: the upper 16 bits of the external attributes, when the
     * zip was made on Unix). Executables keep their x bit after extract. @return array<string, int>
     */
    public static function zipModes(string $zip): array
    {
        $h = @fopen($zip, 'rb');
        if (!$h) {
            return [];
        }
        $size = (int)filesize($zip);
        $tail = min($size, 65557);
        fseek($h, $size - $tail);
        $end = (string)fread($h, $tail);
        $at = strrpos($end, "PK\x05\x06");
        if ($at === false || strlen($end) < $at + 22) {
            fclose($h);
            return [];
        }
        $eocd = unpack('ventries/Vsize/Voffset', substr($end, $at + 10, 10));
        fseek($h, (int)$eocd['offset']);
        $dir = (string)fread($h, min((int)$eocd['size'], 64 * 1024 * 1024));
        fclose($h);
        $modes = [];
        for ($p = 0, $n = 0; $n < $eocd['entries'] && substr($dir, $p, 4) === "PK\x01\x02"; $n++) {
            $e = unpack('vmadeBy/x2/x2/x2/x4/x4/x4/x4/vnameLen/vextraLen/vcommentLen/x2/x2/Vattr', substr($dir, $p + 4, 42));
            $name = substr($dir, $p + 46, $e['nameLen']);
            if (($e['madeBy'] >> 8) === 3) {          // made on Unix
                $modes[rtrim($name, '/')] = ($e['attr'] >> 16) & 0xFFFF;
            }
            $p += 46 + $e['nameLen'] + $e['extraLen'] + $e['commentLen'];
        }
        return $modes;
    }

    private function copyTree(string $from, string $to, bool $record = true): void
    {
        $this->makeDir($to, $record);
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $dest = $to . substr($item->getPathname(), strlen($from));
            if ($item->isLink()) {
                @symlink((string)readlink($item->getPathname()), $dest);
            } elseif ($item->isDir()) {
                $this->makeDir($dest, $record);
            } else {
                if (!@copy($item->getPathname(), $dest)) {
                    throw new \RuntimeException("cannot copy to $dest");
                }
                @chmod($dest, $item->getPerms() & 0777);
                $record && $this->addFile($dest);
            }
        }
    }

    private function makeDir(string $dir, bool $record = true): void
    {
        if ($dir === '' || is_dir($dir)) {
            return;
        }
        $this->makeDir(dirname($dir), $record);
        if (!@mkdir($dir, 0755) && !is_dir($dir)) {
            throw new \RuntimeException("cannot make the folder $dir (allowed to write there?)");
        }
        if ($record) {
            $this->manifest['dirs'][] = $dir;
        }
    }

    private function addFile(string $file): void
    {
        $this->manifest['files'][] = $file;
    }

    /** delete: only inside the install folder, the home folder or the temporary one, never one of them itself. */
    private function guard(string $path): void
    {
        $norm = static fn (string $p): string => rtrim(str_replace('\\', '/', $p), '/');
        $path = $norm($path);
        $roots = array_filter(array_map($norm, [$this->vars->path('{folder}'), $this->vars->get('home'), $this->vars->get('temp')]),
            static fn (string $r): bool => $r !== '' && !str_contains($r, '{'));
        $protected = array_map($norm, array_filter([$this->vars->get('home'), $this->vars->get('programs'), $this->vars->get('desktop'), $this->vars->get('menu'), $this->vars->get('temp')]));
        foreach ($roots as $root) {
            if (str_starts_with($path . '/', $root . '/') && !in_array($path, $protected, true) && !str_contains($path, '/../')) {
                return;
            }
        }
        throw new \RuntimeException("delete: $path is outside the install folder and the home folder: refused");
    }

    /** What the install page says while $step runs (its label:, else a sentence of its action). */
    public static function label(array $step, Vars $vars): string
    {
        return $vars->expand((string)($step['label'] ?? self::defaultLabel($step)));
    }

    private static function defaultLabel(array $step): string
    {
        return match ($step['action']) {
            'download' => 'Downloading ' . basename((string)parse_url((string)$step['url'], PHP_URL_PATH)),
            'extract' => 'Extracting the files',
            'copy' => 'Copying ' . basename((string)$step['from']),
            'mkdir' => 'Making ' . basename((string)$step['path']),
            'write' => 'Writing ' . basename((string)$step['file']),
            'executable' => 'Preparing ' . basename((string)$step['path']),
            'delete' => 'Removing ' . basename((string)$step['path']),
            'shortcut' => 'Making the shortcuts',
            'run' => 'Running ' . basename(strtok((string)$step['command'], ' ') ?: ''),
            'uninstaller' => 'Writing the uninstaller',
            default => (string)$step['action'],
        };
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || (bool)preg_match('#^[A-Za-z]:[\\\\/]|^\\\\\\\\#', $path);
    }

    /** Removes a file or a whole folder (links are removed, not followed). */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::remove($path . DIRECTORY_SEPARATOR . $name);
            }
        }
        @rmdir($path);
    }

    public static function size(int|float $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => round($bytes / 1073741824, 1) . ' GB',
            $bytes >= 1048576 => round($bytes / 1048576, 1) . ' MB',
            $bytes >= 1024 => round($bytes / 1024) . ' KB',
            default => $bytes . ' B',
        };
    }
}
