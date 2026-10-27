<?php
declare(strict_types=1);

namespace App\Core\System\Support;

/**
 * Внутренний контракт стора сессий (не публичный API модуля!).
 * Публичный контракт модуля Session — Contracts\SessionInterface.
 *
 * Ключи здесь — сырые id сессий; сериализацию данных выполняет стор.
 */
interface SessionStoreInterface
{
    /** @return array<string,mixed>|null null — записи нет или она истекла */
    public function read(string $id): ?array;

    /** @param array<string,mixed> $data */
    public function write(string $id, array $data, int $ttlSeconds): bool;

    public function destroy(string $id): bool;

    /**
     * Все живые (не истёкшие) записи — используется при миграции сторов.
     * @return array<string,array<string,mixed>> id => данные
     */
    public function all(): array;

    /** Доступен ли стор прямо сейчас (для graceful-фолбэка и Doctor). */
    public function isAvailable(): bool;
}
