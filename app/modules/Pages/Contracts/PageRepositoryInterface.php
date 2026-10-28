<?php
declare(strict_types=1);

namespace App\Modules\Pages\Contracts;

/**
 * Контракт модуля Pages — единственная граница для остального движка.
 * Ядро и другие модули зависят ТОЛЬКО от этого интерфейса (п.2 Конституции).
 */
interface PageRepositoryInterface
{
    /** @return array<int, array<string, mixed>> пагинированный список опубликованных страниц */
    public function listPublished(int $page = 1, int $perPage = 20): array;

    /** Найти страницу по ЧПУ-слагу (только published). null если нет. */
    public function findBySlug(string $slug): ?array;

    /** Создать страницу, вернуть её id. slug генерируется из title, если не передан. */
    public function create(string $title, string $content, ?string $slug = null, bool $published = false): int;

    /** Обновить существующую страницу. false — если id не найден. */
    public function update(int $id, array $fields): bool;

    /** Удалить страницу. false — если id не найден. */
    public function delete(int $id): bool;

    /** Свободен ли слаг (для валидации при создании/переименовании). */
    public function isSlugFree(string $slug, ?int $exceptId = null): bool;
}
