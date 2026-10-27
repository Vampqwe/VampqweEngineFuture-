<?php
declare(strict_types=1);

namespace App\Core\Module\Installer\Contracts;

/**
 * Контракт HTML-страниц установщика (п.9/п.10 Конституции: движок не падает,
 * а показывает понятный экран). Реализацию отдаёт Installer через InstallerInterface::renderPage().
 */
interface InstallerPageInterface
{
    /** Шаг 1: страница проверки требований окружения + ссылка на начало установки. */
    public function requirementsPage(array $checks): string;

    /** Шаг 2: форма настроек (имя сайта, часовой пояс, режим отладки). */
    public function settingsForm(array $errors = []): string;

    /** Шаг 3: успешное завершение. */
    public function successPage(array $summary): string;

    /** Ошибка установки (с возможностью повторить). */
    public function failurePage(string $reason): string;
}
