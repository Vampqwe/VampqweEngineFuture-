<?php
declare(strict_types=1);

namespace App\modules\Admin\Internal;

use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;
use App\modules\Admin\Contracts\AdminSectionInterface;
use App\modules\Pages\Contracts\PageRepositoryInterface;

/**
 * Раздел «Страницы»: CRUD контента поверх ПУБЛИЧНОГО контракта модуля
 * Pages (п.2 Конституции: панель видит только PageRepositoryInterface,
 * внутренности Pages ей недоступны и не нужны).
 *
 * Маршруты раздела (все относительно /admin/pages):
 *   GET  ''          — список
 *   GET  create      — форма создания
 *   POST create      — создание (slug нормализует репозиторий Pages)
 *   GET  edit/{id}   — форма правки
 *   POST save/{id}   — сохранение
 *   POST delete/{id} — удаление
 * CSRF на всех POST обеспечивает глобальный middleware ядра (п.4).
 */
final class PagesSection implements AdminSectionInterface
{
    public function __construct(
        private readonly ?PageRepositoryInterface $pages = null,
        private readonly ?TemplateInterface $views = null,
    ) {
    }

    public function slug(): string
    {
        return 'pages';
    }

    public function title(): string
    {
        return 'Страницы';
    }

    public function icon(): string
    {
        return '📄';
    }

    public function order(): int
    {
        return 10;
    }

    public function index(Request $request): Response
    {
        if ($this->pages === null) {
            return $this->unavailable();
        }

        $page = max(1, (int) ($request->get('page') ?? 1));
        $list = $this->pages->listPublished($page, 20);

        return $this->render('admin/pages/index', [
            'title' => 'Панель — Страницы',
            'pages' => $list,
            'page'  => $page,
        ]);
    }

    public function handle(string $path, Request $request): ?Response
    {
        if ($this->pages === null) {
            return $this->unavailable();
        }

        $isPost = $request->method === 'POST';

        return match (true) {
            $path === 'create' && !$isPost => $this->render('admin/pages/edit', [
                'title' => 'Панель — Новая страница', 'page' => null,
            ]),
            $path === 'create' && $isPost => $this->create($request),
            str_starts_with($path, 'edit/') => $this->editForm((int) substr($path, 5)),
            str_starts_with($path, 'save/') => $this->save((int) substr($path, 5), $request),
            str_starts_with($path, 'delete/') => $this->delete((int) substr($path, 7), $request),
            default => null,
        };
    }

    private function create(Request $request): Response
    {
        $title   = trim((string) ($request->post['title'] ?? ''));
        $content = (string) ($request->post['content'] ?? '');
        if ($title === '') {
            return $this->render('admin/pages/edit', [
                'title' => 'Панель — Новая страница', 'page' => null, 'error' => 'Заголовок обязателен',
            ], 422);
        }

        $id = $this->pages->create($title, $content, null, ($request->post['status'] ?? 'draft') === 'published');

        return new Response('', 302, ['Location' => '/admin/pages/edit/' . $id]);
    }

    private function editForm(int $id): ?Response
    {
        $rows = $this->pages->listPublished(1, 1000)['items'] ?? [];
        $page = null;
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                $page = $row;
                break;
            }
        }

        if ($page === null) {
            return new Response('<h1>404</h1><p>Страница не найдена.</p>', 404);
        }

        return $this->render('admin/pages/edit', ['title' => 'Панель — Правка страницы', 'page' => $page]);
    }

    private function save(int $id, Request $request): Response
    {
        $fields = array_filter([
            'title'   => trim((string) ($request->post['title'] ?? '')),
            'content' => (string) ($request->post['content'] ?? ''),
            'status'  => in_array($request->post['status'] ?? '', ['published', 'draft'], true)
                ? (string) $request->post['status'] : 'draft',
        ], static fn($v): bool => $v !== '');

        $this->pages->update($id, $fields);

        return new Response('', 302, ['Location' => '/admin/pages']);
    }

    private function delete(int $id, Request $request): Response
    {
        if ($request->method === 'POST') {
            $this->pages->delete($id);
        }

        return new Response('', 302, ['Location' => '/admin/pages']);
    }

    private function render(string $tpl, array $data, int $code = 200): Response
    {
        $html = $this->views?->renderCore($tpl, $data)
            ?? '<pre>' . htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '</pre>';

        return new Response($html, $code);
    }

    private function unavailable(): Response
    {
        return new Response('<h1>503</h1><p>Модуль Pages недоступен (движок ещё не установлен?).</p>', 503);
    }
}
