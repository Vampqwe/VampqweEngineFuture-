<?php
declare(strict_types=1);

namespace App\Core\Module\TimeDate;

use App\Core\Module\TimeDate\Contracts\TimeDateInterface;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Фасад модуля TimeDate. Регистрируется как TimeDateInterface.
 *
 * Зависимости: Config (app.timezone) — резолвится лениво через провайдер.
 */
final class TimeDate implements TimeDateInterface
{
    private DateTimeZone $zone;

    public function __construct(string $timezone = 'UTC')
    {
        try {
            $this->zone = new DateTimeZone($timezone);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("Некорректный часовой пояс [{$timezone}]: " . $e->getMessage());
        }
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->zone);
    }

    public function timestamp(): int
    {
        return time();
    }

    public function parse(string $datetime, ?string $timezone = null): DateTimeImmutable
    {
        $zone = $timezone !== null ? new DateTimeZone($timezone) : $this->zone;

        try {
            return new DateTimeImmutable($datetime, $zone);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("Не удалось разобрать дату [{$datetime}]: " . $e->getMessage());
        }
    }

    public function format(DateTimeInterface $date, string $format): string
    {
        return $date->setTimezone($this->zone)->format($format);
    }

    public function toIso(DateTimeInterface $date): string
    {
        return $date->setTimezone($this->zone)->format(DateTimeInterface::ATOM);
    }

    public function diff(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $interval = $from->diff($to);

        return [
            'years'   => $interval->y,
            'months'  => $interval->m,
            'days'    => $interval->d,
            'hours'   => $interval->h,
            'minutes' => $interval->i,
            'seconds' => $interval->s,
        ];
    }

    public function add(DateTimeImmutable $date, string $isoInterval): DateTimeImmutable
    {
        try {
            return $date->add(new DateInterval($isoInterval));
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("Некорректный ISO-интервал [{$isoInterval}]: " . $e->getMessage());
        }
    }

    public function timezone(): string
    {
        return $this->zone->getName();
    }
}
