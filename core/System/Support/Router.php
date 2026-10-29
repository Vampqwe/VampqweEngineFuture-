<?php
declare(strict_types=1);

namespace App\Core\System\Support;

use App\Core\System\Http\MiddlewareInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;

/**
 * Минимальный маршрутизатор.
 * Поддерживает статические пути, параметры вида /users/{id}
 * и группу middleware, применяемых ко всем маршрутам этого роутера
 * (у каждой «дороги» — свой экземпляр Router со своими middleware).
 */
class Router
{
    /** @var array<string, array<int, array{pattern:string, regex:string, params:string[], handler:callable}>> */
    private array $routes = [];

    /** @var list<MiddlewareInterface> */
    private array $middleware = [];

    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(array $middleware = [])
    {
        $this->middleware = $middleware;
    }

    public function addMiddleware(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

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

                $handler = $route['handler'];
                $destination = static fn(Request $req): Response => $handler($req);

                // Оборачиваем обработчик в middleware (внешний — первый).
                foreach (array_reverse($this->middleware) as $middleware) {
                    $next = $destination;
                    $destination = static fn(Request $req): Response
                        => $middleware->process($req, $next);
                }

                return static fn(Request $request): Response
                    => $destination($request->withRouteParams($params));
            }
        }

        return null;
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(\*?)\}#',
            function (array $m) use (&$params): string {
                $params[] = $m[1];
                // {param*} — «хвост»: матчит несколько сегментов пути.
                return $m[2] === '*' ? '(.+)' : '([^/]+)';
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
