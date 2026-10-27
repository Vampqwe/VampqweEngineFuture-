<?php
declare(strict_types=1);

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Migration\Internal\AbstractMigration;

/**
 * Демонстрационная миграция ядра: индекс уникальности slug у страниц.
 * Идемпотентна (addIndexIfMissing), откат — no-op (sqlite-safe).
 */
final class AddSlugIndexToPages extends AbstractMigration
{
    public function description(): string
    {
        return 'Индекс по pages.slug (ускоряет выборку страниц по ЧПУ)';
    }

    public function up(): int
    {
        if (!$this->db->tableExists('pages')) {
            return 0; // таблицы ещё нет (дамп не развёрнут) — миграция не нужна
        }

        return $this->addIndexIfMissing('idx_pages_slug', 'pages', 'slug');
    }
}

// Фабрика: движок подставит БД и id (имя файла) сам.
return static fn (DatabaseInterface $db, string $id): AbstractMigration => new AddSlugIndexToPages($db, $id);
