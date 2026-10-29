<?php
declare(strict_types=1);

namespace App\Core\Module\Session\Contracts;

/**
 * Capability-контракт модуля Session: фоновая миграция живых сессий между
 * сторами (file <-> redis) при смене session.driver.
 *
 * Почему отдельный интерфейс (п.4 Конституции): публичный контракт
 * SessionInterface описывает ЖИЗНЕННЫЙ ЦИКЛ СЕССИЙ, а перенос содержимого
 * хранилищ — внутренняя механика сторов (Internal\Stores). Ядро резолвит
 * эту способность отдельно и НЕ знает ни драйверов, ни имён сторов.
 * Реализация — ModuleSession (bind в SessionServiceProvider под обоими
 * ключами: SessionInterface::class и этого контракта).
 */
interface SessionStoreMigratorInterface
{
    /**
     * Мигрировать все живые сессии из одного стора в другой.
     * Недоступный стор-источник или приёмник = 0 (без исключений, п.9).
     *
     * @return int количество перенесённых сессий
     */
    public function migrateStore(string $fromDriver, string $toDriver): int;
}
