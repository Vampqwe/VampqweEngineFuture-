<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\System\Enums\AppState;
use App\Core\System\Http\Kernel;
use Tests\Framework\TestCase;

/**
 * РЕГРЕССИЯ ТЕОРЕМЫ СИНХРОНИЗАЦИИ (docs/CONSTITUTION.md):
 *   «lint-rules зелёный ⇒ код соответствует Конституции».
 *
 * @constitution-exception п.7: инспекция исходников репозитория — корень
 *   проекта находится бытовым dirname(__DIR__, 2), т.к. тест читает файлы
 *   core/System/** как данные (T3). Путов приложения тест не строит.
 *
 * Тест закрывает слепое пятно статического анализа — поведение ядра при
 * ОТСУТСТВУЮЩИХ модулях приложения (п.1: ядро не знает бизнес-логику,
 * значит его работоспособность не может зависеть от наличия Pages/Auth):
 *
 *  T1. Kernel без единого модульного сервиса (пустой контейнер) честно
 *      детектирует NotInstalled и отвечает 503 со ссылкой на /install —
 *      удаление любого app/modules/* не роняет HTTP-конвейер.
 *  T2. AppState::detect() — единственный резолвер флагов storage
 *      (п.10): приоритет маркеров и отсутствие '.installed' = NotInstalled.
 *  T3. Имена флагов и session-маркера собираются только из AppState
 *      (п.7): в executable-коде core/System нет литералов '.installed',
 *      '.installing', '.upgrading', '.maintenance', '.error' — проверка
 *      обходит реальные файлы и потому ловит регрессию до того, как её
 *      увидит человек (машинная инвариантность теоремы).
 *  T4. Мост «ядро → модули» (ModuleBridge) структурно замкнут (R6b):
 *      a) ни один файл core/System/** кроме Support/ModuleBridge.php не
 *         содержит literal-ссылок `::class` на App\Core\Module\* или
 *         App\modules\* в executable-коде;
 *      b) сам Bridge деградирует честно: пустой контейнер => все
 *         capability-геттеры отдают null, errorPage() отдаёт fallback,
 *         log()/configValue()/path() не бросают fatal (п.9).
 *      Регрессия «кто-то вернул FQN модуля в Kernel» падает здесь ещё
 *      до того, как её поймает линтер на чужой машине без composer dump.
 */
final class KernelBoundaryTest extends TestCase
{
    public function testKernelSurvivesWithoutApplicationModules(): void
    {
        // Пустой контейнер = картина «удалены все модули приложения и ядра»:
        // если бы Kernel знал классы app/modules/* здесь был бы fatal.
        $container = new \App\Core\System\Container\Container();
        $storage   = sys_get_temp_dir() . '/engine_boundary_' . getmypid() . '_' . uniqid();
        mkdir($storage, 0777, true);

        try {
            $container->instance('storage_path', $storage);
            $kernel   = new Kernel($container);
            $response = $kernel->handle(new \App\Core\System\Http\Request(
                method: 'GET',
                path: '/some-app-page',
            ));

            $this->assertSame(503, $response->statusCode());
            $this->assertTrue(str_contains($response->body(), '/install'),
                'NotInstalled обязан вести на /install, а не падать.');
        } finally {
            @rmdir($storage);
        }
    }

    public function testAppStateDetectIsSingleResolver(): void
    {
        $storage = sys_get_temp_dir() . '/engine_state_' . getmypid() . '_' . uniqid();
        mkdir($storage, 0777, true);

        try {
            // Нет '.installed' -> NotInstalled (базовое состояние до установки)
            $this->assertSame(AppState::NotInstalled, AppState::detect($storage));

            // '.installed' -> Running
            file_put_contents(AppState::NotInstalled->marker($storage), '');
            $this->assertSame(AppState::Running, AppState::detect($storage));

            // Приоритет: любой рабочий маркер перекрывает Running
            foreach ([AppState::Installing, AppState::Upgrading, AppState::Maintenance, AppState::Error] as $case) {
                file_put_contents($case->marker($storage), '');
                $this->assertSame($case, AppState::detect($storage));
                @unlink($case->marker($storage));
            }

            // Session-маркер driver'а живёт в том же словаре имён (п.7)
            $this->assertTrue(
                str_ends_with(AppState::sessionStoreMarker($storage, 'redis'), '/.session_store.redis'),
                'Формат маркера миграции стора фиксирован контрактом AppState.'
            );
        } finally {
            foreach (array_merge(array_values(AppState::flagNames()), ['.installed']) as $f) {
                @unlink($storage . '/' . $f);
            }
            @rmdir($storage);
        }
    }

