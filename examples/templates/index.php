<?php
/**
 * Template Engine Example
 *
 * Demonstrates the full templating syntax supported by MikroApi\View\Engine:
 *   - @extends('layout') / @yield('name')       — layout inheritance
 *   - @section('name') ... @endsection          — sections filled in by child views
 *   - @include('partials.nav')                  — partial includes (dot notation)
 *   - @if / @elseif / @else / @endif             — conditionals
 *   - @foreach($items as $item) / @endforeach    — loops
 *   - {{ $var }}                                 — escaped output (XSS-safe)
 *   - {!! $var !!}                               — raw, unescaped output
 *   - Engine::setCachePath()                     — compiled-template caching on disk
 *
 * Run with:
 *   cd examples/templates
 *   php -S localhost:8000 index.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use MikroApi\App;
use MikroApi\Request;
use MikroApi\Response;
use MikroApi\Attributes\Route;

// 1. Bootstrap the app.
$app = new App();

// 2. Register the views/ directory as the template source.
$app->useViews(__DIR__ . '/views');

// 3. Enable compiled-template caching. The engine hashes each view's source
//    content and stores the compiled PHP in cache/<md5(path)>.php; it is only
//    recompiled when the source content changes (not on every request, and
//    not based on filesystem mtime — see src/View/Engine.php::getCompiled()).
Response::getViewEngine()->setCachePath(__DIR__ . '/cache');

// 4. Controller exposing two pages that exercise every template directive.
class PageController
{
    #[Route('GET', '/')]
    public function home(Request $req): Response
    {
        return Response::render('home', [
            // Rendered with {{ $name }} in home.php — will be HTML-escaped,
            // so this string shows up as literal text, not an executed script.
            'name' => '<script>alert(1)</script>',

            // Rendered with {!! $rawHtml !!} in home.php — output verbatim.
            // This is safe ONLY because it's a hardcoded string we control
            // here on the server, never user/request-supplied data.
            'rawHtml' => '<strong>This is bold, trusted HTML</strong>',
        ]);
    }

    #[Route('GET', '/products')]
    public function products(Request $req): Response
    {
        return Response::render('product-list', [
            'products' => [
                ['name' => 'Wireless Mouse', 'stock' => 12],
                ['name' => 'Mechanical Keyboard', 'stock' => 5],
                ['name' => 'USB-C Hub', 'stock' => 0],
                ['name' => '4K Monitor', 'stock' => 3],
                ['name' => 'Laptop Stand', 'stock' => 0],
            ],
        ]);
    }
}

// 5. Register the controller and run the app.
$app->useController(PageController::class)->run();
