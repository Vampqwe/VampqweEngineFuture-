<?php
declare(strict_types=1);

namespace App\Core\System;

use App\Core\System\Container\Container;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Http\Kernel;
use App\Core\System\Http\Request;
use App\Core\System\Support\EnvLoader;
use RuntimeException;

class App
{
    private ContainerInterface $container;
    private Kernel $kernel;

    public function __construct()
    {
        $this->container = new Container();
        $this->registerCoreServices();
        $this->registerModuleProviders();

        $this->kernel = new Kernel($this->container);
    }

    private function registerCoreServices(): void
    {
        $this->container->instance(ContainerInterface::class, $this->container);

        $this->container->instance('base_path', BASE_PATH);
        $this->container->instance('config_path', BASE_PATH . '/config');
        $this->container->instance('storage_path', BASE_PATH . '/storage');
        $this->container->instance('public_path', BASE_PATH . '/public');
        $this->container->instance('view_path', BASE_PATH . '/view');

        // Загружаем .env ДО чтения конфигов (config/app.php читает $_ENV)
        EnvLoader::load(BASE_PATH . '/.env');
    }

    /**
     * Единственный источник истины о модулях — config/modules.php.
     * Порядок регистрации задаётся порядком в манифесте.
     */
    private function registerModuleProviders(): void
    {
        $manifest = require BASE_PATH . '/config/modules.php';

        if (!is_array($manifest)) {
            throw new RuntimeException('Манифест модулей должен возвращать массив.');
        }

        foreach ($manifest as $name => $module) {
            $providerClass = $module['provider'] ?? null;
            $critical      = (bool) ($module['critical'] ?? false);

            if ($providerClass === null || !class_exists($providerClass)) {
                if ($critical) {
                    throw new RuntimeException(
                        "Критичный модуль [{$name}] не найден: провайдер {$providerClass} отсутствует."
                    );
                }
                // Некритичный ещё нереализованный модуль — просто пропускаем
                continue;
            }

            $provider = new $providerClass();
            $provider->register($this->container);
            $provider->boot();
        }
    }

    public function run(): void
    {
        // Часовой пояс и кодировка из конфигурации, а не из дефолтов PHP
        date_default_timezone_set(
            (string) $this->container
                ->get(\App\Core\Module\Config\Contracts\ConfigRepositoryInterface::class)
                ->get('app.timezone', 'UTC')
        );

        $request = Request::createFromGlobals();
        $response = $this->kernel->handle($request);
        $response->send();
    }
}
