<?php
declare(strict_types=1);

namespace App\Core\Module\Doctor;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Doctor\Checks\ModulePresenceCheck;
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
            'ArrayHelper' => \App\Core\Module\ArrayHelper\Contracts\ArrayHelperInterface::class,
        ],
    ) {
    }

    public function check(): array
    {
        $results = [];

        // 1) Пути: единая точка правды Paths::MAP обязана быть зарегистрирована
        // в контейнере как <ключ>_path — иначе рассинхрон (п.7 Конституции).
        foreach (\App\Core\System\Support\Paths::keys() as $key) {
            $registered = $this->container->has($key . '_path');
            $results[] = [
                'name'    => 'Путь:' . $key,
                'ok'      => $registered,
                'message' => $registered
                    ? (string) $this->container->get($key . '_path')
                    : 'путь не зарегистрирован в контейнере (рассинхрон с Paths::MAP)',
            ];
        }

        // 2) Модули: контракт зарегистрирован И реализуем (иначе Doctor даёт ложное «OK»).
        foreach ($this->services as $name => $contract) {
            if (!$this->container->has($contract)) {
                $results[] = ['name' => $name, 'ok' => false, 'message' => 'модуль отсутствует'];
                continue;
            }

            try {
                $instance = $this->container->get($contract);
            } catch (\Throwable $e) {
                $results[] = ['name' => $name, 'ok' => false, 'message' => 'не резолвится: ' . $e->getMessage()];
                continue;
            }

            $probe = $this->probe($name, $instance);
            $results[] = ['name' => $name, 'ok' => $probe[0], 'message' => $probe[1]];
        }

        // 3) Сверка манифеста и контейнера (внутренняя проверка Doctor).
        if ($this->container->has(ConfigRepositoryInterface::class)) {
            $manifest = $this->container->get(ConfigRepositoryInterface::class)->all('modules');
            if ($manifest !== []) {
                $results = array_merge(
                    $results,
                    ModulePresenceCheck::run($this->container, $manifest, $this->services)
                );
            }
        }

        return $results;
    }

    /**
     * Проба «здоровья»: реально ли модуль работает или это заглушка «в плане».
     * Возвращает [ok, сообщение]. Заглушки считаются НЕ критической проблемой (ok=true),
     * но честно помечаются как план — движок на них не падает.
     *
     * @param object $instance
     * @return array{0:bool,1:string}
     */
    private function probe(string $name, object $instance): array
    {
        // stateless-пробы по контракту
        try {
            switch ($name) {
                case 'Parser':
                    /** @var \App\Core\Module\Parser\Contracts\ParserInterface $instance */
                    $json = $instance->encode(['ok' => true], 'json');
                    return [$instance->parse($json, 'json') === ['ok' => true], 'работает (json roundtrip)'];
                case 'TimeDate':
                    return [$instance->now() instanceof \DateTimeImmutable, 'работает (tz: ' . $instance->timezone() . ')'];
                case 'ArrayHelper':
                    $v = $instance->get(['a' => ['b' => 1]], 'a.b');
                    return [$v === 1, 'работает (точечная нотация)'];
                case 'Security':
                    return [strlen($instance->randomToken(8)) === 16, 'частично (токены работают, crypto в плане)'];
                case 'Database':
                    return [true, 'заглушка (реализация в плане; ping=false)'];
                case 'Cache':
                    return [true, 'заглушка (реализация в плане; remember деградирует безопасно)'];
                case 'Queue':
                    return [true, 'заглушка (реализация в плане; size=0)'];
                case 'Template':
                    return [true, 'заглушка (реализация в плане; шаблонов нет)'];
                case 'Installer':
                    return [true, 'заглушка (requirements() работает, install() в плане)'];
                default:
                    return [true, 'модуль зарегистрирован и резолвится'];
            }
        } catch (\Throwable $e) {
            return [false, 'НЕЗДОРОВ: ' . $e->getMessage()];
        }
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
