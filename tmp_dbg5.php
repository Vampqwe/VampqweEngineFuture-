<?php
declare(strict_types=1);
require '/workspace/vendor/autoload.php';
use App\Core\Module\Template\Internal\Compiler;
$c = new Compiler();
foreach (['requirements','settings','success','failure'] as $t) {
    $src = file_get_contents("/workspace/view/core/installer/$t.phtml");
    echo "=== $t: first 3 lines ===" . PHP_EOL;
    foreach (array_slice(explode("\n", $src), 0, 3) as $l) echo substr($l,0,120) . PHP_EOL;
}
