<?php
declare(strict_types=1);

/**
 * Точка запуска тестов: php tests/run.php
 * Автоподхватывает все tests/Unit/*Test.php.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/Framework/TestCase.php';

$files = glob(__DIR__ . '/Unit/*Test.php');
sort($files);

$failures = 0;
foreach ($files as $file) {
    require_once $file;
    $class = 'Tests\\Unit\\' . basename($file, '.php');
    if (!class_exists($class)) {
        fwrite(STDERR, "Класс {$class} не найден в {$file}\n");
        $failures++;
        continue;
    }
    $failures += $class::runAll();
}

echo $failures === 0 ? "ВСЕ ТЕСТЫ ПРОЙДЕНЫ ✅\n" : "ПРОВАЛЫ: {$failures}\n";
exit($failures === 0 ? 0 : 1);
