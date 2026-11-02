<?php

namespace JumPHP2Installer;

/**
 * What happens to an installer, told to its statistics address: it was started, which pages were seen, where it
 * was cancelled, which step failed and why, when it finished, whether the app was launched, the uninstall. The
 * package's owner sees them in a dashboard (the server's, or jumphp.net's studio).
 *
 * Never in the way: a POST of JSON at a time, without blocking (Download, a little at each frame), whose failure
 * changes nothing; flush() at the end waits a moment for what is still on the way. Nothing personal is sent: the
 * package, its version, the system and its architecture, the installer's version, an anonymous id of this computer
 * for this package (machineId(): a hash, the same at each run on this computer, so two computers count two installs
 * and installing again counts once; kept by the uninstaller), and for a failure the error with the user's folders
 * replaced by {home}, {temp}, {folder}.
 *
 * Where to (address()), the first of:
 *   - the end user's no: --no-stats, or env JUMPHP2INSTALLER_STATS=off               nothing
 *   - the script's  stats: false                                                     nothing
 *   - the script's  stats: https://example.com/stats                                 there
 *   - --stats=URL / env JUMPHP2INSTALLER_STATS / the executable's jumphp2installer.stats (written by --make --stats)
 *   - a package of a server (remote mode): {server}/api/packages/{id}/stats
 *   - else nothing (a local script, a static package or a GitHub release without a stats: address)
 * A path instead of an address (tests, a script tried locally): one JSON line per event appended to that file.
 *
 * The body of each POST (docs/installer/stats.md):
 *   {"event": "start|page|cancel|fail|install|run|uninstall", "package", "app", "version", "os", "arch",
 *    "installer", "install", "mode": "window|silent", "page"?, "step"?, "error"?, "seconds"?}
 */
final class Stats
{
    public const EVENTS = ['start', 'page', 'cancel', 'fail', 'install', 'run', 'uninstall'];
    private const OFF = ['', '0', 'off', 'no', 'false', 'none'];

    /** The id of this install (32 hex): machineId() of the package, or a random one */
    public readonly string $install;
    /** @var list<array{Download, string}> POSTs on the way, and their answer file */
    private array $sending = [];
    /** @var list<string> what was sent (tests) */
    public array $sent = [];
    private float $since;

    /** @param array<string, string> $base the fields of every event (package, app, version, os, arch, installer, mode) */
    public function __construct(public readonly ?string $url, private array $base = [], ?string $install = null)
    {
        $this->install = $install !== null && preg_match('/^[0-9a-f]{32}$/', $install) ? $install : bin2hex(random_bytes(16));
        $this->since = microtime(true);
    }

    /** A Stats that sends nothing. */
    /**
     * An anonymous id of this computer for one package (32 hex), the same at every run: a hash of the system's own id
     * of the computer (Linux /etc/machine-id, Windows' MachineGuid, macOS' IOPlatformUUID) with the package (its
     * statistics address: the same for every installer of it), so the
     * system's id never leaves the computer and two packages can not be linked. Without one: a random id kept in the
     * user's data folder ($dataDir, for tests). Never the address (IP): computers at home share one.
     */
    public static function machineId(string $package, ?string $dataDir = null, ?string $system = null): string
    {
        $system ??= self::systemId();
        if ($system === null || $system === '') {
            $dir = $dataDir ?? self::dataDir();
            $file = $dir . DIRECTORY_SEPARATOR . 'id';
            $system = is_file($file) ? trim((string)@file_get_contents($file)) : '';
            if (!preg_match('/^[0-9a-f]{32}$/', $system)) {
                $system = bin2hex(random_bytes(16));
                @mkdir($dir, 0700, true);
                @file_put_contents($file, $system);
            }
        }
        return substr(hash('sha256', "jumphp2installer\0$package\0" . strtolower(trim($system))), 0, 32);
    }

