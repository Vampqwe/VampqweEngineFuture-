<?php
declare(strict_types=1);

namespace App\Core\Module\Template\Contracts;

/**
 * Контракт модуля Template — рендеринг представлений (view-слой).
 * Единственное, что видят другие модули (п.2 Конституции).
 *
 * ДВА РАЗДЕЛА view-слоя (решение по архитектуре):
 *   'core/...' — шаблоны ЯДРА: страницы ошибок, инсталлер, админ-панель;
 *   'app/...'  — пользовательские темы сайтов (мультисайт), фолбэк темы — _default.
 * Имена полные, с префиксом раздела: render('core/errors/404'), render('app/pages/home').
 */
interface TemplateInterface
{
    /** Отрендерить шаблон в строку. $template — имя без расширения ('core/errors/404'). */
    public function render(string $template, array $data = []): string;

    /** Отрендерить строковый шаблон (для писем и т.п.). */
    public function renderString(string $contents, array $data = []): string;

    /** Глобальная переменная, доступная во всех шаблонах. */
    public function share(string $key, mixed $value): void;

    /** Шаблон существует? */
    public function exists(string $template): bool;

    /**
     * Выбрать тему пользовательского раздела для текущего запроса (мультисайт):
     * setSite('animals') -> рендер 'app/...' из view/app/animals/ с фолбэком на _default.
     */
    public function setSite(string $theme): void;

    /** Очистить кэш компилированных шаблонов. Возвращает число удалённых файлов. */
    public function clearCache(): int;
}
