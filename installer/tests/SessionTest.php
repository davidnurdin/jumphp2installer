<?php

namespace JumPHP2Installer\Tests;

use JumPHP2Installer\Runner;
use JumPHP2Installer\Session;
use JumPHP2Installer\Vars;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/j2i-ses-' . bin2hex(random_bytes(4));
        mkdir("$this->root/pkg/images", 0777, true);
        mkdir("$this->root/temp");
        file_put_contents("$this->root/pkg/images/banner.png", "\x89PNG fake");
        file_put_contents("$this->root/pkg/LICENSE.txt", "Licence \xe9t\xe9");    // Windows-1252
        file_put_contents("$this->root/pkg/latest.json", '{"tag_name": "v2.3.4"}');
        file_put_contents("$this->root/pkg/install.yaml", <<<'YAML'
            app: {name: Demo App, banner: images/banner.png, size: 12 MB}
            version: {latest: "{server}/latest.json"}
            pages:
              - welcome: "Welcome to {app} {version}"
              - license: LICENSE.txt
              - folder: "{programs}/{app} {version}"
              - options:
                  menu: {label: "{app} in the menu", default: false}
              - install
            steps:
              - write: {file: "{folder}/hello.txt", text: "{version}"}
              - write: {file: "{folder}/menu.txt", text: "yes"}
                if: menu
            YAML);
    }

    protected function tearDown(): void
    {
        Runner::remove($this->root);
    }

    public function testTheInstallerFetchesThePackageTheVersionThePicturesAndTheLicense(): void
    {
        $server = new TestServer(['PKG_DIR' => "$this->root/pkg"]);
        // {server} in the script: the version file next to the package's files
        file_put_contents("$this->root/pkg/install.yaml", str_replace('{server}', "$server->url/api/packages/demo/assets", (string)file_get_contents("$this->root/pkg/install.yaml")));
        $vars = new Vars(['os' => 'linux', 'temp' => "$this->root/temp", 'programs' => "$this->root/opt", 'home' => $this->root]);
        $session = Session::remote($server->url, 'demo', $vars);
        self::assertTrue($session->wait(), (string)$session->error);
        $view = $session->view();
        self::assertSame('2.3.4', $view['app']['version']);
        self::assertSame('Welcome to Demo App 2.3.4', $view['pages'][0]['text']);
        self::assertSame("Licence \u{e9}t\u{e9}", $view['pages'][1]['text']);
        self::assertSame("$this->root/opt/Demo App 2.3.4", $view['pages'][2]['default']);
        self::assertSame("$this->root/opt/Demo App 2.3.4", $view['folder']);
        self::assertSame('Demo App in the menu', $view['pages'][3]['items'][0]['label']);
        self::assertSame('data:image/png;base64,' . base64_encode("\x89PNG fake"), $view['images']['banner']);
        self::assertSame('12 MB', $view['app']['size']);

        $runner = $session->install("$this->root/opt/Demo", ['menu' => true]);
        self::assertTrue($runner->runAll(), (string)$runner->error);
        self::assertSame('2.3.4', file_get_contents("$this->root/opt/Demo/hello.txt"));
        self::assertFileExists("$this->root/opt/Demo/menu.txt");
        self::assertFileExists("$this->root/opt/Demo/uninstall.json");
    }

    public function testAPackageTheServerDoesNotHaveSaysSo(): void
    {
        $server = new TestServer(['PKG_DIR' => "$this->root/pkg"]);
        $session = Session::remote($server->url, 'gone42', new Vars(['temp' => "$this->root/temp"]));
        self::assertFalse($session->wait());
        self::assertStringContainsString('(gone42) is not on', (string)$session->error);
    }

    public function testAScriptWithProblemsIsNotInstalled(): void
    {
        file_put_contents("$this->root/pkg/bad.yaml", "app: {name: X}\nsteps:\n  - explode: now\n");
        $session = Session::local("$this->root/pkg/bad.yaml", new Vars(['temp' => "$this->root/temp"]));
        self::assertFalse($session->wait());
        self::assertStringContainsString('unknown step: explode', (string)$session->error);
    }
}
