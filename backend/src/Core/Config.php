<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Reads settings from environment variables (set by Docker / the host).
 * Secrets never live in the repo.
 */
final class Config
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new \RuntimeException("Missing required setting: {$key}");
        }
        return $value;
    }

    public static function requirePositiveInt(string $key): int
    {
        $value = filter_var(self::require($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new \RuntimeException("Setting {$key} must be a positive integer");
        }
        return $value;
    }

    public static function requirePositiveFloat(string $key): float
    {
        $value = filter_var(self::require($key), FILTER_VALIDATE_FLOAT);
        if ($value === false || $value <= 0) {
            throw new \RuntimeException("Setting {$key} must be a positive number");
        }
        return $value;
    }
}