    public function testNoFlagLiteralsInKernelCode(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/core/System'));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with((string) $file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        $literals = ["'.installed'", "'.installing'", "'.upgrading'", "'.maintenance'", "'.error'", "'.session_store."];
        foreach ($files as $abs) {
            if (str_contains($abs, 'Enums' . DIRECTORY_SEPARATOR . 'AppState.php')) {
                continue; // AppState — единственный легальный источник имён
            }
            $src   = (string) file_get_contents($abs);
            // Надёжная чистка: вырезаем комментарии через токены PHP —
            // проверяется только executable-код (как в ArchitectureLinter).
            $clean = '';
            foreach (token_get_all($src) as $t) {
                if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $clean .= is_array($t) ? $t[1] : $t;
            }

            foreach ($literals as $lit) {
                $this->assertFalse(
                    str_contains($clean, $lit),
                    "Литерал {$lit} найден в " . str_replace($root . '/', '', $abs)
                        . ' — имена флагов обязаны приходить из AppState (п.7/п.10).'
                );
            }
        }
    }

    /**
     * T4a: R6b как исполняемая инвариантность — единственный файл core/System/**,
     * которому разрешено знать `::class` контрактов модулей, это Support/ModuleBridge.php.
     */
    public function testOnlyModuleBridgeKnowsModuleClassNames(): void
    {
        $root  = dirname(__DIR__, 2);
        $files = [];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/core/System'));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with((string) $file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $pattern = '#App\\\\Core\\\\Module\\\\[A-Za-z0-9_\\\\]+::class|App\\\\modules\\\\[A-Za-z0-9_\\\\]+::class#';

        foreach ($files as $abs) {
            $rel = str_replace($root . '/', '', $abs);
            if (str_ends_with($rel, 'Support/ModuleBridge.php')) {
                continue; // легальный единичный носитель знания о модулях
            }
            $src   = (string) file_get_contents($abs);
            $clean = '';
            foreach (token_get_all($src) as $t) {
                if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue; // проверяем только executable-код
                }
                $clean .= is_array($t) ? $t[1] : $t;
            }

            $this->assertFalse(
                (bool) preg_match($pattern, $clean),
                "R6b: файл {$rel} содержит прямую ::class-ссылку на модуль — "
                    . 'ядро обязано резолвить способности только через ModuleBridge (п.1/п.2).'
            );
        }
    }

    /**
     * T4b: ModuleBridge честно деградирует на пустом контейнере (п.9):
     * capability-геттеры => null, errorPage => fallback, log/configValue — без fatal.
     */
    public function testModuleBridgeDegradesGracefullyOnEmptyContainer(): void
    {
        $bridge = new \App\Core\System\Support\ModuleBridge(
            new \App\Core\System\Container\Container()
        );

        $this->assertNull($bridge->session());
        $this->assertNull($bridge->storeMigrator());
        $this->assertNull($bridge->hooks());
        $this->assertNull($bridge->assets());
        $this->assertNull($bridge->themes());
        $this->assertNull($bridge->templates());
        $this->assertNull($bridge->database());
        $this->assertNull($bridge->fileSystem());
        $this->assertNull($bridge->config());
        $this->assertNull($bridge->installer());
        $this->assertNull($bridge->installerPages());
        $this->assertNull($bridge->adminPanel());
        $this->assertNull($bridge->authManager());
        $this->assertNull($bridge->pageRenderer());
        $this->assertNull($bridge->errorPages());
        $this->assertFalse($bridge->hasErrorPages());

        // Fallback-страница ошибки вместо fatal
        $html = $bridge->errorPage(503);
        $this->assertTrue(str_contains($html, '503'), 'Без Error-модуля ядро отдаёт минимальный HTML.');

        // best-effort операции не бросают исключений
        $bridge->log('error', 'must-not-throw');
        $this->assertSame('fallback', $bridge->configValue('app.name', 'fallback'));

        // Константы контрактов доступны и вне контейнера (статические, literal-знание живёт в Bridge)
        $this->assertTrue($bridge->defaultTheme() !== '', 'DEFAULT_THEME обязан быть непустой строкой.');
        $this->assertTrue($bridge->scopeApp() !== '', 'SCOPE_APP обязан быть непустой строкой.');
    }
}
