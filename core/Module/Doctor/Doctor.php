<?php
declare(strict_types=1);

namespace App\Core\Module\Doctor;

use App\Core\Module\Doctor\Contracts\DoctorInterface;
use App\Core\System\Container\Contracts\ContainerInterface;

/**
 * Фасад модуля Doctor.
 *
 * Зависимости: Container (проверка регистрации сервисов), Config (манифест модулей),
 *              FileSystem (права на storage), Database::ping(), Queue::size() — в плане.
 * Минимальная рабочая версия: проверяет ключевые контракты ядра.
 */
final class Doctor implements DoctorInterface
{
    /** @param array<string,string> $services имя => FQN контракта */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $services = [
            'FileSystem'  => \App\Core\Module\FileSystem\Contracts\FileSystemInterface::class,
            'Config'      => \App\Core\Module\Config\Contracts\ConfigRepositoryInterface::class,
            'Logger'      => \App\Core\Module\Logger\Contracts\LoggerInterface::class,
            'Exception'   => \App\Core\Module\Exception\Contracts\ExceptionHandlerInterface::class,
            'Parser'      => \App\Core\Module\Parser\Contracts\ParserInterface::class,
            'TimeDate'    => \App\Core\Module\TimeDate\Contracts\TimeDateInterface::class,
            'Cache'       => \App\Core\Module\Cache\Contracts\CacheInterface::class,
            'Database'    => \App\Core\Module\Database\Contracts\DatabaseInterface::class,
            'Queue'       => \App\Core\Module\Queue\Contracts\QueueInterface::class,
            'Security'    => \App\Core\Module\Security\Contracts\SecurityInterface::class,
            'Template'    => \App\Core\Module\Template\Contracts\TemplateInterface::class,
            'Error'       => \App\Core\Module\Error\Contracts\ErrorPageInterface::class,
            'Installer'   => \App\Core\Module\Installer\Contracts\InstallerInterface::class,
        ],
    ) {
    }

    public function check(): array
    {
        $results = [];
        foreach ($this->services as $name => $contract) {
            $ok = $this->container->has($contract);
            $results[] = [
                'name'    => $name,
                'ok'      => $ok,
                'message' => $ok ? 'модуль зарегистрирован' : 'модуль отсутствует',
            ];
        }
        return $results;
    }

    public function isHealthy(): bool
    {
        foreach ($this->check() as $item) {
            if (!$item['ok']) {
                return false;
            }
        }
        return true;
    }

    public function verdict(): string
    {
        $lines = [];
        foreach ($this->check() as $item) {
            $lines[] = sprintf('[%s] %-10s %s', $item['ok'] ? 'OK' : '--', $item['name'], $item['message']);
        }
        return implode("\n", $lines);
    }
}
