<?php

namespace JumPHP2Installer;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * An install script (YAML, docs/installer/script.md): what the installer shows (pages) and does (steps).
 *
 *   app:
 *     name: My App
 *     publisher: Me
 *     icon: images/icon.png            # files of the package (its assets), or https:// addresses
 *     banner: images/banner.png
 *   version:
 *     latest: https://example.com/myapp/latest.json
 *   pages:
 *     - welcome: "Welcome to {app} {version}"
 *     - license: LICENSE.txt
 *     - folder: "{programs}/My App"
 *     - install
 *     - finish: {run: "{folder}/myapp{exe}"}
 *   steps:
 *     - download: https://example.com/myapp/{version}/myapp-{platform}.zip
 *     - extract: "{folder}"
 *     - shortcut: {target: "{folder}/myapp{exe}", where: both}
 *     - uninstaller
 *
 * parse() reads and checks it: the problems (with their line when YAML tells it) or the normalized script, a plain
 * array the installer, the server and the editor's preview all use (toArray()).
 */
final class Script
{
    public const FORMAT = 1;
    public const PAGES = ['welcome', 'license', 'folder', 'options', 'install', 'finish'];
    public const STEPS = ['download', 'extract', 'copy', 'mkdir', 'write', 'executable', 'shortcut', 'run', 'delete', 'uninstaller'];
    public const SYSTEMS = ['windows', 'linux', 'macos'];

    /** @var list<Problem> */
    private array $problems = [];

    /** @param array<string, mixed> $data the normalized script */
    private function __construct(private array $data, private readonly string $source)
    {
    }

    /** Reads and checks $yaml. Never throws: look at problems() / isValid(). */
    public static function parse(string $yaml): self
    {
        $script = new self([], $yaml);
        try {
            $raw = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            $script->problem('', preg_replace('/ at line \d+.*$/s', '', $e->getMessage()) ?: $e->getMessage(), $e->getParsedLine() > 0 ? $e->getParsedLine() : null);
            return $script;
        }
        if (!is_array($raw)) {
            $script->problem('', 'the script is empty: it starts with app: {name: ...}');
            return $script;
        }
        $script->data = $script->normalize($raw);
        return $script;
    }

    public function isValid(): bool
    {
        return $this->problems === [];
    }

