<?php
declare(strict_types=1);

namespace App\Core\Module\Doctor;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Doctor\Checks\ModulePresenceCheck;
use App\Core\Module\Doctor\Checks\SecretLeakCheck;
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
            'Session'     => \App\Core\Module\Session\Contracts\SessionInterface::class,
            'Hook'        => \App\Core\Module\Hook\Contracts\HookInterface::class,
            'Migration'   => \App\Core\Module\Migration\Contracts\MigratorInterface::class,
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
        // Манифест читаем напрямую через PhpArrayParser (документированный бутстрап-слой):
        // ConfigRepository::all() возвращает содержимое config/*.php БЕЗ имени файла,
        // т.е. для 'modules' это уже внутренний массив модулей, а не ['modules' => [...]].
        $configFile = $this->container->get('config_path') . '/modules.php';
        if (is_file($configFile)) {
            $manifest = (new \App\Core\Module\Config\Internal\Parsers\PhpArrayParser())->parse($configFile);
            if (isset($manifest['modules']) && is_array($manifest['modules'])) {
                $manifest = $manifest['modules']; // совместимость со старым форматом
            }
            if ($manifest !== []) {
                $results = array_merge(
                    $results,
                    ModulePresenceCheck::run($this->container, $manifest, $this->services)
                );
            }
        }

        // 4) Секреты не утекли в git (п.8/п.9): .env в ignore, значения ключей
        // APP_KEY/DB_PASSWORD отсутствуют в индексе. Корень — из base_path (п.7).
        $results[] = SecretLeakCheck::run((string) $this->container->get('base_path'));

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
                case 'Hook':
                    /** @var \App\Core\Module\Hook\Contracts\HookInterface $instance */
                    $seen = [];
                    $instance->addAction('doctor.probe', static function (mixed ...$a) use (&$seen): mixed {
                        $seen[] = 'p20';
                        return ($a[0] ?? '') . '|second';
                    }, 20, 'doctor-p20');
                    $instance->addAction('doctor.probe', static function (mixed ...$a) use (&$seen): mixed {
                        $seen[] = 'p5';
                        return ($a[0] ?? '') . '|first';
                    }, 5, 'doctor-p5');
                    $filtered = $instance->applyFilters('doctor.probe', 'start');
                    $count    = $instance->listenerCount('doctor.probe');
                    $removed  = $instance->removeAction('doctor.probe', 'doctor-p5');
                    $clean    = $instance->listenerCount('doctor.probe') === 1 && $removed;
                    $instance->removeAll('doctor.probe');
                    $ok = $filtered === 'start|first|second' && $count === 2 && $clean
                        && $seen === ['p5', 'p20'] && !$instance->has('doctor.probe');
                    return [$ok, $ok ? 'работает (приоритеты + фильтры + снятие)' : 'НЕЗДОРОВ: цепочка нарушена'];
                case 'Security':
                    $tok  = strlen($instance->randomToken(8)) === 16;
                    $hash = $instance->hashPassword('doctor-probe-123');
                    $pwd  = $instance->verifyPassword('doctor-probe-123', $hash)
                        && !$instance->verifyPassword('wrong', $hash);
                    // encrypt/decrypt probe: требует APP_KEY (пишет инсталлятор)
                    $cryptoNote = 'csrf-генерация введена';
                    try {
                        $enc = $instance->encrypt('doctor-probe');
                        $pwd = $pwd && $instance->decrypt($enc) === 'doctor-probe'
                            && $instance->decrypt('tampered') === null;
                        $cryptoNote = 'AES-256-GCM работает';
                    } catch (\RuntimeException) {
                        $cryptoNote = 'encrypt ждёт APP_KEY (.env)';
                    }
                    return [$tok && $pwd, "работает (токены + argon2id + {$cryptoNote})"];
                case 'Session':
                    /** @var \App\Core\Module\Session\Contracts\SessionInterface $instance */
                    $token = $instance->csrfToken();
                    $ok = strlen($token) >= 32 && $instance->validateCsrf($token) && !$instance->validateCsrf('bad');
                    return [$ok, 'работает (driver: ' . ($_ENV['SESSION_DRIVER'] ?? 'file') . ', csrf)'];
                case 'Database':
                    /** @var \App\Core\Module\Database\Contracts\DatabaseInterface $instance */
                    $driver = $instance->driver();
                    if ($instance->ping()) {
                        return [true, "работает (PDO, драйвер: {$driver})"];
                    }
                    // Движок жив, но СУБД недоступна — это предупреждение, не «OK».
                    return [false, "драйвер '{$driver}': соединение недоступно (проверьте .env / запуск СУБД)"];
                case 'Migration':
                    /** @var \App\Core\Module\Migration\Contracts\MigratorInterface $instance */
                    $st = $instance->status();
                    if ($st['pending'] > 0) {
                        return [true, "ждут применения миграции: {$st['pending']} из {$st['total']}"];
                    }
                    return [true, "схема в актуальном состоянии ({$st['applied']} миграций)"];
                case 'Cache':
                    /** @var \App\Core\Module\Cache\Contracts\CacheInterface $instance */
                    $probeKey = 'doctor:cache:probe';
                    $instance->forget($probeKey);
                    $written = $instance->set($probeKey, ['ping' => 1], 30);
                    $hit     = $instance->get($probeKey);
                    $memo    = $instance->remember('doctor:cache:memo', 30, static fn(): int => 42);
                    $instance->forget('doctor:cache:memo');
                    $ok = $written && $hit === ['ping' => 1] && $memo === 42;
                    $driver = method_exists($instance, 'driver') ? $instance->driver() : '?';
                    return [$ok, $ok ? "работает (PDO-free, драйвер: {$driver})" : 'НЕЗДОРОВ: set/get roundtrip не пройден'];
                case 'Queue':
                    /** @var \App\Core\Module\Queue\Contracts\QueueInterface $instance */
                    $driver = $instance->driver();
                    $size   = $instance->size();
                    return [true, "работает (драйвер: {$driver}, задач в очереди: {$size})"];
                case 'Template':
                    /** @var \App\Core\Module\Template\Contracts\TemplateInterface $instance */
                    // Реальная проба: рендер строкового шаблона с экранированием.
                    $out = $instance->renderString('{{$v}}', ['v' => '<b>']);
                    if ($out !== '&lt;b&gt;') {
                        return [false, 'НЕЗДОРОВ: renderString не экранирует вывод (XSS-риск)'];
                    }
                    $coreTpl = $instance->exists('layouts/base', \App\Core\Module\Template\Contracts\TemplateInterface::SCOPE_CORE);
                    return [true, 'работает (рендер+экранирование OK, layout ядра: ' . ($coreTpl ? 'есть' : 'нет') . ')'];
                case 'Theme':
                    /** @var \App\Core\Module\Theme\Contracts\ThemeInterface $instance */
                    $themes   = $instance->available();
                    $current  = $instance->current();
                    $hasDef   = in_array(\App\Core\Module\Theme\Theme::DEFAULT_THEME, $themes, true);
                    $sites    = count($instance->sites());
                    if (!$hasDef || !in_array($current, $themes, true)) {
                        return [false, "НЕЗДОРОВ: тема по умолчанию '_default' или текущая '{$current}' не найдена в view/app/"];
                    }
                    return [true, 'работает (тем: ' . count($themes)
                        . '[' . implode(',', $themes) . '], сайтов в реестре: ' . $sites . ')'];
                case 'Installer':
                    // Реальная проба: requirements() обязан вернуть полные проверки.
                    $req = $instance->requirements();
                    if (!is_array($req) || count($req) < 4) {
                        return [false, 'НЕЗДОРОВ: requirements() вернул пустой набор проверок'];
                    }
                    return [true, 'работает (requirements: ' . count($req) . ' проверок'
                        . ($instance->isInstalled() ? ', установлен' : ', не установлен') . ')'];
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
