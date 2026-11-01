<?php

namespace App\Service;

use App\Entity\Package;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The files of the packages (images, license, small files the script copies): var/packages/<id>/<path>. A path is
 * folders and names of letters, digits, . _ - (no "..", nothing hidden).
 */
final class PackageFiles
{
    public const MAX_SIZE = 50 * 1024 * 1024;

    public function __construct(#[Autowire('%kernel.project_dir%/var/packages')] private readonly string $root)
    {
    }

    public function dir(Package $package): string
    {
        return $this->root . '/' . $package->getId();
    }

    /** @return list<array{path: string, size: int, image: bool}> */
    public function list(Package $package): array
    {
        $dir = $this->dir($package);
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $path = substr($file->getPathname(), strlen($dir) + 1);
            $out[] = ['path' => $path, 'size' => $file->getSize(), 'image' => (bool)preg_match('/\.(png|jpe?g|gif|webp|ico)$/i', $path)];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        return $out;
    }

    /** The file on the disk, or null (a path that is not one of the package's files). */
    public function file(Package $package, string $path): ?string
    {
        if (!self::isPath($path)) {
            return null;
        }
        $file = $this->dir($package) . '/' . $path;
        return is_file($file) ? $file : null;
    }

    public function add(Package $package, UploadedFile $upload, string $folder = ''): string
    {
        $name = (string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $upload->getClientOriginalName());
        $path = trim(trim($folder, '/') . '/' . ltrim($name, '.-'), '/');
        if (!self::isPath($path)) {
            throw new \InvalidArgumentException("not a file name: $path");
        }
        if ($upload->getSize() > self::MAX_SIZE) {
            throw new \InvalidArgumentException('at most ' . (self::MAX_SIZE >> 20) . ' MB per file: put big downloads on another host (a GitHub release)');
        }
        $target = $this->dir($package) . '/' . $path;
        @mkdir(dirname($target), 0775, true);
        $upload->move(dirname($target), basename($target));
        return $path;
    }

    public function remove(Package $package, string $path): bool
    {
        $file = $this->file($package, $path);
        if ($file === null) {
            return false;
        }
        unlink($file);
        // empty folders go too
        for ($dir = dirname($file); $dir !== $this->dir($package) && str_starts_with($dir, $this->dir($package)) && @rmdir($dir); $dir = dirname($dir));
        return true;
    }

    public function removeAll(Package $package): void
    {
        \JumPHP2Installer\Runner::remove($this->dir($package));
    }

    public static function isPath(string $path): bool
    {
        return $path !== '' && strlen($path) < 200 && (bool)preg_match('#^(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*[A-Za-z0-9_-][A-Za-z0-9._-]*$#', $path)
            && !str_contains($path, '..');
    }
}
