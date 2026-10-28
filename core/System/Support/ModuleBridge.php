<?php
declare(strict_types=1);

namespace App\Core\System\Support;

use App\Core\Module\Admin\Contracts\AdminPanelInterface;
use App\Core\Module\Assets\Contracts\AssetsInterface;
use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Error\Contracts\ErrorPageInterface;
use App\Core\Module\FileSystem\Contracts\FileSystemInterface;
use App\Core\Module\Hook\Contracts\HookInterface;
use App\Core\Module\Installer\Contracts\InstallerInterface;
use App\Core\Module\Installer\Contracts\InstallerPageInterface;
use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\Module\Session\Contracts\SessionInterface;
use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\Module\Theme\Contracts\ThemeInterface;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\modules\Auth\Contracts\AuthManagerInterface;
use App\modules\Pages\Contracts\PageRendererInterface;

/**
 * ModuleBridge — ЕДИНСТВЕННЫЙ мост «ядро → контракты модулей» (теорема Конституции).
 *
 * Почему это усиление теоремы, а не обход:
 *  - п.2 соблюдается и без моста (ядро ссылалось только на Contracts/*), но
 *    имена 15+ контрактов были рассыпаны по телу Kernel (120+ FQN-упоминаний) —
 *    ядро оказалось связано ИМЕНАМИ модулей даже при формально чистых use-строках.
 *  - Мост собирает все такие ссылки в один файл-адаптер, и линтер получает
 *    исполняемое правило **R6b**: ни один файл core/System/** (кроме самого
 *    ModuleBridge) не содержит literal-ссылок на App\...\Module\<X>\<...>::class
 *    или App\modules\<X>\<...>::class в executable-коде. Теорема становится
 *    структурной: «зелёный lint-rules ⇒ ядро знает о модулях РОВНО через Bridge».
 *  - Каждый метод — capability-проверка `has()` + резолв `get()`: отсутствие
 *    модуля = null (движок деградирует честно, п.9), никогда не fatal.
 *
 * Архитектурный инвариант (KernelBoundaryTest T3, усиленная форма):
 *   grep '::class' в core/System/** без аннотации @constitution-exception
 *   обязан находить ссылки ТОЛЬКО на System\* и Support\*, и ни одной — на
 *   Module/modules. Нарушение порождает R6b ещё до запуска приложения.
 */
final class ModuleBridge
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    // ---------- опциональные способности (null = модуль недоступен) ----------

    public function session(): ?SessionInterface
    {
        return $this->opt(SessionInterface::class);
    }

    /** Capability Session: фоновая миграция сторов (Internal-механика за контрактом). */
    public function storeMigrator(): ?\App\Core\Module\Session\Contracts\SessionStoreMigratorInterface
    {
        return $this->opt(\App\Core\Module\Session\Contracts\SessionStoreMigratorInterface::class);
    }

    public function hooks(): ?HookInterface
    {
        return $this->opt(HookInterface::class);
    }

    public function assets(): ?AssetsInterface
    {
        return $this->opt(AssetsInterface::class);
    }

    public function themes(): ?ThemeInterface
    {
        return $this->opt(ThemeInterface::class);
    }

    public function templates(): ?TemplateInterface
    {
        return $this->opt(TemplateInterface::class);
    }

    public function database(): ?DatabaseInterface
    {
        return $this->opt(DatabaseInterface::class);
    }

    public function fileSystem(): ?FileSystemInterface
    {
        return $this->opt(FileSystemInterface::class);
    }

    public function config(): ?ConfigRepositoryInterface
    {
        return $this->opt(ConfigRepositoryInterface::class);
    }

    public function installer(): ?InstallerInterface
    {
        return $this->opt(InstallerInterface::class);
    }

    public function installerPages(): ?InstallerPageInterface
    {
        return $this->opt(InstallerPageInterface::class);
    }

    public function adminPanel(): ?AdminPanelInterface
    {
        return $this->opt(AdminPanelInterface::class);
    }

    /** Публичный HTTP-контракт пользовательского модуля Auth (дорога /login...). */
    public function authManager(): ?AuthManagerInterface
    {
        return $this->opt(AuthManagerInterface::class);
    }

    /** Публичный HTTP-контракт пользовательского модуля Pages (дорога /{slug}). */
    public function pageRenderer(): ?PageRendererInterface
    {
        return $this->opt(PageRendererInterface::class);
    }

    // ---------- константы контрактов (единственный легальный источник, R6b) ----------

    /**
     * Дефолтная тема (ThemeInterface::DEFAULT_THEME). Ядро и его support-классы
     * обязаны использовать этот геттер, а не literal `XxxInterface::CONST` —
     * иначе знание о модуле утекает за пределы моста (нарушение R6b).
     */
    public function defaultTheme(): string
    {
        return ThemeInterface::DEFAULT_THEME;
    }

    /** Область рендера «приложение» (TemplateInterface::SCOPE_APP). */
    public function scopeApp(): string
    {
        return TemplateInterface::SCOPE_APP;
    }

    // ---------- обязательные зависимости с fallback (п.9: не падать) ----------

    /** Страницы ошибок отдаёт модуль Error; без него — минимальный HTML-fallback. */
    public function errorPage(int $code, ?\Throwable $e = null): string
    {
        $errors = $this->errorPages();

        return $errors === null
            ? '<h1>Ошибка: ' . $code . '</h1>'
            : $errors->render($code, $e);
    }

    public function errorPages(): ?ErrorPageInterface
    {
        return $this->opt(ErrorPageInterface::class);
    }

    public function hasErrorPages(): bool
    {
        return $this->container->has(ErrorPageInterface::class);
    }

    /** best-effort логирование: без Logger-модуля сообщение просто теряется (п.9). */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->container->has(LoggerInterface::class)) {
            return;
        }
        /** @var LoggerInterface $logger */
        $logger = $this->container->get(LoggerInterface::class);
        $logger->log($level, $message, $context);
    }

    /** Значение из реестра путей (Keys MAP: storage_path, app_view_path, env_path...). */
    public function path(string $key): string
    {
        return (string) $this->container->get($key);
    }

    /** Конфиг: app.name и т.п. — через контракт Config без знания реализации. */
    public function configValue(string $key, mixed $default): mixed
    {
        $config = $this->config();

        return $config === null ? $default : $config->get($key, $default);
    }

    /** @template T @param class-string<T> $id @return T|null */
    private function opt(string $id): ?object
    {
        return $this->container->has($id) ? $this->container->get($id) : null;
    }
}
