<?php
declare(strict_types=1);

namespace App\Core\Module\Installer\Contracts;

/**
 * Контракт модуля Installer — первичная установка движка
 * (создание конфигов, БД, флаг storage/.installed).
 */
interface InstallerInterface
{
    /** Требования окружения: PHP, расширения, права на storage/. */
    public function requirements(): array;

    /**
     * Выполнить установку (шаги: env -> флаги -> database -> схема db.sql).
     *
     * @param array<string,mixed> $options
     * @return array<string,string>|true  true — успех без деталей;
     *         массив «шаг => результат» — итог для UI/CLI (включая БД и схему).
     */
    public function install(array $options = []): array|bool;

    /** Идёт ли установка сейчас (флаг storage/.installing)? */
    public function isInstalling(): bool;

    /** Установлен ли движок (флаг storage/.installed + валидный .env). */
    public function isInstalled(): bool;
}
