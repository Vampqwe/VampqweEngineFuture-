<?php
declare(strict_types=1);

namespace App\Modules\Pages;

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Modules\Pages\Contracts\PageRepositoryInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Реализация репозитория страниц (модуль Pages).
 *
 * Внутренняя кухня модуля (п.2 Конституции): наружу отдаётся ТОЛЬКО через
 * контракт PageRepositoryInterface (регистрируется провайдером). Ядро и
 * другие модули не должны импортировать этот класс напрямую.
 *
 * Данные: таблица `pages` схемы движка (database/db.sql). Все запросы —
 * только через DatabaseInterface (prepared statements, bindings), никакого
 * «сырого» new PDO (п.3 Конституции).
 */
final class PageRepository implements PageRepositoryInterface
{
    /** Статус публикации, соответствующий contract-семантике «published». */
    private const STATUS_PUBLISHED = 'published';

    /** Поля, разрешённые к записи через update() (whitelist — защита от массового присвоения). */
    private const UPDATABLE = [
        'title', 'description', 'keywords', 'canonical',
        'og_title', 'og_description', 'og_image', 'meta_robots',
        'slug', 'content', 'page_type', 'parent_id', 'status',
        'sort_order', 'is_in_menu', 'published_at', 'author_id',
    ];

    public function __construct(
        private readonly DatabaseInterface $db
    ) {
    }

    public function listPublished(int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage)); // жёсткий потолок страницы выборки

        return $this->db->select(
            'SELECT id, title, slug, page_type, sort_order, published_at'
            . ' FROM pages WHERE status = ? ORDER BY sort_order ASC, published_at DESC LIMIT ? OFFSET ?',
            [self::STATUS_PUBLISHED, $perPage, ($page - 1) * $perPage]
        );
    }

    public function findBySlug(string $slug): ?array
    {
        $slug = $this->normalizeSlug($slug);
        if ($slug === '') {
            return null;
        }

        return $this->db->selectOne(
            'SELECT * FROM pages WHERE slug = ? AND status = ?',
            [$slug, self::STATUS_PUBLISHED]
        );
    }

    public function create(string $title, string $content, ?string $slug = null, bool $published = false): int
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('Pages: заголовок страницы обязателен.');
        }

        $slug = $this->normalizeSlug((string) ($slug ?? '')) !== ''
            ? $this->normalizeSlug((string) $slug)
            : $this->slugify($title);

        // Уникальность slug: при коллизии добавляем числовой суффикс (-2, -3, ...).
        $candidate = $slug;
        for ($i = 2; !$this->isSlugFree($candidate); $i++) {
            $candidate = $slug . '-' . $i;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->run(
            'INSERT INTO pages (title, description, canonical, og_title, slug, content, status, created_at, updated_at, published_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $title,
                mb_substr(strip_tags($content), 0, 500),      // description из начала контента
                '/' . $candidate,                              // canonical = /slug
                $title,                                        // og:title = title
                $candidate,
                $content,
                $published ? self::STATUS_PUBLISHED : 'draft',
                $now,
                $now,
                $published ? $now : null,
            ]
        );

        $id = $this->db->lastInsertId();
        if ($id <= 0) {
            throw new RuntimeException('Pages: не удалось получить id созданной страницы.');
        }

        return $id;
    }

    public function update(int $id, array $fields): bool
    {
        $allowed = array_intersect_key($fields, array_flip(self::UPDATABLE));
        if ($allowed === []) {
            return $this->exists($id); // нечего обновлять — но страница может существовать
        }

        if (isset($allowed['slug'])) {
            $allowed['slug'] = $this->normalizeSlug((string) $allowed['slug']);
            if (!$this->isSlugFree((string) $allowed['slug'], $id)) {
                throw new InvalidArgumentException("Pages: слаг '{$allowed['slug']}' уже занят.");
            }
        }

        $sets     = [];
        $bindings = [];
        foreach ($allowed as $col => $val) {
            $sets[]     = $col . ' = ?';
            $bindings[] = $val;
        }

        $bindings[] = date('Y-m-d H:i:s');
        $bindings[] = $id;
        $affected   = $this->db->run(
            'UPDATE pages SET ' . implode(', ', $sets) . ', updated_at = ? WHERE id = ?',
            $bindings
        );

        return $affected > 0 || $this->exists($id);
    }

    public function delete(int $id): bool
    {
        return $this->db->run('DELETE FROM pages WHERE id = ?', [$id]) > 0;
    }

    public function isSlugFree(string $slug, ?int $exceptId = null): bool
    {
        $slug = $this->normalizeSlug($slug);
        if ($slug === '') {
            return false;
        }

        $sql      = 'SELECT id FROM pages WHERE slug = ?';
        $bindings = [$slug];
        if ($exceptId !== null) {
            $sql       .= ' AND id <> ?';
            $bindings[] = $exceptId;
        }

        return $this->db->selectOne($sql, $bindings) === null;
    }

    /** Есть ли страница с таким id (любого статуса). */
    public function exists(int $id): bool
    {
        return $this->db->selectOne('SELECT id FROM pages WHERE id = ?', [$id]) !== null;
    }

    // ================= internal =================

    /** Слаг: только latin/цифры/дефисы, строчные, без ведущих/замыкающих дефисов. */
    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug, " /\t\n\r\x0B-"));
        $slug = preg_replace('/[^a-z0-9\-]+/', '', $slug) ?? '';

        return preg_replace('/-{2,}/', '-', $slug) ?? '';
    }

    /** Транслитерация + слагификация заголовка (ru -> latin). */
    private function slugify(string $title): string
    {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z',
            'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
            'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh',
            'щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];
        $title = strtr(mb_strtolower($title), $map);
        $title = preg_replace('/[^a-z0-9]+/', '-', $title) ?? '';

        $slug = $this->normalizeSlug($title);

        return $slug !== '' ? $slug : 'page-' . substr(md5($title . microtime()), 0, 8);
    }
}
