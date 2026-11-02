<?php

namespace App;

use JumPHP2Installer\Binary;
use JumPHP2Installer\Session;
use JumPHP2Installer\Uninstaller;

/**
 * Where this run's package comes from, the first found of:
 *   --script=install.yaml, env JUMPHP2INSTALLER_SCRIPT       a local script (authors, tests), its files next to it
 *   --uninstall, or a manifest next to this executable        the uninstaller of an install (uninstall.json)
 *   payload/install.yaml in itself                            a standalone installer (Binary::embedFile()): no network
 *   --server= / env JUMPHP2INSTALLER_SERVER / the executable's php.ini section (jumphp2installer.server) / DEFAULT_SERVER
 *   --package= / env JUMPHP2INSTALLER_PACKAGE / its php.ini section (jumphp2installer.package) / its file name (-setup-<id>)
 *   --download= / env JUMPHP2INSTALLER_DOWNLOAD / its file name ("My-App-k3x9p2qa7m4z.exe", Binary::fileName()):
 *     the download it comes from, sent with the package's request (the server knows that download is being installed)
 *   server "github:owner/repo" or package "static": install.yaml and its files at that address / in that release
 *   demo/install.yaml                                         not exported, nothing asked (bin/jumphp app:run / app:shot)
 */
final class Source
{
    /** The server of an installer that says none (a binary renamed by hand, built without one). */
    public const DEFAULT_SERVER = 'https://jumphp.net/installers';

    /** @param 'local'|'remote'|'static'|'uninstall'|'none' $mode */
    private function __construct(
        public readonly string $mode,
        public readonly ?string $script = null,
        public readonly ?string $server = null,
        public readonly ?string $package = null,
        public readonly ?array $manifest = null,
        public readonly ?string $problem = null,
        public readonly ?string $download = null,
    ) {
    }

    /** @param list<string> $args the command line */
    public static function detect(array $args): self
    {
        $opt = static function (string $name) use ($args): ?string {
            foreach ($args as $i => $a) {
                if (str_starts_with($a, "--$name=")) {
                    return substr($a, strlen($name) + 3);
                }
                if ($a === "--$name" && isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '--')) {
                    return $args[$i + 1];
                }
            }
            return null;
        };
        $setting = static function (string $env, string $ini): ?string {
            $v = getenv($env);
            if (is_string($v) && $v !== '') {
                return $v;
            }
            $v = get_cfg_var($ini);
            return is_string($v) && $v !== '' ? $v : null;
        };

        $script = $opt('script') ?? (getenv('JUMPHP2INSTALLER_SCRIPT') ?: null);
        if ($script !== null) {
            return new self('local', script: self::userPath($script));
        }
        $exe = self::executable();
        if (in_array('--uninstall', $args, true) || ($exe !== null && (preg_match('/^uninstall/i', basename($exe)) || preg_match('#/Uninstall [^/]*\.app/Contents/MacOS/#', $exe)))) {
            return new self('uninstall', manifest: $exe !== null ? Uninstaller::find($exe) : null);
        }
        // a standalone installer (Binary::embedFile()): the package inside it, no network, no server
        $root = defined('JUMPHP_ROOT') ? (string)constant('JUMPHP_ROOT') : '';
        if ($root !== '' && $opt('server') === null && $opt('package') === null && is_file("$root/" . Binary::PAYLOAD . '/install.yaml')) {
            return new self('local', script: "$root/" . Binary::PAYLOAD . '/install.yaml');
        }
        $server = $opt('server') ?? $setting('JUMPHP2INSTALLER_SERVER', 'jumphp2installer.server') ?? self::DEFAULT_SERVER;
        $package = $opt('package') ?? $setting('JUMPHP2INSTALLER_PACKAGE', 'jumphp2installer.package')
            ?? ($exe !== null ? Binary::packageFromFileName($exe) : null);
        if ($package === null && str_starts_with($server, 'github:')) {
            $package = 'static';
        }
        // bin/jumphp app:run / app:shot (not an exported installer, nothing asked): the demo package of demo/
        $demo = (defined('JUMPHP_ROOT') ? (string)constant('JUMPHP_ROOT') : '') . '/demo/install.yaml';
        if ($package === null && $exe === null && getenv('JUMPHP2INSTALLER_SELF') === false && is_file($demo)) {
            return new self('local', script: $demo);
        }
        if ($package === null) {
            return new self('none', problem: 'This installer does not say what to install: download it again from its web page '
                . '(its file name ends with -setup-<id>), or start it with --package=<id> --server=<address>.');
        }
        if ($package === 'static' || str_starts_with($server, 'github:')) {
            return new self('static', server: $server, package: 'static');
        }
        if (!preg_match('#^https?://#', $server) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $package)) {
            return new self('none', problem: "Not a package: $package on $server.");
        }
        $download = $opt('download') ?? (getenv('JUMPHP2INSTALLER_DOWNLOAD') ?: null) ?? ($exe !== null ? self::downloadOf($exe) : null);
        return new self('remote', server: $server, package: $package,
            download: $download !== null && preg_match('/^' . Binary::DOWNLOAD . '$/', $download) ? $download : null);
    }

    /** The download in the executable's name, or (macOS) in its .app's: ".../My-App-k3x9p2qa7m4z.app/Contents/MacOS/x". */
    public static function downloadOf(string $exe): ?string
    {
        if (preg_match('#([^/\\\\]+)\.app[/\\\\]Contents[/\\\\]MacOS[/\\\\]#', $exe, $m) && ($d = Binary::downloadFromFileName($m[1])) !== null) {
            return $d;
        }
        return Binary::downloadFromFileName($exe);
    }

    public function session(): ?Session
    {
        return match ($this->mode) {
            'local' => Session::local((string)$this->script),
            'remote' => Session::remote((string)$this->server, (string)$this->package, null, $this->download),
            'static' => Session::static((string)$this->server),
            default => null,
        };
    }

    /**
     * A path the user typed: relative to the folder they were in (the exported program moves to its own folder
     * before PHP starts: PWD tells where they were).
     */
    public static function userPath(string $path): string
    {
        if ($path === '' || \JumPHP2Installer\Runner::isAbsolute($path) || preg_match('#^[a-z]+:#i', $path)) {
            return $path;
        }
        $pwd = defined('JUMPHP_CWD') ? (string)constant('JUMPHP_CWD') : getenv('PWD');
        return is_string($pwd) && $pwd !== '' && is_dir($pwd) ? rtrim($pwd, '/\\') . DIRECTORY_SEPARATOR . $path : $path;
    }

    /** This program's file: the exported executable (its stub says), or null (bin/jumphp app:run, tests). */
    public static function executable(): ?string
    {
        $exe = getenv('JUMPHP2INSTALLER_SELF') ?: (defined('JUMPHP_EXECUTABLE') ? (string)constant('JUMPHP_EXECUTABLE') : '');
        return $exe !== '' && is_file($exe) ? $exe : null;
    }
}
