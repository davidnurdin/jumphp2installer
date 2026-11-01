<?php

namespace JumPHP2Installer;

/**
 * A package: an install script and its files (images, license, small files to copy), as a server serves it.
 *
 * The API every server answers (docs/installer/server.md), {server} being its address:
 *   GET {server}/api/packages/{id}                -> {"id", "name", "script" (the YAML), "assets" (address of its files)}
 *   GET {server}/api/packages/{id}/assets/{file}  -> a file of the package (assets: "images/banner.png")
 *   GET {server}/download/{id}/{platform}         -> the installer with the server and the id written in (Binary)
 *
 * Without a server (tests, an author trying a script): fromFile() reads a YAML file, its files next to it.
 */
final class Package
{
    public function __construct(
        public readonly string $id,
        public readonly Script $script,
        public readonly string $assets,
        public readonly string $server = '',
        public readonly bool $flat = false,
    ) {
    }

    /**
     * A package without a server: install.yaml and its files at a static address (any web host, GitHub Pages,
     * a bucket), or the assets of a GitHub release: "github:owner/repo" (the newest release), "github:owner/repo@v1.2".
     * A release has no folders: its files are found by their name alone (images/banner.png -> banner.png).
     */
    public static function staticBase(string $source): array
    {
        if (preg_match('#^github:([\w.-]+/[\w.-]+)(?:@(.+))?$#', $source, $m)) {
            $base = 'https://github.com/' . $m[1] . '/releases/' . (isset($m[2]) ? 'download/' . rawurlencode($m[2]) : 'latest/download') . '/';
            return [$base, true];
        }
        if (!preg_match('#^https?://#', $source)) {
            throw new \RuntimeException("not a package address: $source (https://..., or github:owner/repo)");
        }
        return [rtrim((string)preg_replace('#/install\.ya?ml$#', '', rtrim($source, '/')), '/') . '/', false];
    }

    /** The YAML of a static package, read from $base (staticBase()). */
    public static function fromStatic(string $yaml, string $source): self
    {
        [$base, $flat] = self::staticBase($source);
        return new self('static', Script::parse($yaml), $base, $source, $flat);
    }

    public static function url(string $server, string $id): string
    {
        return rtrim($server, '/') . '/api/packages/' . rawurlencode($id);
    }

    /** What GET /api/packages/{id} answered ($url: that address, to resolve a relative "assets"). */
    public static function fromJson(string $json, string $url, string $server = ''): self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_string($data['script'] ?? null)) {
            throw new \RuntimeException('the server did not answer an installer package (' . substr(trim($json), 0, 80) . ')');
        }
        $assets = (string)($data['assets'] ?? rtrim($url, '/') . '/assets/');
        if (!preg_match('#^https?://#', $assets)) {
            $p = parse_url($url);
            $assets = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '') . '/' . ltrim($assets, '/');
        }
        return new self((string)($data['id'] ?? ''), Script::parse($data['script']), rtrim($assets, '/') . '/', $server);
    }

    /** A script on the disk, its files in its folder (an author trying it, tests). */
    public static function fromFile(string $file): self
    {
        $yaml = @file_get_contents($file);
        if ($yaml === false) {
            throw new \RuntimeException("cannot read $file");
        }
        return new self('local', Script::parse($yaml), rtrim(dirname((string)realpath($file)), '/\\') . DIRECTORY_SEPARATOR);
    }

    /** The address (or path) of a file of the package; https:// addresses stay as they are. */
    public function asset(string $file): string
    {
        if (preg_match('#^https?://#i', $file)) {
            return $file;
        }
        $file = ltrim(str_replace('\\', '/', $file), '/');
        if ($this->flat) {
            $file = basename($file);
        }
        if (in_array('..', explode('/', $file), true)) {
            throw new \RuntimeException("a file of the package may not go up: $file");
        }
        return preg_match('#^https?://#', $this->assets) ? $this->assets . implode('/', array_map('rawurlencode', explode('/', $file))) : $this->assets . $file;
    }

    public function isRemote(): bool
    {
        return (bool)preg_match('#^https?://#', $this->assets);
    }
}
