<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\System\Http\MiddlewareInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;
use App\Core\System\Support\Router;
use Tests\Framework\TestCase;

/**
 * Router (п.4 Конституции): параметры {id} должны доходить до обработчика
 * через Request->routeParams, middleware — применяться в правильном порядке.
 */
final class RouterTest extends TestCase
{
    public function testStaticRouteMatches(): void
    {
        $router = new Router();
        $router->get('/', fn(Request $r): Response => new Response('home'));

        $handler = $router->match('GET', '/');
        $this->assertNotNull($handler, 'Статический маршрут / должен матчиться');
        $this->assertSame('home', $handler(new Request('GET', '/'))->body());
    }

    public function testRouteParamsArePassedToHandler(): void
    {
        $router = new Router();
        $router->get('/blog/{slug}', fn(Request $r): Response => new Response($r->routeParams['slug'] ?? 'LOST'));

        $handler = $router->match('GET', '/blog/privet-mir');
        $this->assertNotNull($handler);
        $this->assertSame(
            'privet-mir',
            $handler(new Request('GET', '/blog/privet-mir'))->body(),
            'Параметр маршрута {slug} обязан дойти до обработчика'
        );
    }

    public function testMultipleParamsOrderPreserved(): void
    {
        $router = new Router();
        $router->get('/u/{userId}/posts/{postId}', fn(Request $r): Response
            => new Response($r->routeParams['userId'] . ':' . $r->routeParams['postId']));

        $handler = $router->match('GET', '/u/42/posts/7');
        $this->assertNotNull($handler);
        $this->assertSame('42:7', $handler(new Request('GET', '/u/42/posts/7'))->body());
    }

    public function testMethodIsolation(): void
    {
        $router = new Router();
        $router->post('/form', fn(Request $r): Response => new Response('posted'));

        $this->assertNull($router->match('GET', '/form'), 'POST-маршрут не должен матчить GET');
        $this->assertNotNull($router->match('POST', '/form'));
    }

    public function testNoMatchReturnsNull(): void
    {
        $router = new Router();
        $router->get('/a', fn(): Response => new Response('a'));
        $this->assertNull($router->match('GET', '/b'));
        $this->assertNull($router->match('GET', '/a/b'), 'Сегменты не должны схлопываться');
    }

    public function testMiddlewareWrapsInCorrectOrder(): void
    {
        // Внешний middleware — первый (добавляется первым, оборачивает последний).
        $router = new Router([new AddHeaderMw('X-Outer', '1'), new AddHeaderMw('X-Inner', '2')]);
        $router->get('/mw', fn(Request $r): Response => new Response('ok'));

        $response = $router->match('GET', '/mw')(new Request('GET', '/mw'));
        $this->assertSame('ok', $response->body());
        $this->assertSame('1', $response->headers()['X-Outer'] ?? null);
        $this->assertSame('2', $response->headers()['X-Inner'] ?? null);
    }

    public function testAnyMatchesEveryMethod(): void
    {
        $router = new Router();
        $router->any('/clock', fn(Request $r): Response => new Response($r->method));

        $this->assertSame('GET', $router->match('GET', '/clock')(new Request('GET', '/clock'))->body());
        $this->assertSame('DELETE', $router->match('DELETE', '/clock')(new Request('DELETE', '/clock'))->body());
    }
}

/** Внутритестовый middleware: добавляет заголовок и прокидывает дальше. */
final class AddHeaderMw implements MiddlewareInterface
{
    public function __construct(private readonly string $name, private readonly string $value)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);
        return $response->withHeader($this->name, $this->value);
    }
}
