<?php
declare(strict_types=1);

namespace App\Core\Module\Logger;

use App\Core\Module\Logger\Contracts\LoggerInterface;

/**
 * Простейший logger «последней надежды», используемый когда основной
 * Logger ещё не доступен (падение при бутстрапе). Пишет напрямую через
 * file_put_contents, без зависимостей от FileSystem-модуля.
 */
class FallbackLogger implements LoggerInterface
{
    public function __construct(
        private readonly string $logPath
    ) {
        if (!is_dir($this->logPath)) {
            @mkdir($this->logPath, 0755, true);
        }
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->write('EMERGENCY', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->write('ALERT', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->write('CRITICAL', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->write('NOTICE', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('DEBUG', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->write(strtoupper($level), $message, $context);
    }

    private function write(string $level, string $message, array $context = []): void
    {
        $suffix = '';
        if ($context !== []) {
            $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $suffix = ' ' . $encoded;
            }
        }

        $logFile = $this->logPath . '/' . date('Y-m-d') . '.log';
        $entry   = sprintf("[%s] %s: %s%s\n", date('Y-m-d H:i:s'), $level, $message, $suffix);

        @file_put_contents($logFile, $entry, FILE_APPEND);
    }
}
