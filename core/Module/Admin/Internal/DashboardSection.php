<?php
declare(strict_types=1);

namespace App\Core\Module\Admin\Internal;

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;
use App\Core\Module\Admin\Contracts\AdminSectionInterface;

/**
 * Раздел «Дашборд» — главная панели /admin (внутренняя кухня, п.2).
 *
 * Показывает сводку по всем зарегистрированным разделам и счётчики из
 * таблиц движка. Никаких прямых импортов Pages/Auth: данные берутся через
 * контракты ядра (DatabaseInterface) и реестр разделов контейнера — панель
 * не зависит от реализаций (п.3 Конституции).
 */
final class DashboardSection implements AdminSectionInterface
{
    public function __construct(
        private readonly ?TemplateInterface $views = null,
        private readonly ?DatabaseInterface $db = null,
    ) {
    }

    public function slug(): string
    {
        return ''; // корень панели: /admin
    }

    public function title(): string
    {
        return 'Дашборд';
    }

    public function icon(): string
    {
        return '📊';
    }

    public function order(): int
    {
        return 0;
    }

    public function index(Request $request): Response
    {
        $stats = [];
        if ($this->db !== null) {
            foreach (['pages' => 'Страниц', 'users' => 'Пользователей', 'redirects' => 'Редиректов'] as $table => $label) {
                if ($this->db->tableExists($table)) {
                    $row   = $this->db->selectOne('SELECT COUNT(*) AS c FROM `' . $table . '`');
                    $stats[] = ['label' => $label, 'value' => (int) ($row['c'] ?? 0)];
                }
            }
        }

        $html = $this->views?->renderCore('admin/dashboard', [
            'title'    => 'Панель — Дашборд',
            'section'  => $this,
            'stats'    => $stats, // меню layout строит из shared-реестра adminNav
        ]) ?? ('<h1>Дашборд</h1><ul>'
            . implode('', array_map(
                static fn(array $s): string => '<li>' . htmlspecialchars($s['label'], ENT_QUOTES)
                    . ': <b>' . $s['value'] . '</b></li>',
                $stats
            ))
            . '</ul>');

        return new Response($html);
    }

    public function handle(string $path, Request $request): ?Response
    {
        return $path === '' ? $this->index($request) : null;
    }
}
