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

        // Хук «движок собран»: подписчики (будущие Auth/Pages/модули сайта)
        // могут регистрировать свои слушатели/маршруты в момент старта,
        // не трогая ядро. Dispatch защищён: сбой подписчика не валит boot.
        if ($this->container->has(\App\Core\Module\Hook\Contracts\HookInterface::class)) {
            try {
                /** @var \App\Core\Module\Hook\Contracts\HookInterface $hooks */
                $hooks = $this->container->get(\App\Core\Module\Hook\Contracts\HookInterface::class);
                $hooks->dispatch('engine.booted', $this->container);
            } catch (\Throwable) {
                // Hook сам гасит ошибки слушателей; сюда попасть не должен —
                // но молча продолжаем, чтобы boot не зависел от чужих подписок.
            }
        }
    }

    private function registerCoreServices(): void
    {
        $this->container->instance(ContainerInterface::class, $this->container);

        // Единая точка правды о путях (п.7 Конституции): все пути объявлены
        // только в Support\Paths::MAP и регистрируются в контейнер оттуда.
        // Потребители получают их по ключам ('config_path', 'logs_path', ...),
        // никаких конкатенаций BASE_PATH . '/...' в ядре и модулях быть не должно.
        // Ключ 'base' даёт base_path — единственный путь, из которого потом
        // собираются служебные файлы (.env, маркеры состояния).
        $paths = new Paths(BASE_PATH);
        foreach ($paths->all() as $key => $absolute) {
            $this->container->instance($key . '_path', $absolute);
        }
        unset($absolute);

        // Загружаем .env ДО чтения конфигов (config/app.php читает $_ENV)
        EnvLoader::load($paths->get('base') . '/.env');

        // putenv() из загрузчика не обновляет суперглобальный $_ENV — синхронизируем,
        // иначе конфиги, прочитанные позже, увидят только переменные реального окружения.
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && getenv($key) !== false && !isset($_ENV[$key])) {
                $_ENV[$key] = (string) $value;
            }
        }
        unset($key, $value);
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
