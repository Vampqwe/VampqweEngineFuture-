<?php
declare(strict_types=1);

/**
 * Точка запуска тестов: php tests/run.php
 * Автоподхватывает все tests/Unit/*Test.php.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/Framework/TestCase.php';
// Хелперы интеграционного контура (php -S стенд) — без пересборки autoload:
require_once __DIR__ . '/Framework/HttpServer.php';

$files = array_merge(
    glob(__DIR__ . '/Unit/*Test.php') ?: [],
    glob(__DIR__ . '/Integration/*Test.php') ?: [],
);
sort($files);

$failures = 0;
foreach ($files as $file) {
    require_once $file;
    // Namespace выводится из пути: Unit/XTest.php -> Tests\Unit\XTest,
    // Integration/XTest.php -> Tests\Integration\XTest.
    $sub   = str_contains($file, '/Integration/') ? 'Integration' : 'Unit';
    $class = 'Tests\\' . $sub . '\\' . basename($file, '.php');
    if (!class_exists($class)) {
        fwrite(STDERR, "Класс {$class} не найден в {$file}\n");
        $failures++;
        continue;
    }
    $failures += $class::runAll();
}

echo $failures === 0 ? "ВСЕ ТЕСТЫ ПРОЙДЕНЫ ✅\n" : "ПРОВАЛЫ: {$failures}\n";
exit($failures === 0 ? 0 : 1);
