<?php
declare(strict_types=1);

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Migration\Internal\AbstractMigration;

/**
 * Миграция модуля Auth: таблица учётных записей пользователей.
 *
 * Идемпотентна (createTableIfMissing) — повторный прогон безопасен.
 * Пароль хранится только как хеш password_hash() (argon2id/bcrypt),
 * никогда в открытом виде (принцип безопасности, ср. SuperAdmin).
 */
final class CreateAuthUsers extends AbstractMigration
{
    public function description(): string
    {
        return 'Таблица users (учётные записи модуля Auth)';
    }

    public function up(): int
    {
        $created = $this->createTableIfMissing(
            'users',
            'id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . "email VARCHAR(191) NOT NULL UNIQUE,"
            . "name VARCHAR(191) NOT NULL DEFAULT '',"
            . "password VARCHAR(255) NOT NULL,"
            . "role VARCHAR(32) NOT NULL DEFAULT 'user',"
            . "created_at VARCHAR(40) NOT NULL DEFAULT ''"
        );

        // Ускорение точечного поиска по email (UNIQUE уже даёт индекс в sqlite/mysql,
        // но явный индекс идемпотентен и единообразен для всех драйверов).
        return $created + $this->addIndexIfMissing('users_email_idx', 'users', 'email');
    }

    public function down(): int
    {
        return $this->dropTableIfExists('users');
    }
}

// Фабрика: движок подставит БД и id (имя файла) сам.
return static fn (DatabaseInterface $db, string $id): AbstractMigration => new CreateAuthUsers($db, $id);
