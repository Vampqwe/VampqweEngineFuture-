<?php
declare(strict_types=1);

namespace Tests\Framework;

/**
 * Минимальный тестовый фреймворк движка (без внешних зависимостей).
 *
 * Почему свой, а не PHPUnit: composer.json проекта принципиально пуст
 * («require»: только php) — фундамент держим без vendor-зависимостей.
 * API намеренно похож на PHPUnit (assertTrue/assertSame/expectException...),
 * чтобы при необходимости миграция на PHPUnit была механической.
 *
 * Каждый файл tests/**Test.php содержит класс, наследующий TestCase;
 * публичные методы, начинающиеся с test*, выполняются изолированно:
 * исключение метода = падение теста. runAll() возвращает число провалов.
 */
abstract class TestCase
{
    /** @var list<string> сообщения текущего теста */
    private array $messages = [];

    public static function runAll(): int
    {
        $failures = 0;
        $passed   = 0;
        $short    = (new \ReflectionClass(static::class))->getShortName();

        foreach ((new \ReflectionClass(static::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), 'test')) {
                continue;
            }
            $instance = new static();
            try {
                $instance->{$method->getName()}();
                $passed++;
                echo "  \u{2713} {$short}::{$method->getName()}\n";
            } catch (\Throwable $e) {
                $failures++;
                echo "  \u{2717} {$short}::{$method->getName()}\n";
                foreach ($instance->messages as $m) {
                    echo "      ! {$m}\n";
                }
                echo '      -> ' . $e::class . ': ' . $e->getMessage() . "\n";
                echo '      @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
            }
        }

        echo "  Итого: {$passed} passed, {$failures} failed\n";
        return $failures;
    }

    // ================= assertions =================

    protected function assertTrue(bool $cond, string $message = ''): void
    {
        if (!$cond) {
            throw new \AssertionError($message !== '' ? $message : 'Ожидалось true, получено false.');
        }
    }

    protected function assertFalse(bool $cond, string $message = ''): void
    {
        $this->assertTrue(!$cond, $message !== '' ? $message : 'Ожидалось false, получено true.');
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $this->note($message);
            throw new \AssertionError(sprintf(
                'Ожидалось %s, получено %s',
                $this->export($expected),
                $this->export($actual),
            ));
        }
    }

    protected function assertNull(mixed $value, string $message = ''): void
    {
        $this->assertSame(null, $value, $message);
    }

    /** @param \Countable|array<int|string,mixed>|string $haystack */
    protected function assertCount(int $expected, mixed $haystack, string $message = ''): void
    {
        if (!is_array($haystack) && !($haystack instanceof \Countable) && !is_string($haystack)) {
            $this->fail('assertCount: значение не подсчётное (' . get_debug_type($haystack) . ').');
        }

        $actual = is_string($haystack) ? strlen($haystack) : count($haystack);
        if ($actual !== $expected) {
            $this->fail(
                ($message !== '' ? $message . ' ' : '') .
                "Ожидалось элементов: {$expected}, получено: {$actual}."
            );
        }
    }

    protected function assertNotNull(mixed $value, string $message = ''): void
    {
        if ($value === null) {
            throw new \AssertionError($message !== '' ? $message : 'Значение unexpectedly null.');
        }
    }

    protected function expectException(string $class, callable $fn, string $messageContains = ''): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!$e instanceof $class) {
                throw new \AssertionError("Ожидался {$class}, получен " . $e::class . ': ' . $e->getMessage());
            }
            if ($messageContains !== '' && !str_contains($e->getMessage(), $messageContains)) {
                throw new \AssertionError("Сообщение «{$e->getMessage()}» не содержит «{$messageContains}»");
            }
            return;
        }
        throw new \AssertionError("Исключение {$class} не было выброшено.");
    }

    protected function note(string $message): void
    {
        if ($message !== '') {
            $this->messages[] = $message;
        }
    }

    private function export(mixed $v): string
    {
        return match (true) {
            is_array($v) => 'array(' . count($v) . ') ' . json_encode(array_slice($v, 0, 6), JSON_UNESCAPED_UNICODE),
            is_string($v) => '"' . $v . '"',
            $v === null => 'null',
            is_bool($v) => $v ? 'true' : 'false',
            default => var_export($v, true),
        };
    }
}
