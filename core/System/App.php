<?php
declare(strict_types=1);

namespace App\Core\System;

use App\Core\System\Container\Container;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Http\Kernel;
use App\Core\System\Http\Request;
use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\System\Support\EnvLoader;
use App\Core\System\Support\Paths;
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

        // Единая точка правды о путях (п.7 Конституции): все пути объявлены
        // только в Support\Paths::MAP и регистрируются в контейнер оттуда.
        // Потребители получают их по ключам ('config_path', 'logs_path', ...),
        // никаких конкатенаций BASE_PATH . '/...' в ядре и модулях быть не должно.
        $paths = new Paths(BASE_PATH);
        foreach ($paths->all() as $key => $absolute) {
            if ($key === 'base') {
                continue; // base_path уже зарегистрирован выше
            }
            $this->container->instance($key . '_path', $absolute);
        }
        unset($absolute);

        // Загружаем .env ДО чтения конфигов (config/app.php читает $_ENV)
        EnvLoader::load($paths->get('base') . '/.env');
    }

    /**
     * Единственный источник истины о модулях — манифест config/modules.php.
     * Физический путь берём из контейнера ('config_path' — единая точка правды Paths),
     * а не хардкодом BASE_PATH . '/...'. Порядок регистрации задаётся порядком в манифесте.
     */
    private function registerModuleProviders(): void
    {
        $manifestPath = $this->container->get('config_path') . '/modules.php';

        if (!is_file($manifestPath)) {
            throw new RuntimeException("Манифест модулей не найден: {$manifestPath}");
        }

        $manifest = require $manifestPath;

        if (!is_array($manifest)) {
            throw new RuntimeException('Манифест модулей должен возвращать массив.');
        }

        // Служебные ключи манифеста не являются модулями.
        unset($manifest['path']);

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
                ->get(ConfigRepositoryInterface::class)
                ->get('app.timezone', 'UTC')
        );

        $request = Request::createFromGlobals();
        $response = $this->kernel->handle($request);
        $response->send();
    }
}
