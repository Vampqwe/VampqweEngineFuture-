<?php
declare(strict_types=1);

namespace App\Core\Module\Security\Internal;

/**
 * Внутренняя криптография (openssl AES-256-GCM, password_hash).
 * ЗАПРЕЩЕНО использовать напрямую извне модуля (п.2 Конституции).
 * Реализация — в плане.
 */
final class Crypto
{
    // TODO: encrypt/decrypt/sign поверх app.key из конфигурации.
}
