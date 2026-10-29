<?php
declare(strict_types=1);

namespace App\Core\Module\Template\Contracts;

/**
 * Контракт модуля Template — рендеринг представлений (view-слой).
 * Единственное, что видят другие модули (п.2 Конституции).
 *
 * Два раздела views (решение пользователя):
 *   - CORE: view/core/  — шаблоны ядра (страницы ошибок, installer, админка);
 *   - APP:  view/app/<theme>/ — пользовательские темы, мультисайт:
 *           каждый сайт (домен) привязан к своей теме-папке.
 */
interface TemplateInterface
{
    /** Раздел: системные шаблоны ядра. */
    public const SCOPE_CORE = 'core';

    /** Раздел: пользовательские шаблоны сайтов/тем. */
    public const SCOPE_APP = 'app';

    /**
     * Отрендерить шаблон в строку. $template — имя без расширения ('errors/404').
     * $scope — SCOPE_CORE или SCOPE_APP; для APP используется текущая тема сайта.
     */
    public function render(string $template, array $data, string $scope): string;

    /** Рендер ядрового шаблона (сокращение для Error/Installer/Admin). */
    public function renderCore(string $template, array $data): string;

    /** Отрендерить строковый шаблон (для писем и т.п.). */
    public function renderString(string $contents, array $data): string;

    /** Глобальная переменная, доступная во всех шаблонах. */
    public function share(string $key, mixed $value): void;

    /** Шаблон существует? */
    public function exists(string $template, string $scope): bool;

    /**
     * Активировать тему пользовательского сайта (мультисайт).
     * Путь: view/app/<theme>/; fallback на theme engineStart.
     */
    public function setTheme(string $theme): void;

    /** Текущая активная тема. */
    public function theme(): string;

    /** Очистить весь кэш компиляции; возвращает число удалённых файлов. */
    public function clearCache(): int;
}
