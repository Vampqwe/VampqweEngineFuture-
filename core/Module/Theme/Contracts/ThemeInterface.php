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
     * Активировать тему для домена запроса: БД-реестр engine_sites ->
     * эвристика поддомена -> config-тема по умолчанию. Возвращает имя
     * применённой темы ('' = оставить тему по умолчанию).
     */
    public function resolveForRequest(string $host): string;

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
