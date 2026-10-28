<?php
declare(strict_types=1);
require '/workspace/vendor/autoload.php';
use App\Core\Module\Template\Internal\Compiler;
$c = new Compiler();
$src = file_get_contents('/workspace/view/core/installer/requirements.phtml');
$out = $c->compile($src);
// показать строки с startSection
foreach (explode("\n", $out) as $i => $line) {
    if (str_contains($line, 'startSection') || str_contains($line, 'stopSection')) {
        echo ($i+1) . ': ' . substr(trim($line), 0, 200) . PHP_EOL;
    }
}
