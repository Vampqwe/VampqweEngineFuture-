<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Module\Assets\Internal\AssetsRegistry;
use App\Core\Module\FileSystem\FileSystem;
use Tests\Framework\TestCase;

/**
 * Тест модуля Assets (Этап 2): каскад site -> theme -> core, белый список
 * MIME, traversal-защита, топологический порядок deps, URL-резолв.
 */
final class AssetsTest extends TestCase
{
    private ?string $dir = null;

    private function registry(): AssetsRegistry
    {
        $this->dir = sys_get_temp_dir() . '/engine_assets_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/core/css', 0775, true);
        mkdir($this->dir . '/appView/site/assets/css', 0775, true);
        mkdir($this->dir . '/sites/example.test/assets/css', 0775, true);

        file_put_contents($this->dir . '/core/css/admin.css', 'CORE');
        file_put_contents($this->dir . '/appView/site/assets/style.css', 'THEME');
        file_put_contents($this->dir . '/sites/example.test/assets/css/admin.css', 'SITE');
        file_put_contents($this->dir . '/core/evil.php', '<?php system($_GET["c"]);');

        return new AssetsRegistry(
            new FileSystem(),
            $this->dir . '/core',
            $this->dir . '/appView',
            $this->dir . '/sites',
        );
    }

    public function testCascadeSiteThemeCore(): void
    {
        $r = $this->registry();

        // Только core-контекст: берём из view/core.
        $r->useContext(null, '_none_');
        $f = $r->serve('css/admin.css');
        $this->assertSame('CORE', $f['body'] ?? null);
        $this->assertTrue(str_starts_with($f['mime'] ?? '', 'text/css'));

        // Тема отдаёт свой файл, которого нет в core.
        $r->useContext(null, 'site');
        $f = $r->serve('style.css');
        $this->assertSame('THEME', $f['body'] ?? null);

        // Песочница сайта перекрывает core (изоляция загруженного сайта).
        $r->useContext('example.test', 'site');
        $f = $r->serve('css/admin.css');
        $this->assertSame('SITE', $f['body'] ?? null);
    }

    public function testTraversalAndWhitelistRejected(): void
    {
        $r = $this->registry();
        $r->useContext('example.test', 'site');

        $this->assertNull($r->serve('../sites/example.test/css/admin.css'), 'traversal up');
        $this->assertNull($r->serve('css/../evil.php'), 'mixed traversal');
        $this->assertNull($r->serve('evil.php'), 'не-MIME расширение (php) запрещено');
        $this->assertNull($r->serve(''), 'пустой путь');
        $this->assertNull($r->serve('a b.css'), 'пробелы запрещены');
        $this->assertNull($r->serve('missing.css'), 'нет файла — null');
    }

    public function testDependencyOrderingAndForget(): void
    {
        $r = $this->registry();
        // Регистрация в «неправильном» порядке — вывод обязан быть по deps.
        $r->registerStyle('admin', 'css/admin.css', ['base']);
        $r->registerStyle('base', 'css/base.css');
        $html = $r->styles();
        $posBase = strpos($html, 'css/base.css');
        $posAdmin = strpos($html, 'css/admin.css');
        $this->assertTrue($posBase !== false && $posAdmin !== false && $posBase < $posAdmin, 'base раньше admin');

        // Цикл deps — деградация без падения и без дублей (п.9).
        $r->registerStyle('a', 'css/a.css', ['b']);
        $r->registerStyle('b', 'css/b.css', ['a']);
        $html2 = $r->styles();
        $this->assertSame(1, substr_count($html2, 'css/a.css'));
        $this->assertSame(1, substr_count($html2, 'css/b.css'));

        $r->forget('base');
        $this->assertFalse(str_contains($r->styles(), 'css/base.css'));
    }

    public function testUrlResolution(): void
    {
        $r = $this->registry();
        // Существующий файл каскада -> URL с кэш-бустингом ?v=<8 hex>.
        $url = $r->url('css/admin.css');
        $this->assertTrue(str_starts_with($url, '/assets/css/admin.css?v='), 'версионированный URL: ' . $url);
        $this->assertSame(1, preg_match('/^\?v=[0-9a-f]{8}$/', substr($url, strlen('/assets/css/admin.css'))));
        // Несуществующий файл -> без версии (не ломать внешние/отсутствующие src).
        $this->assertSame('/assets/css/missing.css', $r->url('css/missing.css'));
        // Тот же src с ведущим слэшем — тот же версионированный URL.
        $this->assertSame($url, $r->url('/css/admin.css'));
        $this->assertSame('https://cdn.example/x.css', $r->url('https://cdn.example/x.css'));
        $this->assertSame('//cdn.example/x.js', $r->url('//cdn.example/x.js'));

        $r->registerScript('main', 'js/main.js');
        $this->assertTrue(str_contains($r->scripts(), '<script src="/assets/js/main.js" defer>'));
    }

    public function testVersionChangesWithFile(): void
    {
        $r = $this->registry();
        $before = $r->url('css/admin.css');
        sleep(1);
        file_put_contents($this->dir . '/core/css/admin.css', 'CORE2-changed');
        clearstatcache();
        $after = $r->url('css/admin.css');
        $this->assertFalse($before === $after, 'изменение файла меняет ?v= (сброс кэша браузера)');
    }

    public function testServeReturnsPathForValidation(): void
    {
        $r = $this->registry();
        $file = $r->serve('css/admin.css');
        $this->assertTrue($file !== null && str_ends_with($file['path'], 'core/css/admin.css'), 'path первого совпадения каскада');
    }

    public function testHookFiltersExtendAssetTags(): void
    {
        // П.4 Конституции: сторонний модуль дописывает свои теги через хуки,
        // не правя движок и layout.
        $hook = new \App\Core\Module\Hook\Hook();
        $r = new AssetsRegistry(
            new FileSystem(),
            $this->dir . '/core',
            $this->dir . '/appView',
            $this->dir . '/sites',
            $hook,
        );
        $r->registerStyle('admin', 'css/admin.css');
        // В шине движка фильтры и действия — один механизм: addAction + applyFilters.
        $hook->addAction('engine.assets.styles', static fn(string $html): string => $html . "\n<link rel=\"stylesheet\" href=\"/extra.css\">");
        $hook->addAction('engine.assets.scripts', static fn(string $html): string => $html . "\n<script src=\"/extra.js\"></script>");

        $this->assertTrue(str_contains($r->styles(), '/extra.css'), 'фильтр стилей применён');
        $this->assertTrue(str_contains($r->scripts(), '/extra.js'), 'фильтр скриптов применён');
    }

    public function testFontAndImageMimes(): void
    {
        $r = $this->registry();
        file_put_contents($this->dir . '/core/f.woff2', 'F');
        file_put_contents($this->dir . '/core/i.svg', '<svg/>');
        $this->assertSame('font/woff2', $r->serve('f.woff2')['mime'] ?? null);
        $this->assertSame('image/svg+xml', $r->serve('i.svg')['mime'] ?? null);
    }
}
