<?php

namespace MikroApi\Tests;

use MikroApi\Middleware\InMemoryRateLimitStore;
use MikroApi\Middleware\RateLimitMiddleware;
use MikroApi\Request;
use MikroApi\Response;
use PHPUnit\Framework\TestCase;

class RateLimitMiddlewareTest extends TestCase
{
    // Nota (AUD-004): el contador ya no es un array estático compartido por
    // todas las instancias de RateLimitMiddleware; cada instancia recibe su
    // propio InMemoryRateLimitStore (o el que se le inyecte), por lo que ya
    // no es necesario resetear estado global entre tests.

    private function makeRequest(): Request
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        $_GET = [];
        $_POST = [];
        return Request::capture();
    }

    private function passthrough(): callable
    {
        return fn(Request $r) => Response::json(['ok' => true]);
    }

    public function testAllowsWithinLimit(): void
    {
        $mw = new RateLimitMiddleware(maxRequests: 5, windowSeconds: 60);

        $res = $mw->handle($this->makeRequest(), $this->passthrough());

        $this->assertEquals(200, $res->getStatus());
    }

    public function testBlocksWhenExceeded(): void
    {
        $mw = new RateLimitMiddleware(maxRequests: 2, windowSeconds: 60);
        $next = $this->passthrough();

        $mw->handle($this->makeRequest(), $next);
        $mw->handle($this->makeRequest(), $next);
        $res = $mw->handle($this->makeRequest(), $next);

        $this->assertEquals(429, $res->getStatus());
    }

    public function testDefaultLimits(): void
    {
        $mw = new RateLimitMiddleware();
        $res = $mw->handle($this->makeRequest(), $this->passthrough());

        $this->assertEquals(200, $res->getStatus());
    }

    public function testSharedStoreAcrossInstances(): void
    {
        $store = new InMemoryRateLimitStore();
        $mw1 = new RateLimitMiddleware(maxRequests: 2, windowSeconds: 60, store: $store);
        $mw2 = new RateLimitMiddleware(maxRequests: 2, windowSeconds: 60, store: $store);
        $next = $this->passthrough();

        $mw1->handle($this->makeRequest(), $next);
        $mw2->handle($this->makeRequest(), $next);
        $res = $mw1->handle($this->makeRequest(), $next);

        $this->assertEquals(429, $res->getStatus());
    }

    public function testInMemoryStoreResetsAfterWindowExpires(): void
    {
        $store = new InMemoryRateLimitStore();

        $first = $store->increment('1.2.3.4', 60);
        $this->assertEquals(1, $first['count']);

        // Simula que la ventana ya expiró manipulando el reset guardado.
        $ref = new \ReflectionClass(InMemoryRateLimitStore::class);
        $prop = $ref->getProperty('store');
        $prop->setAccessible(true);
        $data = $prop->getValue($store);
        $data['1.2.3.4']['reset'] = \time() - 1;
        $prop->setValue($store, $data);

        $afterExpiry = $store->increment('1.2.3.4', 60);
        $this->assertEquals(1, $afterExpiry['count']);
    }
}
