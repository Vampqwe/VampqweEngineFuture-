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
 * Проверяемые инварианты (по фактическому коду репозитория):
 *  R1. Каждый PHP-файл ядра/модулей/приложения/tests начинается с
 *      declare(strict_types=1) — инвариант README (129/129 и более).
 *  R2. Запрещён dirname(__DIR__, N) для построения путей проекта (п.7):
 *      пути живут только в Paths::MAP. Бытовой dirname() от имени файла
 *      (mkdir рекурсивно) — допустим и не детектится.
 *  R3. Internal/ модуля не импортируется из чужого модуля (п.2):
 *      use App\...\Module\<X>\Internal\... разрешён только внутри <X>.
 *  R4. Файлы core/ и app/ должны соответствовать PSR-4: namespace
 *      выводится из пути (App\Core\... / App\...).
 *  R5. config/modules.php: провайдеры объявлены классами, которые существуют.
 *
 * Выход: построчный отчёт; exit code 1 при любом нарушении — готов к CI.
 */
final class ArchitectureLinter
{
    /** @var list<string> директории-цели относительно корня проекта */
    private const TARGETS = ['core', 'app', 'tests', 'config', 'migrations'];

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
            // Канон проекта: пользовательские модули живут в App\Modules\<X>
            // (совпадает с фактическими namespace в app/modules/).
            return 'App\\Modules\\' . implode('\\', array_slice($parts, 2));
        }
        return null;
    }
}
