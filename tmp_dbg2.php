<?php
declare(strict_types=1);
require '/workspace/vendor/autoload.php';
use App\Core\Module\Template\Internal\Compiler;
use App\Core\Module\Template\Internal\Escaper;
use App\Core\Module\Template\Internal\TemplateEnvironment;

$c = new Compiler(); $e = new Escaper();
$childPhp = $c->compile(file_get_contents('/workspace/view/core/installer/requirements.phtml'));
$layoutSrc = file_get_contents('/workspace/view/core/layouts/installer.phtml');
$renderer = function(string $n, array $d) use ($c, $layoutSrc): string { return $c->compile($layoutSrc); };
$data = ['title'=>'T','rows'=>[['label'=>'PHP','class'=>'ok','icon'=>'✔','verdict'=>'OK']],'allOk'=>true,'step'=>1];
$env = new TemplateEnvironment($c, $e, $renderer);
$out = $env->render($childPhp, $data);
// Есть ли таблица с PHP в выводе?
echo 'HAS TABLE ROW: ' . (str_contains($out, 'PHP') ? 'YES' : 'NO') . PHP_EOL;
echo 'HAS FORM: ' . (str_contains($out, 'action="/install/settings"') ? 'YES' : 'NO') . PHP_EOL;
echo 'LEN: ' . strlen($out) . PHP_EOL;
