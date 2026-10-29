<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Module\Config\ConfigRepository;
use App\Core\Module\Config\Internal\Parsers\PhpArrayParser;
use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Database\DatabaseManager;
use App\Core\Module\FileSystem\FileSystem;
use App\Core\Module\Template\Template;
use App\Core\Module\Theme\Internal\SiteRegistry;
use App\Core\Module\Theme\Theme;
use App\Core\System\Container\Container;
use Tests\Framework\TestCase;

/**
 * Интеграционный тест модуля Theme (мультисайт) на реальном SQLite (in-memory).
 * Проверяет: резолв домена через реестр engine_sites, идемпотентный
 * register(), эвристику поддомена и честную деградацию без БД.
 */
final class ThemeTest extends TestCase
{
    private ?string $dir = null;

    public function testMultisiteResolveRegisterAndFallbacks(): void
    {
        $this->dir = sys_get_temp_dir() . '/engine_theme_test_' . bin2hex(random_bytes(4));
        $appView   = $this->dir . '/view/app';
        mkdir($appView . '/animals', 0775, true);
        mkdir($appView . '/_default', 0775, true);
        mkdir($this->dir . '/config', 0775, true);

        file_put_contents($this->dir . '/config/database.php', "<?php\nreturn [\n  'driver' => 'sqlite',\n  'connections' => ['sqlite' => ['enabled' => true, 'driver' => 'sqlite', 'database' => ':memory:']],\n  'emulate_prep' => false,\n  'timeout' => 5,\n];\n");

        $container = new Container();
        $config    = new ConfigRepository(new PhpArrayParser(), $this->dir . '/config');
        $container->instance(ConfigRepository::class, $config);
        $container->instance('storage_path', $this->dir);

        /** @var DatabaseInterface $db */
        $db = new DatabaseManager($container, $config);
        $db->run("CREATE TABLE engine_sites (id INTEGER PRIMARY KEY AUTOINCREMENT, domain VARCHAR(191) NOT NULL UNIQUE, theme VARCHAR(64) NOT NULL DEFAULT '_default', title VARCHAR(191) NOT NULL DEFAULT '', is_active SMALLINT NOT NULL DEFAULT 1, created_at VARCHAR(40) NOT NULL DEFAULT '')");

        $views = new Template($this->dir . '/view/core', $appView, $this->dir . '/cache', false);
        $theme = new Theme($views, new SiteRegistry($db), new FileSystem(), $appView, '_default');

        // 1. Пустой реестр -> тема по умолчанию ('' = не переключали).
        $this->assertSame('', $theme->resolveForRequest('unknown.test'));
        $this->assertNull($theme->site());

        // 2. Регистрация сайта + точный резолв карточки.
        $this->assertTrue($theme->registerSite('shop.test', 'animals', 'Магазин'), 'registerSite пишет в БД.');
        $site = $theme->resolveSite('shop.test');
        $this->assertNotNull($site);
        $this->assertSame(['domain' => 'shop.test', 'theme' => 'animals', 'title' => 'Магазин'], $site);

        // 3. Идемпотентность: повторная регистрация обновляет, а не дублирует.
        $this->assertTrue($theme->registerSite('shop.test', 'animals', 'Лавка'));
        $this->assertCount(1, $theme->sites(), 'Один сайт после двух регистраций одного домена.');
        $this->assertSame('Лавка', $theme->resolveSite('shop.test')['title']);

        // 4. Резолв для запроса активирует тему (существующая папка).
        $this->assertSame('animals', $theme->resolveForRequest('shop.test'));
        $this->assertSame('animals', $theme->current());
        $this->assertSame('animals', $views->theme(), 'Template переключён на тему сайта.');
        $this->assertSame('Лавка', $theme->site()['title'], 'site() помнит карточку последнего запроса.');

        // 5. Незарегистрированный домен -> первая запись реестра (дефолтный сайт).
        $this->assertSame('animals', $theme->resolveForRequest('other.test'));

        // 6. Тема из реестра без папки -> деградация на '_default' (движок не падает).
        //    Возвращается '_default' (а не ''): Kernel должен отличать
        //    «переключили на фолбэк» от «не переключали» — иначе утечка темы
        //    между доменами при повторном резолве в одном запросе.
        $theme->registerSite('bloggs.test', 'bloggs');
        $this->assertSame('_default', $theme->resolveForRequest('bloggs.test'));
        $this->assertSame('_default', $theme->current());
        $this->assertSame('bloggs', $theme->site()['theme'], 'Карточка сайта при этом сохранена.');

        // 7. Эвристика поддомена при недоступной БД (реестр пуст).
        $offline = new Theme($views, new SiteRegistry(null), new FileSystem(), $appView, '_default');
        $this->assertSame('animals', $offline->resolveForRequest('animals.localhost'));
        // Голый 'localhost' — не поддомен: НЕ переключали (''), активна дефолтная тема.
        $this->assertSame('', $offline->resolveForRequest('localhost'));
        $this->assertSame('_default', $offline->current());

        // 8. available()/exists() — скан папок view/app.
        $this->assertSame(['_default', 'animals'], array_values($offline->available()));
        $this->assertTrue($offline->exists('animals'));
        $this->assertFalse($offline->exists('bloggs'));

        $this->cleanup();
    }

