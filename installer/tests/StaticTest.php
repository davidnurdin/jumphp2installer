<?php

namespace JumPHP2Installer\Tests;

use JumPHP2Installer\Binary;
use JumPHP2Installer\Package;
use JumPHP2Installer\Runner;
use JumPHP2Installer\Script;
use JumPHP2Installer\Session;
use JumPHP2Installer\Vars;
use PHPUnit\Framework\TestCase;

/** Packages without a server: a static folder (any web host) or a GitHub release. */
final class StaticTest extends TestCase
{
    public function testAGithubReleaseIsReadByTheNamesOfItsFiles(): void
    {
        self::assertSame(['https://github.com/me/app/releases/latest/download/', true], Package::staticBase('github:me/app'));
        self::assertSame(['https://github.com/me/app/releases/download/v1.2/', true], Package::staticBase('github:me/app@v1.2'));
        self::assertSame(['https://cdn.example.com/app/', false], Package::staticBase('https://cdn.example.com/app/install.yaml'));
        $package = Package::fromStatic("app: {name: X}\nsteps:\n  - download: app-{platform}.zip\n  - extract\n", 'github:me/app');
        self::assertSame('https://github.com/me/app/releases/latest/download/banner.png', $package->asset('images/banner.png'));
    }

    public function testTheVersionCanBeTheNewestGithubRelease(): void
    {
        $script = Script::parse("app: {name: X}\nversion: {github: me/app}\nsteps:\n  - mkdir: \"{folder}\"\n");
        self::assertSame(['url' => 'https://api.github.com/repos/me/app/releases/latest', 'field' => 'tag_name'], $script->toArray()['version']);
    }

    public function testAStaticFolderOnAWebServerIsAPackage(): void
    {
        $root = sys_get_temp_dir() . '/j2i-static-' . bin2hex(random_bytes(4));
        mkdir("$root/pkg", 0777, true);
        mkdir("$root/temp");
        file_put_contents("$root/pkg/install.yaml", "app: {name: Static}\nversion: 3.0\nsteps:\n  - copy: {from: notes.txt, to: \"{folder}/\"}\n");
        file_put_contents("$root/pkg/notes.txt", 'from a static host');
        $server = new TestServer(['PKG_DIR' => "$root/pkg"]);
        $session = Session::static("$server->url/api/packages/demo/assets/", new Vars(['os' => 'linux', 'temp' => "$root/temp", 'programs' => "$root/opt"]));
        self::assertTrue($session->wait(), (string)$session->error);
        self::assertSame('3.0', $session->version);
        self::assertTrue($session->install("$root/opt/Static")->runAll());
        self::assertSame('from a static host', file_get_contents("$root/opt/Static/notes.txt"));
        $missing = Session::static("$server->url/nothing/", new Vars(['temp' => "$root/temp"]));
        self::assertFalse($missing->wait());
        self::assertStringContainsString('no install.yaml at', (string)$missing->error);
        Runner::remove($root);
    }

    public function testTheMacAppIsPatchedInsideItsZip(): void
    {
        $dir = sys_get_temp_dir() . '/j2i-zip-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $exe = 'MICRO' . "\xfd\xf6\x69\xe6" . pack('N', 13) . "ffi.enable=1\n" . 'PHAR';
        $zip = new \ZipArchive();
        $zip->open("$dir/in.zip", \ZipArchive::CREATE);
        $zip->addFromString('Setup.app/Contents/MacOS/setup', $exe);
        $zip->setExternalAttributesName('Setup.app/Contents/MacOS/setup', \ZipArchive::OPSYS_UNIX, 0100755 << 16);
        $zip->addFromString('Setup.app/Contents/Info.plist', '<plist/>');
        $zip->close();
        Binary::patchFile("$dir/in.zip", "$dir/out.zip", 'github:me/app', 'static');
        $zip->open("$dir/out.zip");
        self::assertSame('github:me/app', Binary::settings((string)$zip->getFromName('Setup.app/Contents/MacOS/setup'))['jumphp2installer.server']);
        $zip->getExternalAttributesName('Setup.app/Contents/MacOS/setup', $os, $attr);
        self::assertSame(0100755, $attr >> 16);
        $zip->close();
        Runner::remove($dir);
    }
}
