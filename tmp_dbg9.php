<?php
declare(strict_types=1);
require '/workspace/vendor/autoload.php';
use App\Core\Module\Template\Internal\Compiler;
$c = new Compiler();
$src = file_get_contents('/workspace/view/core/installer/requirements.phtml');
$out = $c->compile($src);
file_put_contents('/tmp/dbg_out.txt', "=== COMPILED (first 600) ===\n" . substr($out,0,600) . "\n\nstopSection count: " . substr_count($out,'stopSection'));
