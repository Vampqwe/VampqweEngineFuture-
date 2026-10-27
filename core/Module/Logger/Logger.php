<?php
declare(strict_types=1);

namespace App\Core\Module\Logger;

use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\Module\Logger\Enums\LogLevel;
use App\Core\Module\FileSystem\Contracts\FileSystemInterface;

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
        
        // Создаём папку логов, если её нет
        if (!$this->fileSystem->exists($this->logPath)) {
            $this->fileSystem->createDirectory($this->logPath);
        }
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
        // Проверяем минимальный уровень
        if (!$this->shouldLog($level)) {
            return;
        }

        // Форматируем сообщение
        $formatted = $this->formatMessage($level, $message, $context);
        
        // Записываем в файл
        $logFile = $this->logPath . '/' . date('Y-m-d') . '.log';
        $this->fileSystem->append($logFile, $formatted . PHP_EOL);
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
        
        // Заменяем плейсхолдеры {key} на значения из контекста
        foreach ($context as $key => $value) {
            $message = str_replace('{' . $key . '}', (string) $value, $message);
        }

        return "[{$timestamp}] {$levelUpper}: {$message}";
    }
}