<?php
declare(strict_types=1);

namespace App\Core\Module\Installer\Internal\Steps;

use App\Core\Module\FileSystem\Contracts\FileSystemInterface;

/**
 * Внутренний шаг установщика: рендер .env из шаблона с учётом уже заданных значений.
 * ЗАПРЕЩЕНО извне модуля (п.2). Работает поверх контракта FileSystemInterface (п.3).
 */
final class CreateEnvStep
{
    public function __construct(private readonly FileSystemInterface $fs)
    {
    }

    /**
     * @param string $envPath   полный путь к .env (из контейнера, не хардкод)
     * @param array<string,string> $values ключ=>значение (APP_KEY, APP_NAME, LOG_LEVEL...)
     * @return bool true — файл записан; существующие ключи сохраняются (merge),
     *               значения из $values имеют приоритет над старыми
     */
    public function handle(string $envPath, array $values): bool
    {
        // Merge-политика (регрессия 500 на HTTP-стенде): если .env уже есть
        // (например, конфиг БД задан оператором до установки), существующие
        // ключи СОХРАНЯЮТСЯ, новые дописываются. Ранее шаг возвращал false и
        // молча не создавал APP_KEY/хеш админа — установка падала с 500.
        $existing = [];
        if ($this->fs->isFile($envPath)) {
            foreach (preg_split('/\R/', $this->fs->read($envPath)) ?: [] as $line) {
                $t = trim($line);
                if ($t === '' || str_starts_with($t, '#')) {
                    continue;
                }
                $pos = strpos($t, '=');
                if ($pos !== false) {
                    $existing[substr($t, 0, $pos)] = substr($t, $pos + 1);
                }
            }
        }

        $merged = array_merge($existing, $values);

        $lines = ['# Сгенерировано Installerом ' . date('c')];
        foreach ($merged as $key => $value) {
            $lines[] = $key . '=' . $value;
        }

        return $this->fs->write($envPath, implode(PHP_EOL, $lines) . PHP_EOL);
    }
}
