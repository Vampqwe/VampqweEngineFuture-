<?php
declare(strict_types=1);

namespace App\Core\System\Http;

use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Enums\AppState;
use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;

class Kernel
{
    public function __construct(
        private readonly ContainerInterface $container
    ) {}

    public function handle(Request $request): Response
    {
        try {
            $state = $this->detectAppState();
            
            $config = $this->container->get(ConfigRepositoryInterface::class);
            $appName = $config->get('app.name', 'Unknown Engine');
            $debug = $config->get('app.debug', false) ? 'ON' : 'OFF';

            return match($state) {
                AppState::NotInstalled => new Response(
                    "🚀 <b>{$appName}</b> (Debug: {$debug})<br>Движок не установлен. Запускаем Installer...", 
                    501
                ),
                AppState::Installing   => new Response('⚙️ Идет процесс установки...', 503),
                AppState::Maintenance  => new Response('🔧 Сайт находится в режиме технического обслуживания.', 503),
                AppState::Running      => new Response('✅ <b>VampqweEngineFuture успешно запущен!</b>', 200),
                AppState::Error        => new Response('❌ Критическая ошибка приложения.', 500),
            };
        } catch (\Throwable $e) {
            return new Response(' <b>Ошибка:</b> ' . htmlspecialchars($e->getMessage()), 500);
        }
    }

    private function detectAppState(): AppState
    {
        $storagePath = $this->container->get('storage_path');

        if (!file_exists($storagePath . '/.installed')) return AppState::NotInstalled;
        if (file_exists($storagePath . '/.installing')) return AppState::Installing;
        if (file_exists($storagePath . '/.maintenance')) return AppState::Maintenance;

        return AppState::Running;
    }
}