<?php

namespace MikroApi\Tests;

use MikroApi\App;
use MikroApi\Container;
use MikroApi\Middleware\MiddlewareInterface;
use MikroApi\Request;
use MikroApi\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests de App que NO requieren mockear el entorno HTTP completo
 * (ver tests/README.md). run() queda deliberadamente sin cobertura
 * directa aquí: requiere Request::capture() + superglobales completas.
 */
class AppTest extends TestCase
{
    private mixed $prevEnvValue;
    private bool $prevEnvWasSet;

    private mixed $prevServerValue;
    private bool $prevServerWasSet;

    private string|false $prevGetenvValue;

    protected function setUp(): void
    {
        $this->prevEnvWasSet = \array_key_exists('APP_ENV', $_ENV);
        $this->prevEnvValue  = $this->prevEnvWasSet ? $_ENV['APP_ENV'] : null;
        unset($_ENV['APP_ENV']);

        $this->prevServerWasSet = \array_key_exists('APP_ENV', $_SERVER);
        $this->prevServerValue  = $this->prevServerWasSet ? $_SERVER['APP_ENV'] : null;
        unset($_SERVER['APP_ENV']);

        $this->prevGetenvValue = \getenv('APP_ENV');
        \putenv('APP_ENV');
    }

    protected function tearDown(): void
    {
        if ($this->prevEnvWasSet) {
            $_ENV['APP_ENV'] = $this->prevEnvValue;
        } else {
            unset($_ENV['APP_ENV']);
        }

        if ($this->prevServerWasSet) {
            $_SERVER['APP_ENV'] = $this->prevServerValue;
        } else {
            unset($_SERVER['APP_ENV']);
        }

        if ($this->prevGetenvValue === false) {
            \putenv('APP_ENV');
        } else {
            \putenv("APP_ENV={$this->prevGetenvValue}");
        }
    }

    private function callIsProduction(App $app): bool
    {
        $method = new \ReflectionMethod(App::class, 'isProduction');
        $method->setAccessible(true);
        return $method->invoke($app);
    }

    /* ------------------------------------------------------------------ */
    /*  isProduction()                                                      */
    /* ------------------------------------------------------------------ */

    public function testIsProductionTrueWhenEnvSuperglobalIsProduction(): void
    {
        $_ENV['APP_ENV'] = 'production';

        $this->assertTrue($this->callIsProduction(new App()));
    }

    public function testIsProductionTrueWhenServerSuperglobalIsProduction(): void
    {
        $_SERVER['APP_ENV'] = 'production';

        $this->assertTrue($this->callIsProduction(new App()));
    }

    public function testIsProductionTrueWhenGetenvIsProduction(): void
    {
        \putenv('APP_ENV=production');

        $this->assertTrue($this->callIsProduction(new App()));
    }

    public function testIsProductionFalseWhenNotSet(): void
    {
        $this->assertFalse($this->callIsProduction(new App()));
    }

    public function testIsProductionFalseWhenSetToNonProductionValue(): void
    {
        $_ENV['APP_ENV'] = 'development';

        $this->assertFalse($this->callIsProduction(new App()));
    }

    public function testIsProductionPrefersEnvOverServerOverGetenv(): void
    {
        // $_SERVER y getenv() dicen "production", pero $_ENV manda y dice otra cosa.
        $_ENV['APP_ENV']    = 'staging';
        $_SERVER['APP_ENV'] = 'production';
        \putenv('APP_ENV=production');

        $this->assertFalse($this->callIsProduction(new App()));

        // $_ENV no está seteado; $_SERVER manda sobre getenv().
        unset($_ENV['APP_ENV']);
        $_SERVER['APP_ENV'] = 'staging';
        \putenv('APP_ENV=production');

        $this->assertFalse($this->callIsProduction(new App()));
    }

    /* ------------------------------------------------------------------ */
    /*  Wiring fluido                                                       */
    /* ------------------------------------------------------------------ */

    public function testUseMiddlewareReturnsSelfForChaining(): void
    {
        $app = new App();
        $middleware = new class implements MiddlewareInterface {
            public function handle(Request $request, callable $next): Response
            {
                return $next($request);
            }
        };

        $result = $app->useMiddleware($middleware);

        $this->assertSame($app, $result);
    }

    public function testUseControllerReturnsSelfForChaining(): void
    {
        $app    = new App();
        $result = $app->useController(AppTestDummyController::class);

        $this->assertSame($app, $result);
    }

    public function testEnableSwaggerReturnsSelfAndDoesNotThrowWithoutControllers(): void
    {
        $app = new App();

        $result = $app->enableSwagger();

        $this->assertSame($app, $result);
    }

    public function testGetContainerReturnsSameContainerPassedToConstructor(): void
    {
        $container = new Container();
        $app       = new App($container);

        $this->assertSame($container, $app->getContainer());
    }

    public function testGetContainerReturnsNewContainerWhenNoneProvided(): void
    {
        $app = new App();

        $this->assertInstanceOf(Container::class, $app->getContainer());
    }
}

// ── Fixtures ────────────────────────────────────────────────────────────

class AppTestDummyController
{
    public function index(): Response
    {
        return Response::json([]);
    }
}
