<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\Framework\HttpServer;
use Tests\Framework\TestCase;

/**
 * Интеграционная регрессия матрицы идентичности сайта на РЕАЛЬНОМ HTTP:
 * встроенный сервер PHP поднимается из тест-раннера (см. HttpServer),
 * запросы идут с настоящими Host-заголовками через весь конвейер ядра.
 *
 * Закрывает слепое пятно: юнит-тесты SiteIdentity проверяли чистые функции,
 * но связку «конфиг -> Kernel -> home-роут» раньше подтверждал только ручной
 * смоук. Теперь это автоматический merge-gate контур.
 *
 * Матрица (BASE_URL_SITE задан):
 *  - strict (SITE_STRICT_HOST=true):  свой хост => 200, чужой => 404;
 *  - мягкий (false):                  и свой, и чужой => 200 (деградация по п.9);
 *  - legacy (BASE_URL_SITE пуст):     любой хост => 200 (обратная совместимость).
 */
final class SiteIdentityHttpTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @var list<HttpServer> поднятые стенды (гарантированная остановка) */
    private array $servers = [];

    public function testStrictModeRejectsForeignHost(): void
    {
        $server = $this->stand([
            'APP_KEY'           => 'integration-test-key-not-for-production',
            'DB_DRIVER'         => 'sqlite',
            'DB_SQLITE_ENABLED' => 'true',
            'DB_DATABASE_PATH'  => 'engine.sqlite',
            'BASE_URL_SITE'     => 'https://own.test',
            'SITE_STRICT_HOST'  => 'true',
        ]);

        $own = $server->get('/', 'own.test');
        $this->assertSame(200, $own['status'], 'Свой хост в strict-режиме обслуживается.');

        $foreign = $server->get('/', 'intruder.test');
        $this->assertSame(404, $foreign['status'], 'Чужой хост в strict-режиме отклоняется.');
    }

    public function testSoftModeServesAnyHost(): void
    {
        $server = $this->stand([
            'APP_KEY'           => 'integration-test-key-not-for-production',
            'DB_DRIVER'         => 'sqlite',
            'DB_SQLITE_ENABLED' => 'true',
            'DB_DATABASE_PATH'  => 'engine.sqlite',
            'BASE_URL_SITE'     => 'https://own.test',
            'SITE_STRICT_HOST'  => 'false',
        ]);

        $this->assertSame(200, $server->get('/', 'own.test')['status']);
        $this->assertSame(200, $server->get('/', 'other.test')['status'], 'Мягкий режим не ломает доступность.');
    }

    public function testLegacyModeWithoutBaseUrlSite(): void
    {
        $server = $this->stand([
            'APP_KEY'           => 'integration-test-key-not-for-production',
            'DB_DRIVER'         => 'sqlite',
            'DB_SQLITE_ENABLED' => 'true',
            'DB_DATABASE_PATH'  => 'engine.sqlite',
            // BASE_URL_SITE намеренно пуст — легаси-резолв по Host.
        ]);

        $this->assertSame(200, $server->get('/', 'any-host.test')['status'], 'Легаси-режим жив (обратная совместимость).');
    }

    /**
     * Регрессия «белой страницы с галочкой»: на корректно установленном
     * стенде главная ОБЯЗАНА отдавать контент стартового сайта engineStart
     * (гайд по движку), а не голый fallback-текст «успешно запущен».
     * Если рендер home.phtml снова сломается — этот тест упадёт первым.
     */
    public function testHomeRendersEngineStartGuideNotBareFallback(): void
    {
        $server = $this->stand([
            'APP_KEY'           => 'integration-test-key-not-for-production',
            'DB_DRIVER'         => 'sqlite',
            'DB_SQLITE_ENABLED' => 'true',
            'DB_DATABASE_PATH'  => 'engine.sqlite',
        ]);

        $home = $server->get('/', 'guide.test');
        $this->assertSame(200, $home['status']);
        $this->assertTrue(
            str_contains($home['body'], 'успешно запущен') === false,
            'Главная не должна быть голым fallback-текстом — должен рендериться engineStart.'
        );
        $this->assertTrue(
            str_contains($home['body'], 'engineStart') || str_contains($home['body'], 'движк'),
            'На главной присутствует контент стартового сайта/гайда.'
        );
    }

    /**
     * Поднимает изолированный стенд и регистрирует его для остановки.
     *
     * migrate: true — разворачивает sqlite-схему из database/*.sql тем же
     * единственным путём (модуль Migration), что и установщик. Без этого
     * «установленный» стенд был бы с пустой БД: миграция в ядре больше не
     * применяется в фоне при запросах, только инсталлятором.
     */
    private function stand(array $env): HttpServer
    {
        $server = HttpServer::boot(self::ROOT, $env, installed: true, migrate: true);
        $this->servers[] = $server;

        return $server;
    }

    public function stopAll(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
    }
}
