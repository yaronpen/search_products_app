<?php
declare(strict_types=1);

namespace App\Core;

/** Maps "METHOD /path" to a handler, e.g. [$searchController, 'search']. */
final class Router
{
    /** @var array<string, array<string, callable(Request): JsonResponse>> path => method => handler */
    private array $routes = [];

    /** @param callable(Request): JsonResponse $handler */
    public function get(string $path, callable $handler): void
    {
        $this->routes[$path]['GET'] = $handler;
    }

    public function dispatch(Request $request): JsonResponse
    {
        $methods = $this->routes[$request->path] ?? null;
        if ($methods === null) {
            return JsonResponse::error(404, 'not_found', 'Route not found');
        }
        $handler = $methods[$request->method] ?? null;
        if ($handler === null) {
            return JsonResponse::error(405, 'method_not_allowed', 'Method not allowed');
        }
        return $handler($request);
    }
}
