<?php

declare(strict_types=1);

$l = require __DIR__ . '/vendor/autoload.php';

$p = (new ReflectionProperty('Composer\Autoload\ClassLoader', 'prefixDirsPsr4'));
$p->setAccessible(true);
$all = $p->getValue($l);

foreach ([
    'App\\Modules\\Pages\\PageRepository',
    'App\\Core\\Module\\Database\\Providers\\DatabaseServiceProvider',
] as $c) {
    $logicalPathPsr4 = strtr($c, '\\', '/') . '.php';
    echo "--- {$c}\n";
    $subPath = $c;
    while (false !== $lastPos = strrpos($subPath, '\\')) {
        $subPath = substr($subPath, 0, $lastPos);
        $search = $subPath . '\\';
        if (isset($all[$search])) {
            $pathEnd = DIRECTORY_SEPARATOR . substr($logicalPathPsr4, $lastPos + 1);
            foreach ($all[$search] as $d) {
                $full = $d . $pathEnd;
                echo "    prefix [{$search}] check {$full} => ", var_export(file_exists($full), true), "\n";
            }
        }
    }
    echo "    findFile => ", var_export($l->findFile($c), true), "\n";
}
