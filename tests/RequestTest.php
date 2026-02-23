<?php

namespace MikroApi\Tests;

use MikroApi\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    protected function setUp(): void
    {
        // Limpiar variables globales antes de cada test
        $_SERVER = [];
        $_GET = [];
        $_POST = [];
    }

    public function testCaptureGetRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users';

        $request = Request::capture();

        $this->assertEquals('GET', $request->method);
        $this->assertEquals('/users', $request->path);
    }

    public function testCapturePostRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/users';

        $request = Request::capture();

        $this->assertEquals('POST', $request->method);
    }

    public function testPathNormalization(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users/';

        $request = Request::capture();

        $this->assertEquals('/users', $request->path);
    }

    public function testRootPath(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $request = Request::capture();

        $this->assertEquals('/', $request->path);
    }

    public function testQueryStringParsing(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users?page=2&limit=10';
        $_GET = ['page' => '2', 'limit' => '10'];

        $request = Request::capture();

        $this->assertEquals('/users', $request->path);
        $this->assertEquals(['page' => '2', 'limit' => '10'], $request->query);
    }

    public function testFormDataBody(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/users';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = ['name' => 'John', 'email' => 'john@example.com'];

        $request = Request::capture();

        $this->assertEquals(['name' => 'John', 'email' => 'john@example.com'], $request->body);
    }

    public function testHeaderParsing(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer token123';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        $request = Request::capture();

        $this->assertEquals('Bearer token123', $request->header('Authorization'));
        $this->assertEquals('TestAgent/1.0', $request->header('User-Agent'));
        $this->assertEquals('application/json', $request->header('Content-Type'));
    }

    public function testHeaderCaseInsensitive(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer token123';

        $request = Request::capture();

        $this->assertEquals('Bearer token123', $request->header('authorization'));
        $this->assertEquals('Bearer token123', $request->header('AUTHORIZATION'));
        $this->assertEquals('Bearer token123', $request->header('Authorization'));
    }

    public function testHeaderReturnsNullWhenNotFound(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users';

        $request = Request::capture();

        $this->assertNull($request->header('X-Custom-Header'));
    }

    public function testInputMethod(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/users';
        $_POST = ['name' => 'John', 'email' => 'john@example.com'];

        $request = Request::capture();

        $this->assertEquals('John', $request->input('name'));
        $this->assertEquals('john@example.com', $request->input('email'));
    }

    public function testInputWithDefault(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/users';
        $_POST = ['name' => 'John'];

        $request = Request::capture();

        $this->assertEquals('default@example.com', $request->input('email', 'default@example.com'));
    }

    public function testEmptyBody(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users';

        $request = Request::capture();

        $this->assertEquals([], $request->body);
    }

    public function testMethodUppercase(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';
        $_SERVER['REQUEST_URI'] = '/users';

        $request = Request::capture();

        $this->assertEquals('POST', $request->method);
    }

    public function testComplexPath(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/users/123/posts?sort=date';

        $request = Request::capture();

        $this->assertEquals('/api/v1/users/123/posts', $request->path);
    }

    public function testParamsInitiallyEmpty(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users';

        $request = Request::capture();

        $this->assertEquals([], $request->params);
    }

    public function testDtoInitiallyNull(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users';

        $request = Request::capture();

        $this->assertNull($request->dto);
    }
}
