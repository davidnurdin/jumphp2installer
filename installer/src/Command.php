<?php

namespace JumPHP2Installer;

/**
 * A program started without waiting for it (shortcuts through PowerShell, reg.exe, a script's run: step):
 * isDone() at each frame, then $exitCode and $output.
 */
final class Command
{
    public ?int $exitCode = null;
    public string $output = '';

    /** @var resource|null */
    private $process;
    /** @var array<int, resource> */
    private array $pipes = [];

    /** @param string|list<string> $command a line for the shell, or the program and its arguments */
    public function __construct(public readonly string|array $command, ?string $cwd = null)
    {
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $options = PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => is_array($command), 'create_no_window' => true] : [];
        $process = @proc_open($command, $spec, $pipes, $cwd !== null && is_dir($cwd) ? $cwd : null, null, $options);
        if (!is_resource($process)) {
            $this->exitCode = 127;
            $this->output = 'cannot start ' . (is_array($command) ? $command[0] : $command);
            return;
        }
        $this->process = $process;
        $this->pipes = $pipes;
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
    }

    /** Runs a command to its end (the command line: no frame to keep). */
    public static function run(string|array $command, ?string $cwd = null): self
    {
        $c = new self($command, $cwd);
        while (!$c->isDone()) {
            usleep(5000);
        }
        return $c;
    }

    /** Starts a program and forgets it (the installed app, at the end): it lives on after the installer quits. */
    public static function detached(string $program, ?string $cwd = null): bool
    {
        $cwd ??= dirname($program);
        if (PHP_OS_FAMILY === 'Windows') {
            $p = @popen('start "" /D "' . $cwd . '" "' . $program . '"', 'r');
            return $p !== false && pclose($p) !== -1;
        }
        $q = escapeshellarg($program);
        $line = PHP_OS_FAMILY === 'Darwin' && (str_ends_with($program, '.app') || is_dir($program)) ? "open $q" : "cd " . escapeshellarg($cwd) . " && nohup $q >/dev/null 2>&1 &";
        $c = self::run(['sh', '-c', $line]);
        return $c->ok();
    }

    public function isDone(): bool
    {
        if ($this->exitCode !== null) {
            return true;
        }
        $this->drain();
        $status = proc_get_status($this->process);
        if ($status['running']) {
            return false;
        }
        $this->drain();
        foreach ([1, 2] as $i) {
            fclose($this->pipes[$i]);
        }
        $code = proc_close($this->process);
        $this->exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : $code;
        return true;
    }

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }

    private function drain(): void
    {
        foreach ([1, 2] as $i) {
            while (is_resource($this->pipes[$i] ?? null) && ($data = fread($this->pipes[$i], 65536)) !== false && $data !== '') {
                $this->output = substr($this->output . $data, -16384);
            }
        }
    }
}
