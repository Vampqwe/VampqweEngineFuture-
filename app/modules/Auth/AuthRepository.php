<?php
declare(strict_types=1);

namespace App\modules\Auth;

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\modules\Auth\Contracts\AuthRepositoryInterface;

/**
 * Репозиторий учётных записей (внутренняя кухня модуля Auth, п.2 Конституции).
 *
 * Данные: таблица `users` (миграция 2026_10_28_*_create_auth_users.php).
 * Все запросы — только через DatabaseInterface (prepared statements, п.3):
 * никакого new PDO. Пароли — password_hash() с автоматическим выбором
 * алгоритма (argon2id, если собран) и rehash при устаревании (ср. SuperAdmin).
 */
final class AuthRepository implements AuthRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db
    ) {
    }

    public function findByEmail(string $email): ?array
    {
        $email = $this->normalize($email);
        if ($email === '') {
            return null;
        }

        return $this->db->selectOne('SELECT * FROM users WHERE email = ?', [$email]);
    }

    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return $this->db->selectOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function create(string $email, string $password, string $name = ''): ?int
    {
        $email = $this->normalize($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            return null;
        }

        // Идемпотентность конфликта: UNIQUE на email + проверка заранее.
        if ($this->findByEmail($email) !== null) {
            return null;
        }

        $affected = $this->db->run(
            'INSERT INTO users (email, name, password, created_at)'
            . ' VALUES (?, ?, ?, ?)',
            [$email, trim($name), password_hash($password, PASSWORD_DEFAULT), date('c')]
        );

        return $affected > 0 ? $this->db->lastInsertId() : null;
    }

    public function verifyPassword(array $user, string $password): bool
    {
        $hash = (string) ($user['password'] ?? '');
        if ($hash === '' || $password === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $this->db->run('UPDATE users SET password = ? WHERE id = ?', [$passwordHash, $id]);
    }

    private function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}
