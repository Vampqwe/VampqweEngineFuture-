<?php
declare(strict_types=1);

namespace App\Core\Module\Error;

use App\Core\Module\Error\Contracts\ErrorPageInterface;
use App\Core\Module\Template\Contracts\TemplateInterface;
use Throwable;

/**
 * Фасад модуля Error.
 *
 * Зависимости: Template (view/core/errors/* — красивые страницы), Config (app.debug).
 * Если Template не зарегистрирован (ранняя стадия бутстрапа) — отдаёт минимальную
 * встроенную страницу, чтобы движок не падал сам в обработчике ошибок.
 */
final class ErrorPageRenderer implements ErrorPageInterface
{
    /** @var array<int,string> карта код -> имя шаблона view/core/errors/<name>.phtml */
    private const TEMPLATES = [
        404 => 'errors/404',
        419 => 'errors/419',
        500 => 'errors/500',
        503 => 'errors/503',
    ];

    /** @var array<int,string> переопределения имён шаблонов (extend()) */
    private array $templates = [];

    public function __construct(
        private readonly bool $debug = false,
        private readonly ?TemplateInterface $templates_ = null,
    ) {
    }

    public function render(int $statusCode, ?Throwable $exception = null): string
    {
        $title = match ($statusCode) {
            404 => 'Страница не найдена',
            419 => 'Сессия истекла или токен безопасности недействителен',
            503 => 'Сервис временно недоступен',
            default => 'Внутренняя ошибка сервера',
        };

        if ($this->templates_ !== null) {
            $name = $this->templates[$statusCode] ?? self::TEMPLATES[$statusCode] ?? 'errors/500';
            if ($this->templates_->exists($name, TemplateInterface::SCOPE_CORE)) {
                try {
                    return $this->templates_->renderCore($name, [
                        'code'      => $statusCode,
                        'title'     => $title,
                        'exception' => $this->debug ? $exception : null,
                        'debug'     => $this->debug,
                    ]);
                } catch (Throwable) {
                    // шаблон сломан — падаем на встроенную страницу, а не в рекурсию
                }
            }
        }

        return $this->fallback($statusCode, $title, $exception);
    }

    public function extend(int $statusCode, string $template): void
    {
        $this->templates[$statusCode] = $template;
    }

    private function fallback(int $code, string $title, ?Throwable $e): string
    {
        $trace = '';
        if ($this->debug && $e !== null) {
            $trace = '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</pre>';
        }

        return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>' . $code . '</title></head>'
             . '<body><h1>' . $code . ' — ' . htmlspecialchars($title, ENT_QUOTES) . '</h1>' . $trace . '</body></html>';
    }
}
