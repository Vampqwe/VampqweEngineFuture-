<?php
declare(strict_types=1);

namespace App\Core\Module\Parser;

use App\Core\Module\Parser\Contracts\ParserInterface;
use App\Core\Module\Parser\Internal\Parsers\IniParser;
use App\Core\Module\Parser\Internal\Parsers\JsonParser;
use InvalidArgumentException;

/**
 * Фасад модуля Parser. Регистрируется в контейнере как ParserInterface.
 * Зависимости: нет (фундаментный модуль, уровень 0 загрузки).
 */
final class Parser implements ParserInterface
{
    /** @var array<string, object> format => внутренний парсер */
    private array $parsers;

    public function __construct()
    {
        // new допускается: это реализация модуля, а не бизнес-логика (п.3 Конституции)
        $this->parsers = [
            'json' => new JsonParser(),
            'ini'  => new IniParser(),
        ];
    }

    public function parse(string $content, string $format): array
    {
        $parser = $this->parsers[strtolower($format)] ?? null;

        if ($parser instanceof JsonParser) {
            return $parser->decode($content);
        }
        if ($parser instanceof IniParser) {
            return $parser->decode($content);
        }

        throw new InvalidArgumentException("Формат [{$format}] не поддерживается модулем Parser.");
    }

    public function encode(array $data, string $format, bool $pretty = false): string
    {
        $parser = $this->parsers[strtolower($format)] ?? null;

        if ($parser instanceof JsonParser) {
            return $parser->encode($data, $pretty);
        }
        if ($parser instanceof IniParser) {
            return $parser->encode($data, $pretty);
        }

        throw new InvalidArgumentException("Формат [{$format}] не поддерживается модулем Parser.");
    }

    public function supports(string $format): bool
    {
        return isset($this->parsers[strtolower($format)]);
    }

    public function formats(): array
    {
        return array_keys($this->parsers);
    }
}
