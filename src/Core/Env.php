<?php
declare(strict_types=1);

namespace Club\Core;

final class Env
{
    /** @return array<string, string> */
    public static function load(string $file): array
    {
        if (!is_file($file)) return [];
        $env = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim(trim($value), "\"'");
        }
        return $env;
    }
}
