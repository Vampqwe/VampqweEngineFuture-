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
    public const DEFAULT_THEME = '_default';

    private string $current;

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

        // 1. Реестр сайтов из БД (точный маппинг домен -> тема).
        $theme = $this->registry->themeFor($host);
        if ($theme !== '' && $this->exists($theme)) {
            $this->use($theme);
            return $theme;
        }

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
        if ($host === '' || $host === 'localhost' || str_contains($host, '127.0.0.1')) {
            return '';
        }
        $first = explode('.', $host)[0];
        if (preg_match('#^[a-z0-9_-]+$#', $first) && $first !== 'www') {
            return $first;
        }
        return '';
    }
}
