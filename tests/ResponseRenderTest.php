<?php

namespace MikroApi\Tests;

use MikroApi\Response;
use MikroApi\View\Engine;
use PHPUnit\Framework\TestCase;

class ResponseRenderTest extends TestCase
{
    private string $viewsPath;

    protected function setUp(): void
    {
        $this->viewsPath = sys_get_temp_dir() . '/mikro_render_' . uniqid();
        mkdir($this->viewsPath, 0777, true);

        // Reset static engine
        $ref = new \ReflectionProperty(Response::class, 'viewEngine');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->viewsPath . '/*.php'));
        @rmdir($this->viewsPath);

        $ref = new \ReflectionProperty(Response::class, 'viewEngine');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    public function testRenderThrowsWithoutEngine(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('View engine not configured');
        Response::render('test');
    }

    public function testRenderReturnsHtmlResponse(): void
    {
        file_put_contents($this->viewsPath . '/greet.php', 'Hi {{ $name }}');
        Response::setViewEngine(new Engine($this->viewsPath));

        $res = Response::render('greet', ['name' => 'Dan']);
        $this->assertEquals(200, $res->getStatus());
        $this->assertEquals('Hi Dan', $res->getBody());
    }

    public function testRenderCustomStatus(): void
    {
        file_put_contents($this->viewsPath . '/err.php', 'Not found');
        Response::setViewEngine(new Engine($this->viewsPath));

        $res = Response::render('err', [], 404);
        $this->assertEquals(404, $res->getStatus());
    }
}
