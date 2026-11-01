<?php

namespace JumPHP2Installer;

/**
 * The installer's executable as a server hands it out: the same file for every package, with the server's address
 * and the package's id written into its php.ini section (StaticPHP's micro reads it at start: get_cfg_var()).
 *
 * An exported JumPHP app is micro + ini section + PHAR; the ini section is "\xfd\xf6\x69\xe6" + its length (32 bits,
 * big endian) + php.ini lines. patch() finds it (the installer's own lines start with ffi.enable=1) and writes
 * another one with jumphp2installer.server / jumphp2installer.package added. Nothing else changes: the PHAR's own
 * signature stays valid. The package id is also in the file name the server gives (Name-setup-<id>.exe): the
 * installer reads that when the section has none (a binary renamed by hand).
 */
final class Binary
{
    private const MAGIC = "\xfd\xf6\x69\xe6";

    /** $binary with the server and the package written in; throws when it has no ini section of an installer. */
    public static function patch(string $binary, string $server, string $package): string
    {
        [$offset, $length, $ini] = self::section($binary);
        $lines = array_values(array_filter(explode("\n", $ini), static fn (string $l): bool =>
            trim($l) !== '' && !str_starts_with(ltrim($l), 'jumphp2installer.')));
        $lines[] = 'jumphp2installer.server="' . addcslashes($server, '"\\') . '"';
        $lines[] = 'jumphp2installer.package="' . addcslashes($package, '"\\') . '"';
        $newIni = implode("\n", $lines) . "\n";
        return substr($binary, 0, $offset) . self::MAGIC . pack('N', strlen($newIni)) . $newIni . substr($binary, $offset + 8 + $length);
    }

    /**
     * patch() on a file: an installer executable (Linux, Windows), or the .zip of the macOS app (its executable,
     * Contents/MacOS/*, is patched inside; ZipArchive needed). Writes $out.
     */
    public static function patchFile(string $in, string $out, string $server, string $package): void
    {
        $data = @file_get_contents(self::readable($in));
        if ($data === false) {
            throw new \RuntimeException("cannot read $in");
        }
        if (!str_starts_with($data, "PK\x03\x04")) {
            if (@file_put_contents($out, self::patch($data, $server, $package)) === false) {
                throw new \RuntimeException("cannot write $out");
            }
            @chmod($out, 0755);
            return;
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('the zip extension is needed to patch the macOS app');
        }
        if ($in !== $out && !@copy(self::readable($in), $out)) {
            throw new \RuntimeException("cannot write $out");
        }
        $zip = new \ZipArchive();
        if ($zip->open($out) !== true) {
            throw new \RuntimeException("$in: not a zip");
        }
        $patched = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (!preg_match('#\.app/Contents/MacOS/[^/]+$#', $name)) {
                continue;
            }
            $bytes = (string)$zip->getFromIndex($i);
            try {
                $new = self::patch($bytes, $server, $package);
            } catch (\RuntimeException) {
                continue;       // another program of the app (not the installer)
            }
            $zip->getExternalAttributesIndex($i, $os, $attr);
            $zip->addFromString($name, $new, \ZipArchive::FL_OVERWRITE);
            $zip->setExternalAttributesName($name, $os, $attr);
            $patched++;
        }
        $zip->close();
        if ($patched === 0) {
            @unlink($out);
            throw new \RuntimeException("$in: no installer executable in this macOS app");
        }
    }

    /**
     * A path to read an executable by, even the one running now: StaticPHP's micro answers its own exact path with
     * its script (the PHAR) only; dir/./file is the same file to the system, not to micro.
     */
    public static function readable(string $path): string
    {
        $dir = dirname($path);
        return $dir . DIRECTORY_SEPARATOR . '.' . DIRECTORY_SEPARATOR . basename($path);
    }

    /** What the binary's ini section says (tests, the server's checks). @return array<string, string> */
    public static function settings(string $binary): array
    {
        [, , $ini] = self::section($binary);
        $out = [];
        foreach (explode("\n", $ini) as $line) {
            if (preg_match('/^\s*([\w.]+)\s*=\s*"?(.*?)"?\s*$/', $line, $m)) {
                $out[$m[1]] = stripcslashes($m[2]);
            }
        }
        return $out;
    }

    /** The file name a server gives: "My App-setup-k2q9x7a1.exe" (an id of the package). */
    public static function fileName(string $appName, string $package, string $platform): string
    {
        $name = trim((string)preg_replace('/[^\w.-]+/u', '-', $appName), '-') ?: 'Setup';
        return $name . '-setup-' . $package . match (true) {
            str_starts_with($platform, 'windows') => '.exe',
            str_starts_with($platform, 'macos') => '.zip',
            default => '',
        };
    }

    /** The package id in a file name made by fileName() (the user may add " (1)" and the like), or null. */
    public static function packageFromFileName(string $file): ?string
    {
        return preg_match('/-setup-([a-z0-9]{6,32})(?:[ ._(-]|$)/i', basename($file), $m) ? strtolower($m[1]) : null;
    }

    /** @return array{int, int, string} offset, length, text of the installer's ini section */
    private static function section(string $binary): array
    {
        $at = 0;
        while (($at = strpos($binary, self::MAGIC, $at)) !== false) {
            $length = unpack('N', substr($binary, $at + 4, 4))[1] ?? 0;
            $ini = substr($binary, $at + 8, $length);
            if ($length > 0 && $length < 65536 && str_starts_with($ini, 'ffi.enable=')) {
                return [$at, $length, $ini];
            }
            $at++;
        }
        throw new \RuntimeException('not an installer executable (no php.ini section starting with ffi.enable=)');
    }
}
