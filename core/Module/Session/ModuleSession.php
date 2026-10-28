<?php
declare(strict_types=1);

namespace App\Core\Module\Session;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Security\Contracts\SecurityInterface;
use App\Core\Module\Session\Contracts\SessionInterface;
use App\Core\Module\Session\Contracts\SessionStoreMigratorInterface;
use App\Core\Module\Session\Internal\Cookie;
use App\Core\Module\Session\Internal\Crypto;
use App\Core\System\Support\SessionStoreInterface;
use DateTimeImmutable;

/**
 * Фасад модуля Session: серверные сессии + подписанные куки + CSRF.
 *
 * Зависимости (через контракты, п.3 Конституции): Config (session.*), Security.
 * Пути — только из контейнера ('sessions_path', единая точка правды Paths).
 *
 * Миграция file<->redis без потери сессий: при чтении id запись ищется в
 * активном сторе, а при отсутствии — в ДРУГИХ сторах; найденная переносится
 * в активный, старый ключ удаляется. Смена session.driver в конфиге поэтому
 * не «выкидывает» пользователей. Если redis недоступен (нет ext/сервера) —
 * прозрачная деградация на file-стор.
 */
final class ModuleSession implements SessionInterface, SessionStoreMigratorInterface
{
    private string $id = '';
    /** @var array<string,mixed> */
    private array $data = [];
    private bool $loaded = false;
    private bool $dirty  = false;

    /** @var array<string,SessionStoreInterface> */
    private array $stores = [];

    public function __construct(
        private readonly ConfigRepositoryInterface $config,
        private readonly SecurityInterface $security
    ) {
    }

    // ======================== API сессий ========================

    public function id(): string
    {
        $this->ensureLoaded();

        return $this->id;
    }

    public function start(): string
    {
        // перегенерация id — защита от session fixation
        $this->destroyCurrentRecord();
        $this->id   = bin2hex(random_bytes(20)); // 40 hex-символов
        $this->data = [];
        $this->loaded = true;
        $this->dirty  = true;

        return $this->id;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureLoaded();

        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureLoaded();
        $this->data[$key] = $value;
        $this->dirty      = true;
    }

    public function has(string $key): bool
    {
        $this->ensureLoaded();

        return array_key_exists($key, $this->data);
    }

    public function forget(string $key): void
    {
        $this->ensureLoaded();
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
    }

    public function all(): array
    {
        $this->ensureLoaded();

        return $this->data;
    }

    public function save(): void
    {
        $this->ensureLoaded();
        if ($this->id === '' || !$this->dirty) {
            return;
        }

        $store = $this->activeStore();
        if ($store !== null) {
            $store->write($this->id, $this->data, $this->ttlSeconds());
            $this->dirty = false;
        }
    }

    public function destroy(): void
    {
        $this->destroyCurrentRecord();
        $this->id     = '';
        $this->data   = [];
        $this->loaded = true;
        $this->dirty  = false;
    }

    // ======================== CSRF ========================

    public function csrfToken(): string
    {
        $token = $this->get('_csrf');
        if (!is_string($token) || strlen($token) < 32) {
            $token = $this->security->randomToken(32);
            $this->set('_csrf', $token);
        }

        return $token;
    }

    public function validateCsrf(?string $token): bool
    {
        $stored = $this->get('_csrf');

        return is_string($stored) && is_string($token) && $token !== ''
            && hash_equals($stored, $token);
    }

    // ======================== Куки ========================

    public function signedCookie(
        string $name,
        ?string $value = null,
        int $minutes = 120,
        bool $encrypt = false,
        array &$setCookieHeaders = []
    ): ?string {
        $read = $this->readSignedCookie($name);

        if ($value !== null) {
            $encoded = $encrypt
                ? 'v:' . Crypto::encrypt($value, $this->key())
                : Cookie::encode($value, key: '');
            $signature        = Cookie::sign($name, $encoded, $this->key());
            $setCookieHeaders[] = $this->buildSetCookie($name, $encoded . '.' . $signature, $minutes * 60);

            return $value;
        }

        return $read;
    }

    /** Заголовок Set-Cookie для текущей сессии (нужен ли — решает Kernel). */
    public function sessionCookieHeader(): string
    {
        $this->ensureLoaded();
        $name  = $this->cookieName();
        $value = $this->id !== ''
            ? $this->id . '.' . Cookie::sign($name, $this->id, $this->key())
            : 'x'; // пустая сессия — кука-заглушка, чтобы браузер начал её слать

        return $this->buildSetCookie($name, $value, $this->ttlSeconds());
    }

