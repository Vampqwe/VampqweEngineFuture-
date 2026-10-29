<?php
declare(strict_types=1);

namespace App\Core\Module\Admin\Internal;

use App\Core\Container\ContainerInterface;
use App\Core\Module\Doctor\Contracts\DoctorInterface;
use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\Module\Theme\Contracts\ThemeInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;
use App\Core\System\Support\SiteIdentity;
use App\Core\System\Support\ModuleBridge;
use App\Core\Module\Admin\Contracts\AdminSectionInterface;

/**
 * Раздел «Система»: самодиагностика Doctor прямо в панели (п.9 Конституции)
 * + реестр сайтов движка с бейджем «этот экземпляр» (идентичность из
 * SITE_DOMAIN/BASE_URL_SITE, концепция «один запуск = один сайт»).
 *
 * Панель спрашивает только контракты DoctorInterface/ThemeInterface —
 * проверки и вывод живут в модулях. Здесь — аккуратное HTML-представление.
 */
final class SystemSection implements AdminSectionInterface
{
    public function __construct(
        private readonly ?DoctorInterface $doctor = null,
        private readonly ?TemplateInterface $views = null,
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    public function slug(): string
    {
        return 'system';
    }

    public function title(): string
    {
        return 'Система';
    }

    public function icon(): string
    {
        return '🩺';
    }

    public function order(): int
    {
        return 90;
    }

    public function index(Request $request): Response
    {
        if ($this->doctor === null) {
            return new Response('<h1>503</h1><p>Модуль Doctor недоступен.</p>', 503);
        }

        $report = $this->doctor->check();

        return $this->page('admin/system/index', [
            'title'     => 'Панель — Система',
            'healthy'   => $this->doctor->isHealthy(),
            'verdict'   => $this->doctor->verdict(),
            'checks'    => $report,
            'site'      => $this->siteSummary(),
        ]);
    }

    public function handle(string $path, Request $request): ?Response
    {
        return $path === '' ? $this->index($request) : null;
    }

    /**
     * Реестр сайтов (engine_sites через ThemeInterface) + идентичность этого
     * экземпляра. Опционально: при недоступных модулях — пустые значения,
     * раздел не падает (честная деградация, п.9).
     *
     * @return array{domain:string,sites:array<int,array{domain:string,theme:string,isCurrent:bool}>}
     */
    private function siteSummary(): array
    {
        if ($this->container === null) {
            return ['domain' => '', 'sites' => []];
        }

        // Та же формула домена, что у ядра — через мост, без дублирования (п.10).
        $bridge = new ModuleBridge($this->container);
        $domain = $bridge->siteDomain();

        $sites  = [];
        $themes = $this->container->has(ThemeInterface::class)
            ? $this->container->get(ThemeInterface::class)
            : null;
        if ($themes !== null) {
            foreach ($themes->sites() as $row) {
                $rowDomain = strtolower(trim((string) ($row['domain'] ?? '')));
                $sites[] = [
                    'domain'    => $rowDomain,
                    'theme'     => (string) ($row['theme'] ?? ''),
                    'isCurrent' => $domain !== '' && $rowDomain !== ''
                        && SiteIdentity::normalizeHost($rowDomain) === $domain,
                ];
            }
        }

        return ['domain' => $domain, 'sites' => $sites];
    }

    private function page(string $tpl, array $data): Response
    {
        $html = $this->views?->renderCore($tpl, $data)
            ?? ('<h1>Система</h1><p>' . nl2br(htmlspecialchars($data['verdict'] ?? '', ENT_QUOTES)) . '</p>');

        return new Response($html);
    }
}
