<?php

namespace JumPHP2Installer;

/** Something wrong in an install script: where ("steps[2].download"), what, and its line when known. */
final class Problem implements \JsonSerializable
{
    public function __construct(public readonly string $path, public readonly string $message, public readonly ?int $line = null)
    {
    }

    public function __toString(): string
    {
        return ($this->line !== null ? "line $this->line: " : '') . ($this->path !== '' ? "$this->path: " : '') . $this->message;
    }

    public function jsonSerialize(): array
    {
        return ['path' => $this->path, 'message' => $this->message, 'line' => $this->line];
    }
}