    /** Есть ли у клиента валидная кука текущей сессии (иначе Kernel отдаёт Set-Cookie). */
    public function clientHasValidCookie(): bool
    {
        $this->ensureLoaded();
        if ($this->id === '') {
            return false;
        }
        $name = $this->cookieName();
        $raw  = $_COOKIE[$name] ?? '';

        return is_string($raw)
            && Cookie::verify($name, $this->id, explode('.', $raw, 2)[1] ?? '', $this->key());
    }

    // ======================== Миграция сторов ========================

    public function driver(): string
    {
        return $this->configuredDriver();
    }

    public function migrateStore(string $fromDriver, string $toDriver): int
    {
        $from = $this->stores[$fromDriver] ?? null;
        $to   = $this->stores[$toDriver] ?? null;
        if ($from === null || $to === null || $from === $to || !$to->isAvailable()) {
            return 0;
        }

        $moved = 0;
        foreach ($from->all() as $id => $data) {
            if ($to->write((string) $id, $data, $this->ttlSeconds())) {
                $from->destroy((string) $id);
                $moved++;
            }
        }

        return $moved;
    }

    /** Инжектируется провайдером (пути — только из контейнера, п.7). */
    public function setStores(array $stores): void
    {
        /** @var array<string,SessionStoreInterface> $stores */
        $this->stores = $stores;
    }

    // ==================== внутреннее ====================

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $name = $this->cookieName();
        $raw  = $_COOKIE[$name] ?? '';
        if (!is_string($raw) || $raw === '' || !str_contains($raw, '.')) {
            return;
        }

        [$id, $sig] = explode('.', $raw, 2);
        if (!Cookie::verify($name, $id, $sig, $this->key())) {
            return; // куку подделали — делаем вид, что её нет
        }

        $active = $this->activeStore();
        $data   = $active?->read($id);

        if ($data === null) {
            // БЕСШОВНАЯ МИГРАЦИЯ: возможно, драйвер сменился (file<->redis).
            foreach ($this->stores as $driver => $store) {
                if ($store === $active) {
                    continue;
                }
                $found = $store->read($id);
                if ($found !== null) {
                    $active?->write($id, $found, $this->ttlSeconds());
                    $store->destroy($id);
                    $data = $found;
                    break;
                }
            }
        }

        if ($data === null) {
            return; // сессия истекла или её нет
        }

        $this->id   = $id;
        $this->data = $data;
    }

    private function destroyCurrentRecord(): void
    {
        if ($this->id === '') {
            return;
        }
        foreach ($this->stores as $store) {
            $store->destroy($this->id);
        }
    }

    private function configuredDriver(): string
    {
        return strtolower((string) $this->config->get('session.driver', 'file'));
    }

    private function activeStore(): ?SessionStoreInterface
    {
        $store = $this->stores[$this->configuredDriver()] ?? null;
        if ($store !== null && $store->isAvailable()) {
            return $store;
        }

        return $this->stores['file'] ?? null; // graceful-деградация
    }

    private function cookieName(): string
    {
        return (string) $this->config->get('session.cookie', 'VSESSID');
    }

    private function readSignedCookie(string $name): ?string
    {
        $raw = $_COOKIE[$name] ?? '';
        if (!is_string($raw) || $raw === '' || !str_contains($raw, '.')) {
            return null;
        }
        [$encoded, $sig] = explode('.', $raw, 2);
        if (!Cookie::verify($name, $encoded, $sig, $this->key())) {
            return null;
        }
        $decoded = Cookie::decode($encoded);
        if ($decoded === null) {
            return null;
        }

        return $decoded['encrypted']
            ? Crypto::decrypt($decoded['value'], $this->key())
            : $decoded['value'];
    }

    private function key(): string
    {
        $key = (string) ($_ENV['APP_KEY'] ?? $this->config->get('app.key', ''));
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }

        return $key !== '' ? $key : 'vampqwe-engine-dev-key-change-me';
    }

    private function buildSetCookie(string $name, string $value, int $maxAge): string
    {
        $domain = (string) $this->config->get('session.domain', '');
        $secure = (bool) $this->config->get('session.secure', false);
        $same   = (string) $this->config->get('session.same_site', 'Lax');

        $parts = [
            $name . '=' . rawurlencode($value),
            'Path=' . ((string) $this->config->get('session.path', '/')),
            'Max-Age=' . $maxAge,
            'Expires=' . (new DateTimeImmutable("+{$maxAge} seconds"))->format('D, d M Y H:i:s') . ' GMT',
            'HttpOnly',
            'SameSite=' . $same,
        ];
        if ($domain !== '') {
            $parts[] = 'Domain=' . $domain;
        }
        if ($secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private function ttlSeconds(): int
    {
        return max(60, (int) $this->config->get('session.lifetime', 7200));
    }
}
