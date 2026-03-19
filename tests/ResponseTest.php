<?php

namespace MikroApi\Tests;

use MikroApi\Response;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    public function testJson(): void
    {
        $res = Response::json(['ok' => true]);
        $this->assertEquals(200, $res->getStatus());
        $this->assertEquals('{"ok":true}', $res->getBody());
    }

    public function testJsonWithStatus(): void
    {
        $res = Response::json(['created' => true], 201);
        $this->assertEquals(201, $res->getStatus());
    }

    public function testText(): void
    {
        $res = Response::text('hello');
        $this->assertEquals(200, $res->getStatus());
        $this->assertEquals('hello', $res->getBody());
    }

    public function testHtml(): void
    {
        $res = Response::html('<h1>Hi</h1>');
        $this->assertEquals('<h1>Hi</h1>', $res->getBody());
    }

    public function testError(): void
    {
        $res = Response::error('Not Found', 404);
        $this->assertEquals(404, $res->getStatus());
        $this->assertStringContainsString('Not Found', $res->getBody());
    }

    public function testEmpty(): void
    {
        $res = Response::empty();
        $this->assertEquals(204, $res->getStatus());
        $this->assertEquals('', $res->getBody());
    }

    public function testEmptyCustomStatus(): void
    {
        $res = Response::empty(202);
        $this->assertEquals(202, $res->getStatus());
    }

    public function testRedirect(): void
    {
        $res = Response::redirect('https://example.com');
        $this->assertEquals(302, $res->getStatus());
    }

    public function testRedirectPermanent(): void
    {
        $res = Response::redirect('https://example.com', 301);
        $this->assertEquals(301, $res->getStatus());
    }

    public function testWithHeaderIsImmutable(): void
    {
        $original = Response::json(['ok' => true]);
        $modified = $original->withHeader('X-Custom', 'value');

        $this->assertNotSame($original, $modified);
    }

    public function testWithStatusIsImmutable(): void
    {
        $original = Response::json(['ok' => true]);
        $modified = $original->withStatus(201);

        $this->assertEquals(200, $original->getStatus());
        $this->assertEquals(201, $modified->getStatus());
    }

    public function testChainedModifiers(): void
    {
        $res = Response::json(['ok' => true])
            ->withStatus(201)
            ->withHeader('X-Custom', 'value');

        $this->assertEquals(201, $res->getStatus());
    }

    public function testErrorDefaultStatus(): void
    {
        $res = Response::error('fail');
        $this->assertEquals(500, $res->getStatus());
    }

    public function testJsonUnicode(): void
    {
        $res = Response::json(['name' => 'José']);
        $this->assertStringContainsString('José', $res->getBody());
    }
}
