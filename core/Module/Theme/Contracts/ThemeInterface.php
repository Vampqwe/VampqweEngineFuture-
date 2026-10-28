<?php
declare(strict_types=1);

namespace App\Core\Module\Theme\Contracts;

/**
 * Контракт модуля Theme — реестр сайтов мультисайта (домен -> тема).
 * Единственное, что видят другие модули (п.2 Конституции).
 *
 * Тема = папка шаблонов пользовательского сайта: view/app/<theme>/
 * (см. TemplateInterface::SCOPE_APP). Модуль Theme НЕ рендерит —
 * он только решает, КАКАЯ тема активна для текущего запроса, и
 * переключает её в Template. Разделение ответственности:
 *   Theme    -> «кто мы» (сайт/домен -> имя темы),
 *   Template -> «как рисуем» (view/app/<theme>/*.phtml).
 */
interface ThemeInterface
{
    /**
     * Специальная тема-фолбэк для сайтов без своей папки. Живёт в контракте,
     * а не в классе Theme: ядро (Kernel) резолвит дефолт по интерфейсу и не
     * знает конкретную реализацию (п.1/п.2, правило R6 линтера).
     */
    public const DEFAULT_THEME = '_default';

    /**
     * Активировать тему для домена запроса: БД-реестр engine_sites ->
     * эвристика поддомена -> config-тема по умолчанию. Возвращает имя
     * применённой темы ('' = оставить тему по умолчанию).
     */
    public function resolveForRequest(string $host): string;

    /**
     * Резолвить САЙТ мультисайта для хоста запроса (без побочных эффектов):
     *   ['domain' => ..., 'theme' => ..., 'title' => ...] или null,
     * если реестр пуст / БД недоступна. Тема из карточки может не
     * существовать как папка — тогда рендер идёт через fallback '_default'
     * модуля Template (движок не падает).
     *
     * @return array{domain:string,theme:string,title:string}|null
     */
    public function resolveSite(string $host): ?array;

    /**
     * Карточка сайта, резолвленного для ПОСЛЕДНЕГО запроса
     * (или переданного хоста); null — если реестр недоступен.
     *
     * @return array{domain:string,theme:string,title:string}|null
     */
    public function site(?string $host): ?array;

    /**
     * Зарегистрировать сайт в реестре (домен -> тема). Идемпотентно по домену.
     * false — если БД недоступна/не установлена или таблица не пишет.
     */
    public function registerSite(string $domain, string $theme, string $title): bool;

    /** Немедленно переключить шаблонизатор на тему (без проверки домена). */
    public function use(string $theme): void;

    /** Имя текущей активной темы. */
    public function current(): string;

    /** Список доступных тем (папки view/app/* с layouts/site.phtml). */
    public function available(): array;

    /** Тема существует как папка? */
    public function exists(string $theme): bool;

    /** Реестр сайтов из БД: [['domain'=>..., 'theme'=>...], ...] ([] если БД недоступна). */
    public function sites(): array;
}
