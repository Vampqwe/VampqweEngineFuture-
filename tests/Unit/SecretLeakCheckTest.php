<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Module\Doctor\Checks\SecretLeakCheck;
use Tests\Framework\TestCase;

/**
 * Юнит-тесты детектора утечки секретов в git (долг CONSTITUTION.md → теорема).
 *
 * @constitution-exception п.2: юнит-тест внутренней проверки Doctor обязан
 * иметь доступ к объекту теста (Checks\SecretLeakCheck — Internal-зона модуля).
 * @constitution-exception п.7: поиск корня репозитория для автопропуска теста
 * вне git через dirname(__DIR__, 2) — бытовая идиома tests/, путями приложения
 * тест не оперирует (стенд строится в sys_get_temp_dir()).
 */
final class SecretLeakCheckTest extends TestCase
{
    private const SECRET_KEYS = ['APP_KEY', 'DB_PASSWORD'];

    /** @var list<string> созданные стенды для уборки */
    private array $stands = [];

    private static ?bool $gitAvailable = null;

    /** Детерминизм CI: без бинаря git стенд не построить — пропускаем тесты честно. */
    private function skipWithoutGit(): bool
    {
        if (self::$gitAvailable === null) {
            self::$gitAvailable = trim((string) shell_exec('git --version 2>/dev/null')) !== '';
        }
        if (!self::$gitAvailable) {
            $this->note('git недоступен в окружении — тесты стенда пропущены');
        }

        return !self::$gitAvailable;
    }

    public function testNotAGitRepoIsHonestPass(): void
    {
        $dir = $this->makeTempDir('slc_nogit_');
        $res = SecretLeakCheck::check($dir, self::SECRET_KEYS);

        $this->assertTrue($res['ok'], 'вне git проверка обязана быть ok=true');
        $this->assertTrue(
            str_contains($res['message'], 'не git-репозиторий'),
            'ожидалось честное «проверка пропущена», получено: ' . $res['message']
        );
    }

    public function testCleanStandPasses(): void
    {
        if ($this->skipWithoutGit()) {
            return;
        }

        $repo = $this->makeStand(env: "APP_KEY=" . $this->secret() . "\n", tracked: [
            'config/app.php' => "<?php\n// конфиг без секретов\n",
        ]);

        $res = SecretLeakCheck::check($repo, self::SECRET_KEYS);
        $this->assertTrue($res['ok'], 'чистый стенд должен проходить, сообщение: ' . $res['message']);
    }

    public function testEnvNotIgnoredIsDetected(): void
    {
        if ($this->skipWithoutGit()) {
            return;
        }

        // .gitignore без строки .env: файл остаётся untracked (add -A его не берёт),
        // но check-ignore молчит -> нарушение п.8 «не исключён».
        $repo = $this->makeTempDir('slc_noig_');
        file_put_contents($repo . '/.gitignore', "/vendor\n");
        file_put_contents($repo . '/.env', 'APP_KEY=' . $this->secret() . "\n");
        file_put_contents($repo . '/readme.txt', "stand\n");
        $this->git($repo, 'init -q');
        $this->git($repo, 'config user.email t@t.t');
        $this->git($repo, 'config user.name t');
        // add БЕЗ .env: файл untracked и не заигнорен — ровно сценарий «забыли в .gitignore»
        $this->git($repo, 'add .gitignore readme.txt');
        $this->git($repo, 'commit -q -m stand');

        $res = SecretLeakCheck::check($repo, self::SECRET_KEYS);
        $this->assertFalse($res['ok'], '.env без gitignore обязан быть пойман');
        $this->assertTrue(str_contains($res['message'], '.gitignore'), $res['message']);
    }

