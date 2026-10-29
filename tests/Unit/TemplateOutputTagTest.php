<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Module\Template\Internal\Compiler;
use Tests\Framework\TestCase;

/**
 * Регрессия на жадный матчер тегов вывода {{ ... }} / {!! ... !!}.
 *
 * Исторический дефект (падал SiteIdentityHttpTest): в home.phtml стартера
 * литерал '{{ $name }}' был обёрнут в вывод-строку {{ '{{ $name }}' }} —
 * единый матчер токенов захватывал вложенные фигурные скобки и генерировал
 * невалидный PHP («echo $this->e('{{ $name'); }}» + мусор), из-за чего
 * главная страница отдавала 500. Теперь содержимое тега вывода не может
 * содержать '{' или '}': вложенные литералы остаются текстом, а шаблон
 * демонстрации экранирует скобки HTML-сущностями (&#123;&#123;).
 */
final class TemplateOutputTagTest extends TestCase
{
    private function compile(string $src): string
    {
        return (new Compiler())->compile($src);
    }

    public function testNestedBracesStayAsPlainText(): void
    {
        // Регрессия: старый жадный матчер съедал вложенные скобки целиком
        // и генерировал битый PHP с мусором '}}' — главная стартера падала в 500.
        // Теперь '{'/'}' запрещены внутри тега вывода, поэтому ни один
        // PHP-фрагмент компиляции не может содержать обрывок «}}».
        $compiled = $this->compile("<h1>{{ \$a }}</h1>\n<p>{{ '{{ \$b }}' }}</p>\n<i>{{ \$c }}</i>");
        $this->assertTrue(str_contains($compiled, 'echo $this->e($a);'));
        $this->assertTrue(str_contains($compiled, 'echo $this->e($c);'));

        preg_match_all('/<\?php .*? \?>/s', $compiled, $tags);
        foreach ($tags[0] as $tag) {
            $this->assertFalse(
                str_contains($tag, '}}'),
                'PHP-тег не должен содержать обрывок }}: ' . $tag
            );
        }
    }

    public function testSimpleOutputTagsStillWork(): void
    {
        $this->assertTrue(str_contains($this->compile('{{ $name }}'), 'echo $this->e($name);'));
        $this->assertTrue(str_contains($this->compile('{!! $html !!}'), 'echo ($html);'));
        // expr со встроенными [] {} внутри — по-прежнему валидны для простых случаев
        $this->assertTrue(
            str_contains($this->compile('{{ $a . "-" . $b }}'), 'echo $this->e($a . "-" . $b);')
        );
    }

    public function testStarterHomeTemplateCompilesToValidPhp(): void
    {
        $path     = \dirname(__DIR__, 2) . '/view/app/engineStart/home.phtml';
        $source   = @file_get_contents($path);
        $this->assertNotNull($source, 'шаблон стартера home.phtml должен существовать');

        $compiled = $this->compile((string) $source);
        $tmp      = tempnam(sys_get_temp_dir(), 'vampc') . '.php';
        file_put_contents($tmp, $compiled);
        $out = [];
        $rc  = 0;
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
        unlink($tmp);

        $this->assertSame(0, $rc, 'home.phtml компилируется в валидный PHP: ' . implode("\n", $out));
    }
}
