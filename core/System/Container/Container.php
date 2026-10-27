<?php
declare(strict_types=1);

namespace App\Core\System\Container;

use App\Core\System\Container\Contracts\ContainerInterface;

class Container implements ContainerInterface
{
    private array $instances = [];

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function get(string $abstract): mixed
    {
        if (!isset($this->instances[$abstract])) {
            throw new \RuntimeException("Сервис [{$abstract}] не найден в контейнере.");
        }
        return $this->instances[$abstract];
    }

    public function has(string $abstract): bool
    {
        return isset($this->instances[$abstract]);
    }
}