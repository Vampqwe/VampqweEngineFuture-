<?php
declare(strict_types=1);

namespace App\modules\Admin\Contracts;

use App\Core\System\Http\Request;
use App\Core\System\Http\Response;

/**
 * Публичная граница стража панели (п.2 Конституции).
 *
 * Ядро (Kernel) спрашивает наличие этого контракта и оборачивает им
 * всю дорогу /admin/* — деталей реализации (сессии, SuperAdmin-хеш)
 * ядро не знает (слабая связь, ср. AuthManagerInterface/PageController).
 */
interface GuardInterface
{
    /**
     * Допустить запрос к /admin/*. Возвращает ответ-заглушку
     * (редирект на вход / 403) либо null — «пропускаем дальше».
     */
    public function guard(Request $request): ?Response;
}
