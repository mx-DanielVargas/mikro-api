<?php

namespace MikroApi\Tests;

use MikroApi\View\Engine;
use PHPUnit\Framework\TestCase;

class ViewEngineTest extends TestCase
{
    private string $viewsPath;

    protected function setUp(): void
    {
        $this->viewsPath = sys_get_temp_dir() . '/mikro_views_' . uniqid();
        mkdir($this->viewsPath, 0777, true);
        mkdir($this->viewsPath . '/partials', 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->viewsPath . '/partials/*.php'));
        @rmdir($this->viewsPath . '/partials');
        array_map('unlink', glob($this->viewsPath . '/*.php'));
        @rmdir($this->viewsPath);
    }

    private function write(string $name, string $content): void
    {
        file_put_contents($this->viewsPath . '/' . $name . '.php', $content);
    }

    private function engine(): Engine
    {
        return new Engine($this->viewsPath);
    }

    public function testVariableInterpolation(): void
    {
        $this->write('hello', 'Hello {{ $name }}!');
        $this->assertEquals('Hello World!', $this->engine()->render('hello', ['name' => 'World']));
    }

    public function testEscapesHtml(): void
    {
        $this->write('xss', '{{ $input }}');
        $this->assertEquals('&lt;script&gt;', $this->engine()->render('xss', ['input' => '<script>']));
    }

    public function testRawOutput(): void
    {
        $this->write('raw', '{!! $html !!}');
        $this->assertEquals('<b>bold</b>', $this->engine()->render('raw', ['html' => '<b>bold</b>']));
    }

    public function testIfElse(): void
    {
        $this->write('cond', '@if($show)yes@elseno@endif');
        $this->assertEquals('yes', $this->engine()->render('cond', ['show' => true]));
        $this->assertEquals('no', $this->engine()->render('cond', ['show' => false]));
    }

    public function testForeach(): void
    {
        $this->write('loop', '@foreach($items as $i){{ $i }},@endforeach');
        $this->assertEquals('a,b,', $this->engine()->render('loop', ['items' => ['a', 'b']]));
    }

    public function testInclude(): void
    {
        $this->write('partials/nav', '<nav>{{ $title }}</nav>');
        $this->write('page', '<div>@include(\'partials.nav\')</div>');
        $this->assertEquals('<div><nav>Hi</nav></div>', $this->engine()->render('page', ['title' => 'Hi']));
    }

    public function testLayout(): void
    {
        $this->write('layout', '<html>@yield(\'content\')</html>');
        $this->write('child', "@extends('layout')@section('content')Hello@endsection");
        $this->assertEquals('<html>Hello</html>', $this->engine()->render('child'));
    }

    public function testViewNotFoundThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('View not found');
        $this->engine()->render('nonexistent');
    }

    public function testElseif(): void
    {
        $this->write('elseif', '@if($v === 1)one@elseif($v === 2)two@elsemany@endif');
        $this->assertEquals('one', $this->engine()->render('elseif', ['v' => 1]));
        $this->assertEquals('two', $this->engine()->render('elseif', ['v' => 2]));
        $this->assertEquals('many', $this->engine()->render('elseif', ['v' => 99]));
    }
}
