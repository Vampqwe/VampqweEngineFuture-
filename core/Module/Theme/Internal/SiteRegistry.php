<?php
declare(strict_types=1);

namespace App\Core\Module\Theme\Internal;

use App\Core\Module\Database\Contracts\DatabaseInterface;

/**
 * Реестр сайтов мультисайта (внутренняя кухня модуля Theme — п.2).
 *
 * Хранит маппинг домен -> тема в таблице engine_sites (через контракт
 * DatabaseInterface, никакого new PDO). Результат кэшируется на время
 * запроса; при недоступной БД реестр пуст — резолвер использует
 * эвристику поддомена и тему по умолчанию.
 */
final class SiteRegistry
{
    /** @var array<string,string>|null domain => theme (кэш загрузки) */
    private ?array $map = null;

    public function __construct(
        private readonly ?DatabaseInterface $db,
    ) {
    }

    /**
     * Домен -> тема из БД ('' если нет записи / БД недоступна).
     */
    public function themeFor(string $host): string
    {
        $map = $this->load();
        return $map[$host] ?? '';
    }

    /**
     * Весь реестр: [['domain' => ..., 'theme' => ...], ...].
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->load() as $domain => $theme) {
            $out[] = ['domain' => $domain, 'theme' => $theme];
        }
        return $out;
    }

    /** @return array<string,string> */
    private function load(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        if ($this->db === null || !$this->db->tableExists('engine_sites')) {
            return $this->map = [];
        }

        try {
            $rows = $this->db->select(
                'SELECT domain, theme FROM engine_sites WHERE is_active = 1'
            );
        } catch (\Throwable) {
            // БД жива, но запрос упал (например, дамп без таблицы) — пустой реестр.
            return $this->map = [];
        }

        $map = [];
        foreach ($rows as $row) {
            $domain = strtolower(trim((string) ($row['domain'] ?? '')));
            $theme  = trim((string) ($row['theme'] ?? ''));
            if ($domain !== '' && $theme !== '') {
                $map[$domain] = $theme;
            }
        }

        return $this->map = $map;
    }
}
