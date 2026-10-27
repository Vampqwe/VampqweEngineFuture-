<?php
declare(strict_types=1);

namespace App\Core\System\Support;

use App\Core\System\Http\Request;
use App\Core\System\Http\Response;

/**
 * Минимальный маршрутизатор.
 * Поддерживает статические пути и параметры вида /users/{id}.
 */
class Router
{
    /** @var array<string, array<int, array{pattern:string, regex:string, params:string[], handler:callable}>> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function any(string $pattern, callable $handler): void
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->add($method, $pattern, $handler);
        }
    }

    /**
     * Вернуть обработчик для метода+пути или null, если совпадений нет.
     */
    public function match(string $method, string $path): ?callable
    {
        foreach ($this->routes[strtoupper($method)] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $m)) {
                $params = array_combine($route['params'], array_slice($m, 1));
                if (!is_array($params)) {
                    $params = [];
                }

                return static fn(Request $request): Response
                    => $route['handler']($request->withRouteParams($params));
            }
        }

        return null;
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        );

        $this->routes[$method][] = [
            'pattern' => $pattern,
            'regex'   => '#^' . $regex . '$#u',
            'params'  => $params,
            'handler' => $handler,
        ];
    }
}
