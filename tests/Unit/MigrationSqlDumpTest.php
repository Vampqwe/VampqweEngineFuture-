<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Module\Config\ConfigRepository;
use App\Core\Module\Config\Internal\Parsers\PhpArrayParser;
use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Database\DatabaseManager;
use App\Core\Module\FileSystem\FileSystem;
use App\Core\Module\Hook\Hook;
use App\Core\Module\Logger\FallbackLogger;
use App\Core\Module\Migration\Contracts\MigrationInterface;
use App\Core\Module\Migration\Internal\SqlSchemaMigrator;
use App\Core\Module\TimeDate\TimeDate;
use App\Core\System\Container\Container;
use RuntimeException;
use Tests\Framework\TestCase;

/**
 * Юнит-тесты модуля Migration: единый путь развёртывания схемы — SQL-дампы
 * database/*.sql (п.2 Конституции: наружу только MigrationInterface).
 *
 * Проверено на реальном DatabaseManager (SQLite in-memory) и реальном
 * парсере дампов SqlFileRunner, поэтому тесты ловят именно те дефекты,
 * которые ломали установку: конфликтующий повторный CREATE TABLE, падение
 * на индексах раньше таблиц, отсутствие источника схемы.
 */
final class MigrationSqlDumpTest extends TestCase
{
    /** @var list<string> временные каталоги для удаления в tearDown */
    private array $dirs = [];

    public function testFilesAreFoundSortedAlphabetically(): void
    {
        $dir = $this->dumpDir([
            '02-second.sql' => 'CREATE TABLE t_two (id INTEGER PRIMARY KEY AUTOINCREMENT);',
            '01-first.sql'  => 'CREATE TABLE t_one (id INTEGER PRIMARY KEY AUTOINCREMENT);',
        ]);

        [$m] = $this->migrator($dir);
        $names = array_map(static fn (string $f): string => basename($f), $m->files());

        $this->assertSame(['01-first.sql', '02-second.sql'], $names, 'Порядок применения дампов — по алфавиту.');
    }

    public function testAbsentDatabaseDirectoryIsNotFatal(): void
    {
        // Каталог database/ отсутствует (или пуст): источник схемы не найден.
        // Падать нельзя — Installer обязан показать предупреждение «создайте БД»
        // и поле ввода параметров базы, а не выдать 500.
        [$m] = $this->migrator(sys_get_temp_dir() . '/vamp_migration_absent_' . bin2hex(random_bytes(4)));

        $this->assertSame([], $m->files(), 'Отсутствующий каталог даёт пустой список дампов.');
        $this->assertFalse($m->hasSchemaSource(), 'Без файлов нет и источника схемы.');
        $this->assertSame([], $m->plan()['tables']);
        $this->assertSame([], $m->plan()['missing']);
    }

    public function testDumpWithoutCreateTableIsNotASchemaSource(): void
    {
        // Дамп есть, но CREATE TABLE в нём нет (только DML) => это НЕ схема:
        // hasSchemaSource() = false, run() честно требует создать базу вручную.
        $dir = $this->dumpDir(['seed.sql' => "INSERT INTO pages (id) VALUES (1);\n"]);
        [$m] = $this->migrator($dir);

        $this->assertFalse($m->hasSchemaSource(), 'DML-only дамп не считается источником схемы.');

        $message = '';
        try {
            $m->run();
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }
        $this->assertTrue($message !== '' && str_contains($message, 'не содержит CREATE TABLE'),
            'run() сообщает о необходимости создать базу данных: ' . $message);
    }

    public function testRunDeploysWholeSchemaFromSingleDump(): void
    {
        $dir = $this->dumpDir([
            'db.sql' => 'CREATE TABLE alpha (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(64) NOT NULL DEFAULT \'\');'
                      . "\nCREATE TABLE beta (id INTEGER PRIMARY KEY AUTOINCREMENT);"
                      . "\nCREATE INDEX alpha_name ON alpha (name);",
        ]);

        [$m, $db] = $this->migrator($dir);

        $plan = $m->plan();
        $this->assertSame(['alpha', 'beta'], $plan['tables'], 'plan() вычитывает таблицы из дампа без записи.');
        $this->assertSame(['alpha', 'beta'], $plan['missing'], 'До run() все таблицы отсутствуют.');

        $report = $m->run();
        $this->assertSame(['db.sql'], $report['files']);
        $this->assertGreaterThan(0, $report['statements']);
        $this->assertTrue($db->tableExists('alpha'), 'Схема развёрнута из дампа.');
        $this->assertTrue($db->tableExists('beta'));
        $this->assertTrue($db->tableExists(MigrationInterface::REGISTRY_TABLE), 'Реестр применённых дампов создан.');
        $this->assertSame([], $m->plan()['missing'], 'После run() недостающих таблиц нет.');
    }

