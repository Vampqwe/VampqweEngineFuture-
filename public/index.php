<?php

declare(strict_types=1);

// ЕДИНСТВЕННЫЙ хардкод во всем приложении — стартовая константа.
define('BASE_PATH', dirname(__DIR__));

use App\Core\System\App;
use App\Core\System\Support\FatalPage;

try {
    require_once BASE_PATH . '/vendor/autoload.php';

    $app = new App();
    $app->run();
} catch (\Throwable $e) {
    // Движок упал ДО инициализации ядра (нет автозагрузчика, синтаксис конфига,
    // фатал при создании контейнера). Модуль Error ещё недоступен — печатаем
    // единственный разрешённый хардкод-рендер Support\FatalPage (см. его PHPDoc).
    FatalPage::send($e);
}
