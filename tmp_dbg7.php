<?php
declare(strict_types=1);
require '/workspace/vendor/autoload.php';
use App\Core\Module\Template\Internal\Compiler;
$c = new Compiler();
$src = file_get_contents('/workspace/view/core/installer/requirements.phtml');
$out = $c->compile($src);
echo 'LEN: ' . strlen($out) . PHP_EOL;
echo 'TAIL 250: ' . substr($out, -250) . PHP_EOL;
