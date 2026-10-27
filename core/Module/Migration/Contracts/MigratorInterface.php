<?php
declare(strict_types=1);

namespace App\Core\Module\Migration\Contracts;

/**
 * Контракт модуля Migration — версионирование схемы БД.
 * Единственное, что видят другие модули (п.2 Конституции).
 *
 * Все методы оперируют файлами из 'migrations_path' (единая точка правды
 * Paths) и таблицей-реестром `engine_migrations` в активной БД.
 */
interface MigratorInterface
{
    /** Все найденные миграции (id => описание), в порядке исполнения. */
    public function all(): array;

    /** Id уже применённых миграций (из реестра), в порядке применения. */
    public function applied(): array;

    /** Миграции, которые ещё НЕ применены (id => описание). */
    public function pending(): array;

    /**
     * Применить все ожидающие миграции (или конкретную по id).
     * Каждая миграция выполняется в отдельной транзакции.
     * @return list<string> id применённых на этом вызове миграций
     */
    public function migrate(?string $id = null): array;

    /**
     * Откатить последнюю применённую (или конкретную по id) миграцию.
     * @return string|null id откаченной миграции (null — откатывать нечего)
     */
    public function rollback(?string $id = null): ?string;

    /** Создать файл-заготовку новой миграции. @return string путь к созданному файлу */
    public function make(string $name): string;

    /** Откатить ВСЕ применённые миграции (обратный порядок). @return int число откаченных */
    public function reset(): int;

    /** Вердикт для Doctor/CLI: ['ok'=>bool,'total'=>int,'applied'=>int,'pending'=>int]. */
    public function status(): array;

    /**
     * Автоприменение ожидающих миграций (используется Installer после развёртки db.sql).
     * Отсутствие СУБД/папки миграций НЕ валит установку — деградирует тихо (warning в лог).
     * @return list<string> применённые id
     */
    public function migratePending(): array;
}