    /**
     * Инвариант само-регистрации идентичности (концепция «один запуск = один сайт»,
     * используется KernelDispatcher перед резолвом темы):
     *  - ensureSite создаёт карточку для нового домена;
     *  - повторный вызов НЕ перетирает тему/заголовок, выбранные админом
     *    (идемпотентность: side-effect-free на уже зарегистрированном домене);
     *  - пустой домен — честный false без записи в БД.
     */
    public function testEnsureSiteIsIdempotentAndNonDestructive(): void
    {
        $this->dir = sys_get_temp_dir() . '/engine_theme_ensure_' . bin2hex(random_bytes(4));
        $appView   = $this->dir . '/view/app';
        mkdir($appView . '/animals', 0775, true);
        mkdir($appView . '/_default', 0775, true);
        mkdir($this->dir . '/config', 0775, true);
        file_put_contents($this->dir . '/config/database.php', "<?php\nreturn [\n  'driver' => 'sqlite',\n  'connections' => ['sqlite' => ['enabled' => true, 'driver' => 'sqlite', 'database' => ':memory:']],\n  'emulate_prep' => false,\n  'timeout' => 5,\n];\n");

        $container = new Container();
        $config    = new ConfigRepository(new PhpArrayParser(), $this->dir . '/config');
        $container->instance(ConfigRepository::class, $config);
        $container->instance('storage_path', $this->dir);

        /** @var DatabaseInterface $db */
        $db = new DatabaseManager($container, $config);
        $db->run("CREATE TABLE engine_sites (id INTEGER PRIMARY KEY AUTOINCREMENT, domain VARCHAR(191) NOT NULL UNIQUE, theme VARCHAR(64) NOT NULL DEFAULT '_default', title VARCHAR(191) NOT NULL DEFAULT '', is_active SMALLINT NOT NULL DEFAULT 1, created_at VARCHAR(40) NOT NULL DEFAULT '')");

        $views = new Template($this->dir . '/view/core', $appView, $this->dir . '/cache', false);
        $theme = new Theme($views, new SiteRegistry($db), new FileSystem(), $appView, '_default');

        // 1. Новый домен -> карточка создана.
        $this->assertTrue($theme->ensureSite('new.test', 'animals', 'Новый'), 'ensureSite регистрирует новый домен.');
        $this->assertSame(['domain' => 'new.test', 'theme' => 'animals', 'title' => 'Новый'], $theme->resolveSite('new.test'));

        // 2. Админ сменил тему/заголовок через registerSite (перезапись разрешена).
        $theme->registerSite('new.test', '_default', 'Админ переписал');

        // 3. Повторный ensureSite (например, после рестарта процесса) НЕ должен
        //    переть старую конфигурацию из аргументов — только сохранить факт.
        $this->assertTrue($theme->ensureSite('new.test', 'animals', 'Старое название'));
        $site = $theme->resolveSite('new.test');
        $this->assertNotNull($site);
        $this->assertSame('_default', $site['theme'], 'Тема админа сохранена.');
        $this->assertSame('Админ переписал', $site['title'], 'Заголовок админа сохранён.');
        $this->assertCount(1, $theme->sites(), 'Без дублей при повторной само-регистрации.');

        // 4. Пустой домен -> false, ничего не пишется.
        $this->assertFalse($theme->ensureSite('', 'animals', ''));
        $this->assertCount(1, $theme->sites());

        $this->cleanup();
    }

    private function cleanup(): void
    {
        if ($this->dir !== null && is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }
}
