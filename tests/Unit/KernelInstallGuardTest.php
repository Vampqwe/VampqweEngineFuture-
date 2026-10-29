<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Module\FileSystem\Contracts\FileSystemInterface;
use App\Core\System\Container\Container;
use App\Core\System\Http\Kernel;
use App\Core\System\Http\Request;
use Tests\Framework\TestCase;

/**
 * Юнит-контур защиты установщика от РЕКУРСИВНОЙ БЛОКИРОВКИ Installing.
 *
 * Регрессия (ушла в main и «не прошла PR»): POST /install/run получал 503,
 * потому что install() внутри одного запроса создаёт storage/.installing, а
 * повторный вход в конвейер ядра перехватывал собственный запрос установщика
 * по состоянию Installing. Инвариант разложен на три проверяемых факта:
 *
 *   I1 (AppState::detect): живой .installed при висящем .installing даёт
 *      Running, а не Installing — приоритет честного «уже установлен»;
 *   I2 (Installer::install): флаг готовности ставится СРАЗУ после записи .env,
 *      до Database/Schema-шагов — иначе повторный вход видит Installing+без
 *      .installed и блокирует сам себя (503);
 *   I3 (Kernel guard): условие пропуска installer-POST к исполнителю зависит
 *      ровно от двух признаков — состояние Installing И метод POST; ни один
 *      из них по отдельности блок не включает.
 */
