<?php
declare(strict_types=1);

namespace App\Core\System\Http;

class Request
{
    /**
     * @param array<string,string> $routeParams параметры маршрута ({id} и т.п.)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $server = [],
        public readonly array $routeParams = []
    ) {}

    public static function createFromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // parse_url может вернуть null/false при некорректном URI — защищаемся
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        // Нормализация: убираем лишние завершающие слэши (кроме корня)
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            $_SERVER
        );
    }

    /**
     * Копия запроса с параметрами маршрута (readonly-объект неизменяем).
     */
    public function withRouteParams(array $params): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->post,
            $this->server,
            $params
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $this->query[$key] ?? $this->post[$key] ?? $default;
    }
}
