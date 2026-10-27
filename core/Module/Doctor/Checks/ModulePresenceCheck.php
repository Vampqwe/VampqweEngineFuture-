<?php
declare(strict_types=1);

namespace App\Core\Module\Doctor\Checks;

use App\Core\System\Container\Contracts\ContainerInterface;

/**
 * Внутренняя проверка Doctor: каждый модуль из манифеста зарегистрирован в контейнере.
 * ЗАПРЕЩЕНО использовать напрямую извне модуля (п.2 Конституции).
 * Используется Doctor::check() как дополнительный сверочный слой:
 * манифест config/modules.php <=> зарегистрированные контракты.
 */
final class ModulePresenceCheck
{
    /**
     * @param array<string,array{provider?:string,critical?:bool}> $manifest
     * @param array<string,string> $services имя => FQN контракта
     * @return list<array{name:string,ok:bool,message:string}>
     */
    public static function run(ContainerInterface $container, array $manifest, array $services): array
    {
        $results = [];

        foreach ($services as $name => $contract) {
            $inManifest  = isset($manifest[$name]);
            $registered  = $container->has($contract);

            $ok      = $inManifest && $registered;
            $message = match (true) {
                !$inManifest && !$registered => 'отсутствует и в манифесте, и в контейнере',
                !$inManifest                 => 'зарегистрирован, но НЕ объявлен в манифесте (рассинхрон)',
                !$registered                 => 'объявлен в манифесте, но НЕ зарегистрирован в контейнере',
                default                      => 'манифест + контейнер согласованы',
            };

            $results[] = ['name' => 'Сверка:' . $name, 'ok' => $ok, 'message' => $message];
        }

        return $results;
    }
}
