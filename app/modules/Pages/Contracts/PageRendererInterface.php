<?php
declare(strict_types=1);

namespace App\modules\Pages\Contracts;

use App\Core\System\Http\Request;
use App\Core\System\Http\Response;

/**
 * Публичный HTTP-граница модуля Pages: рендер страницы по ЧПУ-слагу.
 *
 * Ядро (Kernel) подключает дорогу /{slug} ТОЛЬКО через этот контракт
 * (п.1/п.2 Конституции): ни PageController, ни репозиторий модуля ядру
 * не известны. Удаление модуля Pages = дорога молча исчезает, ядро живёт.
 *
 * Биндится PagesServiceProvider на тот же синглтон, что и PageController
 * (внутренняя кухня модуля остаётся внутренней).
 */
interface PageRendererInterface
{
    /** GET /{slug} — опубликованная страница или честная 404 без исключений. */
    public function show(Request $request): Response;
}
