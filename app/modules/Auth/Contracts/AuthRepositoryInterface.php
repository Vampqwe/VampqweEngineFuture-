<?php
declare(strict_types=1);

namespace App\modules\Auth\Contracts;

/**
 * Публичная граница модуля Auth (п.2 Конституции): хранилище учётных
 * записей пользователей. Реализация (AuthRepository) — внутренняя кухня,
 * наружу отдаётся только этот контракт через провайдер.
 *
 * Пароли: только password_hash/password_verify (argon2id/bcrypt по PHP
 * default), никаких самодельных схем (принцип безопасности ядра, ср. SuperAdmin).
 */
interface AuthRepositoryInterface
{
    /** Найти пользователя по email (нормализованному). */
    public function findByEmail(string $email): ?array;

    public function findById(int $id): ?array;

    /** Создать пользователя; возвращает id новой записи или null при конфликте email. */
    public function create(string $email, string $password, string $name = ''): ?int;

    /** Проверка пароля по храну (время-постоянная внутри password_verify). */
    public function verifyPassword(array $user, string $password): bool;

    /** Обновить хеш пароля (смена пароля / rehash при смене алгоритма). */
    public function updatePassword(int $id, string $passwordHash): void;
}
