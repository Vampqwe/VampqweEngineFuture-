<?php
declare(strict_types=1);

namespace App\Core\System;

use App\Core\System\Container\Container;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Http\Kernel;
use App\Core\System\Http\Request;
use App\Core\Module\FileSystem\Providers\FileSystemServiceProvider;
use App\Core\Module\Logger\Providers\LoggerServiceProvider;
use App\Core\Module\Config\Providers\ConfigServiceProvider;
use App\Core\Module\Exception\Providers\ExceptionServiceProvider;

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
    }

    private function registerModuleProviders(): void
    {
        $providers = [
            FileSystemServiceProvider::class,
            LoggerServiceProvider::class,
            ConfigServiceProvider::class,
            ExceptionServiceProvider::class,
        ];

        foreach ($providers as $providerClass) {
            $provider = new $providerClass();
            $provider->register($this->container);
            $provider->boot();
        }
    }

    public function run(): void
    {
        $request = Request::createFromGlobals();
        $response = $this->kernel->handle($request);
        $response->send();
    }
}