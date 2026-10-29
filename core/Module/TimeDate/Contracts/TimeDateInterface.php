<?php
declare(strict_types=1);

namespace App\Core\Module\TimeDate\Contracts;

/**
 * Контракт модуля TimeDate — единая работа с датой и временем.
 *
 * Единственное, что видят другие модули (п.2 Конституции).
 * Бизнес-логике запрещено использовать голые date()/time()/strtotime() —
 * только через этот контракт, чтобы часовой пояс был централизован
 * (берётся из конфигурации app.timezone).
 */
interface TimeDateInterface
{
    /** Текущий момент (ООП-представление, без строк). */
    public function now(): \DateTimeImmutable;

    /** Unix-таймстемт текущего момента. */
    public function timestamp(): int;

    /** Парсить строку даты в DateTimeImmutable (ЧП из конфига, если не задан). */
    public function parse(string $datetime, ?string $timezone): \DateTimeImmutable;

    /** Отформатировать дату по PHP-шаблону ('Y-m-d H:i:s'). */
    public function format(\DateTimeInterface $date, string $format): string;

    /** ISO-8601 представление (для API, очередей, кэша). */
    public function toIso(\DateTimeInterface $date): string;

    /**
     * Разница между двумя датами.
     *
     * @return array{years:int,months:int,days:int,hours:int,minutes:int,seconds:int}
     */
    public function diff(\DateTimeInterface $from, \DateTimeInterface $to): array;

    /** Прибавить ISO-интервал ('P1D', 'PT2H30M'). */
    public function add(\DateTimeImmutable $date, string $isoInterval): \DateTimeImmutable;

    /** Текущий часовой пояс движка (app.timezone). */
    public function timezone(): string;
}
