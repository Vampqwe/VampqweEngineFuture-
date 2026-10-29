<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Module\Config\ConfigRepository;
use App\Core\Module\Config\Internal\Parsers\PhpArrayParser;
use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Database\DatabaseManager;
use App\Core\Module\Hook\Hook;
use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\Module\Migration\Internal\Migrator;
use App\Core\Module\TimeDate\TimeDate;
use App\Core\System\Container\Container;
use Tests\Framework\TestCase;

/**
 * Интеграционный тест модуля Migration на реальном SQLite (in-memory).
 * Проверяет: авто-реестр engine_migrations, migrate/pending/rollback/reset,
 * идемпотентность повторного migrate(), хуки migration.before/after.
 */
final class MigrationTest extends TestCase
{
    private ?string $dir = null;

    public function testMigrateApplyRollbackAndHooks(): void
    {
        $this->dir = sys_get_temp_dir() . '/engine_mig_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/migrations', 0775, true);

        // Две простые sqlite-миграции (формат проекта: файл возвращает фабрику).
        file_put_contents($this->dir . '/migrations/0001_create_widgets.php', <<<'PHP'
            <?php
            use App\Core\Module\Database\Contracts\DatabaseInterface;
            return static fn (DatabaseInterface $db, string $id): object => new class ($db, $id) implements \App\Core\Module\Migration\Contracts\MigrationInterface {
                public function __construct(private DatabaseInterface $db, private string $mid) {}
                public function id(): string { return $this->mid; }
                public function description(): string { return 'create widgets'; }
                public function up(): int { return $this->db->run('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)'); }
                public function down(): int { return $this->db->run('DROP TABLE widgets'); }
            };
            PHP);
        file_put_contents($this->dir . '/migrations/0002_seed_widget.php', <<<'PHP'
            <?php
            use App\Core\Module\Database\Contracts\DatabaseInterface;
            return static fn (DatabaseInterface $db, string $id): object => new class ($db, $id) implements \App\Core\Module\Migration\Contracts\MigrationInterface {
                public function __construct(private DatabaseInterface $db, private string $mid) {}
                public function id(): string { return $this->mid; }
                public function description(): string { return 'seed widget'; }
                public function up(): int { return $this->db->run("INSERT INTO widgets (name) VALUES ('first')"); }
                public function down(): int { return $this->db->run("DELETE FROM widgets WHERE name = 'first'"); }
            };
            PHP);

        $hook   = new Hook();
        $events = [];
        $hook->addAction('migration.before', static function (string $id) use (&$events): void {
            $events[] = 'before:' . $id;
        });
        $hook->addAction('migration.after', static function (string $id) use (&$events): void {
            $events[] = 'after:' . $id;
        });

        $migrator = $this->makeMigrator($hook);

        // Реестр создаётся автоматически, pending виден до применения.
        $pending = $migrator->pending();
        $this->assertSame(['0001_create_widgets', '0002_seed_widget'], array_keys($pending));

        $done = $migrator->migrate();
        $this->assertSame(['0001_create_widgets', '0002_seed_widget'], $done);
        $this->assertSame([], $migrator->pending(), 'После migrate() ожидающих нет.');
        $this->assertSame(
            ['before:0001_create_widgets', 'after:0001_create_widgets',
             'before:0002_seed_widget', 'after:0002_seed_widget'],
            $events,
            'Хуки before/around вызваны для каждой миграции.'
        );

        // Идемпотентность: повторный migrate — no-op.
        $this->assertSame([], $migrator->migrate());

        // Откат последней.
        $rolled = $migrator->rollback();
        $this->assertSame('0002_seed_widget', $rolled);
        $this->assertSame(['0001_create_widgets'], $migrator->applied());

        // reset — откатывает всё в обратном порядке.
        $this->assertSame(1, $migrator->reset());
        $this->assertSame([], $migrator->applied());

        $this->cleanup();
    }

    public function testMakeCreatesStubFile(): void
    {
        $this->dir = sys_get_temp_dir() . '/engine_mig_make_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/migrations', 0775, true);

        $migrator = $this->makeMigrator(new Hook());
        $file     = $migrator->make('add title to posts');

        $this->assertTrue(is_file($file), 'Файл-заготовка создан.');
        $this->assertTrue(str_contains((string) file_get_contents($file), 'MigrationInterface'));
        $this->assertTrue(str_contains(basename($file), 'add_title_to_posts'));

        $this->cleanup();
    }

    // ================= helpers =================

    private function makeMigrator(Hook $hook): Migrator
    {
        $container = new Container();
        $config    = new ConfigRepository(new PhpArrayParser(), $this->dir . '/config');
        mkdir($this->dir . '/config', 0775, true);
        file_put_contents($this->dir . '/config/database.php', "<?php\nreturn [\n  'driver' => 'sqlite',\n  'connections' => ['sqlite' => ['enabled' => true, 'driver' => 'sqlite', 'database' => ':memory:']],\n  'emulate_prep' => false,\n  'timeout' => 5,\n];\n");
        // Перезагрузить конфиг после записи файла.
        $config = new ConfigRepository(new PhpArrayParser(), $this->dir . '/config');

        $container->instance(ConfigRepository::class, $config);
        $container->instance('storage_path', $this->dir);

        /** @var DatabaseInterface $db */
        $db = new DatabaseManager($container, $config);

        return new Migrator(
            $db,
            new TimeDate(),
            $this->nullLogger(),
            $hook,
            $this->dir . '/migrations',
        );
    }

    private function nullLogger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            public function emergency(string $message, array $context = []): void {}
            public function alert(string $message, array $context = []): void {}
            public function critical(string $message, array $context = []): void {}
            public function error(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function info(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
            public function log(mixed $level, string $message, array $context = []): void {}
        };
    }

    private function cleanup(): void
    {
        if ($this->dir === null || !is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }
}
