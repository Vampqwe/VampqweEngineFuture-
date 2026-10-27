<?php
declare(strict_types=1);

namespace App\Core\Module\Config;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Config\Internal\Parsers\PhpArrayParser;

class ConfigRepository implements ConfigRepositoryInterface
{
    /** @var array<string, array> Загруженные конфиги по именам файлов */
    private array $items = [];

    public function __construct(
        private readonly PhpArrayParser $parser,
        private readonly string $configPath
    ) {
        $this->loadAll();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $group = array_shift($segments);

        if (!array_key_exists($group, $this->items)) {
            return $default;
        }

        $value = $this->items[$group];

        foreach ($segments as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return $this->get($key, self::MISSING) !== self::MISSING;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $group = array_shift($segments);

        if (!isset($this->items[$group]) || $segments === []) {
            $this->items[$group] = $value;
            return;
        }

        $target = &$this->items[$group];
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $target[$segment] = $value;
                break;
            }
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }
    }

    public function all(?string $group = null): array
    {
        if ($group === null) {
            return $this->items;
        }

        return $this->items[$group] ?? [];
    }

    /**
     * Маркер «ключа нет» для корректной работы has() с null-значениями.
     */
    private const MISSING = "\0__missing__\0";

    private function loadAll(): void
    {
        foreach (glob($this->configPath . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $this->items[$name] = $this->parser->parse($file);
        }
    }
}
