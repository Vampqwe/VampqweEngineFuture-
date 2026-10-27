<?php
declare(strict_types=1);

namespace App\Core\Module\Installer\Internal\Steps;

/**
 * Внутренний шаг установщика: копирование .env.example -> .env.
 * ЗАПРЕЩЕНО извне модуля (п.2). Реализация — в плане.
 */
final class CreateEnvStep
{
    // TODO: реализовать поверх FileSystemInterface.
}
