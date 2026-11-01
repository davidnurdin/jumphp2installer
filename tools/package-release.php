<?php
// Prepares a package for a GitHub release (or any static host): its install.yaml and its files side by side (a
// release has no folders: images/banner.png becomes banner.png), and the installers of the package, made from the
// base installers (jumphp2installer-linux-x86_64, -windows-x86_64.exe, -macos.zip) with its address written in.
// Plain PHP (the zip extension for the macOS app): GitHub Actions, the IDE and tools/github-release.sh run it.
//
//   php tools/package-release.php <package folder> <source> <base installers folder> <out folder>
//     source: github:owner/repo (the newest release) | https://host/folder/
require __DIR__ . '/../installer/src/Binary.php';
require __DIR__ . '/../installer/src/Zip.php';

use JumPHP2Installer\Binary;

[, $folder, $source, $bases, $out] = $argv + [null, null, null, null, null];
if ($out === null) {
    fwrite(STDERR, "usage: php tools/package-release.php <package folder> <source> <base installers folder> <out folder>\n");
    exit(2);
}
$fail = static function (string $message): never {
    fwrite(STDERR, "error: $message\n");
    exit(1);
};
$yaml = @file_get_contents("$folder/install.yaml") ?: $fail("no $folder/install.yaml");
// the app's name, for the installers' file names (app: {name: X} or app:\n  name: X)
preg_match('/^app:\s*\{[^}]*\bname:\s*["\']?([^,}"\']+)/m', $yaml, $m) || preg_match('/^app:\s*\n(?:\s+.*\n)*?\s+name:\s*["\']?([^"\'\n#]+)/m', $yaml, $m);
$name = trim((string)preg_replace('/[^\w.-]+/', '-', trim($m[1] ?? 'Setup')), '-') ?: 'Setup';

@mkdir($out, 0777, true);
$seen = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    /** @var SplFileInfo $file */
    $base = $file->getFilename();
    if (str_starts_with($base, '.')) {
        continue;
    }
    if (preg_match('/\s/', $base)) {
        $fail("$file: no spaces in file names (GitHub renames them)");
    }
    if (isset($seen[$base])) {
        $fail("two files named $base ($seen[$base], $file): a release has no folders, names must differ");
    }
    $seen[$base] = (string)$file;
    copy((string)$file, "$out/$base") || $fail("cannot write $out/$base");
}
$made = [];
foreach ([
    'jumphp2installer-linux-x86_64' => "$name-setup-linux-x86_64",
    'jumphp2installer-windows-x86_64.exe' => "$name-setup.exe",
    'jumphp2installer-macos.zip' => "$name-setup-macos.zip",
] as $from => $to) {
    if (!is_file("$bases/$from")) {
        fwrite(STDERR, "  (no $bases/$from: no installer for that system)\n");
        continue;
    }
    try {
        Binary::patchFile("$bases/$from", "$out/$to", $source, 'static');
    } catch (Throwable $e) {
        $fail("$from: " . $e->getMessage());
    }
    $made[] = $to;
}
echo implode("\n", [...array_keys($seen), ...$made]), "\n";
