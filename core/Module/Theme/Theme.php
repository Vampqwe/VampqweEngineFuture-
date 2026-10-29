<?php
declare(strict_types=1);

namespace App\Core\Module\Theme;

use App\Core\Module\FileSystem\Contracts\FileSystemInterface;
use App\Core\Module\Theme\Contracts\ThemeInterface;
use App\Core\Module\Theme\Internal\SiteRegistry;
use App\Core\Module\Template\Contracts\TemplateInterface;

/**
 * Модуль Theme — реестр сайтов мультисайта и переключатель тем.
 *
 * Разделяет view на два мира (решение пользователя):
 *   CORE: view/core/        — шаблоны ядра (ошибки, installer, админка);
 *   APP:  view/app/<theme>/ — темы пользовательских сайтов, по папке на сайт.
 *
 * Для домена запроса определяется тема (реестр engine_sites -> эвристика
 * поддомена -> дефолт из config/template.php) и переключается Template.
 * Зависимости приходят только через контракты (п.3 Конституции).
 */
final class Theme implements ThemeInterface
{
    /** Специальная тема-фолбэк для сайтов без своей папки. */
    public const DEFAULT_THEME = 'engineStart';

    private string $current;

    /** Хост последнего запроса (для site()). */
    private string $lastHost = '';

    /** @var array{domain:string,theme:string,title:string}|null карточка последнего резолва. */
    private ?array $lastSite = null;

    public function __construct(
        private readonly TemplateInterface $views,
        private readonly SiteRegistry $registry,
        private readonly FileSystemInterface $fs,
        private readonly string $appViewPath,
        private readonly string $defaultTheme = self::DEFAULT_THEME,
    ) {
        $this->current = $this->defaultTheme;
    }

    public function resolveForRequest(string $host): string
    {
        $host = strtolower($this->normalizeHost($host));
        $this->lastHost = $host;

        // 1. Полная карточка сайта из БД-реестра (+ эвристика поддомена в резолвере).
        $site = $this->registry->resolve($host);
        if ($site !== null) {
            $this->lastSite = $site;

            if ($this->exists($site['theme'])) {
                $this->use($site['theme']);
                return $site['theme'];
            }

            // Тема реестра без папки: деградируем на тему по умолчанию, НО
            // возвращаем её как активную — иначе вызывающий код (Kernel) не
            // сможет отличить «переключили» от «не переключали».
            $this->use($this->defaultTheme);
            return $this->defaultTheme;
        }

        $this->lastSite = null;

        // 2. Эвристика поддомена: animals.local / animals.localhost -> 'animals'.
        //    Работает даже при недоступной БД (деградация, а не падение).
        $guessed = $this->guessFromSubdomain($host);
        if ($guessed !== '' && $this->exists($guessed)) {
            $this->use($guessed);
            return $guessed;
        }

        // 3. Тема по умолчанию из конфигурации.
        $this->use($this->defaultTheme);
        return '';
    }

    public function resolveSite(string $host): ?array
    {
        return $this->registry->resolve(strtolower($this->normalizeHost($host)));
    }

    public function site(?string $host = null): ?array
    {
        if ($host === null) {
            return $this->lastSite;
        }
        return $this->resolveSite($host);
    }

    public function registerSite(string $domain, string $theme, string $title = ''): bool
    {
        return $this->registry->register($domain, $theme, $title);
    }

    /**
     * Идемпотентная само-регистрация идентичности экземпляра в реестре
     * (концепция «один запуск = один сайт»): если домен уже зарегистрирован —
     * ничего не делаем (не перетираем тему/заголовок, выбранные админом).
     * Возвращает true, если запись есть/создана; false при недоступной БД.
     */
    public function ensureSite(string $domain, string $theme, string $title = ''): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return false;
        }
        if ($this->registry->hasDomain($domain)) {
            return true;
        }

        return $this->registry->register($domain, $theme, $title);
    }

    public function use(string $theme): void
    {
        $this->current = $theme;
        $this->views->setTheme($theme);
    }

    public function current(): string
    {
        return $this->current;
    }

    public function available(): array
    {
        if (!$this->fs->isDirectory($this->appViewPath)) {
            return [];
        }

        // glob() — не файловая операция ядра в смысле бизнес-логики, а скан
        // каталога тем (FileSystemInterface не предоставляет listDirectory).
        $themes = [];
        foreach ((array) glob($this->appViewPath . '/*', GLOB_ONLYDIR) as $entry) {
            $name = $this->fs->basename((string) $entry);
            if ($name !== '' && $name[0] !== '.') {
                $themes[] = $name;
            }
        }
        sort($themes);
        return $themes;
    }

    public function exists(string $theme): bool
    {
        // Только безопасное имя папки-темы (та же защита, что в Template::setTheme):
        // 'animals', engineStart; всё остальное ('../x', пустое) — не тема.
        if (!preg_match('#^[A-Za-z0-9_\-]+$#', $theme)) {
            return false;
        }

        return $this->fs->isDirectory($this->appViewPath . '/' . $theme);
    }

    public function sites(): array
    {
        return $this->registry->all();
    }

    /** Убирает порт: example.com:8080 -> example.com */
    private function normalizeHost(string $host): string
    {
        return preg_replace('/:\d+$/', '', $host) ?? $host;
    }

    /** animals.localhost -> animals (без www, localhost, 127.0.0.1). */
    private function guessFromSubdomain(string $host): string
    {
        // Эвристика имеет смысл только для ПОДДОМЕНА: хост с точкой внутри
        // ('animals.localhost'). Голый хост без точки ('localhost', 'server') —
        // это не поддомен, угадывать тут нечего -> дефолтная тема.
        if ($host === '' || !str_contains($host, '.') || str_contains($host, '127.0.0.1')) {
            return '';
        }
        $first = explode('.', $host)[0];
        if (preg_match('#^[a-z0-9_-]+$#', $first) && $first !== 'www') {
            return $first;
        }
        return '';
    }
}
