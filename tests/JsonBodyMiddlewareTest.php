<?php

namespace MikroApi\Tests;

use MikroApi\Middleware\JsonBodyMiddleware;
use MikroApi\Request;
use MikroApi\Response;
use PHPUnit\Framework\TestCase;

class JsonBodyMiddlewareTest extends TestCase
{
    private function passthrough(): callable
    {
        return fn(Request $r) => Response::json(['ok' => true]);
    }

    public function testPassesGetRequests(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test'];
        $_GET = [];
        $_POST = [];
        $req = Request::capture();

        $mw = new JsonBodyMiddleware();
        $res = $mw->handle($req, $this->passthrough());

        $this->assertEquals(200, $res->getStatus());
    }

    public function testPassesNonJsonPost(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/test',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];
        $_GET = [];
        $_POST = ['name' => 'test'];
        $req = Request::capture();

        $mw = new JsonBodyMiddleware();
        $res = $mw->handle($req, $this->passthrough());

        $this->assertEquals(200, $res->getStatus());
    }

    public function testPassesValidJsonPost(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/test',
            'CONTENT_TYPE' => 'application/json',
        ];
        $_GET = [];
        $_POST = [];
        // Simulate valid parsed JSON body
        $req = Request::capture();
        $req->body = ['name' => 'test'];

        $mw = new JsonBodyMiddleware();
        $res = $mw->handle($req, $this->passthrough());

        $this->assertEquals(200, $res->getStatus());
    }

    public function testPassesPutRequests(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'PUT',
            'REQUEST_URI' => '/test',
            'CONTENT_TYPE' => 'text/plain',
        ];
        $_GET = [];
        $_POST = [];
        $req = Request::capture();

        $mw = new JsonBodyMiddleware();
        $res = $mw->handle($req, $this->passthrough());

        $this->assertEquals(200, $res->getStatus());
    }
}
