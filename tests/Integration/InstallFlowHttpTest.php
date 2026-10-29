<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\Framework\HttpServer;
use Tests\Framework\TestCase;

/**
 * Интеграционная регрессия полного HTTP-пути установки движка «с нуля».
 *
 * Закрывает слепое пятно: ранее установка проверялась только ручным смоуком,
 * из-за чего в main ушла регрессия 503 — Kernel перехватывал собственный
 * POST /install/run по флагу AppState::Installing (рекурсивная блокировка:
 * install() создаёт флаг, а роутинг до него уже не доходил). Правка в
 * Kernel::handle() («Installing» не блокирует инсталлер-POST) теперь
 * защищена этим автоматическим контуром в CI.
 *
 * Контур (реальный php -S стенд, изолированный .env/sqlite/storage):
 *   GET  /install          => 200, страница требований (шаг 1);
 *   POST /install/run      => 201, успех (НЕ 503!) — регрессия блокировки;
 *   артефакты              => storage/.installed + .env с APP_KEY и хешем пароля;
 *   GET  /                 => 200, сайт работает сразу после установки;
 *   GET  /install          => 200 без форм — идемпотентность («уже установлен»).
 */
final class InstallFlowHttpTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @var list<HttpServer> поднятые стенды (гарантированная остановка) */
    private array $servers = [];

    public function testFullInstallFlowFromScratch(): void
    {
        // Стенд БЕЗ флага .installed и БЕЗ .env — состояние NotInstalled,
        // ровно то, что получает пользователь, скачавший движок.
        $server = HttpServer::boot(self::ROOT, [
            'DB_DRIVER'         => 'sqlite',
            'DB_SQLITE_ENABLED' => 'true',
            'DB_DATABASE_PATH'  => 'engine.sqlite',
        ], installed: false);
        $this->servers[] = $server;

        // Шаг 1: требования окружения отдаются в состоянии NotInstalled.
        $requirements = $server->get('/install', 'install.test');
        $this->assertSame(200, $requirements['status'], 'GET /install доступен до установки.');
        $this->assertTrue(
            str_contains($requirements['body'], 'Окружение') || str_contains($requirements['body'], 'PDO'),
            'Страница требований содержит чеклист окружения.',
        );

        // Шаг 3: выполнение установки. КЛЮЧЕВАЯ РЕГРЕССИЯ: ответ должен быть
        // 201 (успех), а не 503 («Идет установка» — рекурсивная блокировка
        // Installing-флага, из-за которой POST никогда не доходил до install()).
        $run = $server->post('/install/run', 'install.test', [
            'app_name'             => 'EngineStart Test',
            'timezone'             => 'Europe/Moscow',
            'super_admin_password' => 'integration-secret-pass-1',
        ]);
        $this->assertSame(201, $run['status'], 'POST /install/run проходит сквозь Installing-флаг (без 503).');
        $this->assertTrue(str_contains($run['body'], 'установлен') || str_contains($run['body'], 'Установка'),
            'Страница успеха содержит итог установки.');

        // Артефакты установки на месте.
        $this->assertTrue($server->has('storage/.installed'), 'Флаг .installed проставлен.');
        $env = $server->readFile('.env');
        $this->assertTrue($env !== '', '.env создан установщиком.');
        $this->assertTrue(str_contains($env, 'APP_KEY='), '.env содержит APP_KEY.');
        $this->assertTrue(str_contains($env, 'SUPER_ADMIN'), '.env содержит хеш супер-администратора.');
        // SQLite-файл: DatabaseManager резолвит относительный DB_DATABASE_PATH
        // от storage_path (п.7) => storage/engine.sqlite, а НЕ storage/database/.
        $this->assertTrue(
            $server->has('storage/engine.sqlite') || $server->has('storage/database/engine.sqlite'),
            'SQLite-база создана схемой db.sql (по пути из .env через storage_path).',
        );

        // Сайт жив сразу после установки (main-роут через полный конвейер ядра).
        $home = $server->get('/', 'install.test');
        $this->assertSame(200, $home['status'], 'Главная доступна после установки.');

        // Идемпотентность: повторный вход в установщик не предлагает формы.
        $again = $server->get('/install', 'install.test');
        $this->assertSame(200, $again['status']);
        $this->assertTrue(str_contains($again['body'], 'уже установлен'), 'Повторный запуск честно сообщает об установке.');
        $this->assertFalse(str_contains($again['body'], 'super_admin_password'), 'Формы установки больше нет.');
    }

    public function testInvalidSettingsAreRejectedBeforeInstall(): void
    {
        $server = HttpServer::boot(self::ROOT, [
            'DB_DRIVER'         => 'sqlite',
            'DB_SQLITE_ENABLED' => 'true',
            'DB_DATABASE_PATH'  => 'engine.sqlite',
        ], installed: false);
        $this->servers[] = $server;

        // Короткий пароль (<8) — валидация модуля Installer обязана вернуть 422
        // и НЕ создавать артефактов установки.
        $run = $server->post('/install/run', 'install.test', [
            'app_name'             => 'Bad Settings',
            'timezone'             => 'UTC',
            'super_admin_password' => 'short',
        ]);
        $this->assertSame(422, $run['status'], 'Невалидные настройки отклоняются.');
        $this->assertFalse($server->has('storage/.installed'), 'При ошибке валидации флаг установки не ставится.');
    }

    public function stopAll(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
    }
}
