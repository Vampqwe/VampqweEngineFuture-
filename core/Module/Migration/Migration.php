<?php
declare(strict_types=1);

namespace App\Core\Module\Migration;

use App\Core\Module\Migration\Contracts\MigratorInterface;
use App\Core\Module\Migration\Internal\Migrator;

/**
 * Фасад модуля Migration — версионирование схемы БД движка.
 *
 * Публичная граница модуля — контракт MigratorInterface; этот класс лишь
 * точка входа для документации/диагностики (Doctor резолвит контракт).
 *
 * Зависимости (уровень 5): Database, TimeDate, Logger, Hook + 'migrations_path'.
 * Хук-события: migration.before / migration.after / migration.rollbacked.
 */
final class Migration
{
    public function __construct(private readonly MigratorInterface $migrator)
    {
    }

    /** Быстрая сводка для Doctor/CLI. */
    public function status(): array
    {
        return $this->migrator->status();
    }

    public function migrator(): Migrator
    {
        assert($this->migrator instanceof Migrator);

        return $this->migrator;
    }
}
