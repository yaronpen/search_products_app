<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @param array<string, mixed> $query */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), rtrim($path, '/') ?: '/', $_GET);
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->query);
    }
}
