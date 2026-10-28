<?php
declare(strict_types=1);

namespace App\Core\System\Enums;

enum AppState: string
{
    case NotInstalled = 'not_installed';
    case Installing = 'installing';
    case Maintenance = 'maintenance';
    case Upgrading = 'upgrading';
    case Running = 'running';
    case Error = 'error';

    /**
     * Имена точечных маркеров состояния в storage (порядок = приоритет проверки,
     * исторически — порядок detectAppState()). Единственный источник имён
     * флагов: Kernel/FatalPage читают их отсюда, литералы '.installed' и т.п.
     * вне перечисления запрещены (R7 «пути только из MAP»).
     *
     * @return array<string, string> case-name => имя файла-маркера
     */
    public static function flagNames(): array
    {
        return [
            self::NotInstalled->name  => '.installed',
            self::Installing->name    => '.installing',
            self::Upgrading->name     => '.upgrading',
            self::Maintenance->name   => '.maintenance',
            self::Error->name         => '.error',
        ];
    }

    /** Абсолютный путь маркера данного состояния от корня storage. */
    public function marker(string $storagePath): string
    {
        return $storagePath . '/' . self::flagNames()[$this->name];
    }

    /**
     * Собрать состояние по каталогу storage: первый найденный маркер =
     * текущее состояние, отсутствие '.installed' = NotInstalled (приоритет
     * задаётся порядком flagNames()). Единственная реализация «детекта» —
     * Kernel и FatalPage вызывают её, не дублируя список флагов (п.10).
     */
    public static function detect(string $storagePath): self
    {
        foreach (self::cases() as $case) {
            if ($case === self::NotInstalled) {
                continue; // это «нет маркеров», проверяется последним
            }
            if (is_file($case->marker($storagePath))) {
                return $case;
            }
        }

        return is_file(self::NotInstalled->marker($storagePath))
            ? self::Running   // '.installed' есть, прочих нет — живой движок
            : self::NotInstalled;
    }
}