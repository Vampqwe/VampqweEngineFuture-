<?php
declare(strict_types=1);

namespace App\Core\Module\Config\Contracts;

interface ConfigRepositoryInterface
{
    /**
     * Получить значение по «точечному» ключу: app.name, modules.Config.critical и т.д.
     */
    public function get(string $key, mixed $default): mixed;

    /**
     * Проверить наличие ключа.
     */
    public function has(string $key): bool;

    /**
     * Переопределить значение (например, из .env или во время установки).
     */
    public function set(string $key, mixed $value): void;

    /**
     * Весь массив конфигурации заданного файла (или всего конфига без аргумента).
     */
    public function all(?string $group): array;
}
