<?php

namespace JumPHP2Installer\Tests;

use JumPHP2Installer\Binary;
use PHPUnit\Framework\TestCase;

final class ZipTest extends TestCase
{
    public function testTheMacAppInsideAZipIsPatchedAndKeepsItsModes(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is needed to make the test zip');
        }
        $dir = sys_get_temp_dir() . '/j2i-zip-' . getmypid();
        @mkdir($dir);
        $ini = "ffi.enable=1\n" . Binary::RESERVE . str_repeat('.', 120) . "\n";
        $exe = "ELF...\xfd\xf6\x69\xe6" . pack('N', strlen($ini)) . $ini . '<?php phar';
        $zip = new \ZipArchive();
        $zip->open("$dir/in.zip", \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addEmptyDir('Setup.app/Contents/MacOS');
        $zip->addFromString('Setup.app/Contents/MacOS/jumphp2installer', '#!/bin/sh');
        $zip->setExternalAttributesName('Setup.app/Contents/MacOS/jumphp2installer', \ZipArchive::OPSYS_UNIX, 0100755 << 16);
        $zip->addFromString('Setup.app/Contents/MacOS/jumphp2installer-arm64', $exe);
        $zip->setExternalAttributesName('Setup.app/Contents/MacOS/jumphp2installer-arm64', \ZipArchive::OPSYS_UNIX, 0100755 << 16);
        $zip->addFromString('Setup.app/Contents/Info.plist', str_repeat('<plist/>', 50));
        $zip->close();

        Binary::patchFile("$dir/in.zip", "$dir/out.zip", 'https://x.io', 'p1');

        $out = new \ZipArchive();
        self::assertTrue($out->open("$dir/out.zip", \ZipArchive::CHECKCONS));
        $patched = (string)$out->getFromName('Setup.app/Contents/MacOS/jumphp2installer-arm64');
        self::assertSame(strlen($exe), strlen($patched));
        $settings = Binary::settings($patched);
        self::assertSame('https://x.io', $settings['jumphp2installer.server'] ?? null);
        self::assertSame('p1', $settings['jumphp2installer.package'] ?? null);
        self::assertSame('#!/bin/sh', $out->getFromName('Setup.app/Contents/MacOS/jumphp2installer'));
        self::assertSame(str_repeat('<plist/>', 50), $out->getFromName('Setup.app/Contents/Info.plist'));
        $out->getExternalAttributesName('Setup.app/Contents/MacOS/jumphp2installer-arm64', $os, $attr);
        self::assertSame(0100755, $attr >> 16);
        $out->close();
        array_map('unlink', glob("$dir/*") ?: []);
        rmdir($dir);
    }
}
