<?php
declare(strict_types=1);

namespace App\Core\Module\Doctor\Checks;

/**
 * Внутренняя проверка Doctor: секреты не утекли в git (п.9 — честная
 * самодиагностика, п.8 — строгость). ЗАПРЕЩЕНО использовать извне модуля (п.2).
 *
 * Детектор «утечки секретов в репозиторий» из долга CONSTITUTION.md:
 *  1) .env (файл с живыми ключами) обязан быть в .gitignore и НЕ должен
 *     попадать в индекс (`git ls-files`);
 *  2) непустые значения секретных ключей (.env.example — шаблон, поэтому
 *     проверяются только реально отслеживаемые git файлы) не должны встречаться
 *     ни в одном файле индекса.
 *
 * Вне git-репозитория (дистрибутив без .git) или при отсутствии git —
 * ok=true с честным сообщением «проверка недоступна»: Doctor не врёт о том,
 * чего не проверял.
 */
final class SecretLeakCheck
{
    /** Ключи .env, чьи непустые значения считаются секретами. @var list<string> */
    private const SECRET_KEYS = ['APP_KEY', 'DB_PASSWORD'];

    /** Максимум файлов индекса для содержательного скана (защита от веток-монстров). */
    private const MAX_SCANNED_FILES = 3000;

    /**
     * @param string $repoPath корень репозитория (только для чтения)
     * @return array{name:string,ok:bool,message:string}
     */
    public static function run(string $repoPath): array
    {
        $name = 'Секреты';

        if (!is_dir($repoPath . '/.git')) {
            return ['name' => $name, 'ok' => true, 'message' => 'не git-репозиторий — проверка пропущена'];
        }

        // 1) .env заигнорен?
        $ignoreOut = self::exec('git check-ignore .env', $repoPath);
        if ($ignoreOut === null) {
            return ['name' => $name, 'ok' => false, 'message' => 'git недоступен — проверка не выполнена'];
        }
        if (trim($ignoreOut) !== '.env') {
            return ['name' => $name, 'ok' => false, 'message' => '.env НЕ исключён через .gitignore (п.8)'];
        }

        // 2) .env не в индексе?
        $tracked = self::exec('git ls-files -- .env', $repoPath);
        if ($tracked === null) {
            return ['name' => $name, 'ok' => false, 'message' => 'git ls-files завершился ошибкой'];
        }
        if (trim($tracked) !== '') {
            return ['name' => $name, 'ok' => false, 'message' => '.env закоммичен в репозиторий (п.8)!'];
        }

        // 3) Значения секретов из локального .env не гуляют по индексу?
        $envFile = $repoPath . '/.env';
        if (!is_file($envFile)) {
            return ['name' => $name, 'ok' => true, 'message' => '.env в ignore, индекс чист (нет локального .env для сверки)'];
        }
        $secrets = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key   = trim(substr($line, 0, $pos));
            $value = trim(trim(substr($line, $pos + 1)), "\"'");
            if (in_array($key, self::SECRET_KEYS, true) && strlen($value) >= 8) {
                $secrets[$key] = $value;
            }
        }
        if ($secrets === []) {
            return ['name' => $name, 'ok' => true, 'message' => '.env в ignore, секретных значений нет (или это стенд)'];
        }

        $listOut = self::exec('git ls-files', $repoPath);
        if ($listOut === null) {
            return ['name' => $name, 'ok' => false, 'message' => 'git ls-files завершился ошибкой'];
        }
        $files = array_slice(preg_split('/\r\n|\r|\n/', trim($listOut)) ?: [], 0, self::MAX_SCANNED_FILES);
        foreach ($files as $rel) {
            $abs = $repoPath . '/' . $rel;
            if (!is_file($abs) || !self::looksTextual($abs)) {
                continue;
            }
            $contents = @file_get_contents($abs);
            if ($contents === false) {
                continue;
            }
            foreach ($secrets as $key => $value) {
                if (str_contains($contents, $value)) {
                    return ['name' => $name, 'ok' => false, 'message' => "значение {$key} найдено в {$rel} (утечка секрета!)"];
                }
            }
        }

        return ['name' => $name, 'ok' => true, 'message' => '.env в ignore, секреты в индексе не найдены'];
    }

    /** @return string|null stdout команды; null — если git вообще недоступен (shell_exec/exec нет) */
    private static function exec(string $cmd, string $cwd): ?string
    {
        // 'cd <repo> && <cmd>' — рабочая директория без зависимости от cwd процесса.
        $full = 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>/dev/null';
        if (function_exists('shell_exec')) {
            $res = shell_exec($full);

            return is_string($res) ? $res : '';
        }
        if (function_exists('exec')) {
            $lines = [];
            @exec($full, $lines);

            return implode("\n", $lines);
        }

        return null;
    }

    /** Пропускаем бинарные файлы (экономим память и ложные срабатывания). */
    private static function looksTextual(string $file): bool
    {
        if (!is_readable($file) || filesize($file) > 2_000_000) {
            return false;
        }
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = fread($fh, 512);
        fclose($fh);

        return $head !== false && !str_contains($head, "\0");
    }
}
