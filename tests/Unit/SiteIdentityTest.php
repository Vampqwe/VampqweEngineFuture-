<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\System\Support\SiteIdentity;
use Tests\Framework\TestCase;

/**
 * SiteIdentity — инварианты идентичности сайта (BASE_URL_SITE / SITE_DOMAIN).
 *
 * Закрывает слепое пятно «конфиг важнее Host»: чистые функции резолва
 * домена проверяются без контейнера и HTTP.
 */
final class SiteIdentityTest extends TestCase
{
    public function testDomainFromUrlStripsSchemePortAndPath(): void
    {
        self::assertSame('vampqwe.ru', SiteIdentity::domainFromUrl('https://vampqwe.ru'));
        self::assertSame('vampqwe.ru', SiteIdentity::domainFromUrl('https://vampqwe.ru/'));
        self::assertSame('vampqwe.ru', SiteIdentity::domainFromUrl('https://vampqwe.ru:8443/blog'));
        self::assertSame('localhost', SiteIdentity::domainFromUrl('http://localhost:8000'));
        // Регистр нормализуется.
        self::assertSame('example.com', SiteIdentity::domainFromUrl('HTTP://Example.COM'));
    }

    public function testDomainFromUrlInvalidYieldsEmptyMeaningLegacyMode(): void
    {
        self::assertSame('', SiteIdentity::domainFromUrl(''));
        self::assertSame('', SiteIdentity::domainFromUrl('   '));
        // Без scheme parse_url не даёт host -> легаси-режим, а не угадывание.
        self::assertSame('', SiteIdentity::domainFromUrl('vampqwe.ru'));
        self::assertSame('', SiteIdentity::domainFromUrl('not a url at all'));
    }

    public function testNormalizeHostLowercasesAndDropsNumericPort(): void
    {
        self::assertSame('example.com', SiteIdentity::normalizeHost('Example.COM:8080'));
        self::assertSame('example.com', SiteIdentity::normalizeHost('  example.com  '));
        self::assertSame('localhost', SiteIdentity::normalizeHost('localhost:8000'));
        self::assertSame('', SiteIdentity::normalizeHost(''));
        // IPv6-литерал без числового порта не режется.
        self::assertSame('[::1]', SiteIdentity::normalizeHost('[::1]'));
    }

    public function testHostMatches(): void
    {
        // Пустая идентичность = легаси: совпадает с любым хостом.
        self::assertTrue(SiteIdentity::hostMatches('', 'anything.test'));
        // Совпадение с учётом регистра и порта в заголовке.
        self::assertTrue(SiteIdentity::hostMatches('vampqwe.ru', 'VampQWE.ru:443'));
        self::assertFalse(SiteIdentity::hostMatches('vampqwe.ru', 'other.ru'));
        // Поддомен — НЕ совпадение: второй сайт = второй экземпляр движка.
        self::assertFalse(SiteIdentity::hostMatches('vampqwe.ru', 'shop.vampqwe.ru'));
    }
}