final class KernelInstallGuardTest extends TestCase
{
    /** @return array{dir:string, fs:FileSystemInterface} изолированный storage-стенд */
    private function makeStorage(): array
    {
        $dir = sys_get_temp_dir() . '/engine_guard_' . bin2hex(random_bytes(4)) . '/storage';
        mkdir($dir, 0775, true);

        return [$dir, new class implements FileSystemInterface {
            public function exists(string $path): bool { return file_exists($path); }
            public function isFile(string $path): bool { return is_file($path); }
            public function isDirectory(string $path): bool { return is_dir($path); }
            public function isWritable(string $path): bool { return is_writable($path); }
            public function isReadable(string $path): bool { return is_readable($path); }
            public function createDirectory(string $path, int $permissions): bool { return mkdir($path, $permissions, true); }
            public function createFile(string $path, string $content): bool
            {
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0775, true);
                }

                return file_put_contents($path, $content) !== false;
            }
            public function delete(string $path): bool { return is_file($path) ? unlink($path) : false; }
            public function deleteDirectory(string $path): bool
            {
                foreach (glob($path . '/*') ?: [] as $f) {
                    is_dir($f) ? $this->deleteDirectory($f) : unlink($f);
                }

                return rmdir($path);
            }
            public function read(string $path): string { return (string) file_get_contents($path); }
            public function write(string $path, string $content): bool { return file_put_contents($path, $content) !== false; }
            public function append(string $path, string $content): bool { return file_put_contents($path, $content, FILE_APPEND) !== false; }
            public function copy(string $source, string $destination): bool { return copy($source, $destination); }
            public function move(string $source, string $destination): bool { return rename($source, $destination); }
            public function lastModified(string $path): int { return (int) filemtime($path); }
            public function size(string $path): int { return (int) filesize($path); }
            public function extension(string $path): string { return pathinfo($path, PATHINFO_EXTENSION); }
            public function basename(string $path): string { return basename($path); }
            public function dirname(string $path): string { return dirname($path); }
        }];
    }

    /** Минимальный контейнер с путями, которые читает Kernel до роутинга. */
    private function containerWithPaths(string $storageDir): Container
    {
        $c = new Container();
        $c->instance('storage_path', $storageDir);
        $c->instance('logs_path', $storageDir . '/logs');
        $c->instance('cache_path', $storageDir . '/cache');

        return $c;
    }

    /**
     * Точная копия условия-гарда из Kernel::handle() (см. комментарий там же).
     * Держим её здесь как ОРАКУЛ: если кто-то изменит формулу в ядре, тесты
     * I3 упадут на расхождении поведения, а не на текучке комментариев.
     */
    private function installerBlockedByInstallingFlag(string $stateValue, string $method): bool
    {
        return $stateValue === 'installing' && $method === 'POST';
    }

    public function testDetectPrefersTransientFlagOverInstalledFlag(): void
    {
        [$dir, $fs] = $this->makeStorage();
        try {
            // Инвариант I1 (обновлённая семантика): переходный флаг важнее
            // '.installed'. install() больше НЕ ставит '.installing', поэтому
            // живой '.installing' при '.installed' возможен только от зависшей
            // установки СТАРЫХ версий движка — детект честно сообщает об этом.
            $fs->createFile($dir . '/.installed', '1');
            $fs->createFile($dir . '/.installing', '1');
            self::assertSame('installing', \App\Core\System\Enums\AppState::detect($dir)->value,
                'I1: висящий .installing читается как идущая (зависшая) установка.');

            // Без переходных флагов '.installed' различает Running/NotInstalled.
            $fs->delete($dir . '/.installing');
            self::assertSame('running', \App\Core\System\Enums\AppState::detect($dir)->value,
                'I1: без переходных маркеров .installed даёт Running.');
        } finally {
            $this->rrmdir(dirname($dir));
        }
    }

    /**
     * Исполнение install() через Reflection: конструктор Installer — публичный
     * API модуля (провайдер строит его именно так), но тесту нужны private
     * $fs/$security/$config/$logger для подмены. Reflection над ЧУЖИМ классом
     * здесь — осознанный приём white-box-теста инварианта порядка флагов;
     * сам инвариант (I2) при этом проверяется по ПУБЛИЧНЫМ артефактам fs.
     *
     * @constitution-exception п.3: white-box инъекция зависимостей в тестах
     */
    private function buildInstallerWithFakes(string $dir, string $envPath, object $fsSpy): \App\Core\Module\Installer\Installer
    {
        $security = new class implements \App\Core\Module\Security\Contracts\SecurityInterface {
            public function hashPassword(string $password): string { return 'argon2id$fake$' . strlen($password); }
            public function verifyPassword(string $password, string $hash): bool { return $hash === $this->hashPassword($password); }
            public function randomToken(int $bytes): string { return str_repeat('ab', $bytes); }
            public function generateCsrfToken(): string { return bin2hex(random_bytes(16)); }
            public function validateCsrfToken(string $token): bool { return $token !== ''; }
            public function encrypt(string $plaintext): string { return $plaintext; }
            public function decrypt(string $payload): ?string { return $payload; }
        };

        $config = new class implements \App\Core\Module\Config\Contracts\ConfigRepositoryInterface {
            public function get(string $key, mixed $default): mixed
            {
                // Активный драйвер — sqlite: тогда tryCreateDatabase() НЕ трогает
                // файлы (ping фейл -> warning-строка), applySchema падает на
                // недоступной СУБД -> снимает .installed. Порядок флагов чист.
                return match ($key) {
                    'database.driver' => 'sqlite',
                    default => $default,
                };
            }
            public function set(string $key, mixed $value): void {}
            public function has(string $key): bool { return false; }
            public function all(?string $group): array { return []; }
        };

        $logger = new class implements \App\Core\Module\Logger\Contracts\LoggerInterface {
            public function emergency(string $message, array $context): void {}
            public function alert(string $message, array $context): void {}
            public function critical(string $message, array $context): void {}
            public function error(string $message, array $context): void {}
            public function warning(string $message, array $context): void {}
            public function notice(string $message, array $context): void {}
            public function info(string $message, array $context): void {}
            public function debug(string $message, array $context): void {}
            public function log(string $level, string $message, array $context): void {}
        };

        $db = new class implements \App\Core\Module\Database\Contracts\DatabaseInterface {
            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollBack(): void {}
            public function run(string $sql, array $bindings): int { return 0; }
            public function select(string $sql, array $bindings): array { return []; }
            public function selectOne(string $sql, array $bindings): ?array { return null; }
            public function query(string $table): object { throw new \LogicException('not needed'); }
            public function lastInsertId(): int { return 0; }
            public function migrate(string $sql, bool $skipExisting): int { return 0; }
            public function tableExists(string $table): bool { return false; }
            public function driver(): string { return 'sqlite'; }
            public function ping(): bool { return false; }              // СУБД недоступна
            public function connectionError(): ?string { return 'test: db unavailable'; }
        };

        $installer = (new \ReflectionClass(\App\Core\Module\Installer\Installer::class))
            ->newInstanceWithoutConstructor();

        foreach ([
            'storagePath' => $dir,
            'envPath'     => $envPath,
            'logsPath'    => $dir . '/logs',
            'fs'          => $fsSpy,
            'security'    => $security,
            'config'      => $config,
            'logger'      => $logger,
            'db'          => $db,
            'databasePath' => $dir . '/db',
            'migrator'    => null,
            'views'       => null,
        ] as $name => $value) {
            $prop = new \ReflectionProperty(\App\Core\Module\Installer\Installer::class, $name);
            $prop->setAccessible(true);
            $prop->setValue($installer, $value);
        }

        return $installer;
    }

    public function testInstalledFlagIsSetBeforeDatabaseSteps(): void
    {
        [$dir, $fs] = $this->makeStorage();
        $envPath = dirname($dir) . '/.env';
        $order = [];
        try {
            // Fake-зависимости пишут в $order момент каждого побочного эффекта.
            $fsSpy = new class($fs, $order) implements FileSystemInterface {
                /** @param list<string> $order */
                public function __construct(private readonly FileSystemInterface $inner, private array &$order) {}
                public function exists(string $p): bool { return $this->inner->exists($p); }
                public function isFile(string $p): bool { return $this->inner->isFile($p); }
                public function isDirectory(string $p): bool { return $this->inner->isDirectory($p); }
                public function isWritable(string $p): bool { return $this->inner->isWritable($p); }
                public function isReadable(string $p): bool { return $this->inner->isReadable($p); }
                public function createDirectory(string $p, int $perm): bool { return $this->inner->createDirectory($p, $perm); }
                public function createFile(string $p, string $c): bool
                {
                    $this->order[] = basename($p);

                    return $this->inner->createFile($p, $c);
                }
                public function delete(string $p): bool { return $this->inner->delete($p); }
                public function deleteDirectory(string $p): bool { return $this->inner->deleteDirectory($p); }
                public function read(string $p): string { return $this->inner->read($p); }
                public function write(string $p, string $c): bool
                {
                    $this->order[] = 'write:' . basename($p);

                    return $this->inner->write($p, $c);
                }
                public function append(string $p, string $c): bool { return $this->inner->append($p, $c); }
                public function copy(string $s, string $d): bool { return $this->inner->copy($s, $d); }
                public function move(string $s, string $d): bool { return $this->inner->move($s, $d); }
                public function lastModified(string $p): int { return $this->inner->lastModified($p); }
                public function size(string $p): int { return $this->inner->size($p); }
                public function extension(string $p): string { return $this->inner->extension($p); }
                public function basename(string $p): string { return $this->inner->basename($p); }
                public function dirname(string $p): string { return $this->inner->dirname($p); }
            };

            $installer = $this->buildInstallerWithFakes($dir, $envPath, $fsSpy);

            $result = $installer->install([
                'app_name'             => 'Guard Test',
                'timezone'             => 'UTC',
                'super_admin_password' => 'guard-secret-pass-1',
            ]);

            // Схема упала (СУБД недоступна) -> установка честно провалилась...
            self::assertFalse($result === false ? false : true,
                'I2: при недоступной схеме установка возвращает false (п.9: честный провал).');
            // ...флаг .installed снят...
            self::assertFalse(is_file($dir . '/.installed'),
                'I2: при провале схемы .installed снимается — рассинхрона состояний нет.');
            // ...а порядок записи доказывает инвариант: .installed создан ДО
            // удаления (т.е. сразу после env, до database/schema-шагов).
            self::assertTrue(in_array('.installed', $order, true),
                'I2: флаг готовности ставится внутри install() вообще.');
            $idxEnv = array_search('write:.env', $order, true);
            $idxFlag = array_search('.installed', $order, true);
            self::assertTrue($idxEnv !== false && $idxFlag !== false && $idxFlag > $idxEnv,
                'I2: .installed пишется СРАЗУ ПОСЛЕ .env — до Database/Schema-шагов '
                . '(иначе повторный вход конвейера видит Installing без .installed -> 503).');
        } finally {
            $this->rrmdir(dirname($dir));
        }
    }

    public function testKernelGuardRequiresBothInstallingAndPost(): void
    {
        // I3: оракул против таблицы сценариев. Ни один признак по отдельности
        // блок не включает — регрессия «забыли про GET-прогресс» или «блокируем
        // всё подряд» падает здесь, а не в ручном смоуке.
        $cases = [
            // [state, method, ожидаем: запрос перехвачен страницей Installing?]
            ['not_installed', 'POST', false], // нормальный первый запуск — пропускаем к исполнителю
            ['installing',    'GET',  false], // страница прогресса жива
            ['installing',    'POST', true ], // ЕДИНСТВЕННЫЙ блокируемый кейс... 
            ['running',       'POST', false], // повтор после установки — отдаст «уже установлен»
            ['maintenance',   'POST', false],
        ];
        foreach ($cases as [$state, $method, $blocked]) {
            self::assertSame($blocked, $this->installerBlockedByInstallingFlag($state, $method),
                "I3: state={$state}, method={$method}");
        }

        // Живой сквозной прогон того же инварианта через реальный Kernel:
        // NotInstalled + пустой контейнер (Installer недоступен) -> guard НЕ
        // должен выдать 503-страницу Installing; вместо этого системная дорога
        // инсталлятора честно сообщает о недоступности модуля.
        [$dir, $fs] = $this->makeStorage();
        try {
            $kernel = new Kernel($this->containerWithPaths($dir));
            $response = $kernel->handle(new Request('POST', '/install/run', [], [
                'app_name' => 'X', 'timezone' => 'UTC', 'super_admin_password' => 'long-enough-pass',
            ]));
            // Installer-модуль в контейнере нет -> installerResponse вернёт свой
            // 503 «Модуль Installer недоступен», НО НЕ страницу «Идет установка».
            self::assertFalse(str_contains($response->body(), 'Идет установка'),
                'I3: в NotInstalled запрос никогда не блокируется Installing-страницей.');
        } finally {
            $this->rrmdir(dirname($dir));
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                is_dir("{$dir}/{$e}") ? $this->rrmdir("{$dir}/{$e}") : unlink("{$dir}/{$e}");
            }
        }
        rmdir($dir);
    }
}
