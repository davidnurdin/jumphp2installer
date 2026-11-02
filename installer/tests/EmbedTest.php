<?php

namespace JumPHP2Installer\Tests;

use JumPHP2Installer\Binary;
use PHPUnit\Framework\TestCase;

/** A standalone installer: the package put inside the installer's PHAR (Binary::embed()). */
final class EmbedTest extends TestCase
{
    public function testThePackageGoesInsideThePharWithABuildOfItsOwn(): void
    {
        if (filter_var(ini_get('phar.readonly'), FILTER_VALIDATE_BOOL)) {
            self::markTestSkipped('php -d phar.readonly=0');
        }
        $dir = sys_get_temp_dir() . '/j2i-embed-test-' . bin2hex(random_bytes(4));
        mkdir("$dir/pkg/images", 0777, true);
        file_put_contents("$dir/pkg/install.yaml", "app: {name: X}\nversion: 1.0\nsteps: [uninstaller]\n");
        file_put_contents("$dir/pkg/images/icon.png", 'icon');
        // an exported app: micro + ini section + a PHAR whose stub says its build (tools/standalone-stub.php)
        $phar = new \Phar("$dir/game.phar", 0, 'jumphp-game.phar');
        $phar->startBuffering();
        $phar->addFromString('game/app.php', '<?php echo 1;');
        $phar->setStub("<?php Phar::mapPhar('jumphp-game.phar'); const JUMPHP_GAME_BUILD = 'abcdef123456'; __HALT_COMPILER();");
        $phar->stopBuffering();
        unset($phar);
        $bytes = (string)file_get_contents("$dir/game.phar");
        \Phar::unlinkArchive("$dir/game.phar");      // its alias is free again (as for the real installer)
        $ini = "ffi.enable=1\n";
        $head = 'MICRO' . random_bytes(32) . "\xfd\xf6\x69\xe6" . pack('N', strlen($ini)) . $ini;
        file_put_contents("$dir/base", $head . $bytes);

        Binary::embedFile("$dir/base", "$dir/setup", "$dir/pkg");
        Binary::embedFile("$dir/base", "$dir/setup2", "$dir/pkg");     // twice in one process: no alias clash
        $out = (string)file_get_contents("$dir/setup");
        self::assertStringStartsWith($head, $out);
        self::assertSame($out, file_get_contents("$dir/setup2"));
        file_put_contents("$dir/check.phar", substr($out, strlen($head)));
        $read = new \Phar("$dir/check.phar");
        self::assertSame("app: {name: X}\nversion: 1.0\nsteps: [uninstaller]\n", file_get_contents($read['game/payload/install.yaml']->getPathname()));
        self::assertSame('icon', file_get_contents($read['game/payload/images/icon.png']->getPathname()));
        self::assertSame('<?php echo 1;', file_get_contents($read['game/app.php']->getPathname()));
        self::assertMatchesRegularExpression("/JUMPHP_GAME_BUILD = 'abcdef123456-[0-9a-f]{12}'/", $read->getStub());
        unset($read);
        \Phar::unlinkArchive("$dir/check.phar");

        $this->expectExceptionMessage('no install.yaml');
        Binary::embedFile("$dir/base", "$dir/setup3", "$dir/images");
    }
}
