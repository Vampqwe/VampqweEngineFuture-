<?php
declare(strict_types=1);

namespace App\Core\Module\Template;

use App\Core\Module\Template\Contracts\TemplateInterface;
use RuntimeException;

/**
 * Фасад модуля Template.
 *
 * Зависимости: FileSystem (чтение файлов шаблонов), Config (view.driver), Parser (компиляция).
 * РЕАЛИЗАЦИЯ В ПЛАНЕ.
 */
final class Template implements TemplateInterface
{
    public function __construct(private readonly string $viewPath = '')
    {
    }

    public function render(string $template, array $data = []): string
    {
        throw new RuntimeException('Модуль [Template] объявлен в ядре, но ещё не реализован.');
    }

    public function renderString(string $contents, array $data = []): string
    {
        throw new RuntimeException('Модуль [Template] ещё не реализован.');
    }

    public function share(string $key, mixed $value): void
    {
        throw new RuntimeException('Модуль [Template] ещё не реализован.');
    }

    public function exists(string $template): bool
    {
        return false; // честно: шаблонов пока нет
    }
}
