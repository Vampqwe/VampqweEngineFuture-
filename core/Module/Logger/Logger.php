<?php
declare(strict_types=1);

namespace App\Core\Module\Logger;

use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\Module\Logger\Enums\LogLevel;
use App\Core\Module\FileSystem\Contracts\FileSystemInterface;

/**
 * Логгер с каналами: КАЖДЫЙ модуль пишет в свой файл
 * logs/<Y-m-d>/<канал>.log (единая точка путей — ключ контейнера 'logs_path').
 * Канал берётся из context['channel'] (по умолчанию 'app').
 */
class Logger implements LoggerInterface
{
    private string $logPath;
    private string $minLevel;

    public function __construct(
        private readonly FileSystemInterface $fileSystem,
        string $logPath,
        string $minLevel = 'debug'
    ) {
        $this->logPath = rtrim($logPath, '/');
        $this->minLevel = $minLevel;
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log(LogLevel::Emergency->value, $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log(LogLevel::Alert->value, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::Critical->value, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::Error->value, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::Warning->value, $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log(LogLevel::Notice->value, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::Info->value, $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::Debug->value, $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        $formatted = $this->formatMessage($level, $message, $context);

        // Файл канала создаём лениво — папка не трогаем на старте.
        $logFile = $this->channelFile($this->channel($context));
        $dir = dirname($logFile);
        if (!$this->fileSystem->exists($dir)) {
            $this->fileSystem->createDirectory($dir);
        }
        $this->fileSystem->append($logFile, $formatted . PHP_EOL);
    }

    /** Путь файла лога конкретного канала (для диагностики/Doctor). */
    public function channelFile(string $channel, ?string $date = null): string
    {
        $date ??= date('Y-m-d');

        return $this->logPath . '/' . $date . '/' . $this->sanitizeChannel($channel) . '.log';
    }

    private function channel(array $context): string
    {
        $raw = isset($context['channel']) ? (string) $context['channel'] : 'app';

        return $this->sanitizeChannel($raw);
    }

    /** Имя канала -> безопасное имя файла: letters/digits/dash/underscore, не длиннее 64. */
    private function sanitizeChannel(string $channel): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '_', $channel) ?? 'app';
        $clean = substr(trim($clean, '_'), 0, 64);

        return $clean === '' ? 'app' : $clean;
    }

    private function shouldLog(string $level): bool
    {
        $levels = [
            'emergency' => 0,
            'alert' => 1,
            'critical' => 2,
            'error' => 3,
            'warning' => 4,
            'notice' => 5,
            'info' => 6,
            'debug' => 7,
        ];

        return ($levels[$level] ?? 0) <= ($levels[$this->minLevel] ?? 7);
    }

    private function formatMessage(string $level, string $message, array $context): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);

        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $message = str_replace('{' . $key . '}', (string) $value, $message);
            }
        }

        // В строку пишем канал и остаток контекста (кроме channel), чтобы файл был читаемым.
        $meta = $context;
        unset($meta['channel']);
        $suffix = $meta === [] ? '' : ' ' . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "[{$timestamp}] {$levelUpper}: {$message}{$suffix}";
    }
}
