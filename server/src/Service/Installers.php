<?php

namespace App\Service;

use App\Entity\Package;
use JumPHP2Installer\Binary;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The installers handed out: the base installers (var/installers/jumphp2installer-<platform>, fetched from a
 * jumphp2installer release by bin/console app:installers:fetch) with this server's address and the package's id
 * written in (Binary::patchFile), kept in var/cache/installers until the base changes.
 */
final class Installers
{
    /** platform => [base file, kind] */
    public const PLATFORMS = [
        'windows-x86_64' => ['jumphp2installer-windows-x86_64.exe', 'Windows (64-bit)'],
        'macos' => ['jumphp2installer-macos.zip', 'macOS (Apple silicon, Intel)'],
        'linux-x86_64' => ['jumphp2installer-linux-x86_64', 'Linux (x86_64)'],
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/installers')] private readonly string $bases,
        #[Autowire('%kernel.project_dir%/var/cache/installers')] private readonly string $cache,
        private readonly HttpClientInterface $http,
    ) {
    }

    /** @return array<string, string> the platforms whose base installer is here => their name */
    public function available(): array
    {
        $out = [];
        foreach (self::PLATFORMS as $platform => [$file, $label]) {
            if (is_file("$this->bases/$file")) {
                $out[$platform] = $label;
            }
        }
        return $out;
    }

    /** The installer of $package for $platform, made if needed: [file, name to give]. */
    public function make(Package $package, string $platform, string $server): array
    {
        [$base] = self::PLATFORMS[$platform] ?? throw new \InvalidArgumentException("unknown platform $platform");
        $baseFile = "$this->bases/$base";
        if (!is_file($baseFile)) {
            throw new \RuntimeException("no base installer for $platform (bin/console app:installers:fetch)");
        }
        $key = substr(hash('sha256', $package->getId() . "\0" . $server . "\0" . filemtime($baseFile) . filesize($baseFile)), 0, 20);
        $out = "$this->cache/$key-$base";
        if (!is_file($out)) {
            @mkdir($this->cache, 0775, true);
            $tmp = "$out.tmp" . getmypid();
            Binary::patchFile($baseFile, $tmp, $server, $package->getId());
            rename($tmp, $out);
        }
        return [$out, Binary::fileName($package->getName(), $package->getId(), $platform)];
    }

    /**
     * Downloads the base installers of a jumphp2installer release (default: the newest) into var/installers.
     * @return list<string> what was fetched
     */
    public function fetch(string $repo = 'davidnurdin/jumphp2installer', string $tag = 'latest'): array
    {
        $base = "https://github.com/$repo/releases/" . ($tag === 'latest' ? 'latest/download' : 'download/' . rawurlencode($tag));
        @mkdir($this->bases, 0775, true);
        $got = [];
        foreach (self::PLATFORMS as [$file]) {
            $response = $this->http->request('GET', "$base/$file", ['headers' => ['User-Agent' => 'jumphp2installer-server']]);
            if ($response->getStatusCode() !== 200) {
                continue;
            }
            $tmp = "$this->bases/$file.part";
            $h = fopen($tmp, 'wb');
            foreach ($this->http->stream($response) as $chunk) {
                fwrite($h, $chunk->getContent());
            }
            fclose($h);
            rename($tmp, "$this->bases/$file");
            chmod("$this->bases/$file", 0755);
            $got[] = $file;
        }
        return $got;
    }
}
