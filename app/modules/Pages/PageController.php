<?php
declare(strict_types=1);

namespace App\modules\Pages;

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Error\Contracts\ErrorPageInterface;
use App\Core\Module\Template\Contracts\TemplateInterface;
use App\modules\Pages\Contracts\PageRendererInterface;
use App\modules\Pages\Contracts\PageRepositoryInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;

/**
 * HTTP-контроллер модуля Pages (внутренняя кухня, п.2 Конституции).
 *
 * Отдаёт страницу по ЧПУ-слагу через тему сайта (Template SCOPE_APP):
 *   /{slug} -> view/app/<theme>/pages/show.phtml (fallback на '_default').
 *
 * Честная деградация (принцип «движок не падает»): если Database или
 * Template недоступны (до установки/выключенный модуль) — 404 как при
 * отсутствующей странице, без исключений наружу.
 */
final class PageController implements PageRendererInterface
{
    public function __construct(
        private readonly ?PageRepositoryInterface $pages,
        private readonly ?TemplateInterface $views,
        private readonly ?DatabaseInterface $db,
        private readonly ?ErrorPageInterface $errorPages = null,
    ) {
    }

    /** GET /{slug} — показать опубликованную страницу. */
    public function show(Request $request): Response
    {
        $slug = trim((string) ($request->routeParams['slug'] ?? ''), '/');

        if ($this->pages === null || $this->views === null) {
            return $this->notFound();
        }

        $page = $this->pages->findBySlug($slug);
        if ($page === null) {
            return $this->notFound();
        }

        // Счётчик просмотров — best effort: сбой инкремента не должен ломать показ.
        try {
            $this->db?->run('UPDATE pages SET views_count = views_count + 1 WHERE id = ?', [(int) $page['id']]);
        } catch (\Throwable) {
            // молча продолжаем: статистика важнее, чем 500 на глазах у читателя
        }

        try {
            $body = $this->views->render('pages/show', ['page' => $page]);
        } catch (\Throwable) {
            // нет шаблона в теме — простейший fallback-рендер, движок не падает
            $body = '<h1>' . htmlspecialchars((string) $page['title'], ENT_QUOTES) . '</h1>'
                  . (string) $page['content'];
        }

        return new Response($body, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function notFound(): Response
    {
        // Страницу 404 формирует модуль Error (своя тема страницы из БД + core-шаблон).
        if ($this->errorPages !== null) {
            return new Response($this->errorPages->render(404), 404);
        }

        return new Response('', 404);
    }
}
