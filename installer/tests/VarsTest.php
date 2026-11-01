<?php

namespace JumPHP2Installer\Tests;

use JumPHP2Installer\Binary;
use JumPHP2Installer\Vars;
use JumPHP2Installer\Version;
use PHPUnit\Framework\TestCase;

final class VarsTest extends TestCase
{
    public function testVariablesAreExpandedEvenInsideOtherOnes(): void
    {
        $vars = (new Vars(['os' => 'windows', 'programs' => 'C:\\Users\\a\\AppData\\Local\\Programs', 'exe' => '.exe']))
            ->withApp(['name' => 'Hello', 'id' => 'hello'])
            ->with(['folder' => '{programs}/{app}']);
        self::assertSame('C:\\Users\\a\\AppData\\Local\\Programs\\Hello\\hello.exe', $vars->path('{folder}/hello{exe}'));
        self::assertSame('{nothing} stays', $vars->expand('{nothing} stays'));
        self::assertSame('https://x/hello.zip', $vars->path('https://x/{id}.zip'));
    }

    public function testStepsFollowTheirOptionsAndSystems(): void
    {
        $vars = (new Vars(['os' => 'linux', 'desktop' => '/home/a/Desktop']))->with(['desktop' => true, 'menu' => false]);
        self::assertSame('/home/a/Desktop', $vars->expand('{desktop}'));
        self::assertTrue($vars->allows(['if' => 'desktop']));
        self::assertFalse($vars->allows(['if' => 'menu']));
        self::assertTrue($vars->allows(['if' => '!menu']));
        self::assertTrue($vars->allows(['os' => ['linux', 'macos']]));
        self::assertFalse($vars->allows(['os' => ['windows']]));
    }

    public function testThisComputerHasItsPlaces(): void
    {
        $vars = Vars::forThisComputer();
        foreach (['os', 'arch', 'home', 'temp', 'programs', 'desktop', 'platform'] as $name) {
            self::assertNotSame('', $vars->get($name), $name);
        }
    }

    public function testTheNewestVersionIsReadFromWhatTheAddressAnswers(): void
    {
        self::assertSame('1.4.2', Version::fromAnswer('{"tag_name": "v1.4.2", "name": "Release 1.4.2"}'));
        self::assertSame('2.0', Version::fromAnswer('{"version": "2.0"}'));
        self::assertSame('3.1', Version::fromAnswer('{"data": {"v": "3.1"}}', 'data.v'));
        self::assertSame('0.9.1', Version::fromAnswer("v0.9.1\n"));
    }

    public function testTheServerWritesItsAddressAndThePackageIntoTheExecutable(): void
    {
        $binary = 'MICRO' . random_bytes(64) . "\xfd\xf6\x69\xe6" . pack('N', 26) . "ffi.enable=1\nmemory=512M\n" . '<?php __HALT_COMPILER(); PHARDATA';
        $patched = Binary::patch($binary, 'https://jumphp.net', 'k2q9x7a1');
        self::assertSame(['ffi.enable' => '1', 'memory' => '512M', 'jumphp2installer.server' => 'https://jumphp.net', 'jumphp2installer.package' => 'k2q9x7a1'], Binary::settings($patched));
        self::assertStringEndsWith('PHARDATA', $patched);
        self::assertStringStartsWith(substr($binary, 0, 69), $patched);
        // patched again (another package): the old values are replaced, not added
        self::assertSame('other1', Binary::settings(Binary::patch($patched, 'https://x', 'other1'))['jumphp2installer.package']);
        self::assertSame('Hello-App-setup-k2q9x7a1.exe', Binary::fileName('Hello App', 'k2q9x7a1', 'windows-x86_64'));
        self::assertSame('k2q9x7a1', Binary::packageFromFileName('C:\\Downloads\\Hello-App-setup-k2q9x7a1 (1).exe'));
        self::assertSame('k2q9x7a1', Binary::packageFromFileName('/home/a/Hello-App-setup-k2q9x7a1'));
        self::assertNull(Binary::packageFromFileName('/home/a/setup.exe'));

        // a released installer reserves room: patched, it keeps its size exactly
        $ini = "ffi.enable=1\n" . Binary::RESERVE . str_repeat('.', 400) . "\n";
        $base = 'MICRO' . "\xfd\xf6\x69\xe6" . pack('N', strlen($ini)) . $ini . 'PHAR';
        $once = Binary::patch($base, 'https://jumphp.net/installers', 'k2q9x7a1');
        self::assertSame(strlen($base), strlen($once));
        self::assertSame('k2q9x7a1', Binary::settings($once)['jumphp2installer.package']);
        $twice = Binary::patch($once, 'github:me/app', 'static');
        self::assertSame(strlen($base), strlen($twice));
        self::assertSame('github:me/app', Binary::settings($twice)['jumphp2installer.server']);
        $this->expectExceptionMessage('too long');
        Binary::patch($base, 'https://' . str_repeat('x', 500), 'id');
    }
}
