<?php
declare(strict_types=1);

namespace App\Core\System\Container\Contracts;

interface ContainerInterface
{
    public function instance(string $abstract, mixed $instance): void;
    public function bind(string $abstract, callable $factory): void;
    public function get(string $abstract): mixed;
    public function has(string $abstract): bool;

    /**
     * Опциональный резолв: вернёт сервис, если он зарегистрирован, иначе null.
     * Нужен ядру для capability-контрактов (например, миграция сторов Session):
     * «есть способность — используй, нет — деградируй честно» без try/catch
     * и без знания конкретных классов модуля (п.2 Конституции).
     */
    public function tryGet(string $abstract): mixed;
}