<?php

namespace JumPHP2Installer;

/**
 * The variables of an install script: {app} {id} {publisher} {version} {folder} and this computer's places and
 * names. The options' check boxes (with(['desktop' => true])) are kept apart: steps' if: reads them, and an option
 * named like a place ({desktop}) does not hide it. expand("{folder}/myapp{exe}").
 *
 * | variable     | Windows                                   | Linux                         | macOS            |
 * |--------------|-------------------------------------------|-------------------------------|------------------|
 * | {programs}   | %LOCALAPPDATA%\Programs (no admin needed) | ~/.local/opt                  | ~/Applications   |
 * | {desktop}    | the desktop                               | xdg-user-dir DESKTOP          | ~/Desktop        |
 * | {menu}       | the Start menu's Programs                 | ~/.local/share/applications   | (none)           |
 * | {home}       | %USERPROFILE%                             | $HOME                         | $HOME            |
 * | {temp}       | the temporary folder                      |                               |                  |
 * | {os}         | windows                                   | linux                         | macos            |
 * | {arch}       | x86_64, arm64...                          |                               |                  |
 * | {platform}   | {os}-{arch}: windows-x86_64, linux-x86_64, macos-arm64...                                 |
 * | {exe}        | .exe                                      | (nothing)                     | (nothing)        |
 * | {download}   | the file of the last download step                                                        |
 */
final class Vars
{
    /** @var array<string, string> */
    private array $values;
    /** @var array<string, bool> the options' check boxes */
    private array $options = [];

    /** @param array<string, string> $values */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    /** This computer's variables (+ the script's app). */
    public static function forThisComputer(array $app = []): self
    {
        $os = self::os();
        $home = rtrim((string)(getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir()), '/\\');
        $values = [
            'os' => $os,
            'arch' => self::arch(),
            'exe' => $os === 'windows' ? '.exe' : '',
            'home' => $home,
            'temp' => rtrim(sys_get_temp_dir(), '/\\'),
        ];
        $values['platform'] = $values['os'] . '-' . $values['arch'];
        switch ($os) {
            case 'windows':
                $local = rtrim((string)(getenv('LOCALAPPDATA') ?: "$home\\AppData\\Local"), '\\');
                $roaming = rtrim((string)(getenv('APPDATA') ?: "$home\\AppData\\Roaming"), '\\');
                $values['programs'] = "$local\\Programs";
                $values['desktop'] = "$home\\Desktop";
                $values['menu'] = "$roaming\\Microsoft\\Windows\\Start Menu\\Programs";
                break;
            case 'macos':
                $values['programs'] = "$home/Applications";
                $values['desktop'] = "$home/Desktop";
                $values['menu'] = '';
                break;
            default:
                $values['programs'] = "$home/.local/opt";
                $desktop = trim((string)@shell_exec('xdg-user-dir DESKTOP 2>/dev/null'));
                $values['desktop'] = $desktop !== '' && $desktop !== $home ? $desktop : "$home/Desktop";
                $values['menu'] = rtrim((string)(getenv('XDG_DATA_HOME') ?: "$home/.local/share"), '/') . '/applications';
        }
        $vars = new self($values);
        return $app !== [] ? $vars->withApp($app) : $vars;
    }

    /** {app} {id} {publisher} {website} of the script's app section. */
    public function withApp(array $app): self
    {
        return $this->with([
            'app' => (string)($app['name'] ?? ''),
            'id' => (string)($app['id'] ?? ''),
            'publisher' => (string)($app['publisher'] ?? ''),
            'website' => (string)($app['website'] ?? ''),
        ]);
    }

    /** @param array<string, string|bool> $values */
    public function with(array $values): self
    {
        $copy = clone $this;
        foreach ($values as $name => $value) {
            if (is_bool($value)) {
                $copy->options[$name] = $value;
            } else {
                $copy->values[$name] = (string)$value;
            }
        }
        return $copy;
    }

    public function get(string $name, string $default = ''): string
    {
        return $this->values[$name] ?? $default;
    }

    /** @return array<string, bool> */
    public function options(): array
    {
        return $this->options;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->values;
    }

    /** "{folder}/myapp{exe}" with the values ({unknown} stays as written); on Windows, / becomes \ in paths. */
    public function expand(string $text): string
    {
        // twice: a folder's default may itself hold variables ("{programs}/{app}")
        for ($i = 0; $i < 3 && str_contains($text, '{'); $i++) {
            $text = (string)preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/', fn (array $m): string => $this->values[$m[1]] ?? $m[0], $text);
        }
        return $text;
    }

    /** expand() for a path: Windows' separators on Windows. */
    public function path(string $text): string
    {
        $path = $this->expand($text);
        return ($this->values['os'] ?? '') === 'windows' && !preg_match('#^https?://#', $path) ? str_replace('/', '\\', $path) : $path;
    }

    /** A step runs: its if: (an option; !option = when not checked) and its os: allow it. */
    public function allows(array $step): bool
    {
        if (isset($step['os']) && !in_array($this->values['os'] ?? '', (array)$step['os'], true)) {
            return false;
        }
        if (isset($step['if'])) {
            $not = str_starts_with((string)$step['if'], '!');
            $on = $this->options[ltrim((string)$step['if'], '!')] ?? false;
            return $not ? !$on : $on;
        }
        return true;
    }

    public static function os(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Darwin' => 'macos',
            default => 'linux',
        };
    }

    public static function arch(): string
    {
        $arch = strtolower(php_uname('m'));
        return match ($arch) {
            'amd64', 'x64' => 'x86_64',
            'aarch64' => self::os() === 'macos' ? 'arm64' : 'aarch64',
            default => $arch,
        };
    }
}
