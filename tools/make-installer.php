<?php
// Makes the installer of a package from a base installer executable: writes the package's address into it.
// Plain PHP (no Composer, no YAML): GitHub Actions and the IDE run it as is.
//
//   php tools/make-installer.php <base installer> <out> <source> [package]
//     source: github:owner/repo | https://host/folder/ (install.yaml there) | https://server (with a package id)
//
//   php tools/make-installer.php installer-windows-x86_64.exe "MyApp-setup.exe" github:me/myapp
require __DIR__ . '/../installer/src/Binary.php';

[$self, $in, $out, $source] = $argv + [null, null, null, null];
$package = $argv[4] ?? 'static';
if ($in === null || $out === null || $source === null) {
    fwrite(STDERR, "usage: php tools/make-installer.php <base installer> <out> <source> [package]\n");
    exit(2);
}
try {
    JumPHP2Installer\Binary::patchFile($in, $out, $source, $package);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "$out: installs $source" . ($package !== 'static' ? " ($package)" : '') . "\n";
