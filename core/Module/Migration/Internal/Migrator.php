<?php
declare(strict_types=1);

namespace App\Core\Module\Migration\Internal;

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Hook\Contracts\HookInterface;
use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\Module\Migration\Contracts\MigrationInterface;
use App\Core\Module\Migration\Contracts\MigratorInterface;
use App\Core\Module\TimeDate\Contracts\TimeDateInterface;
use RuntimeException;

/**
 * Реализация модуля Migration — реестр и исполнитель версий схемы БД.
 *
 * Зависимости (только контракты + путь из единой точки правды, п.3/п.7):
 *   DatabaseInterface  — исполнение SQL и реестр engine_migrations;
 *   TimeDateInterface  — метки применения (через ядро, не new DateTime в логике);
 *   LoggerInterface    — канал 'Migration';
 *   HookInterface      — события migration.before / migration.after / migration.rollbacked;
 *   'migrations_path'  — папка миграций (Paths::MAP['migrations']).
 *
 * Инварианты:
 *  - таблица-реестр создаётся автоматически при первом обращении;
 *  - каждая up()/down() выполняется в отдельной транзакции (атомарно с записью реестра);
 *  - порядок исполнения — лексикографическая сортировка id файлов.
 */
final class Migrator implements MigratorInterface
{
    /** Имя таблицы-реестра применённых миграций. */
    public const REGISTRY_TABLE = 'engine_migrations';

    /**
     * Шаблон заготовки миграции (make()). Замещаемые токены:
     * {{CLASS}} — имя класса, {{ID}} — id файла, {{DESCRIPTION}} — описание.
     */
    private const STUB = <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Migrations;

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Migration\Contracts\MigrationInterface;

/**
 * Миграция {{ID}} — {{DESCRIPTION}}.
 * Файл возвращает реализацию MigrationInterface; исполняет модуль Migration.
 */
final class {{CLASS}} implements MigrationInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $migrationId = '{{ID}}',
    ) {
    }

    public function id(): string
    {
        return $this->migrationId;
    }

    public function description(): string
    {
        return '{{DESCRIPTION}}';
    }

    public function up(): int
    {
        // Доступ к БД — только через контракт DatabaseInterface.
        // Пример идемпотентного изменения схемы:
        // if ($this->db->tableExists('pages')) {
        //     return $this->db->run("ALTER TABLE pages ADD COLUMN subtitle TEXT DEFAULT ''");
        // }
        return 0;
    }

    public function down(): int
    {
        // Откат по умолчанию no-op (SQLite не умеет DROP COLUMN в старых версиях).
        return 0;
    }
}

