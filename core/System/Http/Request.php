<?php
declare(strict_types=1);

namespace App\Core\System\Http;

class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $server = []
    ) {}

    public static function createFromGlobals(): self
    {
        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
            $_GET,
            $_POST,
            $_SERVER
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $this->post[$key] ?? $default;
    }
}