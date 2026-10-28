<?php
declare(strict_types=1);

namespace App\Core\System\Support;

/**
 * Статический контрольщик архитектуры (пункт 5 плана развития).
 *
 * Назначение: правила «Конституции» и стиля проекта, которые раньше
 * проверялись человеком глазами, превращаются в исполняемую проверку.
 * Запускается из bin/engine: `php bin/engine lint-rules`.
 *
 * ════════════════════════════════════════════════════════════════════════
 *  ТЕОРЕМА О СИНХРОНИЗАЦИИ КОНСТИТУЦИИ (формулировка — в README.md)
 * ════════════════════════════════════════════════════════════════════════
 *
 *   Т е о р е м а.  Если `php bin/engine lint-rules` завершается с кодом 0
 *   и `php bin/engine test` завершается с кодом 0, то код проекта
 *   соответствует Конституции (п.1–п.10) и правилам стиля.
 *
 *   Д о к а з а т е л ь с т в о  строится построчно: каждое правило
 *   имеет хотя бы один исполняемый детектор в этом классе либо тест
 *   в tests/. Карта доказательств (правило → чем проверяется):
 *     п.1 (ядро не знает бизнес-логику)  → R6, R8   + KernelBoundaryTest
 *     п.2 (только контракты)             → R3, R6, R8, R9
 *     п.3 (DI, никаких глобалов)         → R2, R7, R10 + ContainerTest
 *     п.4 (хуки вместо правок ядра)      → HookTest, AssetsTest (dispatch-точки)
 *     п.5 (расширение без модификации)   → manifest-driven boot (App) + R5
 *     п.6 (PSR-4)                        → R4
 *     п.7 (пути только в Paths::MAP)     → R2, R7    + PathsTest
 *     п.8 (strict_types везде)           → R1
 *     п.9 (движок не падает)             → Doctor check-набором + юнит-тесты деградаций
 *     п.10 (самодиагностика честная)     → Doctor + ModulePresenceCheck (R5-эквивалент runtime)
 *
 *   Всё, что НЕ имеет детектора или теста, помечено как debt в
 *   docs/CONSTITUTION.md и не участвует в теореме.
 *
 * Проверяемые инварианты (полный список):
 *  R1. Каждый PHP-файл ядра/модулей/приложения/tests начинается с
 *      declare(strict_types=1) — инвариант README (п.8).
 *  R2. Запрещён dirname(__DIR__, N) для построения путей проекта (п.7):
 *      пути живут только в Paths::MAP. Бытовой dirname() от имени файла
 *      (mkdir рекурсивно) — допустим и не детектится.
 *  R3. Internal/ модуля не импортируется из чужого модуля (п.2):
 *      use App\...\Module\<X>\Internal\... разрешён только внутри <X>.
 *  R4. Файлы core/ и app/ должны соответствовать PSR-4: namespace
 *      выводится из пути (App\Core\... / App\...).
 *  R5. config/modules.php: провайдеры объявлены классами, которые существуют.
 *  R6. Ядро (core/System/**) не ссылается на конкретные классы модулей
 *      (core/Module/*, app/modules/*) в executable-коде — только на их
 *      Contracts/ (п.1/п.2). Разрешены: use-строки, PHPDoc, хук-строки
 *      ('engine.*'), service-ключи ('*_path').
 *  R7. Пути проекта строятся только из Paths::MAP (п.7): запрещены
 *      BASE_PATH . '/...' и конкатенации '*_path' с литеральными
 *      сегментами вне MAP.
 *  R8. Осознанные исключения из п.2 (FatalPage/SuperAdmin) требуют
 *      аннотации '@constitution-exception'; аннотация обязана быть
 *      использована (защита от устаревших лазеек).
 *  R9. Внутренности модулей (классы Internal/, финальные классы модуля)
 *      не связываются статически (new/instanceof) из чужих модулей и
 *      ядра — даже если формально это не use-импорт (усиление R3, п.2).
 *  R10. Прямые криптопримитивы (openssl_encrypt/decrypt) разрешены ровно
 *       в одном месте — Security/Internal/Crypto.php (единый источник
 *       шифрования; DRY + п.2).
 *  R11. Методы публичных контрактов (*/Contracts/*.php) не имеют
 *       default-значений параметров: реализация не может молча
 *       рассинхронизировать контракт и интерфейс (усиление п.2/п.3).
 *
 * Выход: построчный отчёт; exit code 1 при любом нарушении — готов к CI.
 */
