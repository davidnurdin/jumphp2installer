<?php

namespace App;

use JumPHP2Installer\Runner;
use JumPHP2Installer\Script;
use JumPHP2Installer\Session;
use JumPHP2Installer\Stats;
use JumPHP2Installer\Uninstaller;

/**
 * The installer without a window (Main::cli): what IT departments and scripts use.
 *
 *   setup --silent [--folder=DIR] [--option=desktop] [--no-option=menu]     installs with the defaults, prints the steps
 *   setup --check [--script=install.yaml]                                    reads the script, prints its problems
 *   uninstall --uninstall --silent                                           removes the app
 *   setup --help | --version
 *
 * Anything else: null, the window opens.
 */
final class Cli
{
    public const VERSION = '0.2.9';

    /** @var resource|null where it writes (tests: php://memory), STDOUT / STDERR when null */
    public static $output = null;

    public function __construct(private readonly Source $source)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): ?int
    {
        $has = static fn (string $flag): bool => in_array($flag, $args, true);
        if ($has('--help') || $has('-h') || $has('/?')) {
            $this->out(self::help());
            return 0;
        }
        if ($has('--version')) {
            $this->out('jumphp2installer ' . self::VERSION . ' (install script format ' . Script::FORMAT . ')');
            return 0;
        }
        if (($make = self::value($args, 'make')) !== null) {
            return $this->make($make, $args);
        }
        if ($has('--check')) {
            return $this->check();
        }
        if (!$has('--silent') && !$has('/S')) {
            return null;
        }
        return $this->source->mode === 'uninstall' ? $this->uninstall() : $this->install($args);
    }

    /** --make OUT --server SOURCE [--package ID] [--from BASE]: an installer of a package, from this one (or BASE). */
    private function make(string $out, array $args): int
    {
        $server = self::value($args, 'server');
        if ($server === null) {
            $this->err('--make needs --server (github:owner/repo, https://host/folder/ or a server with --package)');
            return 2;
        }
        $out = Source::userPath($out);
        $from = ($f = self::value($args, 'from')) !== null ? Source::userPath($f) : Source::executable();
        if ($from === null) {
            $this->err('--make needs --from <installer executable> here (this is not an exported installer)');
            return 2;
        }
        try {
            \JumPHP2Installer\Binary::patchFile($from, $out, $server, self::value($args, 'package') ?? 'static', self::value($args, 'stats'));
        } catch (\Throwable $e) {
            $this->err($e->getMessage());
            return 1;
        }
        $this->out("$out: installs $server");
        return 0;
    }

    /** --name=value or --name value */
    private static function value(array $args, string $name): ?string
    {
        foreach ($args as $i => $a) {
            if (str_starts_with($a, "--$name=")) {
                return substr($a, strlen($name) + 3);
            }
            if ($a === "--$name" && isset($args[$i + 1])) {
                return $args[$i + 1];
            }
        }
        return null;
    }

    private function check(): int
    {
        $session = $this->source->session();
        if ($session === null) {
            $this->err($this->source->problem ?? 'nothing to check');
            return 2;
        }
        if (!$session->wait()) {
            $this->err((string)$session->error);
            return 1;
        }
        $view = $session->view();
        $this->out(sprintf('%s %s: the script is right (%d pages, folder %s)', $view['app']['name'], $session->version, count($view['pages']), $view['folder']));
        return 0;
    }

    /** @param list<string> $args */
    private function install(array $args): int
    {
        $session = $this->source->session();
        if ($session === null) {
            $this->err($this->source->problem ?? 'nothing to install');
            return 2;
        }
        $this->out('Reading the package...');
        if (!$session->wait()) {
            $this->err((string)$session->error);
            return 1;
        }
        $folder = $session->defaultFolder();
        $options = [];
        foreach ($args as $i => $a) {
            if (str_starts_with($a, '--folder=')) {
                $folder = Source::userPath(substr($a, 9));
            } elseif ($a === '--folder' && isset($args[$i + 1])) {
                $folder = Source::userPath($args[$i + 1]);
            } elseif (str_starts_with($a, '--option=')) {
                $options[substr($a, 9)] = true;
            } elseif (str_starts_with($a, '--no-option=')) {
                $options[substr($a, 12)] = false;
            }
        }
        [$configured, $refused] = Stats::configured($args);
        $stats = self::sessionStats($session, $configured, $refused, 'silent');
        $stats->send('start');
        $this->out(sprintf('Installing %s %s into %s', $session->view()['app']['name'], $session->version, $folder));
        $runner = $session->install($folder, $options, Source::executable());
        $runner->stats = $stats->forManifest();
        $printed = 0;
        $ok = $runner->runAll(function () use ($runner, &$printed): void {
            for (; $printed < count($runner->log); $printed++) {
                $this->out($runner->log[$printed]);
            }
        }, fn () => $stats->tick(1.0));
        for (; $printed < count($runner->log); $printed++) {
            $this->out($runner->log[$printed]);
        }
        if (!$ok) {
            $stats->send('fail', ['step' => $runner->step(), 'error' => Stats::clean((string)$runner->error, $runner->vars())]);
            $stats->flush();
            return 1;
        }
        $stats->send('install', ['seconds' => $stats->seconds()]);
        $stats->flush();
        $this->out('Installed.');
        return 0;
    }

    private function uninstall(): int
    {
        $manifest = $this->source->manifest;
        if ($manifest === null) {
            $this->err('nothing to uninstall here (no ' . Runner::MANIFEST . ' next to this program)');
            return 2;
        }
        $u = new Uninstaller($manifest, Source::executable());
        $this->out('Removing ' . $u->name() . ' from ' . ($manifest['folder'] ?? ''));
        [$configured, $refused] = Stats::configured($_SERVER['argv'] ?? []);
        $stats = self::uninstallStats($manifest, $configured, $refused, 'silent');
        $u->runAll();
        foreach ($u->log as $line) {
            $this->out($line);
        }
        $stats->send('uninstall', ['seconds' => $stats->seconds()]);
        $stats->flush();
        $this->out('Uninstalled.');
        return 0;
    }

    /** The statistics of a run whose package is in (Stats::address()). */
    public static function sessionStats(?Session $session, ?string $configured, bool $refused, string $mode): Stats
    {
        $package = $session?->package;
        if ($package === null) {
            return Stats::off();
        }
        $url = Stats::address($package, $package->script->toArray()['stats'] ?? null, $configured, $refused);
        // one id per computer and statistics address (anonymous): two computers count two installs, installing again
        // counts once, whichever installer of the package (jumphp.net's, a GitHub release's) was run
        return new Stats($url, Stats::base($package, $session->version, $session->vars(), self::VERSION, $mode, $session->download), $url !== null ? Stats::machineId($url) : null);
    }

    /** The statistics of the install an uninstaller removes (its uninstall.json says where). */
    public static function uninstallStats(?array $manifest, ?string $configured, bool $refused, string $mode): Stats
    {
        return Stats::fromManifest($manifest, $mode, $configured, $refused);
    }

    public static function help(): string
    {
        return <<<'TXT'
            jumphp2installer: installs the package this installer was downloaded for.

              (no option)                  the installation wizard
              --silent                     install without a window, with the defaults
                --folder=DIR               ... into DIR
                --option=NAME              ... with the option NAME checked (--no-option=NAME: unchecked)
              --uninstall [--silent]       remove what this program's folder holds (the uninstaller)
              --check                      read the install script and say what is wrong
              --script=install.yaml        a local script instead of the server's package (its files next to it)
              --server=URL --package=ID    another server / package
              --server=github:owner/repo   the package of a GitHub release (install.yaml and its files)
              --make=OUT --server=SOURCE   write an installer of SOURCE (from this one, or --from=BASE)
                --stats=URL                ... that tells URL what happens (started, cancelled, failed, installed)
              --no-stats                   tell nothing (env JUMPHP2INSTALLER_STATS=off: the same)
              --version, --help
            TXT;
    }

    private function out(string $line): void
    {
        fwrite(self::$output ?? STDOUT, $line . "\n");
    }

    private function err(string $line): void
    {
        fwrite(self::$output ?? STDERR, 'error: ' . $line . "\n");
    }
}
