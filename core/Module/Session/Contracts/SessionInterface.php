<?php
declare(strict_types=1);

namespace App\Core\Module\Session\Contracts;

/**
 * Контракт модуля Session — серверные сессии + безопасные куки.
 * Единственное, что видят другие модули (п.2 Конституции).
 *
 * Хранилище настраивается через session.driver (file|redis). При СМЕНЕ драйвера
 * в конфигурации существующие сессии мигрируются безболезненно: ModuleSession
 * при чтении находит запись в старом сторе, переносит её в новый и отдаёт id
 * как есть — пользователь не теряет вход. Обратная миграция — симметрична.
 */
interface SessionInterface
{
    /** Текущий id сессии (из подписанной куки), '' если сессии нет. */
    public function id(): string;

    /** Начать новую сессию (с перегенерацией id — защита от fixation). */
    public function start(): string;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function forget(string $key): void;

    /** Все значения сессии. */
    public function all(): array;

    /** Записать сессию в активный стор (вызывается Kernel в конце запроса). */
    public function save(): void;

    /** Удалить сессию целиком и протухить куку. */
    public function destroy(): void;

    /** CSRF-токен текущей сессии (создаётся лениво). */
    public function csrfToken(): string;

    /** Проверить присланный CSRF-токен (hash_equals, время-постоянно). */
    public function validateCsrf(?string $token): bool;

    /**
     * Подписанная (и опционально зашифрованная) кука: значение переживает
     * подделку на клиенте. Возвращает null, если подпись не сходится.
     * Вызывать ДО отправки ответа; Set-Cookie копит в переданном массиве headers.
     *
     * @param array<string,string> $setCookieHeaders накопитель заголовков Set-Cookie
     */
    public function signedCookie(
        string $name,
        ?string $value = null,
        int $minutes = 120,
        bool $encrypt = false,
        array &$setCookieHeaders = []
    ): ?string;

    /** Активный драйвер хранилища ('file'|'redis'). */
    public function driver(): string;

    /**
     * Мигрировать все живые сессии из одного стора в другой (файл<->редис).
     * Используется ядром при обнаружении смены session.driver.
     *
     * @return int количество перенесённых сессий
     */
    public function migrateStore(string $fromDriver, string $toDriver): int;
}
