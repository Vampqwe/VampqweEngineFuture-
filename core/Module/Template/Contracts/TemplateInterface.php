<?php
declare(strict_types=1);

namespace App\Core\Module\Template\Contracts;

/**
 * Контракт модуля Template — рендеринг представлений (view-слой).
 * Единственное, что видят другие модули (п.2 Конституции).
 */
interface TemplateInterface
{
    /** Отрендерить шаблон в строку. $template — имя без расширения ('errors/404'). */
    public function render(string $template, array $data = []): string;

    /** Отрендерить строковый шаблон (для писем и т.п.). */
    public function renderString(string $contents, array $data = []): string;

    /** Глобальная переменная, доступная во всех шаблонах. */
    public function share(string $key, mixed $value): void;

    /** Шаблон существует? */
    public function exists(string $template): bool;
}
