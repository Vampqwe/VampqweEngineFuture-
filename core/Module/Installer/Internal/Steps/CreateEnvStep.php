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
     * @return bool true — файл создан; false — уже существовал (не перетираем)
     */
    public function handle(string $envPath, array $values): bool
    {
        if ($this->fs->isFile($envPath)) {
            return false;
        }

        $lines = ['# Сгенерировано Installerом ' . date('c')];
        foreach ($values as $key => $value) {
            $lines[] = $key . '=' . $value;
        }

        return $this->fs->write($envPath, implode(PHP_EOL, $lines) . PHP_EOL);
    }
}
