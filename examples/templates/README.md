# Template Engine Example

This example demonstrates the full templating syntax supported by MikroAPI's built-in view engine (`MikroApi\View\Engine`).

## Features Demonstrated

- ✅ Layouts (`@extends('layout')` / `@yield('name')`)
- ✅ Sections (`@section('name')` / `@endsection`)
- ✅ Includes / partials with dot notation (`@include('partials.nav')`)
- ✅ Conditionals (`@if` / `@elseif` / `@else` / `@endif`)
- ✅ Loops (`@foreach($items as $item)` / `@endforeach`)
- ✅ Escaped vs. raw output (`{{ $var }}` vs `{!! $var !!}`)
- ✅ Compiled template caching (`Engine::setCachePath()`)

## Running the Example

```bash
cd examples/templates
php -S localhost:8000 index.php
```

Then visit:

- **`http://localhost:8000/`** — escaped vs. raw output demo
- **`http://localhost:8000/products`** — loops + conditionals demo

## File Structure

```
examples/templates/
├── index.php               # Bootstraps App, enables views + template caching
├── views/
│   ├── layout.php           # Base layout: @yield('title'), @include, @yield('content')
│   ├── partials/
│   │   └── nav.php          # Hardcoded nav bar, pulled in via @include
│   ├── home.php              # @extends('layout'); escaped vs. raw output
│   └── product-list.php      # @extends('layout'); @foreach + @if/@else
└── cache/                    # Compiled template cache (generated at runtime)
```

## Directives Reference

| Directive | Description |
|-----------|-------------|
| `{{ $var }}` | Escaped output (XSS-safe) |
| `{!! $var !!}` | Raw output (no escaping) |
| `@if` / `@elseif` / `@else` / `@endif` | Conditionals |
| `@foreach($items as $item)` / `@endforeach` | Loops |
| `@include('partial.name')` | Include sub-template (dot notation) |
| `@extends('layout')` | Inherit from a layout |
| `@section('name')` / `@endsection` | Define a section |
| `@yield('name')` | Render a section in layout |

## Escaped vs. Raw Output (`home.php`)

`index.php` passes two values to the `home` view:

```php
'name'    => '<script>alert(1)</script>',   // rendered with {{ $name }}
'rawHtml' => '<strong>This is bold, trusted HTML</strong>', // rendered with {!! $rawHtml !!}
```

- `{{ $name }}` runs the value through `htmlspecialchars()`, so visiting `/` and viewing the page source shows the literal text `&lt;script&gt;alert(1)&lt;/script&gt;` — it is **not** executed as HTML/JS.
- `{!! $rawHtml !!}` outputs the value verbatim, so `<strong>This is bold, trusted HTML</strong>` renders as actual bold text.

> ⚠️ **XSS warning**: `{!! !!}` outputs raw, unescaped HTML. **Never** pass unsanitized user input (form fields, query strings, database records containing user content, etc.) through `{!! !!}` — doing so is a direct cross-site scripting (XSS) vulnerability. Only use it for trusted, server-generated HTML that you fully control (e.g. markdown you rendered yourself), never for anything derived from request input.

## Verifying the Compilation Cache

`index.php` calls:

```php
Response::getViewEngine()->setCachePath(__DIR__ . '/cache');
```

To verify it's working:

1. Start the server and visit `http://localhost:8000/`.
2. Inspect `examples/templates/cache/` — you should see one `.php` file per rendered view/partial (named `md5(<absolute-view-path>).php`), each starting with a `<?php /* src-hash:... */ ?>` marker followed by the compiled PHP output (no more `@if`/`@foreach`/etc. — those have already been turned into real `<?php ... ?>` blocks).
3. Reload the page — subsequent requests reuse the cached compiled PHP instead of re-parsing the template's directives.
4. Edit any view's content (e.g. add a space to `home.php`) and reload — the engine computes a fresh hash of the source file, sees it no longer matches the hash embedded in the cache file, and transparently regenerates the cache entry. This invalidation is **content-hash based, not mtime-based**, so it isn't affected by filesystem timestamp resolution or clock skew.

## Notes

- The `cache/` directory is committed with only a `.gitignore` inside it (ignoring its own generated contents) so the directory exists in git without checking in machine-generated compiled templates.
- `PageController` has no `#[Controller(...)]` prefix, so its routes are `GET /` and `GET /products` directly.
