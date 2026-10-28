<?php
declare(strict_types=1);

namespace App\Core\Module\Assets\Internal;

use App\Core\Module\Assets\Contracts\AssetsInterface;
use App\Core\Module\FileSystem\Contracts\FileSystemInterface;
use App\Core\Module\Hook\Contracts\HookInterface;

/**
 * Реестр ассетов и раздача статики (внутренняя кухня модуля Assets — п.2).
 *
 * Хранит декларации стилей/скриптов, строит топологический порядок по deps
 * и раздаёт файлы из каскада SITE -> THEME -> CORE (см. контракт).
 * Все файловые операции — через FileSystemInterface (п.7: пути приходят
 * из контейнера, здесь их не вычисляют).
 */
final class AssetsRegistry implements AssetsInterface
{
    /** @var array<string,array{handle:string,src:string,deps:array<int,string>,scope:string}> */
    private array $styles = [];

    /** @var array<string,array{handle:string,src:string,deps:array<int,string>,scope:string}> */
    private array $scripts = [];

    /** Белые списки: расширение => [mime, безопасная поддиректория раздачи]. */
    private const MIME_MAP = [
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'application/javascript; charset=utf-8',
        'mjs'  => 'application/javascript; charset=utf-8',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
        'otf'  => 'font/otf',
        'eot'  => 'application/vnd.ms-fontobject',
        'json' => 'application/json; charset=utf-8',
        'txt'  => 'text/plain; charset=utf-8',
        'map'  => 'application/json; charset=utf-8',
        'webmanifest' => 'application/manifest+json',
    ];

    public function __construct(
        private readonly FileSystemInterface $fs,
        private readonly string $coreAssetsPath,   // view/core/assets
        private readonly string $appViewPath,      // view/app
        private readonly string $sitesPath,        // storage/sites
        private readonly ?HookInterface $hook = null, // п.4: хуки расширения (опционально)
    ) {
    }

    public function registerStyle(string $handle, string $src, array $deps = [], string $scope = self::SCOPE_THEME): void
    {
        $this->styles[$handle] = ['handle' => $handle, 'src' => $src, 'deps' => $deps, 'scope' => $scope];
    }

    public function registerScript(string $handle, string $src, array $deps = [], string $scope = self::SCOPE_THEME): void
    {
        $this->scripts[$handle] = ['handle' => $handle, 'src' => $src, 'deps' => $deps, 'scope' => $scope];
    }

    public function forget(string $handle): void
    {
        unset($this->styles[$handle], $this->scripts[$handle]);
    }

    public function styles(): string
    {
        $out = '';
        foreach ($this->order($this->styles) as $item) {
            $href = htmlspecialchars($this->url($item['src']), ENT_QUOTES);
            $out .= "<link rel=\"stylesheet\" href=\"{$href}\" media=\"screen\">\n";
        }
        // П.4 Конституции: хук-фильтр — тема/модуль может дописать свои теги.
        if ($this->hook !== null) {
            $out = (string) $this->hook->applyFilters('engine.assets.styles', $out);
        }
        return rtrim($out, "\n");
    }

    public function scripts(): string
    {
        $out = '';
        foreach ($this->order($this->scripts) as $item) {
            $src = htmlspecialchars($this->url($item['src']), ENT_QUOTES);
            $out .= "<script src=\"{$src}\" defer></script>\n";
        }
        // П.4 Конституции: хук-фильтр для скриптов.
        if ($this->hook !== null) {
            $out = (string) $this->hook->applyFilters('engine.assets.scripts', $out);
        }
        return rtrim($out, "\n");
    }

