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
}
