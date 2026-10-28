<?php
declare(strict_types=1);

namespace App\modules\Admin\Internal;

use App\Core\Module\Database\Contracts\DatabaseInterface;

/**
 * Хранилище учётных записей для панели (внутренняя кухня модуля Admin).
 *
 * Работает с той же таблицей `users`, что и модуль Auth (единый источник
 * истины о пользователях — п.7 Конституции): панель только читает список
 * и переключает роль, никогда не создаёт аккаунты (это зона ответственности
 * Auth) и не трогает пароли кроме смены по прямому действию администратора.
 * Все запросы — prepared statements через DatabaseInterface (п.3 ядра).
 */
final class UserRepository
{
    public function __construct(
        private readonly DatabaseInterface $db
    ) {
    }

    /** @return array<int,array<string,mixed>> все пользователи по newest-first */
    public function all(int $limit = 200): array
    {
        return $this->db->select(
            'SELECT id, email, name, role, created_at FROM users ORDER BY id DESC LIMIT ' . max(1, min(500, $limit))
        );
    }

    public function countAll(): int
    {
        $row = $this->db->selectOne('SELECT COUNT(*) AS c FROM users');

        return (int) ($row['c'] ?? 0);
    }

    /** Сменить роль (значения ограничены whitelist — защита от «волшебных» ролей). */
    public function setRole(int $id, string $role): bool
    {
        if (!in_array($role, \App\modules\Admin\Internal\AdminGuard::ADMIN_ROLES
            + ['user', 'editor', 'viewer'], true)
        ) {
            return false;
        }

        return $this->db->query('users')
            ->where('id', '=', $id)
            ->update(['role' => $role]) > 0;
    }
}
