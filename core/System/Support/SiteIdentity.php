<?php
declare(strict_types=1);

namespace App\Core\System\Support;

/**
 * SiteIdentity — чистая функция идентичности сайта (без контейнера, без I/O).
 *
 * Концепция «один запуск движка = один сайт»: домен экземпляра объявляется
 * в конфигурации (BASE_URL_SITE / SITE_DOMAIN), а не угадывается по
 * заголовку Host запроса. Хост запроса лишь ПРОВЕРЯЕТСЯ на совпадение.
 *
 * Правило разделения ответственности: здесь только детерминированные
 * преобразования строк (parse_url, нормализация порта) — вся политика
 * (что делать при несовпадении) принимается вызывающим кодом (Kernel).
 */
final class SiteIdentity
{
    /**
     * Извлекает нормализованный домен из канонического URL сайта.
     * 'https://vampqwe.ru:8443/' -> 'vampqwe.ru' (порт отбрасывается:
     * Host-заголовок HTTP-запроса может приходить без него).
     * Невалидный/пустой ввод -> '' (означает «идентичность не задана»).
     */
    public static function domainFromUrl(string $url): string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        return $host === null || $host === false
            ? ''
            : strtolower((string) $host);
    }

    /**
     * Нормализация Host-заголовка запроса для сравнения с доменом сайта:
     * lowercase + отбрасывание порта ('Example.COM:8080' -> 'example.com').
     */
    public static function normalizeHost(string $hostHeader): string
    {
        $host = strtolower(trim($hostHeader));
        $dot  = strrpos($host, ':');

        // Порт — только цифры после последней ':' (иначе это IPv6-литерал
        // вида '[::1]' или голый хост — оставляем как есть).
        if ($dot !== false && preg_match('/^\d+$/', substr($host, $dot + 1)) === 1) {
            $host = substr($host, 0, $dot);
        }

        return $host;
    }

    /**
     * Совпадает ли хост запроса с доменом этого экземпляра?
     * identity === '' означает «идентичность не настроена» -> true всегда
     * (легаси-режим: пусть решает реестр сайтов).
     */
    public static function hostMatches(string $identityDomain, string $hostHeader): bool
    {
        if ($identityDomain === '') {
            return true;
        }

        return self::normalizeHost($hostHeader) === $identityDomain;
    }
}
