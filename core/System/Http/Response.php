<?php
declare(strict_types=1);

namespace App\Core\System\Http;

class Response
{
    /** @var list<string> заголовки Set-Cookie (повторяющиеся — особый случай) */
    private array $cookies = [];

    public function __construct(
        private string $body = '',
        private int $statusCode = 200,
        private array $headers = ['Content-Type' => 'text/html; charset=utf-8']
    ) {}

    /** Тело ответа (для тестов и внутреннего использования). */
    public function body(): string
    {
        return $this->body;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** Копия ответа с дополнительным/заменённым заголовком. */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    /** Добавить Cookie (значение уже собрано модулем Session). */
    public function withCookie(string $setCookieHeader): self
    {
        if ($setCookieHeader !== '') {
            $this->cookies[] = $setCookieHeader;
        }

        return $this;
    }

    public function send(): void
    {
        // Не отдаём заголовки, если вывод уже начался (иначе PHP выдаст warning)
        if (!headers_sent()) {
            http_response_code($this->statusCode);

            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
            foreach ($this->cookies as $cookie) {
                header('Set-Cookie: ' . $cookie, false);
            }
        }

        echo $this->body;
    }
}
