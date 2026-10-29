<?php
declare(strict_types=1);

namespace App\Core\Module\Parser\Contracts;

/**
 * Контракт модуля Parser.
 *
 * Единственное, что видят другие модули (п.2 Конституции).
 * Преобразует «сырой» текст/данные в структуры PHP и обратно.
 * НЕ знает ни о каких других модулях — чистый фундамент.
 */
interface ParserInterface
{
    /**
     * Декодировать строку в структуру по формату ('json', 'ini', 'csv').
     *
     * @return array<int|string,mixed>
     */
    public function parse(string $content, string $format): array;

    /**
     * Закодировать структуру в строку по формату.
     *
     * @param array<int|string,mixed> $data
     */
    public function encode(array $data, string $format, bool $pretty): string;

    /** Поддерживается ли данный формат? */
    public function supports(string $format): bool;

    /** Список зарегистрированных парсеров формата: format => FQN класса Internal. */
    public function formats(): array;
}