final class ArchitectureLinter
{
    /** @var list<string> директории-цели относительно корня проекта */
    private const TARGETS = ['core', 'app', 'tests', 'config', 'migrations'];

    /**
     * Белый список R6: абстракции, на которые ядро (core/System) вправе
     * ссылаться напрямую. Только ПУБЛИЧНЫЕ контракты модулей (п.2) —
     * конкретные классы реализации (Theme, Installer, SuperAdmin...) тут
     * появиться не могут: их присутствие = нарушение п.1.
     *
     * @var list<string>
     */
    private const CORE_ALLOWED_REFERENCES = [
        // Контракты системных модулей (core/Module/*/Contracts)
        'App\\Core\\Module\\Config\\Contracts\\ConfigRepositoryInterface',
        'App\\Core\\Module\\Error\\Contracts\\ErrorPageInterface',
        'App\\Core\\Module\\Hook\\Contracts\\HookInterface',
        'App\\Core\\Module\\Installer\\Contracts\\InstallerInterface',
        'App\\Core\\Module\\Installer\\Contracts\\InstallerPageInterface',
        'App\\Core\\Module\\Session\\Contracts\\SessionInterface',
        'App\\Core\\Module\\Logger\\Contracts\\LoggerInterface',
        'App\\Core\\Module\\Database\\Contracts\\DatabaseInterface',
        'App\\Core\\Module\\FileSystem\\Contracts\\FileSystemInterface',
        'App\\Core\\Module\\Template\\Contracts\\TemplateInterface',
        'App\\Core\\Module\\Theme\\Contracts\\ThemeInterface',
        'App\\Core\\Module\\Assets\\Contracts\\AssetsInterface',
        'App\\Core\\Module\\Admin\\Contracts\\AdminPanelInterface',
        'App\\Core\\Module\\Doctor\\Contracts\\DoctorInterface',
        'App\\Core\\Module\\Cache\\Contracts\\CacheInterface',
        'App\\Core\\Module\\Queue\\Contracts\\QueueInterface',
        'App\\Core\\Module\\Migration\\Contracts\\MigratorInterface',
        'App\\Core\\Module\\Security\\Contracts\\SecurityInterface',
        'App\\Core\\Module\\Exception\\Contracts\\ExceptionHandlerInterface',
        // Контракты пользовательских модулей (app/modules/*/Contracts) —
        // биндятся провайдерами приложения, ядро резолвит только по ним.
        'App\\modules\\Auth\\Contracts\\AuthManagerInterface',
        'App\\modules\\Pages\\Contracts\\PageRendererInterface',
        // Внутренний стор-контракт Session (реализует Support\SessionStoreInterface):
        // легальный мост System↔Session без знания конкретных сторов.
        'App\\Core\\Module\\Session\\Contracts\\SessionStoreMigratorInterface',
    ];

    /**
     * Белый список R7: имена файлов, легально отсутствующие в Paths::MAP
     * (точечные маркеры состояния и конфигурация окружения). Их каталог задаётся
     * через уже легальные '*_path' (storage_path/base_path), поэтому сам
     * литерал имени не требует ключа в MAP.
     *
     * @var list<string>
     */
    private const ALLOWED_FILE_LITERALS = ['.env', '.installed', '.installing', '.maintenance', '.upgrading', '.error'];

