<?php
declare(strict_types=1);

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Migration\Internal\AbstractMigration;

/**
 * Миграция ядра: реестр сайтов мультисайта (модуль Theme).
 *
 * Каждая строка — сайт (домен), привязанный к теме view/app/<theme>/.
 * Разделительная способность: доменная привязка вместо эвристики поддоменов.
 * Идемпотентна, sqlite+mysql-safe (только базовые типы).
 */
final class CreateEngineSites extends AbstractMigration
{
    public function description(): string
    {
        return 'Таблица engine_sites (реестр сайтов мультисайта: domain -> theme)';
    }

    public function up(): int
    {
        // ДИАЛЕКТ ВАЖЕН: «INTEGER PRIMARY KEY AUTOINCREMENT» — синтаксис только SQLite;
        // в MySQL тип INTEGER не принимает модификатор PRIMARY KEY (ошибка 1063).
        $definition = $this->db->driver() === 'mysql'
            ? 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
              . "domain VARCHAR(191) NOT NULL UNIQUE,"
              . "theme VARCHAR(64) NOT NULL DEFAULT 'engineStart',"
              . "is_active SMALLINT NOT NULL DEFAULT 1,"
              . "created_at VARCHAR(40) NOT NULL DEFAULT ''"
            : 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
              . "domain VARCHAR(191) NOT NULL UNIQUE,"
              . "theme VARCHAR(64) NOT NULL DEFAULT 'engineStart',"
              . "is_active SMALLINT NOT NULL DEFAULT 1,"
              . "created_at VARCHAR(40) NOT NULL DEFAULT ''";

        $created = $this->createTableIfMissing('engine_sites', $definition);

        if ($created > 0) {
            // Доменный индекс (UNIQUE уже создаёт его в обеих СУБД) + сид:
            // локальная разработка localhost -> тема по умолчанию.
            $this->sql("INSERT INTO engine_sites (domain, theme, is_active, created_at)"
                . " VALUES ('localhost', 'engineStart', 1, '" . date('c') . "')");
        }

        return $created;
    }

    public function down(): int
    {
        return $this->dropTableIfExists('engine_sites');
    }
}

// Фабрика: движок подставит БД и id (имя файла) сам.
return static fn (DatabaseInterface $db, string $id): AbstractMigration => new CreateEngineSites($db, $id);
