<?php

namespace MikroApi\Tests;

use MikroApi\App;
use MikroApi\Router;
use MikroApi\Request;
use MikroApi\Response;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Route;
use PHPUnit\Framework\TestCase;

class RouterCacheTest extends TestCase
{
    private ?string $cacheFile = null;

    protected function tearDown(): void
    {
        if ($this->cacheFile !== null && \is_file($this->cacheFile)) {
            \unlink($this->cacheFile);
        }
        $this->cacheFile = null;
    }

    public function testCacheToAndLoadFromCacheRoundTrip(): void
    {
        $this->cacheFile = $this->makeTempCacheFile();

        $router = new Router();
        $router->registerController(CacheableController::class);
        $router->cacheTo($this->cacheFile);

        $this->assertFileExists($this->cacheFile);

        $freshRouter = new Router();
        $loaded      = $freshRouter->loadFromCache($this->cacheFile);

        $this->assertTrue($loaded);

        $request  = $this->createRequest('GET', '/cached/hello');
        $response = $freshRouter->dispatch($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('hello', $request->params['name']);
    }

    public function testLoadFromCacheReturnsFalseWhenFileDoesNotExist(): void
    {
        $router = new Router();

        $missingFile = \sys_get_temp_dir() . '/mikroapi_test_routes_missing_' . \uniqid() . '.php';

        $this->assertFalse($router->loadFromCache($missingFile));
    }

    public function testRegisterControllerIsNoOpAfterLoadFromCache(): void
    {
        $this->cacheFile = $this->makeTempCacheFile();

        $seedRouter = new Router();
        $seedRouter->registerController(CacheableController::class);
        $seedRouter->cacheTo($this->cacheFile);

        $router = new Router();
        $this->assertTrue($router->loadFromCache($this->cacheFile));

        // No debe lanzar error ni duplicar rutas al intentar registrar de nuevo.
        $router->registerController(CacheableController::class);

        $request  = $this->createRequest('GET', '/cached/world');
        $response = $router->dispatch($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('world', $request->params['name']);
    }

    public function testLoadFromCacheReturnsFalseOnCorruptFile(): void
    {
        $this->cacheFile = $this->makeTempCacheFile();
        \file_put_contents($this->cacheFile, "<?php\n\nreturn array (\n  0 =>\n  array (\n");

        $router = new Router();
        $this->assertFalse($router->loadFromCache($this->cacheFile));
    }

    public function testLoadFromCacheReturnsFalseOnInvalidStructure(): void
    {
        $this->cacheFile = $this->makeTempCacheFile();
        \file_put_contents($this->cacheFile, "<?php\n\nreturn [['foo' => 'bar']];\n");

        $router = new Router();
        $this->assertFalse($router->loadFromCache($this->cacheFile));
    }

    public function testCacheToWritesAtomically(): void
    {
        $this->cacheFile = $this->makeTempCacheFile();

        $router = new Router();
        $router->registerController(CacheableController::class);
        $router->cacheTo($this->cacheFile);

        $this->assertFileExists($this->cacheFile);

        $residualTmpFiles = \glob($this->cacheFile . '.*.tmp');
        $this->assertSame([], $residualTmpFiles);
    }

    public function testCacheRoutesThrowsIfCalledAfterUseController(): void
    {
        $this->cacheFile = $this->makeTempCacheFile();

        $app = new App();
        $app->useController(NoopController::class);

        $this->expectException(\LogicException::class);
        $app->cacheRoutes($this->cacheFile);
    }

    public function testCacheRoutesWorksBeforeUseController(): void
    {
        $this->cacheFile = $this->makeTempCacheFile();

        $app    = new App();
        $result = $app->cacheRoutes($this->cacheFile)
            ->useController(NoopController::class);

        $this->assertInstanceOf(App::class, $result);
    }

    private function makeTempCacheFile(): string
    {
        return \sys_get_temp_dir() . '/mikroapi_test_routes_' . \uniqid() . '.php';
    }

    private function createRequest(string $method, string $path): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $path;

        return Request::capture();
    }
}

// ── Test Controller ─────────────────────────────────────────────────────

#[Controller(prefix: 'cached')]
class CacheableController
{
    #[Route('GET', '/:name')]
    public function show(Request $request): Response
    {
        return Response::json(['name' => $request->params['name']]);
    }
}

class NoopController
{
}