    /** @return list<Problem> */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return array<string, mixed> the normalized script (empty when it could not be read) */
    public function toArray(): array
    {
        return $this->data;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function name(): string
    {
        return (string)($this->data['app']['name'] ?? '');
    }

    public function id(): string
    {
        return (string)($this->data['app']['id'] ?? '');
    }

    // --- checks ------------------------------------------------------------------------------------------------

    /** @param array<mixed> $raw */
    private function normalize(array $raw): array
    {
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, ['installer', 'app', 'version', 'pages', 'steps'], true)) {
                $this->problem((string)$key, "unknown section '$key' (installer, app, version, pages, steps)");
            }
        }
        $format = $raw['installer'] ?? self::FORMAT;
        if ($format !== self::FORMAT) {
            $this->problem('installer', 'this installer reads format ' . self::FORMAT . ' (installer: ' . self::FORMAT . ')');
        }
        $app = $this->app($raw['app'] ?? null);
        $version = $this->version($raw['version'] ?? null);
        $options = [];
        $pages = $this->pages($raw['pages'] ?? null, $options);
        $steps = $this->steps($raw['steps'] ?? null, array_keys($options));
        return ['installer' => self::FORMAT, 'app' => $app, 'version' => $version, 'pages' => $pages, 'options' => $options, 'steps' => $steps];
    }

    private function app(mixed $app): array
    {
        if (!is_array($app) || !is_string($app['name'] ?? null) || trim($app['name']) === '') {
            $this->problem('app', 'app: {name: ...} is needed (the name of the software)');
            $app = is_array($app) ? $app : [];
        }
        $known = ['name', 'id', 'publisher', 'website', 'icon', 'banner', 'accent', 'size'];
        foreach (array_keys($app) as $key) {
            if (!in_array($key, $known, true)) {
                $this->problem("app.$key", "unknown key '$key' (" . implode(', ', $known) . ')');
            }
        }
        $name = trim((string)($app['name'] ?? ''));
        $id = (string)($app['id'] ?? trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'));
        if ($id === '' || !preg_match('/^[a-z0-9][a-z0-9.-]*$/', $id)) {
            $this->problem('app.id', "app.id '$id': lower case letters, digits, dots and dashes (the folder and the uninstall entry use it)");
        }
        $accent = (string)($app['accent'] ?? '#2f6fed');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $this->problem('app.accent', "app.accent '$accent': a colour like #2f6fed");
        }
        return [
            'name' => $name,
            'id' => $id,
            'publisher' => (string)($app['publisher'] ?? ''),
            'website' => (string)($app['website'] ?? ''),
            'icon' => isset($app['icon']) ? (string)$app['icon'] : null,
            'banner' => isset($app['banner']) ? (string)$app['banner'] : null,
            'accent' => $accent,
            'size' => isset($app['size']) ? (string)$app['size'] : null,
        ];
    }

    /** version: "1.2.0" | {latest: url} | {latest: {url, field}} -> {fixed} | {url, field} | null */
    private function version(mixed $version): ?array
    {
        if ($version === null) {
            return null;
        }
        if (is_float($version) && preg_match('/^version:\s*["\']?([0-9][0-9.]*)/m', $this->source, $m)) {
            return ['fixed' => $m[1]];      // 3.0 stays 3.0 (YAML read a number)
        }
        if (is_string($version) || is_int($version) || is_float($version)) {
            return ['fixed' => (string)$version];
        }
        if (!is_array($version)) {
            $this->problem('version', 'version: "1.2.0", {latest: https://...} or {github: owner/repo}');
            return null;
        }
        if (isset($version['fixed'])) {
            return ['fixed' => (string)$version['fixed']];
        }
        if (isset($version['github'])) {
            if (!preg_match('#^[\w.-]+/[\w.-]+$#', (string)$version['github'])) {
                $this->problem('version.github', 'version.github: owner/repo (its newest release gives the version)');
                return null;
            }
            return ['url' => 'https://api.github.com/repos/' . $version['github'] . '/releases/latest', 'field' => 'tag_name'];
        }
        $latest = $version['latest'] ?? null;
        $url = is_array($latest) ? ($latest['url'] ?? null) : $latest;
        if (!is_string($url) || !preg_match('#^https?://#', $url)) {
            $this->problem('version.latest', 'version.latest: the address of the newest version (a JSON {"version": ...}, a GitHub release, or plain text)');
            return null;
        }
        return ['url' => $url, 'field' => (string)(is_array($latest) ? ($latest['field'] ?? '') : '')];
    }

    /** @param array<string, array> $options filled with the options pages' check boxes */
    private function pages(mixed $pages, array &$options): array
    {
        $pages ??= ['welcome', 'folder', 'install', 'finish'];
        if (!is_array($pages) || !array_is_list($pages)) {
            $this->problem('pages', 'pages: a list (- welcome, - folder, - install, - finish)');
            return [];
        }
        $out = [];
        foreach ($pages as $i => $page) {
            [$type, $value] = is_string($page) ? [$page, null] : (is_array($page) && count($page) === 1 ? [(string)array_key_first($page), reset($page)] : ['', null]);
            $path = "pages[$i]";
            if (!in_array($type, self::PAGES, true)) {
                $this->problem($path, ($type === '' ? 'a page is one word or one key' : "unknown page '$type'") . ' (' . implode(', ', self::PAGES) . ')');
                continue;
            }
            $page = ['type' => $type];
            switch ($type) {
                case 'welcome':
                    $page += is_array($value) ? ['title' => (string)($value['title'] ?? ''), 'text' => (string)($value['text'] ?? '')]
                        : ['title' => '', 'text' => (string)($value ?? 'Welcome to the installation of {app} {version}.')];
                    break;
                case 'license':
                    $page += is_array($value) ? ['file' => isset($value['file']) ? (string)$value['file'] : null, 'text' => isset($value['text']) ? (string)$value['text'] : null]
                        : ['file' => $value !== null ? (string)$value : null, 'text' => null];
                    if ($page['file'] === null && $page['text'] === null) {
                        $this->problem($path, 'license: a file of the package (LICENSE.txt) or {text: ...}');
                    }
                    break;
                case 'folder':
                    $page += ['default' => (string)(is_array($value) ? ($value['default'] ?? '{programs}/{app}') : ($value ?? '{programs}/{app}'))];
                    break;
                case 'options':
                    $page['items'] = [];
                    foreach (is_array($value) ? $value : [] as $var => $option) {
                        if (!preg_match('/^[a-z][a-z0-9_]*$/', (string)$var)) {
                            $this->problem("$path.$var", "option '$var': a name of lower case letters, digits and _ (used in steps' if:)");
                            continue;
                        }
                        $option = is_string($option) ? ['label' => $option] : (array)$option;
                        $item = ['var' => (string)$var, 'label' => (string)($option['label'] ?? $var), 'default' => (bool)($option['default'] ?? true)];
                        $page['items'][] = $item;
                        $options[(string)$var] = $item;
                    }
                    if ($page['items'] === []) {
                        $this->problem($path, 'options: {desktop: {label: "A shortcut on the desktop", default: true}, ...}');
                    }
                    break;
                case 'finish':
                    $value = is_string($value) ? ['text' => $value] : (array)($value ?? []);
                    $run = $value['run'] ?? null;
                    if (is_array($run)) {
                        foreach (array_keys($run) as $os) {
                            if (!in_array($os, self::SYSTEMS, true)) {
                                $this->problem("$path.run", "run: one program, or one per system ({windows: ..., linux: ..., macos: ...}), not '$os'");
                            }
                        }
                        $run = array_map('strval', $run);
                    }
                    $page += ['text' => (string)($value['text'] ?? '{app} is installed.'), 'run' => is_array($run) ? $run : ($run !== null ? (string)$run : null),
                        'label' => (string)($value['label'] ?? 'Start {app}')];
                    break;
            }
            $out[] = $page;
        }
        $types = array_column($out, 'type');
        if (!in_array('install', $types, true)) {
            $this->problem('pages', 'pages: the install page is needed (where the steps run)');
        }
        foreach (array_count_values($types) as $type => $count) {
            if ($count > 1 && $type !== 'options') {
                $this->problem('pages', "pages: only one $type page");
            }
        }
        return $out;
    }

    /** @param list<string> $options the variables of the options pages */
    private function steps(mixed $steps, array $options): array
    {
        if (!is_array($steps) || !array_is_list($steps) || $steps === []) {
            $this->problem('steps', 'steps: the list of what to do (- download: ..., - extract: ...)');
            return [];
        }
        $out = [];
        $downloaded = false;
        foreach ($steps as $i => $step) {
            $path = "steps[$i]";
            if (is_string($step)) {
                $step = [$step => true];
            }
            if (!is_array($step)) {
                $this->problem($path, 'a step is one word (uninstaller) or a map (download: ...)');
                continue;
            }
            $actions = array_values(array_intersect(array_keys($step), self::STEPS));
            if (count($actions) !== 1) {
                $this->problem($path, count($actions) === 0 ? 'unknown step: ' . implode(', ', array_keys($step)) . ' (' . implode(', ', self::STEPS) . ')'
                    : 'one action per step: ' . implode(' and ', $actions));
                continue;
            }
            $action = $actions[0];
            foreach (array_keys($step) as $key) {
                if ($key !== $action && !in_array($key, ['if', 'os', 'label'], true)) {
                    $this->problem("$path.$key", "unknown key '$key' next to $action (if, os, label)");
                }
            }
            $value = $step[$action];
            $s = ['action' => $action];
            $map = static fn (string $main) => is_array($value) ? $value : [$main => $value];
            switch ($action) {
                case 'download':
                    $v = $map('url');
                    if (!is_string($v['url'] ?? null) || trim($v['url']) === '' || preg_match('#^(?!https?://)[a-z][a-z0-9+.-]*://#i', $v['url'])) {
                        $this->problem("$path.download", 'download: an https:// address, or a file of the package (variables allowed: {version}, {platform}...)');
                    }
                    if (isset($v['sha256']) && !preg_match('/^[0-9a-fA-F]{64}$/', (string)$v['sha256'])) {
                        $this->problem("$path.download.sha256", 'sha256: 64 hexadecimal characters');
                    }
                    $s += ['url' => (string)($v['url'] ?? ''), 'sha256' => isset($v['sha256']) ? strtolower((string)$v['sha256']) : null,
                        'name' => isset($v['name']) ? (string)$v['name'] : null];
                    $downloaded = true;
                    break;
                case 'extract':
                    $v = $map('into');
                    if (!isset($v['archive']) && !$downloaded) {
                        $this->problem("$path.extract", 'extract: nothing downloaded before it (a download step, or archive: <file>)');
                    }
                    $s += ['into' => (string)($v['into'] ?? '{folder}'), 'archive' => (string)($v['archive'] ?? '{download}'), 'strip' => max(0, (int)($v['strip'] ?? 0))];
                    break;
                case 'copy':
                    $v = $map('from');
                    if (!isset($v['from'], $v['to'])) {
                        $this->problem("$path.copy", 'copy: {from: <file>, to: <file or folder>}');
                    }
                    $s += ['from' => (string)($v['from'] ?? ''), 'to' => (string)($v['to'] ?? '')];
                    break;
                case 'mkdir':
                case 'executable':
                case 'delete':
                    $v = $map('path');
                    if (!is_string($v['path'] ?? null) || $v['path'] === '') {
                        $this->problem("$path.$action", "$action: a path (\"{folder}/...\")");
                    }
                    $s += ['path' => (string)($v['path'] ?? '')];
                    break;
                case 'write':
                    if (!is_array($value) || !isset($value['file'])) {
                        $this->problem("$path.write", 'write: {file: <path>, text: ...}');
                    }
                    $s += ['file' => (string)($value['file'] ?? ''), 'text' => (string)($value['text'] ?? '')];
                    break;
                case 'shortcut':
                    $v = $map('target');
                    $where = (string)($v['where'] ?? 'both');
                    if (!in_array($where, ['desktop', 'menu', 'both'], true)) {
                        $this->problem("$path.shortcut.where", 'where: desktop, menu or both');
                    }
                    if (!isset($v['target'])) {
                        $this->problem("$path.shortcut", 'shortcut: {target: "{folder}/myapp{exe}", name: ..., where: desktop | menu | both}');
                    }
                    $s += ['target' => (string)($v['target'] ?? ''), 'name' => (string)($v['name'] ?? '{app}'), 'where' => $where,
                        'icon' => isset($v['icon']) ? (string)$v['icon'] : null];
                    break;
                case 'run':
                    $v = $map('command');
                    $s += ['command' => (string)($v['command'] ?? ''), 'wait' => (bool)($v['wait'] ?? true)];
                    break;
                case 'uninstaller':
                    $s += ['name' => (string)(is_array($value) ? ($value['name'] ?? 'Uninstall {app}') : 'Uninstall {app}')];
                    break;
            }
            if (isset($step['if'])) {
                $cond = ltrim((string)$step['if'], '!');
                if (!in_array($cond, $options, true)) {
                    $this->problem("$path.if", "if: '$cond' is not an option of an options page" . ($options !== [] ? ' (' . implode(', ', $options) . ')' : ''));
                }
                $s['if'] = (string)$step['if'];
            }
            if (isset($step['os'])) {
                $systems = is_array($step['os']) ? $step['os'] : [$step['os']];
                foreach ($systems as $os) {
                    if (!in_array($os, self::SYSTEMS, true)) {
                        $this->problem("$path.os", "os: '$os' (windows, linux, macos)");
                    }
                }
                $s['os'] = array_values(array_map('strval', $systems));
            }
            if (isset($step['label'])) {
                $s['label'] = (string)$step['label'];
            }
            $out[] = $s;
        }
        return $out;
    }

    private function problem(string $path, string $message, ?int $line = null): void
    {
        $this->problems[] = new Problem($path, $message, $line ?? $this->lineOf($path));
    }

    /** The line of a path ("steps[2].download") in the source: the line of its last key, roughly. */
    private function lineOf(string $path): ?int
    {
        if ($path === '') {
            return null;
        }
        $lines = preg_split('/\R/', $this->source) ?: [];
        $parts = preg_split('/[.\[\]]+/', $path, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $from = 0;
        foreach ($parts as $part) {
            for ($n = $from, $count = count($lines), $seen = -1; $n < $count; $n++) {
                if (ctype_digit($part)) {
                    // the (part+1)-th "- " item after the current line
                    if (preg_match('/^\s*-\s/', $lines[$n]) && ++$seen === (int)$part) {
                        $from = $n;
                        continue 2;
                    }
                } elseif (preg_match('/(^|\s|\{|,)' . preg_quote($part, '/') . '\s*:/', $lines[$n])) {
                    $from = $n;
                    continue 2;
                }
            }
            break;
        }
        return $from + 1;
    }
}
