<?php
declare(strict_types=1);

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Migration\Internal\AbstractMigration;

/**
 * Миграция ядра: колонка title в реестре сайтов мультисайта.
 *
 * Карточка сайта (домен -> тема) получает человекочитаемое название —
 * шаблоны тем выводят его вместо технического имени домена.
 * Идемпотентна через addColumnIfMissing (проверка PRAGMA/information_schema),
 * sqlite+mysql-safe; down() — no-op: потеря данных хуже лишней колонки.
 */
final class AddTitleToEngineSites extends AbstractMigration
{
    public function description(): string
    {
        return 'engine_sites: колонка title (название сайта для шаблонов тем)';
    }

    public function up(): int
    {
        if (!$this->db->tableExists('engine_sites')) {
            return 0;
        }

        return $this->addColumnIfMissing(
            'engine_sites',
            'title',
            "VARCHAR(191) NOT NULL DEFAULT ''"
        );
    }
}

// Фабрика: движок подставит БД и id (имя файла) сам.
return static fn (DatabaseInterface $db, string $id): AbstractMigration => new AddTitleToEngineSites($db, $id);
