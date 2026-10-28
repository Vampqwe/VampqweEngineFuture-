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

    /** @var array<string,string> domain => title (кэш загрузки) */
    private array $titles = [];

    /** @var array<string,array{domain:string,theme:string,title:string}> host => site (кэш резолва запроса) */
    private array $resolved = [];

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
     * Полная карточка сайта для хоста: точное совпадение домена в реестре ->
     * эвристика поддомена (animals.example.com -> запись example.com с темой
     * 'animals', если такая папка темы есть) -> первая запись реестра
     * (дефолтный сайт) -> null (пустой реестр / нет БД).
     *
     * @return array{domain:string,theme:string,title:string}|null
     */
    public function resolve(string $host): ?array
    {
        if (array_key_exists($host, $this->resolved)) {
            return $this->resolved[$host];
        }

        $map = $this->load();
        if ($map === []) {
            return $this->resolved[$host] = null;
        }

        // 1. Точное совпадение домена.
        if (isset($map[$host])) {
            return $this->resolved[$host] = [
                'domain' => $host,
                'theme'  => $map[$host],
                'title'  => $this->titles[$host] ?? '',
            ];
        }

        // 2. Эвристика поддомена: animals.example.com при зарегистрированном
        //    example.com -> тот же сайт, но тема по имени поддомена.
        $dot    = strpos($host, '.');
        $parent = $dot === false ? '' : substr($host, $dot + 1);
        if ($parent !== '' && isset($map[$parent])) {
            $guessed = substr($host, 0, $dot);
            if ($guessed !== '' && $guessed !== 'www') {
                return $this->resolved[$host] = [
                    'domain' => $parent,
                    'theme'  => $guessed,
                    'title'  => $this->titles[$parent] ?? '',
                ];
            }
        }

        // 3. Первая запись реестра = дефолтный сайт мультисайта.
        $first = (string) array_key_first($map);
        return $this->resolved[$host] = [
            'domain' => $first,
            'theme'  => $map[$first],
            'title'  => $this->titles[$first] ?? '',
        ];
    }

    /**
     * Зарегистрировать сайт в реестре (идемпотентно по домену).
     * Возвращает true при успехе; false — если БД недоступна или запрос упал.
     */
    public function register(string $domain, string $theme, string $title = ''): bool
    {
        $domain = strtolower(trim($domain));
        $theme  = trim($theme);
        if ($domain === '' || $theme === '' || $this->db === null) {
            return false;
        }

        try {
            $exists = $this->db->selectOne(
                'SELECT id FROM engine_sites WHERE domain = ?',
                [$domain]
            );
            if ($exists === null) {
                $this->db->run(
                    'INSERT INTO engine_sites (domain, theme, is_active, created_at) VALUES (?, ?, 1, ?)',
                    [$domain, $theme, date('c')]
                );
            } else {
                $this->db->run(
                    'UPDATE engine_sites SET theme = ?, is_active = 1 WHERE domain = ?',
                    [$theme, $domain]
                );
            }
        } catch (\Throwable) {
            return false;
        }

        // Инвалидация кэшей запроса — реестр обновился.
        $this->map      = null;
        $this->titles   = [];
        $this->resolved = [];
        return true;
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
            // Таблица может не иметь колонки title (старые дампы) — тогда titles пуст.
            try {
                $rows = $this->db->select(
                    'SELECT domain, theme, title FROM engine_sites WHERE is_active = 1 ORDER BY id'
                );
            } catch (\Throwable) {
                $rows = $this->db->select(
                    'SELECT domain, theme FROM engine_sites WHERE is_active = 1 ORDER BY id'
                );
            }
        } catch (\Throwable) {
            // БД жива, но запрос упал (например, дамп без таблицы) — пустой реестр.
            return $this->map = [];
        }

        $map = [];
        $titles = [];
        foreach ($rows as $row) {
            $domain = strtolower(trim((string) ($row['domain'] ?? '')));
            $theme  = trim((string) ($row['theme'] ?? ''));
            if ($domain !== '' && $theme !== '') {
                $map[$domain]    = $theme;
                $titles[$domain] = trim((string) ($row['title'] ?? ''));
            }
        }

        $this->titles = $titles;
        return $this->map = $map;
    }
}
