<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Module\Config\ConfigRepository;
use App\Core\Module\Security\Security;
use Tests\Framework\TestCase;

/**
 * Криптография ядра: пароли (argon2id), токены, AES-шифрование.
 * Security — фундамент Auth и подписанных cookies.
 *
 * @constitution-exception п.2 — юнит-тест внутренностей модулей: PhpArrayParser
 * (Config\Internal) подставляется напрямую, чтобы не тащить весь boot-конвейер
 * ради изоляции криптографии. Запись в TEST_INTERNAL_WHITELIST линтера (R9);
 * аннотация обязательна, иначе exemption считается устаревшим.
 */
final class SecurityTest extends TestCase
{
    private function sec(): Security
    {
        $tmp = sys_get_temp_dir() . '/sec_test_' . getmypid();
        @mkdir($tmp, 0777, true);
        file_put_contents($tmp . '/app.php', "<?php\nreturn ['key' => '" . base64_encode(random_bytes(32)) . "', 'debug' => false];");
        $parser = new \App\Core\Module\Config\Internal\Parsers\PhpArrayParser();
        return new Security(new ConfigRepository($parser, $tmp));
    }

    public function testHashAndVerifyPassword(): void
    {
        $s = $this->sec();
        $hash = $s->hashPassword('secret-123');
        $this->assertTrue($s->verifyPassword('secret-123', $hash), 'верный пароль проходит');
        $this->assertTrue(!$s->verifyPassword('wrong', $hash), 'неверный пароль отклонён');
        $this->assertTrue(str_starts_with($hash, '$argon2id$'), 'используется argon2id');
    }

    public function testRandomTokenUniqueAndLength(): void
    {
        $s = $this->sec();
        $a = $s->randomToken(32);
        $b = $s->randomToken(32);
        $this->assertSame(64, strlen($a), '32 байта -> 64 hex');
        $this->assertTrue($a !== $b, 'токены уникальны');
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $s = $this->sec();
        $plain = 'page-secret-data-üñí©ödé';
        $cipher = $s->encrypt($plain);
        $this->assertTrue($cipher !== $plain, 'шифротекст отличается');
        $this->assertSame($plain, $s->decrypt($cipher), 'roundtrip сохраняет данные');
    }

    /** Контракт decrypt(): повреждённый/подделанный payload => null (не фатал). */
    public function testDecryptTamperedReturnsNull(): void
    {
        $s = $this->sec();
        $cipher = $s->encrypt('hello');
        $raw = base64_decode($cipher, true);
        $this->assertTrue($raw !== false, 'payload — валидный base64');
        // инвертируем последний бит ciphertext -> GCM auth-tag не сойдётся
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);
        $tampered = base64_encode($raw);
        $this->assertSame(null, $s->decrypt($tampered), 'подделка отклонена (null)');
        // мусор вообще не нашей подписи -> тоже null
        $this->assertSame(null, $s->decrypt('!!!not-base64!!!'), 'мусор отклонён');
    }

    /** Без APP_KEY шифрование невозможно — понятный RuntimeException. */
    public function testEncryptWithoutKeyThrows(): void
    {
        $tmp = sys_get_temp_dir() . '/sec_nokey_' . getmypid();
        @mkdir($tmp, 0777, true);
        file_put_contents($tmp . '/app.php', "<?php\nreturn ['key' => '', 'debug' => false];");
        $parser = new \App\Core\Module\Config\Internal\Parsers\PhpArrayParser();
        $s = new Security(new ConfigRepository($parser, $tmp));
        $savedEnv = $_ENV['APP_KEY'] ?? null;
        unset($_ENV['APP_KEY']);
        try {
            $this->expectException(\RuntimeException::class, function () use ($s): void {
                $s->encrypt('x');
            }, 'APP_KEY');
        } finally {
            if ($savedEnv !== null) {
                $_ENV['APP_KEY'] = $savedEnv;
            }
        }
    }
}
