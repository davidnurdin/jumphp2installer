<?php

namespace JumPHP2Installer;

/**
 * The version an install script installs: fixed, or read from the address of the newest one. What that address
 * answers is read in this order: JSON with the field named by `field:`, else "version", "tag_name" (GitHub's
 * releases/latest API), "name"; else the text itself (its first line). A leading "v" is dropped (v1.2.0 -> 1.2.0).
 */
final class Version
{
    /** The version of a version.latest answer ($field: the JSON field, '' = the usual ones). */
    public static function fromAnswer(string $body, string $field = ''): string
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            $fields = $field !== '' ? [$field] : ['version', 'tag_name', 'name'];
            foreach ($fields as $f) {
                $value = $json;
                foreach (explode('.', $f) as $part) {
                    $value = is_array($value) ? ($value[$part] ?? null) : null;
                }
                if (is_string($value) || is_int($value) || is_float($value)) {
                    return self::clean((string)$value);
                }
            }
            throw new \RuntimeException('the newest version: no ' . implode(' / ', $fields) . ' in the answer');
        }
        $line = trim(strtok(trim($body), "\r\n") ?: '');
        if ($line === '' || strlen($line) > 64) {
            throw new \RuntimeException('the newest version: the answer is not a version');
        }
        return self::clean($line);
    }

    public static function clean(string $version): string
    {
        $version = trim($version);
        return preg_match('/^v\d/i', $version) ? substr($version, 1) : $version;
    }
}