    /** The system's id of this computer, or null. */
    public static function systemId(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // reg.exe without a console window (the installer is a GUI program): Command
            $out = Command::run(['reg', 'query', 'HKLM\\SOFTWARE\\Microsoft\\Cryptography', '/v', 'MachineGuid', '/reg:64'])->output;
            return preg_match('/MachineGuid\s+REG_SZ\s+([0-9A-Fa-f-]{36})/', $out, $m) ? $m[1] : null;
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            $out = Command::run(['ioreg', '-rd1', '-c', 'IOPlatformExpertDevice'])->output;
            return preg_match('/"IOPlatformUUID" = "([0-9A-Fa-f-]{36})"/', $out, $m) ? $m[1] : null;
        }
        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $file) {
            $id = is_readable($file) ? trim((string)@file_get_contents($file)) : '';
            if (preg_match('/^[0-9a-f]{32}$/', $id)) {
                return $id;
            }
        }
        return null;
    }

    private static function dataDir(): string
    {
        $base = getenv('LOCALAPPDATA') ?: getenv('XDG_DATA_HOME') ?: ((getenv('HOME') ?: sys_get_temp_dir()) . (PHP_OS_FAMILY === 'Darwin' ? '/Library/Application Support' : '/.local/share'));
        return rtrim((string)$base, '/\\') . DIRECTORY_SEPARATOR . 'jumphp2installer';
    }

    public static function off(): self
    {
        return new self(null);
    }

    /**
     * The address of a package's statistics (see the class), or null.
     *
     * @param string|false|null $script the script's stats: (Script::toArray()['stats'])
     * @param ?string $configured --stats= / env / the executable's jumphp2installer.stats ('off': the user's no)
     */
    public static function address(?Package $package, string|false|null $script, ?string $configured, bool $refused = false): ?string
    {
        if ($refused || ($configured !== null && in_array(strtolower(trim($configured)), self::OFF, true)) || $script === false) {
            return null;
        }
        if (is_string($script) && $script !== '') {
            return $script;
        }
        if ($configured !== null) {
            return trim($configured);
        }
        if ($package !== null && $package->server !== '' && preg_match('#^https?://#', $package->server) && !in_array($package->id, ['', 'static', 'local'], true)) {
            return rtrim($package->server, '/') . '/api/packages/' . rawurlencode($package->id) . '/stats';
        }
        return null;
    }

    /** --stats=URL / --no-stats on the command line, else env JUMPHP2INSTALLER_STATS, else the executable's ini line. */
    public static function configured(array $args): array
    {
        $refused = in_array('--no-stats', $args, true);
        foreach ($args as $i => $a) {
            if (str_starts_with($a, '--stats=')) {
                return [substr($a, 8), $refused];
            }
        }
        $env = getenv('JUMPHP2INSTALLER_STATS');
        if (is_string($env)) {
            return [$env, $refused];
        }
        $ini = get_cfg_var('jumphp2installer.stats');
        return [is_string($ini) && $ini !== '' ? $ini : null, $refused];
    }

    /** The fields of every event, from a package and this computer. */
    public static function base(Package $package, string $version, Vars $vars, string $installer, string $mode): array
    {
        $app = $package->script->toArray()['app'] ?? [];
        return [
            'package' => $package->id,
            'app' => (string)($app['id'] ?? ''),
            'version' => $version,
            'os' => $vars->get('os'),
            'arch' => $vars->get('arch'),
            'installer' => $installer,
            'mode' => $mode,
        ];
    }

    /** What uninstall.json keeps (Runner::$stats): the uninstaller tells the same address, with the same id. */
    public function forManifest(): ?array
    {
        return $this->url === null ? null : ['url' => $this->url, 'install' => $this->install, 'base' => $this->base];
    }

    /** The statistics of an install, from its uninstall.json (null: none). */
    public static function fromManifest(?array $manifest, string $mode, ?string $configured = null, bool $refused = false): self
    {
        $s = $manifest['stats'] ?? null;
        if ($refused || ($configured !== null && in_array(strtolower(trim($configured)), self::OFF, true)) || !is_array($s) || !is_string($s['url'] ?? null)) {
            return self::off();
        }
        $base = array_map('strval', array_filter((array)($s['base'] ?? []), 'is_scalar'));
        return new self($s['url'], ['mode' => $mode] + $base, is_string($s['install'] ?? null) ? $s['install'] : null);
    }

    public function isOn(): bool
    {
        return $this->url !== null;
    }

    /** @param array<string, mixed> $base more fields of every event (the version, once known) */
    public function with(array $base): void
    {
        $this->base = $base + $this->base;
    }

    /** One event (Stats::EVENTS), with its fields: page, step, error, seconds. */
    public function send(string $event, array $fields = []): void
    {
        if ($this->url === null || !in_array($event, self::EVENTS, true)) {
            return;
        }
        $body = ['event' => $event] + $this->base + ['install' => $this->install];
        foreach (['page', 'step', 'error'] as $k) {
            if (isset($fields[$k]) && $fields[$k] !== '') {
                $body[$k] = mb_substr((string)$fields[$k], 0, $k === 'error' ? 300 : 40);
            }
        }
        if (isset($fields['seconds'])) {
            $body['seconds'] = round((float)$fields['seconds'], 1);
        }
        $json = (string)json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->sent[] = $json;
        if (!preg_match('#^https?://#i', $this->url)) {
            @file_put_contents($this->url, $json . "\n", FILE_APPEND | LOCK_EX);
            return;
        }
        $answer = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jumphp2installer-stats-' . bin2hex(random_bytes(6));
        $this->sending[] = [new Download($this->url, $answer, 10.0, $json), $answer];
        $this->tick();
    }

    /** Seconds since this run started. */
    public function seconds(): float
    {
        return microtime(true) - $this->since;
    }

    /** Some sending (at each frame). True when nothing is left on the way. */
    public function tick(float $budgetMs = 2.0): bool
    {
        foreach ($this->sending as $i => [$d, $answer]) {
            if ($d->tick($budgetMs)) {
                @unlink($answer);
                unset($this->sending[$i]);
            }
        }
        return $this->sending === [];
    }

    /** Before quitting: what is on the way gets at most $seconds to arrive. */
    public function flush(float $seconds = 2.0): void
    {
        $end = microtime(true) + $seconds;
        while (!$this->tick(20.0) && microtime(true) < $end) {
            usleep(5000);
        }
        foreach ($this->sending as [, $answer]) {
            @unlink($answer);
        }
        $this->sending = [];
    }

    /** An error message without the user's own folders (their name is often in them). */
    public static function clean(string $error, Vars $vars): string
    {
        $places = [];
        foreach (['folder', 'temp', 'home'] as $k) {
            $v = $vars->get($k, '');
            if (strlen($v) > 3) {
                $places[$v] = '{' . $k . '}';
                $places[str_replace('\\', '/', $v)] = '{' . $k . '}';
            }
        }
        uksort($places, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $error = strtr($error, $places);
        // what is left of a user's folder: C:\Users\name\..., /home/name/..., /Users/name/...
        return (string)preg_replace(['#([A-Za-z]:[\\\\/]Users[\\\\/])[^\\\\/\s]+#i', '#(/(?:home|Users)/)[^/\s]+#'], ['$1…', '$1…'], $error);
    }
}