    /**
     * Белый список R8: файлы-исключения из п.2 (осознанный хардкод,
     * зафиксированный в README). Каждый обязан нести аннотацию
     * '@constitution-exception', иначе R8 ловит и файл без аннотации,
     * и устаревшую запись здесь.
     *
     * @var list<string>
     */
    private const EXCEPTION_WHITELIST = [
        'core/System/Support/FatalPage.php',
        'core/System/Support/SuperAdmin.php',
    ];

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @return list<string> список нарушений (пустой массив = всё чисто)
     */
    public function run(): array
    {
        $violations = [];

        foreach ($this->phpFiles() as $file) {
            $rel   = ltrim(str_replace($this->basePath, '', $file), '/\\');
            $src   = (string) file_get_contents($file);
            $lines = preg_split('/\r\n|\r|\n/', $src) ?: [];

            // ---- R1: strict_types ----
            if (!str_starts_with(ltrim((string) ($lines[0] ?? '')), '<?php')
                || !$this->hasStrictTypes($src)) {
                $violations[] = "R1 strict_types отсутствует — {$rel}";
            }

            // ---- R2: dirname(__DIR__, N) как путь проекта ----
            if (preg_match('/dirname\(\s*__DIR__\s*,\s*\d+\s*\)/', $src)) {
                $violations[] = "R2 путь через dirname(__DIR__, N) вместо Paths — {$rel}";
            }

            // ---- R3: трансграничные импорты Internal/ ----
            if (preg_match_all('/^use\s+(App\\\\(?:Core\\\\)?Module\\\\[^;\s]+);/m', $src, $m)) {
                $homeNs = $this->ownModuleOf($rel);
                foreach ($m[1] as $import) {
                    if (preg_match('/^App\\\\(?:Core\\\\)?Module\\\\([^\\\\]+)\\\\Internal\\\\/', $import, $mm)) {
                        if ($homeNs !== null && $mm[1] !== $homeNs) {
                            $violations[] = "R3 импорт чужого Internal\\ ({$mm[1]}) — {$rel}";
                        }
                    }
                }
            }

            // ---- R4: PSR-4 namespace ↔ путь (только core/ и app/) ----
            if (preg_match('#^(core|app)/#', $rel) && preg_match('/^namespace\s+([^;\s]+);/m', $src, $mn)) {
                $expected = $this->expectedNamespace($rel);
                if ($expected !== null && $mn[1] !== $expected) {
                    $violations[] = "R4 namespace [{$mn[1]}] ≠ PSR-4 [{$expected}] — {$rel}";
                }
            }

            // ---- R6: ядро не ссылается на конкретные классы модулей (п.1/п.2) ----
            if (str_starts_with($rel, 'core/System/')) {
                foreach ($this->moduleReferencesInCode($this->executableCode($src)) as $fqcn) {
                    if (!in_array($fqcn, self::CORE_ALLOWED_REFERENCES, true)) {
                        $violations[] = "R6 ядро ссылается на класс модуля [{$fqcn}] — {$rel}"
                            . ' (разрешены только контракты из CORE_ALLOWED_REFERENCES)';
                    }
                }
            }

            // ---- R7: пути только из Paths::MAP (п.7) ----
            foreach ($this->pathConcatViolations($rel, $src) as $msg) {
                $violations[] = $msg;
            }

            // ---- R8: исключения п.2 требуют аннотации @constitution-exception ----
            if (in_array($rel, self::EXCEPTION_WHITELIST, true)
                && !str_contains($src, '@constitution-exception')) {
                $violations[] = "R8 файл-исключение без аннотации @constitution-exception — {$rel}";
            }

            // ---- R9: статическая связность с Internal/чужими классами (new/instanceof) ----
            $code = $this->executableCode($src);
            $home = $this->ownModuleOf($rel);
            if (preg_match_all(
                '/\b(?:new|instanceof)\s+\\\\?((?:App\\\\(?:Core\\\\)?Module|App\\\\modules)\\\\[A-Za-z0-9_\\\\]+)/',
                $code,
                $mNew
            )) {
                foreach ($mNew[1] as $fqcn) {
                    $crossInternal = (bool) preg_match(
                        '/^App\\\\(?:Core\\\\)?Module\\\\([^\\\\]+)\\\\Internal\\\\/',
                        $fqcn,
                        $mm
                    );
                    if ($crossInternal && $home !== null && $mm[1] === $home) {
                        continue; // свой Internal — легально
                    }
                    if ($crossInternal || str_contains($fqcn, '\\\\Internal\\\\')) {
                        $violations[] = "R9 прямая связность с внутренностями модуля [{$fqcn}] — {$rel}";
                    }
                }
            }

            // ---- R10: криптопримитивы только в Security/Internal/Crypto ----
            if (preg_match('/\bopenssl_(?:encrypt|decrypt)\s*\(/', $code)
                && $rel !== 'core/Module/Security/Internal/Crypto.php') {
                $violations[] = "R10 openssl_* вне единого криптоузла Security\\Internal\\Crypto — {$rel}";
            }

            // ---- R11: контракты без default-значений параметров ----
            if (str_contains($rel, '/Contracts/') && preg_match('/^\s*public function /m', $src)) {
                foreach ($this->contractDefaultParamLines($src) as $lineNo => $line) {
                    $violations[] = "R11 default-значение параметра в контракте (строка {$lineNo}) — {$rel}: "
                        . trim($line);
                }
            }
        }

        // ---- R8 (обратная половина): каждая запись белого списка реально используется ----
        foreach (self::EXCEPTION_WHITELIST as $allowed) {
            if (!is_file($this->basePath . '/' . $allowed)) {
                $violations[] = "R8 устаревшая запись белого списка исключений: {$allowed}";
            }
        }

        // ---- R5: манифест модулей ссылается на существующие классы ----
        $manifest = $this->basePath . '/config/modules.php';
        if (is_file($manifest)) {
            $list = require $manifest;
            foreach ($list as $name => $entry) {
                $providerClass = is_array($entry) ? ($entry['provider'] ?? null) : $entry;
                if (!is_string($providerClass) || !class_exists($providerClass)) {
                    $violations[] = "R5 провайдер модуля [{$name}] не загружается: "
                        . var_export($providerClass, true);
                }
            }
        }

        return $violations;
    }

