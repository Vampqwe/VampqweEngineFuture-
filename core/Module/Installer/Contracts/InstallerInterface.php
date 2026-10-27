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

    /** Выполнить установку (шаги: env -> config -> database -> .installed). */
    public function install(array $options = []): bool;

    /** Идёт ли установка сейчас (флаг storage/.installing)? */
    public function isInstalling(): bool;

    /** Установлен ли движок (флаг storage/.installed + валидный .env). */
    public function isInstalled(): bool;
}
