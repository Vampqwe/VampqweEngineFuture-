<?php
declare(strict_types=1);

namespace App\Core\Module\Config\Internal\Parsers;

use App\Core\Module\Config\Contracts\ConfigParserInterface;
use RuntimeException;

class PhpArrayParser implements ConfigParserInterface
{
    public function parse(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Файл конфигурации не найден: {$filePath}");
        }

        $config = require $filePath;

        if (!is_array($config)) {
            throw new RuntimeException("Файл конфигурации должен возвращать массив: {$filePath}");
        }

        return $config;
    }
}