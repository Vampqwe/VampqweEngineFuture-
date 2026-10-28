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
        // Running — состояние «маркеров нет, есть только .installed»:
        // его маркером является флаг NotInstalled (историческое имя).
        $flags   = self::flagNames();
        $marker  = $this === self::Running ? $flags[self::NotInstalled->name] : $flags[$this->name];

        return $storagePath . '/' . $marker;
    }

    /**
     * Флаг-маркер session-driver'а (storage/.session_store.<driver>):
     * одноразовая фоновая миграция хранилища после смены session.driver.
     * Не является состоянием движка — но живёт в том же каталоге флагов,
     * поэтому имя определяется здесь (п.7: сегменты путей только из
     * единого источника), а не литералом в Kernel.
     */
    public static function sessionStoreMarker(string $storagePath, string $driver): string
    {
        return $storagePath . '/.session_store.' . $driver;
    }

    /**
     * Собрать состояние по каталогу storage: первый найденный маркер =
     * текущее состояние, отсутствие '.installed' = NotInstalled (приоритет
     * задаётся порядком flagNames()). Единственная реализация «детекта» —
     * Kernel и FatalPage вызывают её, не дублируя список флагов (п.10).
     */
    public static function detect(string $storagePath): self
    {
        // Приоритет задаёт порядок flagNames() (исторический порядок
        // detectAppState()), а не порядок объявления кейсов enum:
        // Installing > Upgrading > Maintenance > Error. Имена флагов
        // берутся из словаря, литералов в детекте нет (п.7/п.10).
        $flags = self::flagNames();
        unset($flags[self::NotInstalled->name]); // это «нет маркеров», проверяется последним
        foreach ($flags as $name => $markerFile) {
            if (is_file($storagePath . '/' . $markerFile)) {
                return self::fromName($name);
            }
        }

        return is_file(self::NotInstalled->marker($storagePath))
            ? self::Running   // '.installed' есть, прочих нет — живой движок
            : self::NotInstalled;
    }

    /** Кейс по имени объявления (name, не backing value). */
    private static function fromName(string $name): self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }
        throw new \InvalidArgumentException("Unknown AppState name: {$name}");
    }
}