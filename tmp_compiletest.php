<?php
declare(strict_types=1);
require '/workspace/vendor/autoload.php';

use App\Core\Module\Template\Internal\Compiler;

$src = file_get_contents('/tmp/tw5.phtml');
echo 'SRC:' . str_replace("\n", '|', $src) . PHP_EOL;
$c = new Compiler();
echo 'COMPILED:' . str_replace("\n", '|', $c->compile($src)) . PHP_EOL;
