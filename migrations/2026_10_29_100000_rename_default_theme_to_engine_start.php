<?php
declare(strict_types=1);

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Migration\Internal\AbstractMigration;

/**
 * Переименование темы по умолчанию: '_default' -> 'engineStart'.
 *
 * Стартовый сайт движка теперь называется engineStart — он же является
 * документацией (гайд по созданию страниц, модулей и тем живёт в его
 * home.phtml). Миграция переписывает существующие записи реестра сайтов,
 * чтобы уже установленные инсталляции продолжали рендериться корректно.
 * Идемпотентна: UPDATE не трогает строки после повтора; при отсутствии
 * таблицы engine_sites — no-op (миграция CreateEngineSites создаст её
 * сразу с 'engineStart').
 */
final class RenameDefaultThemeToEngineStart extends AbstractMigration
{
    public function description(): string
    {
        return 'engine_sites: переименование темы _default в engineStart';
    }

    public function up(): int
    {
        if (!$this->db->tableExists('engine_sites')) {
            return 0;
        }

        return $this->sql("UPDATE engine_sites SET theme = 'engineStart' WHERE theme = '_default'");
    }

    public function down(): int
    {
        if (!$this->db->tableExists('engine_sites')) {
            return 0;
        }

        return $this->sql("UPDATE engine_sites SET theme = '_default' WHERE theme = 'engineStart'");
    }
}

// Фабрика: движок подставит БД и id (имя файла) сам.
return static fn (DatabaseInterface $db, string $id): AbstractMigration => new RenameDefaultThemeToEngineStart($db, $id);
