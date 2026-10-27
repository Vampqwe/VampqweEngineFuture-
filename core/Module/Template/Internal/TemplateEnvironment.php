<?php
declare(strict_types=1);

namespace App\Core\Module\Template\Internal;

use RuntimeException;

/**
 * Внутренний рантайм-окружитель шаблонов (п.2: только изнутри модуля Template).
 * Захватывает тела @section через output buffering и хранит имя layout (@extends).
 * Layout-обёртку делает Template::render() двухпроходным рендером.
 */
final class TemplateEnvironment
{
    /** @var array<string,string> */
    private array $sections = [];

    /** @var list<string> */
    private array $stack = [];

    public ?string $extends = null;

    public function startSection(string $name): void
    {
        $this->stack[] = $name;
        ob_start();
    }

    public function stopSection(): void
    {
        $name = array_pop($this->stack);
        if ($name === null) {
            throw new RuntimeException('stopSection() без startSection().');
        }
        $this->sections[$name] = trim((string) ob_get_clean());
    }

    /** @return array<string,string> */
    public function sections(): array
    {
        return $this->sections;
    }
}
