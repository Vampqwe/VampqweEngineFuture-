<?php
declare(strict_types=1);

namespace App\Core\Module\Doctor\Checks;

/**
 * Внутренняя проверка Doctor: каждый модуль из манифеста зарегистрирован в контейнере.
 * ЗАПРЕЩЕНО использовать напрямую извне модуля (п.2 Конституции).
 */
final class ModulePresenceCheck
{
    // TODO: сверять config('modules') с container->has(Interface::class).
}
