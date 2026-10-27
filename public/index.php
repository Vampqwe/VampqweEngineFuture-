<?php

declare(strict_types=1);

// ЕДИНСТВЕННЫЙ хардкод во всем приложении — стартовая константа.
define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';

use App\Core\System\App;

$app = new App();
$app->run();