    public function testTrackedEnvIsDetected(): void
    {
        if ($this->skipWithoutGit()) {
            return;
        }

        // Классика утечки: .env был добавлен ДО появления правила в .gitignore
        // (git хранит отслеживаемые файлы независимо от ignore) — ловим по ls-files.
        $repo = $this->makeTempDir('slc_pre_');
        file_put_contents($repo . '/.gitignore', ".env\n/vendor\n");
        file_put_contents($repo . '/.env', 'APP_KEY=' . $this->secret() . "\n");
        $this->git($repo, 'init -q');
        $this->git($repo, 'config user.email t@t.t');
        $this->git($repo, 'config user.name t');
        $this->git($repo, 'add .gitignore');
        $this->git($repo, 'commit -q -m base');
        // Классика утечки: git add -f .env поверх ignore-правила
        $this->git($repo, 'add -f .env');
        $this->git($repo, 'commit -q -m force-env');

        $res = SecretLeakCheck::check($repo, self::SECRET_KEYS);
        $this->assertFalse($res['ok'], 'закоммиченный .env обязан быть пойман');
        $this->assertTrue(str_contains($res['message'], 'закоммичен'), $res['message']);
    }

    public function testSecretValueInTrackedFileIsDetected(): void
    {
        if ($this->skipWithoutGit()) {
            return;
        }

        $secret = $this->secret();
        $repo   = $this->makeStand(env: "APP_KEY={$secret}\n", tracked: [
            'src/leak.php' => "<?php\n\$key = '{$secret}'; // подсмотрел из .env\n",
        ]);

        $res = SecretLeakCheck::check($repo, self::SECRET_KEYS);
        $this->assertFalse($res['ok'], 'значение секрета в отслеживаемом файле обязано быть поймано');
        $this->assertTrue(str_contains($res['message'], 'src/leak.php'), $res['message']);
        $this->assertTrue(str_contains($res['message'], 'APP_KEY'), $res['message']);
    }

    public function testShortPlaceholderValuesAreNotFlagged(): void
    {
        if ($this->skipWithoutGit()) {
            return;
        }

        // Короткие (<8) значения — заглушки стендов, не секреты: ложных срабатываний нет
        $repo = $this->makeStand(env: "APP_KEY=abc\n", tracked: ['x.txt' => "abc\n"]);

        $res = SecretLeakCheck::check($repo, self::SECRET_KEYS);
        $this->assertTrue($res['ok'], 'заглушка <8 символов не считается секретом: ' . $res['message']);
    }

    // ================= helpers =================

    /** Строит временный git-стенд; возвращает его корень. */
    private function makeStand(string $env, array $tracked, string $gitignore = ".env\n/vendor\n"): string
    {
        $repo = $this->makeTempDir('slc_repo_');
        file_put_contents($repo . '/.gitignore', $gitignore);
        if ($env !== '') {
            file_put_contents($repo . '/.env', $env);
        }
        foreach ($tracked as $rel => $contents) {
            $abs = $repo . '/' . $rel;
            if (!is_dir(dirname($abs))) {
                mkdir(dirname($abs), 0o777, true);
            }
            file_put_contents($abs, $contents);
        }

        $this->git($repo, 'init -q');
        $this->git($repo, 'config user.email t@t.t');
        $this->git($repo, 'config user.name t');
        $this->git($repo, 'add -A');
        $this->git($repo, 'commit -q -m stand');

        return $repo;
    }

    private function secret(): string
    {
        return bin2hex(random_bytes(16)); // 32 символа — гарантированно >= 8
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(4));
        mkdir($dir, 0o777, true);
        $this->stands[] = $dir;

        return $dir;
    }

    private function git(string $cwd, string $args): string
    {
        $out = shell_exec('cd ' . escapeshellarg($cwd) . ' && git ' . $args . ' 2>&1');

        return is_string($out) ? $out : '';
    }

    protected function tearDown(): void
    {
        foreach ($this->stands as $dir) {
            $this->rrmdir($dir);
        }
        $this->stands = [];
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . '/' . $e;
            is_dir($p) && !is_link($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
