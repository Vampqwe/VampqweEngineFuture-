<?php
declare(strict_types=1);
require '/workspace/vendor/autoload.php';
use App\Core\Module\Template\Template;
$t = new Template('/workspace/view/core', '/workspace/view/app', '/workspace/storage/cache/templates', true);
$out = $t->renderCore('installer/requirements', ['title'=>'T','rows'=>[['label'=>'PHP','class'=>'ok','icon'=>'OK','verdict'=>'OK']],'allOk'=>true,'step'=>1]);
echo 'HAS H1: ' . (str_contains($out, '<h1>') ? 'YES':'NO') . PHP_EOL;
echo 'HAS FORM: ' . (str_contains($out, 'install/settings') ? 'YES':'NO') . PHP_EOL;
echo substr($out, strpos($out,'<div class="steps">'), 600) . PHP_EOL;
