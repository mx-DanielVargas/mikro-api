# MikroAPI Examples

This directory contains complete, runnable applications demonstrating every major feature of MikroAPI. Each example is self-contained — `cd` into its directory and run it with PHP's built-in server.

| Example | What it demonstrates |
|---|---|
| [`basic/`](./basic) | Minimal routing — the smallest possible MikroAPI app |
| [`auth/`](./auth) | JWT authentication with guards |
| [`swagger/`](./swagger) | Full Swagger/OpenAPI documentation generation |
| [`crud-api/`](./crud-api) | Repository pattern, migrations, relations, soft deletes, pagination, transactions |
| [`middleware/`](./middleware) | The full middleware pipeline: CORS, rate limiting, JSON body validation, custom middleware |
| [`templates/`](./templates) | The built-in template engine: layouts, sections, includes, directives, compiled caching |
| [`config-and-caching/`](./config-and-caching) | `ConfigService` (.env) and route caching for production setups |

## Basic Example

A simple "Hello World" API demonstrating basic routing.

```bash
cd examples/basic
php -S localhost:8000 index.php
```

Test it:
```bash
curl http://localhost:8000/api/hello
curl http://localhost:8000/api/hello/John
```

## Authentication Example

Demonstrates JWT authentication with guards.

```bash
cd examples/auth
php -S localhost:8000 index.php
```

Test it:
```bash
# Login
curl -X POST http://localhost:8000/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}'

# Use the token from response
curl http://localhost:8000/auth/me \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

## Swagger Documentation Example

Full OpenAPI 3.0 spec generation, Swagger UI, `#[ApiDoc]`/`#[ApiTag]` enrichment, and excluding endpoints from docs. See [`swagger/README.md`](./swagger/README.md) for the complete guide.

```bash
php -S localhost:8000 examples/swagger/index.php
# Visit http://localhost:8000/docs
```

## CRUD API Example ("Mini Blog")

A complete "authors have many posts" blog API running against a real, file-based SQLite database: `BaseRepository` CRUD, the fluent `QueryBuilder`, attribute-driven migrations (`#[Table]`, `#[Column]`, `#[ForeignKey]`, `#[Timestamps]`, `#[SoftDeletes]`...), `#[HasMany]`/`#[BelongsTo]` relations with eager loading (`with()`), soft deletes with restore, pagination, transactions, and DTO validation. Auto-migrates and seeds data on first boot — no CLI needed. See [`crud-api/README.md`](./crud-api/README.md).

```bash
cd examples/crud-api
php -S localhost:8000 index.php
```

## Middleware Pipeline Example

The full middleware pipeline: configurable CORS, both rate-limiting storage backends (`InMemoryRateLimitStore` / `ApcuRateLimitStore`, picked automatically based on what's available), JSON body validation, and two custom middlewares showing how to run logic both before and after the rest of the chain — plus a detailed explanation of why registration order matters. See [`middleware/README.md`](./middleware/README.md).

```bash
cd examples/middleware
php -S localhost:8000 index.php
```

## Template Engine Example

The complete Blade-like templating syntax: layouts (`@extends`/`@yield`), sections, includes/partials, conditionals, loops, escaped vs. raw output (`{{ }}` vs `{!! !!}`, with an XSS warning), and the compiled-template disk cache (`Engine::setCachePath()`, invalidated by content hash). See [`templates/README.md`](./templates/README.md).

```bash
cd examples/templates
php -S localhost:8000 index.php
```

## Config & Caching Example

A "production ready" setup combining `ConfigService` (.env loading, typed accessors, namespaced config, validation, variable interpolation, end-of-line comments) with `App::cacheRoutes()`/`clearRouteCache()` for compiled route caching. See [`config-and-caching/README.md`](./config-and-caching/README.md).

```bash
cd examples/config-and-caching
php -S localhost:8000 index.php
```
