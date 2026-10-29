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
     * @return bool true — файл записан; false — запись не удалась (права/диск).
     *
     * Merge-политика: если .env уже существует, его содержимое СОХРАНЯЕТСЯ —
     * значения из $values замещают одноимённые ключи, новые дописываются,
     * комментарии и прочие строки остаются на месте. Прежнее поведение
     * («файл есть → ничего не делаем») теряло конфигурацию БД, которую
     * пользователь предзаполнил до установки, и делало установку с нуля
     * невозможной (приложение открывало дефолтный mysql вместо sqlite).
     */
    public function handle(string $envPath, array $values): bool
    {
        $existingLines = [];

        if ($this->fs->isFile($envPath)) {
            $raw = $this->fs->read($envPath);
            $existingLines = preg_split('/\R/', $raw) ?: [];
        }

        $pending = $values;
        $out = [];

        // Проходим по существующим строкам: заменяем значения известных ключей
        // на месте, сохраняя порядок, комментарии и формат файла.
        foreach ($existingLines as $line) {
            $trimmed = trim($line);
            $pos = strpos($trimmed, '=');

            if ($trimmed !== '' && !str_starts_with($trimmed, '#') && $pos !== false) {
                $key = trim(substr($trimmed, 0, $pos));

                if (array_key_exists($key, $pending)) {
                    $out[] = $key . '=' . $pending[$key];
                    unset($pending[$key]);
                    continue;
                }
            }

            $out[] = $line;
        }

        // Новые ключи — в конец блока.
        foreach ($pending as $key => $value) {
            $out[] = $key . '=' . $value;
        }

        if (!$this->fs->isFile($envPath)) {
            array_unshift($out, '# Сгенерировано Installerом ' . date('c'));
        }

        return $this->fs->write($envPath, implode(PHP_EOL, $out) . PHP_EOL);
    }
}
