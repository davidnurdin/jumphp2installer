<?php

namespace Tests;

use App\Cli;
use App\Main;
use JumPHP\Testing\AppTestCase;
use JumPHP2Installer\Runner;

/** The installer played without a window: the test is the wizard's page (AppTestCase). */
final class MainTest extends AppTestCase
{
    private static string $root;

    protected function setUp(): void
    {
        self::$root = sys_get_temp_dir() . '/j2i-app-' . bin2hex(random_bytes(4));
        mkdir(self::$root . '/pkg/images', 0777, true);
        mkdir(self::$root . '/home');
        $zip = new \ZipArchive();
        $zip->open(self::$root . '/pkg/demo.zip', \ZipArchive::CREATE);
        $zip->addFromString('demo/demo', "#!/bin/sh\necho demo\n");
        $zip->addFromString('demo/README.txt', 'Demo');
        $zip->close();
        file_put_contents(self::$root . '/pkg/images/icon.png', "\x89PNG icon");
        file_put_contents(self::$root . '/pkg/LICENSE.txt', 'Use it well.');
        file_put_contents(self::$root . '/pkg/installer.bin', 'the installer');
        file_put_contents(self::$root . '/pkg/install.yaml', <<<'YAML'
            app: {name: Demo, publisher: Tests, icon: images/icon.png}
            version: 1.0.0
            pages:
              - welcome: "Welcome to {app} {version}"
              - license: LICENSE.txt
              - folder
              - options:
                  desktop: A shortcut on the desktop
              - install
              - finish: {run: "{folder}/demo"}
            steps:
              - download: demo.zip
              - extract: {strip: 1}
              - executable: "{folder}/demo"
              - shortcut: {target: "{folder}/demo", where: desktop}
                if: desktop
              - uninstaller
            YAML);
        putenv('HOME=' . self::$root . '/home');
        putenv('XDG_DATA_HOME=' . self::$root . '/home/.local/share');
        putenv('JUMPHP2INSTALLER_SCRIPT=' . self::$root . '/pkg/install.yaml');
        putenv('JUMPHP2INSTALLER_SELF=' . self::$root . '/pkg/installer.bin');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('JUMPHP2INSTALLER_SCRIPT');
        putenv('JUMPHP2INSTALLER_SELF');
        Runner::remove(self::$root);
    }

    private function untilDone(): array
    {
        for ($i = 0; $i < 600; $i++) {
            $this->frames(1);
            foreach (array_reverse($this->sent('webview.emit')) as $emit) {
                if ($emit['name'] === 'progress' && $emit['payload']['done']) {
                    return $emit['payload'];
                }
            }
        }
        self::fail('the work never finished');
    }

    public function testTheWizardShowsThePackageItsPicturesAndItsLicense(): void
    {
        $view = $this->invoke('load');
        self::assertSame('install', $view['mode']);
        self::assertSame('Demo', $view['app']['name']);
        self::assertSame('1.0.0', $view['app']['version']);
        self::assertSame(['welcome', 'license', 'folder', 'options', 'install', 'finish'], array_column($view['pages'], 'type'));
        self::assertSame('Welcome to Demo 1.0.0', $view['pages'][0]['text']);
        self::assertSame('Use it well.', $view['pages'][1]['text']);
        self::assertSame(self::$root . '/home/.local/opt/Demo', $view['folder']);
        self::assertStringStartsWith('data:image/png;base64,', $view['images']['icon']);
        self::assertSame('Demo 1.0.0 Setup', $this->lastSent('window.set_title')['title'] ?? null);
    }

    public function testTheFolderPageSaysWhatItThinksOfTheFolder(): void
    {
        self::assertFalse($this->invoke('check', ['folder' => 'relative/path'])['ok']);
        $fine = $this->invoke('check', ['folder' => self::$root . '/home/new/Demo']);
        self::assertTrue($fine['ok']);
        self::assertNull($fine['warning']);
        self::assertNotNull($fine['free']);
        self::assertSame('not-empty', $this->invoke('check', ['folder' => self::$root . '/pkg'])['warning']);
    }

    public function testInstallingShowsTheProgressThenTheAppIsThere(): void
    {
        $this->invoke('load');
        $folder = self::$root . '/home/Apps/Demo';
        self::assertTrue($this->invoke('install', ['folder' => $folder, 'options' => ['desktop' => true]]));
        $done = $this->untilDone();
        self::assertNull($done['error'], implode("\n", $done['log']));
        self::assertEquals(1, $done['progress']);
        self::assertSame("#!/bin/sh\necho demo\n", file_get_contents("$folder/demo"));
        self::assertTrue(is_executable("$folder/demo"));
        self::assertFileExists(self::$root . '/home/Desktop/Demo.desktop');
        self::assertSame('the installer', file_get_contents("$folder/uninstall"));
        self::assertSame('already-installed', $this->invoke('check', ['folder' => $folder])['warning']);

        $this->invoke('finish', ['run' => false]);
        self::assertNotNull($this->lastSent('app.exit'));
    }

    public function testTheUninstallerRemovesTheApp(): void
    {
        $this->invoke('load');
        $folder = self::$root . '/home/Apps/Demo';
        $this->invoke('install', ['folder' => $folder, 'options' => ['desktop' => true]]);
        $this->untilDone();
        // the same program, started again as the copy in the install folder
        putenv('JUMPHP2INSTALLER_SCRIPT');
        putenv("JUMPHP2INSTALLER_SELF=$folder/uninstall");
        parent::tearDown();
        parent::setUp();
        $view = $this->invoke('load');
        self::assertSame('uninstall', $view['mode']);
        self::assertSame('Demo', $view['app']['name']);
        self::assertTrue($this->invoke('uninstall'));
        $done = $this->untilDone();
        self::assertNull($done['error']);
        self::assertDirectoryDoesNotExist($folder);
        self::assertFileDoesNotExist(self::$root . '/home/Desktop/Demo.desktop');
    }

    public function testAnInstallerThatSaysNothingExplainsWhatToDo(): void
    {
        putenv('JUMPHP2INSTALLER_SCRIPT');
        putenv('JUMPHP2INSTALLER_SELF=' . self::$root . '/pkg/installer.bin');   // no -setup-<id> in its name
        parent::tearDown();
        parent::setUp();
        $view = $this->invoke('load');
        self::assertSame('error', $view['mode']);
        self::assertStringContainsString('does not say what to install', $view['error']);
    }

    public function testASilentInstallNeedsNoWindow(): void
    {
        Cli::$output = fopen('php://memory', 'w+');
        $folder = self::$root . '/home/Silent';
        self::assertSame(0, Main::cli(['--silent', "--folder=$folder", '--no-option=desktop'], (string)getcwd()));
        self::assertFileExists("$folder/README.txt");
        self::assertFileDoesNotExist(self::$root . '/home/Desktop/Demo.desktop');
        self::assertNull(Main::cli([], (string)getcwd()));           // no option: the window
        self::assertSame(0, Main::cli(['--check'], (string)getcwd()));
        rewind(Cli::$output);
        $out = (string)stream_get_contents(Cli::$output);
        self::assertStringContainsString("Installing Demo 1.0.0 into $folder", $out);
        self::assertStringContainsString('Installed.', $out);
        self::assertStringContainsString('Demo 1.0.0: the script is right', $out);

        putenv('JUMPHP2INSTALLER_SCRIPT');
        putenv("JUMPHP2INSTALLER_SELF=$folder/uninstall");
        self::assertSame(0, Main::cli(['--uninstall', '--silent'], (string)getcwd()));
        self::assertDirectoryDoesNotExist($folder);
        Cli::$output = null;
    }
}
