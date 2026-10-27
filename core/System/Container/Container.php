<?php
declare(strict_types=1);

namespace App\Core\System\Container;

use App\Core\System\Container\Contracts\ContainerInterface;

class Container implements ContainerInterface
{
    /** @var array<string, mixed> Готовые экземпляры (синглтоны) */
    private array $instances = [];

    /** @var array<string, callable> Фабрики-резолверы (lazy) */
    private array $bindings = [];

    /** @var array<string, bool> Защита от циклических зависимостей */
    private array $resolving = [];

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
        unset($this->bindings[$abstract]);
    }

    /**
     * Зарегистрировать ленивую фабрику. Вызывается один раз при первом get(),
     * результат кэшируется (синглтон).
     */
    public function bind(string $abstract, callable $factory): void
    {
        $this->bindings[$abstract] = $factory;
        unset($this->instances[$abstract]);
    }

    public function get(string $abstract): mixed
    {
        if (array_key_exists($abstract, $this->instances)) {
            return $this->instances[$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            if (isset($this->resolving[$abstract])) {
                throw new \RuntimeException("Циклическая зависимость сервиса [{$abstract}].");
            }
            $this->resolving[$abstract] = true;
            try {
                $instance = ($this->bindings[$abstract])($this);
            } finally {
                unset($this->resolving[$abstract]);
            }
            $this->instances[$abstract] = $instance;
            return $instance;
        }

        // Автоматическое разрешение: если это существующий класс — соберём по конструктору
        if (class_exists($abstract)) {
            if (isset($this->resolving[$abstract])) {
                throw new \RuntimeException("Циклическая зависимость сервиса [{$abstract}].");
            }
            $this->resolving[$abstract] = true;
            try {
                $instance = $this->autowire($abstract);
            } finally {
                unset($this->resolving[$abstract]);
            }
            $this->instances[$abstract] = $instance;
            return $instance;
        }

        throw new \RuntimeException("Сервис [{$abstract}] не найден в контейнере.");
    }

    public function has(string $abstract): bool
    {
        return array_key_exists($abstract, $this->instances)
            || isset($this->bindings[$abstract]);
    }

    /**
     * Рекурсивно собрать объект, разрешая параметры конструктора через контейнер.
     */
    private function autowire(string $class): object
    {
        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new \RuntimeException("Класс [{$class}] нельзя инстанцировать напрямую.");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if ($this->has($typeName) || interface_exists($typeName) || class_exists($typeName)) {
                    $arguments[] = $this->get($typeName);
                    continue;
                }
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new \RuntimeException(
                "Не удалось разрешить параметр [\${$parameter->getName()}] класса [{$class}]."
            );
        }

        return $reflection->newInstanceArgs($arguments);
    }
}