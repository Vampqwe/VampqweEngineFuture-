<?php
declare(strict_types=1);

namespace App\modules\Admin\Internal;

use App\Core\Module\Doctor\Contracts\DoctorInterface;
use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;
use App\modules\Admin\Contracts\AdminSectionInterface;

/**
 * Раздел «Система»: самодиагностика Doctor прямо в панели (п.9 Конституции).
 *
 * Панель спрашивает только контракт DoctorInterface — проверки и их вывод
 * живут в модуле Doctor. Здесь — аккуратный HTML-представление вердикта.
 */
final class SystemSection implements AdminSectionInterface
{
    public function __construct(
        private readonly ?DoctorInterface $doctor = null,
        private readonly ?TemplateInterface $views = null,
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
        ]);
    }

    public function handle(string $path, Request $request): ?Response
    {
        return $path === '' ? $this->index($request) : null;
    }

    private function page(string $tpl, array $data): Response
    {
        $html = $this->views?->renderCore($tpl, $data)
            ?? ('<h1>Система</h1><p>' . nl2br(htmlspecialchars($data['verdict'] ?? '', ENT_QUOTES)) . '</p>');

        return new Response($html);
    }
}
