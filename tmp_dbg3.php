<?php
declare(strict_types=1);
require '/workspace/vendor/autoload.php';
use App\Core\Module\Template\Internal\Compiler;
$c = new Compiler();
$src = file_get_contents('/workspace/view/core/installer/requirements.phtml');
$out = $c->compile($src);
echo "--- COMPILED TAIL (последние 500 симв) ---" . PHP_EOL;
echo substr($out, -500) . PHP_EOL;
echo "--- contains stopSection count: " . substr_count($out, 'stopSection') . PHP_EOL;
