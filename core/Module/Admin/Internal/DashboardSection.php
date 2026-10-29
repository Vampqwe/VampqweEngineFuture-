<?php
declare(strict_types=1);

namespace App\Core\Module\Admin\Internal;

use App\Core\Container\ContainerInterface;
use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\Module\Theme\Contracts\ThemeInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;
use App\Core\System\Support\SiteIdentity;
use App\Core\Module\Admin\Contracts\AdminSectionInterface;

/**
 * Раздел «Дашборд» — главная панели /admin (внутренняя кухня, п.2).
 *
 * Показывает сводку по всем зарегистрированным разделам, идентичность
 * ЭТОГО экземпляра движка (BASE_URL_SITE/SITE_DOMAIN, концепция «один
 * запуск = один сайт») и счётчики из таблиц движка. Никаких прямых
 * импортов Pages/Auth: данные берутся через контракты ядра
 * (DatabaseInterface, ThemeInterface) и реестр разделов контейнера —
 * панель не зависит от реализаций (п.3 Конституции).
 */
final class DashboardSection implements AdminSectionInterface
{
    public function __construct(
        private readonly ?TemplateInterface $views = null,
        private readonly ?DatabaseInterface $db = null,
        private readonly ?ContainerInterface $container = null,
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
            'site'     => $this->siteSummary(),
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

    /**
     * Идентичность ЭТОГО экземпляра движка (концепция «один запуск = один
     * сайт»): домен из SITE_DOMAIN/BASE_URL_SITE, активная тема и бейдж
     * «этот экземпляр» в реестре engine_sites. Всё опционально: при
     * недоступных модулях возвращаются пустые значения — панель не падает
     * (честная деградация, п.9).
     *
     * @return array{domain:string,theme:string,sites:array<int,array{domain:string,theme:string,isCurrent:bool}>}
     */
    private function siteSummary(): array
    {
        $container = $this->container;
        if ($container === null) {
            return ['domain' => '', 'theme' => '', 'sites' => []];
        }

        // Домен этого экземпляра — та же логика, что у ядра (ModuleBridge),
        // переиспользуем мост вместо дублирования формулы (п.10 DRY).
        $bridge = new \App\Core\System\Support\ModuleBridge($container);
        $domain = $bridge->siteDomain();

        $theme = '';
        $sites = [];
        $themeModule = $container->has(ThemeInterface::class) ? $container->get(ThemeInterface::class) : null;
        if ($themeModule !== null) {
            $theme = $themeModule->current();
            foreach ($themeModule->sites() as $row) {
                $rowDomain = strtolower(trim((string) ($row['domain'] ?? '')));
                $sites[] = [
                    'domain'    => $rowDomain,
                    'theme'     => (string) ($row['theme'] ?? ''),
                    // бейдж «этот экземпляр»: реестр хранит домены, сверяем
                    // через нормализацию SiteIdentity (порт/регистр, п.7)
                    'isCurrent' => $domain !== '' && $rowDomain !== ''
                        && SiteIdentity::normalizeHost($rowDomain) === $domain,
                ];
            }
        }

        return ['domain' => $domain, 'theme' => $theme, 'sites' => $sites];
    }
}
