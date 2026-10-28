<?php
declare(strict_types=1);

namespace App\Core\Module\Admin\Internal;

use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;
use App\Core\Module\Admin\Contracts\AdminSectionInterface;

/**
 * Раздел «Пользователи»: список аккаунтов и управление ролями.
 *
 * Работает через внутренний UserRepository панели (та же таблица users,
 * что у модуля Auth — единый источник истины, п.7). Создание аккаунтов и
 * сброс паролей — зона ответственности Auth/CLI, панель их намеренно не
 * дублирует (единая точка правды). Смена роли защищена whitelist'ом.
 */
final class UsersSection implements AdminSectionInterface
{
    public function __construct(
        private readonly ?UserRepository $users = null,
        private readonly ?TemplateInterface $views = null,
    ) {
    }

    public function slug(): string
    {
        return 'users';
    }

    public function title(): string
    {
        return 'Пользователи';
    }

    public function icon(): string
    {
        return '👥';
    }

    public function order(): int
    {
        return 20;
    }

    public function index(Request $request): Response
    {
        if ($this->users === null) {
            return new Response('<h1>503</h1><p>Модуль Database недоступен.</p>', 503);
        }

        return $this->page('admin/users/index', [
            'title'    => 'Панель — Пользователи',
            'users'    => $this->users->all(),
            'total'    => $this->users->countAll(),
        ]);
    }

    public function handle(string $path, Request $request): ?Response
    {
        if ($this->users === null) {
            return new Response('<h1>503</h1><p>Модуль Database недоступен.</p>', 503);
        }

        // POST /admin/users/role/{id} — смена роли (CSRF уже проверен ядром).
        if (str_starts_with($path, 'role/') && $request->method === 'POST') {
            $id   = (int) substr($path, 5);
            $role = (string) ($request->post['role'] ?? '');
            $this->users->setRole($id, $role);

            return new Response('', 302, ['Location' => '/admin/users']);
        }

        return null;
    }

    private function page(string $tpl, array $data): Response
    {
        $html = $this->views?->renderCore($tpl, $data)
            ?? '<pre>' . htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '</pre>';

        return new Response($html);
    }
}
