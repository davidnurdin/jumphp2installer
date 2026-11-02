<?php
// Makes the installer of a package from a base installer executable: writes the package's address into it.
// Plain PHP (no Composer, no YAML): GitHub Actions and the IDE run it as is.
//
//   php tools/make-installer.php <base installer> <out> <source> [package]
//     source: github:owner/repo | https://host/folder/ (install.yaml there) | https://server (with a package id)
//
//   php tools/make-installer.php installer-windows-x86_64.exe "MyApp-setup.exe" github:me/myapp
//
//   php -d phar.readonly=0 tools/make-installer.php <base installer> <out> --standalone <folder>
//     a standalone installer: the folder (install.yaml and its files) inside it, installed with no network
require __DIR__ . '/../installer/src/Binary.php';
require __DIR__ . '/../installer/src/Zip.php';

[$self, $in, $out, $source] = $argv + [null, null, null, null];
$package = $argv[4] ?? 'static';
if ($in === null || $out === null || $source === null) {
    fwrite(STDERR, "usage: php tools/make-installer.php <base installer> <out> <source> [package]\n");
    exit(2);
}
if ($source === '--standalone') {
    try {
        JumPHP2Installer\Binary::embedFile($in, $out, (string)($argv[4] ?? ''));
    } catch (Throwable $e) {
        fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
        exit(1);
    }
    echo "$out: installs {$argv[4]}/install.yaml, with no network\n";
    exit(0);
}
try {
    JumPHP2Installer\Binary::patchFile($in, $out, $source, $package);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "$out: installs $source" . ($package !== 'static' ? " ($package)" : '') . "\n";
