<?php
declare(strict_types=1);
require '/workspace/vendor/autoload.php';
use App\Core\Module\Template\Internal\Compiler;
$c = new Compiler();
$src = file_get_contents('/workspace/view/core/installer/requirements.phtml');
$out = $c->compile($src);
echo substr($out, 0, 400) . PHP_EOL;
