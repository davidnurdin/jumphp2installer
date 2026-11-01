<?php

namespace JumPHP2Installer\Tests;

use JumPHP2Installer\Script;
use PHPUnit\Framework\TestCase;

final class ScriptTest extends TestCase
{
    public const EXAMPLE = <<<'YAML'
        app:
          name: Hello App
          publisher: Example
          banner: images/banner.png
        version:
          latest: https://example.com/hello/latest.json
        pages:
          - welcome: "Welcome to {app} {version}"
          - license: LICENSE.txt
          - folder: "{programs}/Hello App"
          - options:
              desktop: {label: A shortcut on the desktop, default: true}
              menu: A shortcut in the menu
          - install
          - finish: {run: "{folder}/hello{exe}"}
        steps:
          - download: https://example.com/hello/{version}/hello-{platform}.zip
          - extract: {into: "{folder}", strip: 1}
          - shortcut: {target: "{folder}/hello{exe}", where: desktop}
            if: desktop
          - executable: "{folder}/hello"
            os: [linux, macos]
          - uninstaller
        YAML;

    public function testAGoodScriptIsReadAndNormalized(): void
    {
        $script = Script::parse(self::EXAMPLE);
        self::assertSame([], array_map('strval', $script->problems()));
        $data = $script->toArray();
        self::assertSame('hello-app', $data['app']['id']);
        self::assertSame(['url' => 'https://example.com/hello/latest.json', 'field' => ''], $data['version']);
        self::assertSame(['welcome', 'license', 'folder', 'options', 'install', 'finish'], array_column($data['pages'], 'type'));
        self::assertSame(['desktop', 'menu'], array_keys($data['options']));
        self::assertTrue($data['options']['menu']['default']);
        self::assertSame(['download', 'extract', 'shortcut', 'executable', 'uninstaller'], array_column($data['steps'], 'action'));
        self::assertSame(1, $data['steps'][1]['strip']);
        self::assertSame('desktop', $data['steps'][2]['if']);
        self::assertSame(['linux', 'macos'], $data['steps'][3]['os']);
    }

    public function testTheProblemsSayWhereTheyAre(): void
    {
        $script = Script::parse(<<<'YAML'
            app:
              name: X
            pages:
              - welcome
              - install
              - slideshow
            steps:
              - download: https://example.com/x.zip
              - unzip: here
              - shortcut: {target: "{folder}/x"}
                if: desktop
            YAML);
        self::assertFalse($script->isValid());
        $problems = array_map('strval', $script->problems());
        self::assertCount(3, $problems, implode("\n", $problems));
        self::assertStringContainsString("line 6: pages[2]: unknown page 'slideshow'", $problems[0]);
        self::assertStringContainsString('line 9: steps[1]: unknown step: unzip', $problems[1]);
        self::assertStringContainsString("line 11: steps[2].if: if: 'desktop' is not an option", $problems[2]);
    }

    public function testBrokenYamlGivesItsLine(): void
    {
        $script = Script::parse("app:\n  name: X\nsteps:\n  - download: [unclosed\n");
        self::assertFalse($script->isValid());
        self::assertSame(4, $script->problems()[0]->line);
    }

    public function testTheMinimalScriptGetsTheUsualPages(): void
    {
        $script = Script::parse("app: {name: Tiny}\nsteps:\n  - download: files/tiny.zip\n  - extract\n");
        self::assertTrue($script->isValid(), implode("\n", array_map('strval', $script->problems())));
        self::assertSame(['welcome', 'folder', 'install', 'finish'], array_column($script->toArray()['pages'], 'type'));
        self::assertSame('{download}', $script->toArray()['steps'][1]['archive']);
    }

    public function testAnExtractWithNothingDownloadedIsRefused(): void
    {
        $script = Script::parse("app: {name: Tiny}\nsteps:\n  - extract\n");
        self::assertStringContainsString('nothing downloaded before it', (string)$script->problems()[0]);
    }
}
