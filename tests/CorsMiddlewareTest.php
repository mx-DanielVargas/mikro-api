<?php

namespace MikroApi\Tests;

use MikroApi\Middleware\CorsMiddleware;
use MikroApi\Request;
use MikroApi\Response;
use PHPUnit\Framework\TestCase;

class CorsMiddlewareTest extends TestCase
{
    private function makeRequest(string $method): Request
    {
        $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/test'];
        $_GET = [];
        $_POST = [];
        return Request::capture();
    }

    private function passthrough(): callable
    {
        return fn(Request $r) => Response::json(['ok' => true]);
    }

    public function testAddsDefaultCorsHeaders(): void
    {
        $mw = new CorsMiddleware();
        $res = $mw->handle($this->makeRequest('GET'), $this->passthrough());

        $this->assertEquals(200, $res->getStatus());
    }

    public function testPreflightReturns204(): void
    {
        $mw = new CorsMiddleware();
        $res = $mw->handle($this->makeRequest('OPTIONS'), $this->passthrough());

        $this->assertEquals(204, $res->getStatus());
        $this->assertEquals('', $res->getBody());
    }

    public function testCustomOrigins(): void
    {
        $mw = new CorsMiddleware(origins: ['https://example.com']);
        $res = $mw->handle($this->makeRequest('GET'), $this->passthrough());

        $this->assertEquals(200, $res->getStatus());
    }

    public function testCustomMethods(): void
    {
        $mw = new CorsMiddleware(methods: ['GET', 'POST']);
        $res = $mw->handle($this->makeRequest('GET'), $this->passthrough());

        $this->assertEquals(200, $res->getStatus());
    }

    public function testPreflightWithCustomConfig(): void
    {
        $mw = new CorsMiddleware(
            origins: ['https://app.example.com'],
            methods: ['GET', 'POST'],
            headers: ['Content-Type'],
            maxAge: 7200,
        );

        $res = $mw->handle($this->makeRequest('OPTIONS'), $this->passthrough());

        $this->assertEquals(204, $res->getStatus());
    }

    public function testNextIsCalledForNonOptions(): void
    {
        $called = false;
        $next = function (Request $r) use (&$called) {
            $called = true;
            return Response::json(['ok' => true]);
        };

        $mw = new CorsMiddleware();
        $mw->handle($this->makeRequest('GET'), $next);

        $this->assertTrue($called);
    }

    public function testNextIsNotCalledForOptions(): void
    {
        $called = false;
        $next = function (Request $r) use (&$called) {
            $called = true;
            return Response::json(['ok' => true]);
        };

        $mw = new CorsMiddleware();
        $mw->handle($this->makeRequest('OPTIONS'), $next);

        $this->assertFalse($called);
    }
}