    public function testRepeatedRunIsIdempotentAndKeepsData(): void
    {
        $dir = $this->dumpDir([
            'a.sql' => 'CREATE TABLE alpha (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(64) NOT NULL DEFAULT \'\');'
                     . "\nCREATE INDEX alpha_name ON alpha (name);",
            'b.sql' => 'CREATE TABLE beta (id INTEGER PRIMARY KEY AUTOINCREMENT);',
        ]);

        [$m, $db] = $this->migrator($dir);
        $m->run();
        $db->run("INSERT INTO alpha (name) VALUES ('kept')", []);

        // Идемпотентность: повторный прогон (например, ручная «Миграция БД»
        // из будущей админ-опции) не роняет движок и не трогает данные.
        $again = $m->run();
        $this->assertSame(['a.sql', 'b.sql'], $again['files'], 'Повторный run() проходит без ошибок.');

        $row = $db->selectOne('SELECT name FROM alpha WHERE name = ?', ['kept']);
        $this->assertNotNull($row, 'skipExisting досоздаёт недостающее, но не пересоздаёт таблицы.');

        $registry = $db->select('SELECT dump FROM ' . MigrationInterface::REGISTRY_TABLE . ' ORDER BY id');
        $this->assertCount(2, $registry, 'UNIQUE dump: два дампа — ровно две строки реестра.');
    }

    public function testPartialSchemaIsCompletedInsteadOfFailing(): void
    {
        // Частичная схема (остаток прошлой установки): run() обязан досоздать
        // недостающее, а не упасть на «table already exists».
        $dir = $this->dumpDir([
            'a.sql' => 'CREATE TABLE alpha (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(64) NOT NULL DEFAULT \'\');',
            'b.sql' => 'CREATE TABLE beta (id INTEGER PRIMARY KEY AUTOINCREMENT);',
        ]);

        [$m, $db] = $this->migrator($dir);
        $db->run('CREATE TABLE alpha (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(64) NOT NULL DEFAULT \'\')', []);

        $this->assertSame(['beta'], $m->plan()['missing'], 'plan() видит одну отсутствующую таблицу.');

        $m->run();
        $this->assertTrue($db->tableExists('beta'), 'Недостающая таблица досоздана.');
        $this->assertSame([], $m->plan()['missing']);
    }

    public function testRegistryRecordsAppliedDumps(): void
    {
        $dir = $this->dumpDir(['only.sql' => 'CREATE TABLE gamma (id INTEGER PRIMARY KEY AUTOINCREMENT);']);
        [$m, $db] = $this->migrator($dir);

        $m->run();

        $row = $db->selectOne(
            'SELECT dump, statements FROM ' . MigrationInterface::REGISTRY_TABLE . ' WHERE dump = ?',
            ['only.sql'],
        );
        $this->assertNotNull($row, 'Факт применения дампа записан в реестр engine_migrations.');
        $this->assertTrue((int) $row['statements'] >= 1, 'Число операторов учтено.');
    }

    public function testHooksFireAroundEachDump(): void
    {
        $dir = $this->dumpDir([
            'one.sql' => 'CREATE TABLE h_one (id INTEGER PRIMARY KEY AUTOINCREMENT);',
            'two.sql' => 'CREATE TABLE h_two (id INTEGER PRIMARY KEY AUTOINCREMENT);',
        ]);

        $hook = new Hook();
        $seen = [];
        $hook->addAction('migration.before', static function (string $name) use (&$seen): void {
            $seen[] = 'before:' . $name;
        }, 10, null);
        $hook->addAction('migration.after', static function (string $name, int $count) use (&$seen): void {
            $seen[] = 'after:' . $name . ':' . ($count > 0 ? 'ok' : 'empty');
        }, 10, null);

        [$m] = $this->migrator($dir, $hook);
        $m->run();

        $this->assertSame(
            ['before:one.sql', 'after:one.sql:ok', 'before:two.sql', 'after:two.sql:ok'],
            $seen,
            'Точки расширения migration.before/after вызываются по каждому дампу.',
        );
    }

    // ==================== вспомогательное ====================

    /**
     * Живой стек: DatabaseManager(SQLite in-memory) + SqlSchemaMigrator.
     *
     * @return array{0: SqlSchemaMigrator, 1: DatabaseInterface}
     */
    private function migrator(string $databasePath, ?Hook $hook = null): array
    {
        $root = sys_get_temp_dir() . '/vamp_migration_stack_' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0775, true);
        mkdir($root . '/logs', 0775, true);
        $this->dirs[] = $root;

        file_put_contents(
            $root . '/config/database.php',
            "<?php\nreturn [\n  'driver' => 'sqlite',\n  'connections' => ['sqlite' => ['enabled' => true, 'driver' => 'sqlite', 'database' => ':memory:']],\n  'emulate_prep' => false,\n  'timeout' => 5,\n];\n",
        );

        $container = new Container();
        $config    = new ConfigRepository(new PhpArrayParser(), $root . '/config');
        $container->instance(ConfigRepository::class, $config);
        $container->instance('storage_path', $root);

        $db = new DatabaseManager($container, $config);

        $migrator = new SqlSchemaMigrator(
            $db,
            new FileSystem(),
            new TimeDate(),
            new FallbackLogger($root . '/logs'),
            $hook ?? new Hook(),
            $databasePath,
        );

        return [$migrator, $db];
    }

    /**
     * Каталог с SQL-дампами: ['имя.sql' => 'содержимое'].
     *
     * @param array<string,string> $dumps
     */
    private function dumpDir(array $dumps): string
    {
        $dir = sys_get_temp_dir() . '/vamp_migration_dumps_' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        $this->dirs[] = $dir;

        foreach ($dumps as $name => $sql) {
            file_put_contents($dir . '/' . $name, $sql);
        }

        return $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $node) {
                $path = (string) $node;
                $node->isDir() ? @rmdir($path) : @unlink($path);
            }
            @rmdir($dir);
        }
        $this->dirs = [];
    }
}
