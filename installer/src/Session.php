<?php

namespace JumPHP2Installer;

/**
 * One run of the installer, before the steps: fetch the package (from its server, or a local script), the newest
 * version, the pictures and the license; then view() gives the page what it shows and install() starts a Runner.
 * Never blocks: tick() at each frame until it returns true ($error, or ready).
 *
 *   $s = Session::remote('https://jumphp.net', 'k2q9x7a1');   // or Session::local('install.yaml')
 *   each frame: if ($s->tick()) { $s->error ?? $s->view(); }
 */
final class Session
{
    public ?string $error = null;
    public ?Package $package = null;
    public string $version = '';
    /** @var array<string, string> icon / banner as data: addresses */
    public array $images = [];
    public ?string $license = null;
    public string $status = 'Connecting';

    private Vars $vars;
    private string $work;
    /** @var list<array{string, Download, callable(Download): void}> */
    private array $fetches = [];
    private bool $ready = false;
    private bool $planned = false;

    private function __construct(private readonly ?string $server, private readonly ?string $id, private readonly ?string $file, ?Vars $vars)
    {
        $this->vars = $vars ?? Vars::forThisComputer();
        $this->work = $this->vars->get('temp', sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'jumphp2installer-s' . getmypid() . bin2hex(random_bytes(3));
    }

    public static function remote(string $server, string $id, ?Vars $vars = null): self
    {
        $s = new self(rtrim($server, '/'), $id, null, $vars);
        $s->fetch('package', Package::url($server, $id), function (Download $d) use ($s, $server, $id): void {
            $s->package = Package::fromJson((string)file_get_contents($d->file), Package::url($server, $id), $server);
        });
        return $s;
    }

    /** A package at a static address or in a GitHub release (Package::staticBase()): its install.yaml first. */
    public static function static(string $source, ?Vars $vars = null): self
    {
        $s = new self($source, 'static', null, $vars);
        try {
            [$base] = Package::staticBase($source);
        } catch (\Throwable $e) {
            $s->error = $e->getMessage();
            return $s;
        }
        $s->fetch('package', $base . 'install.yaml', function (Download $d) use ($s, $source): void {
            $s->package = Package::fromStatic((string)file_get_contents($d->file), $source);
        });
        return $s;
    }

    public static function local(string $file, ?Vars $vars = null): self
    {
        $s = new self(null, null, $file, $vars);
        try {
            $s->package = Package::fromFile($file);
        } catch (\Throwable $e) {
            $s->error = $e->getMessage();
        }
        return $s;
    }

    /** A session over a package already in hand (the server's preview, tests). */
    public static function of(Package $package, ?Vars $vars = null): self
    {
        $s = new self(null, null, null, $vars);
        $s->package = $package;
        return $s;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function vars(): Vars
    {
        return $this->vars;
    }

    public function tick(): bool
    {
        if ($this->ready || $this->error !== null) {
            return true;
        }
        try {
            foreach ($this->fetches as $i => [$what, $d, $then]) {
                if (!$d->tick(4.0)) {
                    continue;
                }
                unset($this->fetches[$i]);
                if ($d->error !== null && $what !== 'image') {
                    throw new \RuntimeException(match ($what) {
                        'package' => $d->status === 404 ? ($this->id === 'static' ? "no install.yaml at $this->server (is the release published, with install.yaml in it?)" : "this installer's package ($this->id) is not on $this->server (any more)")
                            : "cannot reach the installer's server: $d->error",
                        'version' => "cannot read the newest version: $d->error",
                        default => "cannot read the $what: $d->error",
                    });
                }
                $d->error === null && $then($d);
                @unlink($d->file);
            }
            if ($this->package !== null && !$this->planned) {
                $this->plan();
            }
            if ($this->planned && $this->fetches === []) {
                Runner::remove($this->work);
                $this->status = 'Ready';
                $this->ready = true;
            }
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            Runner::remove($this->work);
        }
        return $this->ready || $this->error !== null;
    }

    /** Runs to the end (the command line). */
    public function wait(): bool
    {
        while (!$this->tick()) {
            usleep(2000);
        }
        return $this->error === null;
    }

    /** What the page shows: the app, its pages (texts with the variables), the pictures, the default folder. */
    public function view(): array
    {
        $script = $this->package?->script->toArray() ?? [];
        $vars = $this->pageVars();
        $pages = [];
        foreach ($script['pages'] ?? [] as $page) {
            foreach (['title', 'text', 'label', 'default'] as $k) {
                if (isset($page[$k]) && is_string($page[$k])) {
                    $page[$k] = $vars->expand($page[$k]);
                }
            }
            if ($page['type'] === 'folder') {
                $page['default'] = $vars->path($page['default']);
            }
            if ($page['type'] === 'finish' && is_array($page['run'])) {
                $page['run'] = $page['run'][$vars->get('os')] ?? null;      // the program of this system
            }
            if ($page['type'] === 'license') {
                $page['text'] = $this->license ?? $page['text'] ?? '';
            }
            if ($page['type'] === 'options') {
                foreach ($page['items'] as &$item) {
                    $item['label'] = $vars->expand($item['label']);
                }
                unset($item);
            }
            $pages[] = $page;
        }
        $app = $script['app'] ?? [];
        return [
            'app' => ['name' => $app['name'] ?? '', 'publisher' => $app['publisher'] ?? '', 'website' => $app['website'] ?? '',
                'accent' => $app['accent'] ?? '#2f6fed', 'size' => $app['size'] ?? null, 'version' => $this->version],
            'pages' => $pages,
            'images' => $this->images,
            'folder' => $this->defaultFolder(),
            'os' => $vars->get('os'),
        ];
    }

    /** The folder the folder page proposes (or the one used without a folder page). */
    public function defaultFolder(): string
    {
        foreach ($this->package?->script->toArray()['pages'] ?? [] as $page) {
            if ($page['type'] === 'folder') {
                return $this->pageVars()->path($page['default']);
            }
        }
        return $this->pageVars()->path('{programs}/{app}');
    }

    /** @param array<string, bool> $options the options' check boxes (missing ones: their default) */
    public function install(string $folder, array $options = [], ?string $self = null, ?string $work = null): Runner
    {
        if ($this->package === null || !$this->package->script->isValid()) {
            throw new \RuntimeException('the install script has problems: ' . implode('; ', array_map('strval', $this->package?->script->problems() ?? [])));
        }
        $values = [];
        foreach ($this->package->script->toArray()['options'] ?? [] as $var => $option) {
            $values[$var] = (bool)($options[$var] ?? $option['default']);
        }
        return new Runner($this->package, $this->pageVars()->with(['folder' => $folder] + $values), $work, $self);
    }

    private function pageVars(): Vars
    {
        return $this->vars->withApp($this->package?->script->toArray()['app'] ?? [])->with(['version' => $this->version]);
    }

    /** The package is in: what else it needs (its problems stop here). */
    private function plan(): void
    {
        $this->planned = true;
        $script = $this->package->script;
        if (!$script->isValid()) {
            throw new \RuntimeException("the install script has problems:\n" . implode("\n", array_map('strval', $script->problems())));
        }
        $data = $script->toArray();
        $version = $data['version'];
        if (isset($version['fixed'])) {
            $this->version = $version['fixed'];
        } elseif (isset($version['url'])) {
            $this->status = 'Looking for the newest version';
            $this->fetch('version', $this->vars->withApp($data['app'])->expand($version['url']), function (Download $d) use ($version): void {
                $this->version = Version::fromAnswer((string)file_get_contents($d->file), $version['field']);
            });
        }
        foreach (['icon', 'banner'] as $image) {
            $file = $data['app'][$image] ?? null;
            if ($file !== null) {
                $this->fetch('image', $this->package->asset($file), function (Download $d) use ($image, $file): void {
                    $this->images[$image] = 'data:' . self::mime($file) . ';base64,' . base64_encode((string)file_get_contents($d->file));
                });
            }
        }
        foreach ($data['pages'] as $page) {
            if ($page['type'] === 'license' && $page['file'] !== null) {
                $this->fetch('license', $this->package->asset($page['file']), function (Download $d): void {
                    $text = (string)file_get_contents($d->file);
                    $this->license = mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
                });
            }
        }
    }

    private function fetch(string $what, string $url, callable $then): void
    {
        $this->fetches[] = [$what, new Download($url, $this->work . DIRECTORY_SEPARATOR . $what . count($this->fetches), 30.0), $then];
    }

    private static function mime(string $file): string
    {
        return match (strtolower(pathinfo((string)parse_url($file, PHP_URL_PATH), PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            default => 'image/png',
        };
    }
}
