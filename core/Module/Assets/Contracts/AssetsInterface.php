<?php
declare(strict_types=1);

namespace App\Core\Module\Assets\Contracts;

/**
 * Контракт модуля Assets — реестр CSS/JS и раздача статики.
 * Единственное, что видят другие модули и темы (п.2 Конституции).
 *
 * Каскад источников (по убыванию приоритета поиска файла):
 *   1. SITE:  storage/sites/<домен>/assets/<path> — песочница загруженного
 *      сайта (папки сайтов в public НЕ плодятся: публичная точка входа одна —
 *      public/index.php, изоляция реестром + песочницами);
 *   2. THEME: view/app/<тема>/assets/<path>       — статика темы сайта;
 *   3. CORE:  view/core/assets/<path>             — системная статика (админка).
 *
 * Регистрация стилей/скриптов декларативна, с зависимостями (deps) и
 * хуками расширения без правки движка (п.4 Конституции):
 *   фильтр  'engine.assets.styles'  (string $html, \App\Core\System\Http\Request $r)
 *   фильтр  'engine.assets.scripts' (string $html, \App\Core\System\Http\Request $r)
 *   действие 'engine.assets.registered' (AssetsInterface $assets, Request $r)
 */
interface AssetsInterface
{
    /** Раздел: системная статика ядра (view/core/assets/). */
    public const SCOPE_CORE = 'core';

    /** Раздел: статика текущей темы сайта (view/app/<theme>/assets/). */
    public const SCOPE_THEME = 'theme';

    /** Раздел: песочница загруженного сайта (storage/sites/<domain>/assets/). */
    public const SCOPE_SITE = 'site';

    /**
     * Зарегистрировать стиль. $handle — уникальное имя ('admin', 'theme-main'),
     * $src — относительный путь внутри каскада ('css/admin.css') или внешний
     * URL (http(s)://...); $deps — handles-зависимости (гарантируется порядок).
     */
    public function registerStyle(string $handle, string $src, array $deps, string $scope): void;

    /** Зарегистрировать скрипт (см. registerStyle). */
    public function registerScript(string $handle, string $src, array $deps, string $scope): void;

    /** Отменить регистрацию (тема может вытеснить core-стиль своим). */
    public function forget(string $handle): void;

    /** HTML-теги <link> для зарегистрированных стилей (топологический порядок deps). */
    public function styles(): string;

    /** HTML-теги <script> для зарегистрированных скриптов (топологический порядок deps). */
    public function scripts(): string;

    /**
     * Раздать файл статики по пути запроса '/assets/<relativePath>'.
     * null — файла нет в каскаде (Kernel отдаст 404). MIME — из белого списка
     * расширений; traversal-защита: '..' и абсолютные пути отклоняются.
     *
     * @return array{body:string,mime:string,path:string}|null
     */
    public function serve(string $relativePath): ?array;

    /** URL раздачи: '/assets/' . <путь> (внешний URL возвращается как есть). */
    public function url(string $src): string;

    /** Активировать контекст текущего запроса: домен (SCOPE_SITE) и тема (SCOPE_THEME). */
    public function useContext(?string $domain, string $theme): void;
}
