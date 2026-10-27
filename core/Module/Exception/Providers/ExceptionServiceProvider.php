<?php
declare(strict_types=1);

namespace App\Core\Module\Exception\Providers;

use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;
use App\Core\Module\Exception\Contracts\ExceptionHandlerInterface;
use App\Core\Module\Exception\ExceptionHandler;
use App\Core\Module\Logger\Contracts\LoggerInterface;

class ExceptionServiceProvider implements ModuleProviderInterface
{
    private ?ContainerInterface $container = null;

    public function register(ContainerInterface $container): void
    {
        $this->container = $container;

        $container->instance(ExceptionHandlerInterface::class, new ExceptionHandler(
            $container->get(LoggerInterface::class),
            true
        ));
    }

    public function boot(): void
    {
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
    }

    public function handleException(\Throwable $exception): void
    {
        if ($this->container !== null && $this->container->has(ExceptionHandlerInterface::class)) {
            $handler = $this->container->get(ExceptionHandlerInterface::class);
        } else {
            $handler = $this->createFallbackHandler();
        }

        $handler->report($exception);
        
        $response = $handler->render(
            \App\Core\System\Http\Request::createFromGlobals(),
            $exception
        );
        
        $response->send();
    }

    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    private function createFallbackHandler(): ExceptionHandlerInterface
    {
        $logPath = BASE_PATH . '/storage/logs';
        
        if (!is_dir($logPath)) {
            mkdir($logPath, 0755, true);
        }

        $logger = new class($logPath) implements LoggerInterface {
            private string $logPath;

            public function __construct(string $logPath)
            {
                $this->logPath = $logPath;
            }

            public function emergency(string $message, array $context = []): void { $this->log('EMERGENCY', $message); }
            public function alert(string $message, array $context = []): void { $this->log('ALERT', $message); }
            public function critical(string $message, array $context = []): void { $this->log('CRITICAL', $message); }
            public function error(string $message, array $context = []): void { $this->log('ERROR', $message); }
            public function warning(string $message, array $context = []): void { $this->log('WARNING', $message); }
            public function notice(string $message, array $context = []): void { $this->log('NOTICE', $message); }
            public function info(string $message, array $context = []): void { $this->log('INFO', $message); }
            public function debug(string $message, array $context = []): void { $this->log('DEBUG', $message); }
            public function log(string $level, string $message, array $context = []): void { $this->log($level, $message); }

            private function log(string $level, string $message): void
            {
                $logFile = $this->logPath . '/' . date('Y-m-d') . '.log';
                $entry = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $level, $message);
                file_put_contents($logFile, $entry, FILE_APPEND);
            }
        };

        return new ExceptionHandler($logger, true);
    }
}