<?php
declare(strict_types=1);

namespace App\Core\Module\Hook\Providers;

use App\Core\Module\Hook\Contracts\HookInterface;
use App\Core\Module\Hook\Hook;
use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Hook.
 *
 * Зависимости: нет (уровень 0, рядом с FileSystem/Parser/ArrayHelper).
 * Отдаёт: HookInterface — событийно-фильтровую шину движка.
 *
 * Регистрация ленивая (bind): синглтон создаётся при первом обращении.
 * Приёмник ошибок подвязывается к Logger'у только при первой фактической
 * ошибке слушателя — до этого Hook живёт полностью без внешних зависимостей,
 * поэтому порядок загрузки манифеста не нарушается даже если Logger ещё не boot'ан.
 */
class HookServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(HookInterface::class, static function (ContainerInterface $c): Hook {
            $hook = new Hook();

            // Ленивый мост к Logger: резолвим журнал только когда реально
            // что-то упало внутри слушателя (иначе Hook не зависит ни от чего).
            $hook->setErrorSink(static function (string $message, array $context) use ($c): void {
                if ($c->has(LoggerInterface::class)) {
                    /** @var LoggerInterface $logger */
                    $logger = $c->get(LoggerInterface::class);
                    $logger->error($message, $context + ['channel' => 'Hook']);
                }
            });

            return $hook;
        });
    }

    public function boot(): void
    {
    }
}
