<?php
declare(strict_types=1);

namespace App\Core\System\Support;

/**
 * ЕДИНАЯ ТОЧКА ПРАВДЫ О ПУТЯХ (п.7 Конституции).
 *
 * @constitution-exception п.3/п.7 — физический bootstrap путей: конструктор
 * принимает абсолютный корень проекта (значение глобальной BASE_PATH из
 * public/index.php и bin/engine — единственных мест её определения). Это
 * единственный потребитель BASE_PATH в ядре наряду с App; запись в белом
 * списке R8/R7 линтера, аннотация обязательна.
 *
 * Все пути проекта объявляются ТОЛЬКО здесь. Больше нигде:
 *  - никаких dirname(__DIR__, N) в ядре и модулях;
 *  - никаких конкатенаций BASE_PATH . '/...' в потребителях;
 *  - потребители получают пути из контейнера по строковым ключам
 *    ('base_path', 'logs_path', ...) — зарегистрированным в App.
 *
 * Изменение layout проекта = правка одного этого файла.
 */
final class Paths
{
    /**
     * Карта: ключ контейнера => относительный путь от корня проекта.
     * Порядок и состав карты — единственный источник истины.
     */
    private const MAP = [
        'base'        => '.',             // корень проекта (BASE_PATH)
        'env'         => '.env',          // конфигурация окружения (EnvLoader/Installer)
        'config'      => 'config',
        'storage'     => 'storage',
        'public'      => 'public',
        'core_view'   => 'view/core',  // шаблоны ядра: ошибки, installer, админка
        'app_view'    => 'view/app',   // пользовательские темы сайтов (мультисайт): view/app/<theme>/
        'modules_app' => 'app/modules',
        'logs'        => 'logs',           // логи модулей: logs/<дата>/<канал>.log
        'cache'       => 'storage/cache',  // кэш движка (Cache, driver=file): storage/cache/<hh>/<key>.cache
        'queue'       => 'storage/queue',  // очереди задач (Queue, driver=file): pending/delayed/failed
        'sessions'    => 'storage/sessions', // file-стор сессий (Session, driver=file)
        'database'    => 'database',       // SQL-дампы схемы движка (db.sql) — источник для Installer/миграций
        'migrations'  => 'migrations',     // версии схемы БД (Migration): <ГГГГ_ММ_ДД_HHMMSS>_имя.php
    ];

    public function __construct(private readonly string $basePath)
    {
    }

    /** Абсолютный путь по ключу. Неизвестный ключ — ошибка разработчика. */
    public function get(string $key): string
    {
        if (!isset(self::MAP[$key])) {
            throw new \InvalidArgumentException("Путь [{$key}] не объявлен в Paths::MAP.");
        }

        return $this->basePath . '/' . self::MAP[$key];
    }

    /** Ключ => абсолютный путь, для массовой регистрации в контейнере. */
    public function all(): array
    {
        $out = [];
        foreach (self::MAP as $key => $rel) {
            $out[$key] = $this->basePath . '/' . $rel;
        }

        return $out;
    }

    /** Имена ключей, которые обязан зарегистрировать App (использует Doctor). */
    public static function keys(): array
    {
        return array_keys(self::MAP);
    }

    public function basePath(): string
    {
        return $this->basePath;
    }
}