PHP;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly TimeDateInterface $time,
        private readonly LoggerInterface $logger,
        private readonly HookInterface $hook,
        private readonly string $migrationsPath,
    ) {
    }

    // ==================== MigratorInterface ====================

    public function all(): array
    {
        $out = [];
        foreach ($this->files() as $id => $file) {
            $out[$id] = $this->describe($this->resolve($id, $file));
        }

        return $out;
    }

    public function applied(): array
    {
        $this->ensureRegistry();

        $rows = $this->db->select(
            'SELECT migration_id FROM ' . self::REGISTRY_TABLE . ' ORDER BY applied_at ASC, migration_id ASC'
        );

        return array_column($rows, 'migration_id');
    }

    public function pending(): array
    {
        $applied = array_flip($this->applied());
        $out = [];
        foreach ($this->all() as $id => $description) {
            if (!isset($applied[$id])) {
                $out[$id] = $description;
            }
        }

        return $out;
    }

    public function migrate(?string $id = null): array
    {
        $files = $this->files();

        if ($id !== null) {
            if (!isset($files[$id])) {
                throw new RuntimeException("Модуль [Migration]: миграция [{$id}] не найдена в {$this->migrationsPath}.");
            }
            if (in_array($id, $this->applied(), true)) {
                return []; // уже применена — идемпотентный no-op
            }
            $this->runUp($id, $files[$id]);

            return [$id];
        }

        $done = [];
        foreach ($this->pending() as $pendingId => $_) {
            $this->runUp((string) $pendingId, $files[$pendingId]);
            $done[] = (string) $pendingId;
        }

        return $done;
    }

    /**
     * Автоприменение ожидающих миграций (используется Installer после развёртки db.sql).
     * Отсутствие СУБД/папки миграций НЕ валит установку — деградирует тихо.
     *
     * @return list<string> применённые id
     */
    public function migratePending(): array
    {
        try {
            return $this->migrate();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Автомиграции пропущены: ' . $e->getMessage(),
                ['channel' => 'Migration']
            );

            return [];
        }
    }

    public function rollback(?string $id = null): ?string
    {
        $applied = $this->applied();
        if ($applied === []) {
            return null;
        }

        $target = $id ?? end($applied);
        $index = array_search($target, $applied, true);
        if ($index === false) {
            throw new RuntimeException("Модуль [Migration]: миграция [{$target}] не применялась — откат невозможен.");
        }

        $files = $this->files();
        if (!isset($files[$target])) {
            throw new RuntimeException("Модуль [Migration]: файл миграции [{$target}] исчез из {$this->migrationsPath}.");
        }

        $this->runDown($target, $files[$target]);

        return $target;
    }

    public function make(string $name): string
    {
        $safe = preg_replace('/[^a-z0-9_]/i', '_', trim($name));
        if ($safe === null || $safe === '') {
            throw new RuntimeException('Модуль [Migration]: недопустимое имя миграции.');
        }
        if (!is_dir($this->migrationsPath) && !mkdir($this->migrationsPath, 0o775, true) && !is_dir($this->migrationsPath)) {
            throw new RuntimeException("Модуль [Migration]: не удалось создать папку {$this->migrationsPath}.");
        }

        $id = str_replace(['-', ' ', ':'], '_', $this->time->format('Y_m_d_His', $this->time->now())) . '_' . $safe;
        $file = $this->migrationsPath . '/' . $id . '.php';
        if (file_exists($file)) {
            throw new RuntimeException("Модуль [Migration]: файл [{$id}].php уже существует.");
        }

        $class = 'M' . implode('', array_map(
            static fn (string $part): string => ucfirst($part),
            preg_split('/[^a-z0-9]+/i', $id, -1, PREG_SPLIT_NO_EMPTY) ?: []
        ));

        file_put_contents(
            $file,
            str_replace(['{{CLASS}}', '{{ID}}', '{{DESCRIPTION}}'], [$class, $id, $safe], self::STUB)
        );
        $this->logger->info("Создана заготовка миграции: {$id}", ['channel' => 'Migration']);

        return $file;
    }

    public function reset(): int
    {
        $count = 0;
        foreach (array_reverse($this->applied()) as $id) {
            $files = $this->files();
            if (!isset($files[$id])) {
                throw new RuntimeException("Модуль [Migration]: файл миграции [{$id}] не найден — reset прерван.");
            }
            $this->runDown($id, $files[$id]);
            $count++;
        }

        return $count;
    }

    public function status(): array
    {
        $total   = count($this->all());
        $applied = count($this->applied());

        return [
            'ok'      => $total === $applied,
            'total'   => $total,
            'applied' => $applied,
            'pending' => $total - $applied,
        ];
    }

    // ==================== внутренняя кухня ====================

    /** @return array<string,string> id => путь файла, отсортированные по id */
    private function files(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '/*.php') ?: [];
        $out = [];
        foreach ($files as $file) {
            $out[basename($file, '.php')] = $file;
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    private function resolve(string $id, string $file): MigrationInterface
    {
        $factory = require $file;

        // Допускаются два стиля файла миграций:
        //  1) return new SomeMigration($db);   — готовый экземпляр (обратная совместимость);
        //  2) return static fn(DatabaseInterface $db): MigrationInterface => new ...; — фабрика.
        if ($factory instanceof \Closure) {
            $factory = $factory($this->db);
        }

        if (!$factory instanceof MigrationInterface) {
            throw new RuntimeException(
                "Модуль [Migration]: файл [{$id}] должен возвращать реализацию MigrationInterface"
                . ' (наследник AbstractMigration) или фабрику static fn(DatabaseInterface $db) => ...'
            );
        }

        // Если у миграции пустой id — подставляем имя файла (источник истины — файловая система).
        if ($factory->id() === '') {
            $factory = $this->withId($file, $id);
        } elseif ($factory->id() !== $id) {
            throw new RuntimeException("Модуль [Migration]: id миграции [{$factory->id()}] не совпадает с именем файла [{$id}].");
        }

        return $factory;
    }

    /**
     * Пересоздаёт экземпляр миграции с корректным id из имени файла.
     * Работает для классов-наследников AbstractMigration (конструктор: db, id).
     */
    private function withId(string $file, string $id): MigrationInterface
    {
        $reflection = new \ReflectionClass($this->classFromFile($file));
        $migration = $reflection->newInstance($this->db, $id);
        if (!$migration instanceof MigrationInterface) {
            throw new RuntimeException("Модуль [Migration]: класс файла [{$id}] не является MigrationInterface.");
        }

        return $migration;
    }

    /**
     * Определяет FQCN единственного класса в файле миграции
     * (файлы migrations/ не автозагружаются PSR-4, поэтому парсим заголовок).
     */
    private function classFromFile(string $file): string
    {
        $src = (string) file_get_contents($file);
        $namespace = '';
        if (preg_match('/^\s*namespace\s+([^;{\s]+)/m', $src, $m)) {
            $namespace = $m[1];
        }
        if (!preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $src, $m)) {
            throw new RuntimeException("Модуль [Migration]: в файле [{$file}] не найден класс миграции.");
        }
        $fqcn = $namespace !== '' ? $namespace . '\\' . $m[1] : $m[1];

        if (!class_exists($fqcn)) {
            // Класс уже был объявлен предыдущим require этого же файла — OK; иначе ошибка.
            foreach (get_declared_classes() as $c) {
                if (strcasecmp(substr($c, (int) strrpos($c, '\\') + 1), $m[1]) === 0) {
                    return $c;
                }
            }
            throw new RuntimeException("Модуль [Migration]: класс [{$fqcn}] недоступен.");
        }

        return $fqcn;
    }

    private function describe(MigrationInterface $m): string
    {
        $d = trim($m->description());

        return $d === '' ? $m->id() : $d;
    }

    private function runUp(string $id, string $file): void
    {
        $migration = $this->resolve($id, $file);
        $this->ensureRegistry();

        $this->hook->dispatch('migration.before', $id, 'up');
        $this->db->beginTransaction();
        try {
            $statements = $migration->up();
            $this->db->run(
                'INSERT INTO ' . self::REGISTRY_TABLE . ' (migration_id, description, applied_at) VALUES (?, ?, ?)',
                [$id, $this->describe($migration), $this->time->toIso($this->time->now())]
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error("Миграция [{$id}] НЕ применена: {$e->getMessage()}", ['channel' => 'Migration']);
            throw new RuntimeException("Модуль [Migration]: миграция [{$id}] провалилась: {$e->getMessage()}", 0, $e);
        }

        $this->logger->info("Миграция применена: {$id} ({$statements} операторов)", ['channel' => 'Migration']);
        $this->hook->dispatch('migration.after', $id, 'up');
    }

    private function runDown(string $id, string $file): void
    {
        $migration = $this->resolve($id, $file);

        $this->hook->dispatch('migration.before', $id, 'down');
        $this->db->beginTransaction();
        try {
            $migration->down();
            $this->db->run('DELETE FROM ' . self::REGISTRY_TABLE . ' WHERE migration_id = ?', [$id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error("Откат миграции [{$id}] НЕ выполнен: {$e->getMessage()}", ['channel' => 'Migration']);
            throw new RuntimeException("Модуль [Migration]: откат [{$id}] провалился: {$e->getMessage()}", 0, $e);
        }

        $this->logger->info("Миграция откачена: {$id}", ['channel' => 'Migration']);
        $this->hook->dispatch('migration.rollbacked', $id);
    }

    private function ensureRegistry(): void
    {
        static $checked = false;
        if ($checked || $this->db->tableExists(self::REGISTRY_TABLE)) {
            $checked = true;

            return;
        }

        $this->db->run(
            'CREATE TABLE ' . self::REGISTRY_TABLE . ' ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'migration_id VARCHAR(191) NOT NULL UNIQUE,'
            . 'description VARCHAR(255) NOT NULL DEFAULT \'\','
            . 'applied_at VARCHAR(40) NOT NULL)'
        );
        $checked = true;
    }
}
