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
     * Валидация POST-настроек установки (пустой массив = всё корректно).
     * В контракте намеренно НЕ static: Kernel обязан вызывать её через
     * интерфейс (п.2), а не через конкретный класс Installer (нарушение R6).
     * Реализация может остаться static — PHP принимает static-метод как
     * реализацию интерфейсного.
     *
     * @param array<string, mixed> $post
     * @return list<string> сообщения об ошибках
     */
    public function validateSettings(array $post): array;

    /**
     * Выполнить установку (шаги: env -> флаги -> database -> схема db.sql).
     *
     * @param array<string,mixed> $options
     * @return array<string,string>|true  true — успех без деталей;
     *         массив «шаг => результат» — итог для UI/CLI (включая БД и схему).
     */
    public function install(array $options): array|bool;

    /** Идёт ли установка сейчас (флаг storage/.installing)? */
    public function isInstalling(): bool;

    /** Установлен ли движок (флаг storage/.installed + валидный .env). */
    public function isInstalled(): bool;
}
