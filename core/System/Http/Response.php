<?php
declare(strict_types=1);

namespace App\Core\System\Http;

class Response
{
    public function __construct(
        private string $body = '',
        private int $statusCode = 200,
        private array $headers = ['Content-Type' => 'text/html; charset=utf-8']
    ) {}

    public function send(): void
    {
        // Не отдаём заголовки, если вывод уже начался (иначе PHP выдаст warning)
        if (!headers_sent()) {
            http_response_code($this->statusCode);
        
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        echo $this->body;
    }
}