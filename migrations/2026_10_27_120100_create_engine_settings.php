<?php
declare(strict_types=1);

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Migration\Internal\AbstractMigration;

/**
 * Демонстрационная миграция ядра: таблица настроек движка (ключ-значение).
 */
final class CreateEngineSettings extends AbstractMigration
{
    public function __construct(DatabaseInterface $db, string $migrationId = '2026_10_27_120100_create_engine_settings')
    {
        parent::__construct($db, $migrationId);
    }

    public function description(): string
    {
        return 'Таблица engine_settings (хранилище настроек движка key/value)';
    }

    public function up(): int
    {
        return $this->createTableIfMissing(
            'engine_settings',
            'id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . "key VARCHAR(191) NOT NULL UNIQUE, value TEXT NOT NULL DEFAULT '',"
            . 'updated_at VARCHAR(40) NOT NULL DEFAULT \'\''
        );
    }

    public function down(): int
    {
        return $this->dropTableIfExists('engine_settings');
    }
}
