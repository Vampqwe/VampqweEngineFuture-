<?php
declare(strict_types=1);

namespace App\Core\Module\Migration\Contracts;

/**
 * Контракт модуля Migration — единственный легальный путь развёртывания
 * схемы БД движка (п.2 Конституции: потребители знают только интерфейс).
 *
 * Источником схемы являются SQL-дампы database/*.sql (каталог — из единой
 * точки правды Paths, ключ 'database'). Миграция выполняется ТОЛЬКО
 * установщиком движка (модуль Installer, schema-шаг); фоновых прогонов
 * схемы ядро не делает (старый Migrator с migrations/*.php удалён начисто).
 *
 * Расширяемость на будущее: реализация прогонит несколько *.sql по
 * алфавиту и ведёт реестр применённых дампов (engine_migrations), поэтому
 * опция «Миграция БД» в админ-панели подключается резолвом этого контракта
 * без правок ядра.
 */
interface MigrationInterface
{
    /** Имя таблицы-реестра применённых SQL-дампов (создаётся модулем идемпотентно). */
    public const REGISTRY_TABLE = 'engine_migrations';

    /**
     * Найти SQL-дампы в каталоге схемы (database/*.sql), по алфавиту.
     *
     * @return list<string> абсолютные пути к файлам
     */
    public function files(): array;

    /**
     * Есть ли вообще источник схемы: в database/ найден хотя бы один *.sql,
     * содержащий CREATE TABLE. false => пользователю показывается предупреждение
     * «необходимо создать базу данных» с формой ввода DSN (шаг Database в UI).
     */
    public function hasSchemaSource(): bool;

    /**
     * Отчёт о состоянии схемы без записи: таблицы дампа — какие уже есть
     * в БД, какие отсутствуют. Для Doctor / будущей admin-опции «Миграция БД».
     *
     * @return array{files:list<string>, tables:list<string>, missing:list<string>}
     */
    public function plan(): array;

    /**
     * Применить все найденные дампы (идемпотентно: существующие таблицы
     * пропускаются, недостающие досоздаются). Единственный schema-шаг установки.
     *
     * @return array{statements:int, files:list<string>, skipped:bool}
     */
    public function run(): array;
}