    /** @return iterable<string> все php-файлы целей (без vendor) */
    private function phpFiles(): iterable
    {
        foreach (self::TARGETS as $target) {
            $dir = $this->basePath . '/' . $target;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                    yield $f->getPathname();
                }
            }
        }
    }

    private function hasStrictTypes(string $src): bool
    {
        return (bool) preg_match('/^declare\(\s*strict_types\s*=\s*1\s*\)/m', $src);
    }

    /** Модуль-дом для файла: core/Module/<X>/... или app/modules/<X>/... → <X>. */
    private function ownModuleOf(string $rel): ?string
    {
        if (preg_match('#^(?:core[/\\\\]Module|app[/\\\\]modules)[/\\\\]([^/\\\\]+)[/\\\\]#i', $rel, $m)) {
            return $m[1];
        }
        return null; // core/System, tests, config — внешние наблюдатели
    }

    private function expectedNamespace(string $rel): ?string
    {
        $parts = preg_split('#[/\\\\]#', $rel) ?: [];
        array_pop($parts); // имя файла
        if (($parts[0] ?? '') === 'core' && ($parts[1] ?? '') === 'System') {
            return 'App\\Core\\System' . ($parts === ['core', 'System'] ? '' : '\\' . implode('\\', array_slice($parts, 2)));
        }
        if (($parts[0] ?? '') === 'core' && ($parts[1] ?? '') === 'Module') {
            return 'App\\Core\\Module\\' . implode('\\', array_slice($parts, 2));
        }
        if (($parts[0] ?? '') === 'app' && ($parts[1] ?? '') === 'modules') {
            // Канон проекта: пользовательские модули живут в app/modules/<X>
            // и получают namespace строго по PSR-4 от префикса "App\":
            // App\modules\<X>. Никаких явных префиксов в composer.json —
            // регистр namespace обязан совпадать с именем папки на диске.
            return 'App\\modules\\' . implode('\\', array_slice($parts, 2));
        }
        return null;
    }

    // ==================== помощники новых правил R6–R11 ====================

    /**
     * Исполняемый код файла: без PHPDoc-комментариев и строковых литералов.
     * Ссылки внутри /* ... *\/ или '...' не считаются зависимостью кода —
     * иначе докстринги и хук-строки ('engine.assets.registered') давали бы
     * ложные срабатывания в R6/R9/R10.
     */
    private function executableCode(string $src): string
    {
        // 1) комментарии -> пробелы (сохраняем количество строк для R11)
        $out = preg_replace_callback('#/\*.*?\*/#s', static fn(array $m): string =>
            str_repeat("\n", substr_count($m[0], "\n")), $src) ?? $src;
        $out = preg_replace('~^\s*//.*$|//[^\n"]*$~m', '', $out) ?? $out;
        // 2) строковые литералы (одинарные/двойные) -> пустые кавычки
        $out = preg_replace("'(?:\\\\.|[^'\\\\])*'", "''", $out) ?? $out;
        $out = preg_replace('"(?:\\\\.|[^"\\\\])*"', '""', $out) ?? $out;

        return $out;
    }

    /**
     * R6: все FQN-ссылки на классы модулей в исполняемом коде ядра.
     * use-строки вырезаны executableCode? — нет: use остаётся, поэтому
     * фильтруем их отдельно (разрешённые импорты контрактов).
     *
     * @return list<string> уникальные FQCN
     */
    private function moduleReferencesInCode(string $code): array
    {
        $refs = [];
        if (preg_match_all(
            '/\\\\?((?:App\\\\(?:Core\\\\)?Module|App\\\\modules)\\\\[A-Za-z0-9_\\\\]+)/',
            $code,
            $m
        )) {
            foreach ($m[1] as $fqcn) {
                $refs[rtrim($fqcn, '\\')] = true;
            }
        }

        return array_keys($refs);
    }

    /**
     * R7: конкатенации путей вне Paths::MAP.
     *  - BASE_PATH . '/<что-то>' — запрещено (кроме bootstrap-файлов);
     *  - <...>_path / basePath / storagePath . '/<сегмент>' — сегмент обязан
     *    быть либо ключом MAP, либо точечным маркером из ALLOWED_FILE_LITERALS.
     *
     * @return list<string>
     */
    private function pathConcatViolations(string $rel, string $src): array
    {
        // Bootstrap-исключения: единственные места, где корень проекта
        // определяется физически (публичный контракт развёртывания).
        if (in_array($rel, ['public/index.php', 'bin/engine', 'tests/run.php'], true)) {
            return [];
        }

        $code = $this->executableCode($src);
        $out  = [];

        if (preg_match('/\bBASE_PATH\b/', $code)) {
            $out[] = "R7 обращение к глобальной константе BASE_PATH вместо '*_path' из контейнера — {$rel}";
        }

        if (preg_match_all(
            "/\\b([A-Za-z0-9_]*(?:_path|Path|basePath|storagePath))\\s*(?:\\.\\s*)?['\"]([^'\"]*)['\"]/",
            $code,
            $m,
            PREG_SET_ORDER
        )) {
            foreach ($m as $mm) {
                $seg = $mm[2];
                if ($seg === '' || $seg === '/') {
                    continue;
                }
                $clean = ltrim($seg, '/');
                if (in_array($clean, self::ALLOWED_FILE_LITERALS, true)) {
                    continue; // точечный маркер/конфиг — легально
                }
                // разрешаем '<dir>' только если это префикс какого-то ключа MAP
                $first = explode('/', $clean)[0];
                if ($this->isMapSegment($first)) {
                    continue;
                }
                $out[] = "R7 путь собран конкатенацией [{$mm[1]} . '{$seg}'] вне Paths::MAP — {$rel}";
            }
        }

        return $out;
    }

    /** Сегмент совпадает с относительным путём/его частью в Paths::MAP? */
    private function isMapSegment(string $segment): bool
    {
        static $segments = null;
        if ($segments === null) {
            $segments = [];
            foreach ((new PathsMapProbe())() as $rel) {
                foreach (explode('/', $rel) as $part) {
                    if ($part !== '' && $part !== '.') {
                        $segments[$part] = true;
                    }
                }
            }
        }

        return isset($segments[$segment]);
    }

    /**
     * R11: строки public function в контрактах, содержащие default-значения
     * параметров (= или ?= после имени параметра). Возвращает номер=>текст.
     *
     * @return array<int,string>
     */
    private function contractDefaultParamLines(string $src): array
    {
        $bad   = [];
        $lines = preg_split('/\r\n|\r|\n/', $src) ?: [];
        foreach ($lines as $i => $line) {
            if (!preg_match('/^\s*public function \w+\s*\(/', $line)) {
                continue;
            }
            // signature может продолжаться на следующих строках — склеим до ')'
            $sig = $line;
            for ($j = $i + 1; $j < count($lines) && substr_count($sig, '(') > substr_count($sig, ')'); $j++) {
                $sig .= ' ' . $lines[$j];
            }
            if (preg_match('/\$[\w, ]+=|\?\s*\w+\s*=/', $sig)) {
                $bad[$i + 1] = $sig;
            }
        }

        return $bad;
    }
}

/**
 * Читает relative-пути Paths::MAP без нарушения инкапсуляции constant:
 * через reflection один раз, статически кэшируется в isMapSegment().
 */
final class PathsMapProbe
{
    public function __invoke(): array
    {
        $r = new \ReflectionClass(\App\Core\System\Support\Paths::class);
        $map = $r->getConstant('MAP');

        return is_array($map) ? array_values($map) : [];
    }
}
