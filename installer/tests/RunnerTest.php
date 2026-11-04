<?php

namespace JumPHP2Installer\Tests;

use JumPHP2Installer\Package;
use JumPHP2Installer\Runner;
use JumPHP2Installer\Uninstaller;
use JumPHP2Installer\Vars;
use PHPUnit\Framework\TestCase;

final class RunnerTest extends TestCase
{
    private string $root;
    private string $pkg;
    private Vars $vars;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/j2i-run-' . bin2hex(random_bytes(4));
        $this->pkg = "$this->root/pkg";
        foreach (['pkg', 'home/Desktop', 'home/menu', 'temp'] as $d) {
            mkdir("$this->root/$d", 0777, true);
        }
        $zip = new \ZipArchive();
        $zip->open("$this->pkg/hello.zip", \ZipArchive::CREATE);
        $zip->addFromString('hello-1.0/hello', "#!/bin/sh\necho hello\n");
        $zip->setExternalAttributesName('hello-1.0/hello', \ZipArchive::OPSYS_UNIX, 0100755 << 16);
        $zip->addFromString('hello-1.0/data/plain.txt', 'not executable');
        $zip->setExternalAttributesName('hello-1.0/data/plain.txt', \ZipArchive::OPSYS_UNIX, 0100644 << 16);
        $zip->addFromString('hello-1.0/data/levels.txt', 'level 1');
        $zip->addEmptyDir('hello-1.0/empty');
        $zip->close();
        file_put_contents("$this->pkg/LICENSE.txt", 'Free.');
        file_put_contents("$this->pkg/self.bin", 'the installer itself');
        $this->vars = (new Vars([
            'os' => 'linux', 'arch' => 'x86_64', 'platform' => 'linux-x86_64', 'exe' => '',
            'home' => "$this->root/home", 'temp' => "$this->root/temp", 'programs' => "$this->root/home/opt",
            'desktop' => "$this->root/home/Desktop", 'menu' => "$this->root/home/menu",
        ]));
    }

    protected function tearDown(): void
    {
        Runner::remove($this->root);
    }

    private function package(string $yaml): Package
    {
        file_put_contents("$this->pkg/install.yaml", $yaml);
        $package = Package::fromFile("$this->pkg/install.yaml");
        self::assertTrue($package->script->isValid(), implode("\n", array_map('strval', $package->script->problems())));
        return $package;
    }

    private function vars(Package $package, array $more = []): Vars
    {
        return $this->vars->withApp($package->script->toArray()['app'])->with(['version' => '1.0', 'folder' => "$this->root/home/opt/Hello"] + $more);
    }

    private const SCRIPT = <<<'YAML'
        app: {name: Hello, publisher: Tests}
        pages:
          - options:
              desktop: A shortcut on the desktop
              readme: {label: Write a readme, default: false}
          - install
        steps:
          - download: hello.zip
          - extract: {strip: 1}
          - write: {file: "{folder}/version.txt", text: "{app} {version} on {os}"}
            label: Writing the version
          - write: {file: "{folder}/README.txt", text: "read me"}
            if: readme
          - copy: {from: LICENSE.txt, to: "{folder}/"}
          - shortcut: {target: "{folder}/hello", where: both}
            if: desktop
          - write: {file: "{folder}/windows.txt", text: "no"}
            os: windows
          - uninstaller
        YAML;

    public function testAPackageIsInstalledThenUninstalledLeavingNothing(): void
    {
        $package = $this->package(self::SCRIPT);
        $runner = new Runner($package, $this->vars($package, ['desktop' => true, 'readme' => false]), "$this->root/temp/work", "$this->pkg/self.bin");
        $until = microtime(true) + 60;     // a time limit, not a count of turns: a slow machine takes more of them
        $progress = [];
        while (!$runner->tick(2.0)) {
            $progress[] = $runner->progress();
            self::assertLessThan($until, microtime(true), 'the install never ends');
        }
        self::assertNull($runner->error, implode("\n", $runner->log));
        $folder = "$this->root/home/opt/Hello";
        self::assertSame("#!/bin/sh\necho hello\n", file_get_contents("$folder/hello"));
        self::assertTrue(is_executable("$folder/hello"));     // its mode in the zip
        self::assertFalse(is_executable("$folder/data/plain.txt"));
        self::assertSame('level 1', file_get_contents("$folder/data/levels.txt"));
        self::assertDirectoryExists("$folder/empty");
        self::assertSame('Hello 1.0 on linux', file_get_contents("$folder/version.txt"));
        self::assertFileDoesNotExist("$folder/README.txt");
        self::assertFileDoesNotExist("$folder/windows.txt");
        self::assertSame('Free.', file_get_contents("$folder/LICENSE.txt"));
        self::assertSame('the installer itself', file_get_contents("$folder/uninstall"));
        self::assertContains('Writing the version', $runner->log);
        self::assertSame($progress, array_values(array_filter($progress, static fn (float $p): bool => $p >= 0 && $p <= 1)));
        self::assertDirectoryDoesNotExist("$this->root/temp/work");

        $desktop = "$this->root/home/Desktop/Hello.desktop";
        self::assertStringContainsString("Exec=\"$folder/hello\"", (string)file_get_contents($desktop));
        self::assertCount(2, glob("$this->root/home/menu/jumphp2installer-hello-*.desktop") ?: []);   // the app + its uninstaller

        $manifest = Uninstaller::find("$folder/uninstall");
        self::assertNotNull($manifest);
        self::assertSame('Hello', $manifest['app']['name']);
        self::assertSame('1.0', $manifest['app']['version']);
        self::assertSame($folder, $manifest['folder']);

        file_put_contents("$this->root/home/Desktop/mine.txt", 'the user\'s');
        $uninstaller = new Uninstaller($manifest, "$folder/uninstall");
        self::assertTrue($uninstaller->runAll());
        self::assertDirectoryDoesNotExist($folder);
        self::assertDirectoryDoesNotExist("$this->root/home/opt");          // made by the install, empty: removed
        self::assertFileDoesNotExist($desktop);
        self::assertSame([], glob("$this->root/home/menu/*") ?: []);
        self::assertFileExists("$this->root/home/Desktop/mine.txt");       // not the install's
    }

    public function testACommandIsLinkedInLocalBinAndTheProfileGetsIt(): void
    {
        $package = $this->package("app: {name: Hello}\nsteps:\n  - download: hello.zip\n  - extract: {strip: 1}\n  - command: \"{folder}/hello\"\n  - uninstaller\n");
        $runner = new Runner($package, $this->vars($package), null, "$this->pkg/self.bin");
        self::assertTrue($runner->runAll(), implode("\n", $runner->log));
        $folder = "$this->root/home/opt/Hello";
        $link = "$this->root/home/.local/bin/hello";
        self::assertTrue(is_link($link));
        self::assertSame("$folder/hello", readlink($link));
        // the PATH of this test has no <home>/.local/bin: the profile gets it, once
        self::assertStringContainsString('export PATH="$HOME/.local/bin:$PATH"', (string)file_get_contents("$this->root/home/.profile"));

        (new Uninstaller(Uninstaller::find($folder) ?? [], "$folder/uninstall"))->runAll();
        self::assertFalse(is_link($link) || file_exists($link));
        self::assertDirectoryDoesNotExist($folder);
        self::assertFileExists("$this->root/home/.profile");      // the PATH line stays (other commands may use it)

        // a profile that already has it is left as it is
        file_put_contents("$this->root/home/.profile", "PATH=\"\$HOME/.local/bin:\$PATH\"\n");
        self::assertTrue((new Runner($package, $this->vars($package), null, "$this->pkg/self.bin"))->runAll());
        self::assertSame("PATH=\"\$HOME/.local/bin:\$PATH\"\n", file_get_contents("$this->root/home/.profile"));
    }

    public function testTheAppStartsWithTheComputerAndTheUninstallerTakesItOut(): void
    {
        $package = $this->package("app: {name: Hello World}\npages: [welcome, {options: {autostart: {label: Start with the computer, default: false}}}, install]\nsteps:\n  - download: hello.zip\n  - extract: {strip: 1}\n  - autostart: {target: \"{folder}/hello\", id: hello}\n    if: autostart\n  - uninstaller\n");
        $entry = "$this->root/home/.config/autostart/hello.desktop";
        // unticked (its default): no entry
        $folder = "$this->root/home/opt/Hello";
        self::assertTrue((new Runner($package, $this->vars($package, ['autostart' => false]), null, "$this->pkg/self.bin"))->runAll());
        self::assertFileDoesNotExist($entry);
        (new Uninstaller(Uninstaller::find($folder) ?? [], "$folder/uninstall"))->runAll();

        $runner = new Runner($package, $this->vars($package, ['autostart' => true]), null, "$this->pkg/self.bin");
        self::assertTrue($runner->runAll(), implode("\n", $runner->log));
        self::assertFileExists($entry);
        $text = (string)file_get_contents($entry);
        self::assertStringContainsString("Exec=$folder/hello --autostart", $text);
        self::assertStringContainsString('Name=Hello World', $text);
        (new Uninstaller(Uninstaller::find($folder) ?? [], "$folder/uninstall"))->runAll();
        self::assertFileDoesNotExist($entry);
    }

    public function testThePathScriptAddsAndTakesOutOnlyItsFolder(): void
    {
        $add = Runner::pathScript("C:\\Users\\o'brien\\Apps\\Todo", true);
        self::assertStringContainsString("'C:\\Users\\o''brien\\Apps\\Todo'", $add);
        self::assertStringContainsString("GetEnvironmentVariable('Path', 'User')", $add);
        self::assertStringContainsString("+ 'C:", $add);
        self::assertStringNotContainsString('+ ', Runner::pathScript('C:\\X', false));
    }

    public function testAFileTheUserAddedKeepsItsFolder(): void
    {
        $package = $this->package("app: {name: Hello}\nsteps:\n  - download: hello.zip\n  - extract: {strip: 1}\n  - uninstaller\n");
        $runner = new Runner($package, $this->vars($package), null, "$this->pkg/self.bin");
        self::assertTrue($runner->runAll(), implode("\n", $runner->log));
        $folder = "$this->root/home/opt/Hello";
        file_put_contents("$folder/data/save.dat", 'progress');
        (new Uninstaller(Uninstaller::find($folder) ?? [], "$folder/uninstall"))->runAll();
        self::assertFileExists("$folder/data/save.dat");
        self::assertFileDoesNotExist("$folder/hello");
        self::assertFileDoesNotExist("$folder/uninstall");
    }

    public function testAWrongChecksumStopsTheInstall(): void
    {
        $package = $this->package("app: {name: Hello}\nsteps:\n  - download: {url: hello.zip, sha256: " . str_repeat('0', 64) . "}\n  - extract\n");
        $runner = new Runner($package, $this->vars($package));
        self::assertFalse($runner->runAll());
        self::assertStringContainsString('SHA-256', (string)$runner->error);
        self::assertDirectoryDoesNotExist("$this->root/home/opt/Hello");
    }

    public function testDeleteRefusesPathsOutsideTheInstall(): void
    {
        $package = $this->package("app: {name: Hello}\nsteps:\n  - delete: \"{home}\"\n");
        $runner = new Runner($package, $this->vars($package));
        self::assertFalse($runner->runAll());
        self::assertStringContainsString('refused', (string)$runner->error);
        self::assertDirectoryExists("$this->root/home");

        $package = $this->package("app: {name: Hello}\nsteps:\n  - mkdir: \"{folder}/cache\"\n  - delete: \"{folder}/cache\"\n");
        self::assertTrue((new Runner($package, $this->vars($package)))->runAll());
        self::assertDirectoryDoesNotExist("$this->root/home/opt/Hello/cache");
    }

    public function testAnArchiveThatGoesUpIsRefused(): void
    {
        $tar = new \PharData("$this->pkg/evil.tar");
        $tar->addFromString('ok.txt', 'ok');
        file_put_contents("$this->pkg/evil.tar", str_replace('ok.txt', '../x.t', (string)file_get_contents("$this->pkg/evil.tar")));
        $package = $this->package("app: {name: Hello}\nsteps:\n  - download: evil.tar\n  - extract\n");
        $runner = new Runner($package, $this->vars($package));
        $runner->runAll();
        self::assertFileDoesNotExist("$this->root/home/opt/x.t");
    }

    public function testThePackageCanComeFromAServer(): void
    {
        $this->package(self::SCRIPT);
        $server = new TestServer(['PKG_DIR' => $this->pkg]);
        $d = new \JumPHP2Installer\Download("$server->url/api/packages/demo", "$this->root/temp/package.json");
        while (!$d->tick()) {
            usleep(1000);
        }
        $package = Package::fromJson((string)file_get_contents("$this->root/temp/package.json"), "$server->url/api/packages/demo", $server->url);
        self::assertSame("$server->url/api/packages/demo/assets/", $package->assets);
        self::assertSame("$server->url/api/packages/demo/assets/images/my%20banner.png", $package->asset('images/my banner.png'));
        $runner = new Runner($package, $this->vars($package, ['desktop' => false, 'readme' => true]));
        self::assertTrue($runner->runAll(), implode("\n", $runner->log));
        self::assertSame('read me', file_get_contents("$this->root/home/opt/Hello/README.txt"));
        self::assertSame('Free.', file_get_contents("$this->root/home/opt/Hello/LICENSE.txt"));
        self::assertFileDoesNotExist("$this->root/home/Desktop/Hello.desktop");
    }
}
