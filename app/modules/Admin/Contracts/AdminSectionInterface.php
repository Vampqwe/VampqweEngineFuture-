<?php
declare(strict_types=1);

namespace App\modules\Admin\Contracts;

use App\Core\System\Http\Request;
use App\Core\System\Http\Response;

/**
 * Публичный контракт «раздела панели» (п.2/п.4 Конституции).
 *
 * Любой модуль движка может стать разделом админки, НЕ правя код самой
 * панели: реализация регистрируется в DI-контейнере, а панель собирает
 * все такие сервисы и строит меню + маршруты сама. Так Admin видит
 * Pages/Auth/Doctor без жёстких зависимостей от них, а будущие модули
 * (Users, Settings, ImportExport) подключаются нулём строк в панели —
 * чистая инверсия зависимостей.
 */
interface AdminSectionInterface
{
    /** Уникальный slug раздела: URL = /admin/{slug}. */
    public function slug(): string;

    /** Человекочитаемое имя для навигации панели. */
    public function title(): string;

    /** Иконка (emoji/SVG-строка) для меню. */
    public function icon(): string;

    /** Порядок в меню: меньше — выше. */
    public function order(): int;

    /** GET /admin/{slug} — главная страница раздела. */
    public function index(Request $request): Response;

    /**
     * Дополнительный маршрут раздела: путь относительно /admin/{slug}
     * ('' — это сам index). POST защищён глобальным CSRF (п.4).
     */
    public function handle(string $path, Request $request): ?Response;
}
