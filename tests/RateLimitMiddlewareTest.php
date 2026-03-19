<?php

namespace MikroApi\Tests;

use MikroApi\Middleware\RateLimitMiddleware;
use MikroApi\Request;
use MikroApi\Response;
use PHPUnit\Framework\TestCase;

class RateLimitMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset static store between tests
        $ref = new \ReflectionClass(RateLimitMiddleware::class);
        $prop = $ref->getProperty('store');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

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
}
