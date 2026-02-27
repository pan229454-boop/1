<?php

declare(strict_types=1);

namespace ChatApp\Core;

/**
 * 简单环境变量读取器（避免额外依赖，部署更轻量）
 */
final class Env
{
    private static array $data = [];

    public static function load(string $filePath): void
    {
        if (!is_file($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            $val = trim($val, "\"'");
            self::$data[$key] = $val;
        }
    }

    public static function get(string $key, $default = null)
    {
        return self::$data[$key] ?? getenv($key) ?: $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $val = strtolower((string) self::get($key, $default ? 'true' : 'false'));
        return in_array($val, ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, (string) $default);
    }
}