    public function serve(string $relativePath): ?array
    {
        $rel = ltrim($relativePath, '/');
        if ($rel === '' || !preg_match('#^[A-Za-z0-9_\-./]+$#', $rel) || str_contains($rel, '..')) {
            return null; // traversal / недопустимые символы — как будто файла нет
        }

        $ext = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));
        if (!isset(self::MIME_MAP[$ext])) {
            return null; // белый список расширений
        }

        foreach ($this->candidates($rel) as $abs) {
            if ($this->fs->exists($abs)) {
                $body = $this->fs->read($abs);

                return ['body' => $body, 'mime' => self::MIME_MAP[$ext], 'path' => $abs];
            }
        }

        return null;
    }

    public function url(string $src): string
    {
        if (preg_match('#^(https?:)?//#i', $src)) {
            return $src; // внешний URL (CDN) — как есть
        }
        $rel = ltrim($src, '/');

        return '/assets/' . $rel . ($this->versionOf($rel) !== '' ? '?v=' . $this->versionOf($rel) : '');
    }

    /**
     * Версия для кэш-бустинга: 8 hex-симворов md5 от mtime+size+hash содержимого первого
     * существующего файла каскада. Счёт идёт по всем зарегистрированным
     * src'там за один запрос (статичный кэш на экземпляр реестра).
     */
    private array $versionCache = [];

    private function versionOf(string $rel): string
    {
        if (isset($this->versionCache[$rel])) {
            return $this->versionCache[$rel];
        }

        $version = '';
        foreach ($this->candidates($rel) as $abs) {
            clearstatcache(true, $abs);
            if (is_file($abs)) {
                // mtime+size не различают перезапись того же размера за одну
                // секунду — добавляем hash содержимого (файлы статики малы).
                $stat = (string) (filemtime($abs) ?: 0) . ':' . (filesize($abs) ?: 0)
                    . ':' . (@file_get_contents($abs) ?: '');
                $version = substr(md5($stat), 0, 8);
                break;
            }
        }

        return $this->versionCache[$rel] = $version;
    }

    public function useContext(?string $domain, string $theme): void
    {
        $newDomain = $domain !== null && $domain !== '' ? $domain : null;
        $newTheme  = preg_match('#^[A-Za-z0-9_\-]+$#', $theme) ? $theme : '';

        // Смена контекста (сайт/тема) меняет каскад «site -> theme -> core» —
        // значит и резолв файлов для версионирования. Инвалидируем кэш, иначе
        // второй сайт на том же экземпляре реестра получил бы ?v= от чужого
        // каскада (утечка версии между доменами).
        if ($newDomain !== $this->domain || $newTheme !== $this->theme) {
            $this->versionCache = [];
        }

        $this->domain = $newDomain;
        $this->theme  = $newTheme;
    }

    /** Домен/тема текущего запроса (заполняется Kernel'ом через useContext). */
    private ?string $domain = null;
    private string $theme = '';

    /**
     * Каскад абсолютных путей для относительного пути ассета:
     * site-sandbox -> theme -> core. Первый существующий выигрывает.
     *
     * @return array<int,string>
     */
    private function candidates(string $rel): array
    {
        $out = [];
        if ($this->domain !== null) {
            $out[] = $this->sitesPath . '/' . $this->domain . '/assets/' . $rel;
        }
        if ($this->theme !== '') {
            $out[] = $this->appViewPath . '/' . $this->theme . '/assets/' . $rel;
        }
        $out[] = $this->coreAssetsPath . '/' . $rel;
        return $out;
    }

    /**
     * Топологическая сортировка по deps: зависимость печатается раньше
     * зависимого. Цикл deps — деградация: узел печатается как есть (п.9).
     *
     * @param array<string,array{handle:string,src:string,deps:array<int,string>,scope:string}> $items
     * @return array<int,array{handle:string,src:string,deps:array<int,string>,scope:string}>
     */
    private function order(array $items): array
    {
        $result = [];
        $seen   = [];

        $visit = function (string $handle) use (&$visit, &$result, &$seen, $items): void {
            if (isset($seen[$handle])) {
                return; // уже напечатан или цикл — молча пропускаем
            }
            $seen[$handle] = true;

            foreach ($items[$handle]['deps'] ?? [] as $dep) {
                if (isset($items[$dep])) {
                    $visit($dep);
                }
            }
            $result[] = $items[$handle];
        };

        foreach (array_keys($items) as $handle) {
            $visit($handle);
        }

        return $result;
    }
}
