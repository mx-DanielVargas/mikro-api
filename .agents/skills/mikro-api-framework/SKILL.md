---
name: mikro-api-framework
description: Deep knowledge of the MikroAPI PHP micro-framework (attribute-based routing, DI container, DTO validation, Repository/QueryBuilder/migrations, middleware, Swagger, template engine, ConfigService) and the bin/mikro-migrate CLI (init/make:* scaffolding). Use when building, extending, scaffolding, or debugging an API/project with MikroAPI, or when explaining how one of its features works.
---

# MikroAPI Framework Expert

MikroAPI is a minimalist, zero-external-dependency PHP 8.1+ framework (NestJS-inspired): attribute-based routing, DI with autowiring, DTO validation, a lightweight Repository/QueryBuilder ORM, attribute-driven migrations, middleware pipeline, Swagger/OpenAPI generation, a Blade-like template engine, and a `ConfigService`. Namespace root: `MikroApi\`, source in `src/`.

## Fastest path to help the user

| User wants to... | Do this |
|---|---|
| Start a brand-new project | `vendor/bin/mikro-migrate init [path]` — scaffolds `public/index.php`, `src/{Controllers,Repositories,DTOs,Middleware,Guards,Services}`, `config/database.php`, a starter migration, `.env`, `composer.json`. Safe to re-run (never overwrites). |
| Add a full CRUD resource | Run the workflow in "End-to-end: add a resource" below. |
| Just scaffold one piece | `make:controller|make:repository|make:dto|make:middleware|make:guard|make:service <Name>` (fails if the file already exists — safe for scripts). `make <name>` still creates a **migration** (legacy command, unchanged). |
| Understand a specific subsystem in depth | Read the matching `README.md` section (line ranges below) or the matching runnable example in `examples/`. |
| See real working code for a feature | `examples/` has 7 runnable apps: `basic`, `auth` (JWT), `swagger`, `crud-api` (repositories/migrations/relations/soft-deletes/pagination/transactions), `middleware` (CORS/rate-limit/JSON body/custom), `templates` (full view engine syntax + caching), `config-and-caching` (ConfigService + route caching). Each has its own `README.md`. |
| Know what's already been fixed/known limitations | Read `.roadmap/AUDIT.md` — 22+ audited findings with file:line, fix, and commit hash. Check here before "fixing" something that's already a documented, accepted tradeoff. |

## Core building blocks (cheat sheet)

| Concern | Key classes/attributes | README section |
|---|---|---|
| Routing | `#[Controller('/prefix')]`, `#[Route('GET','/:id')]`, `Router` | Quick Start L30-78 |
| DI Container | `Container::set/singleton/instance/get/make`, autowiring by constructor type-hints | Dependency Injection L78-141 |
| Middleware | `MiddlewareInterface`, `CorsMiddleware`, `RateLimitMiddleware`+`RateLimitStore` (`InMemoryRateLimitStore`/`ApcuRateLimitStore`), `JsonBodyMiddleware`, `App::useMiddleware()` (registration order = outer→inner wrapping) | Middleware L141-240 |
| Validation | `RequestDto`, `#[Required]`/`#[Optional]`/`#[IsString]`/`#[IsEmail]`/`#[MinLength]`/etc., `#[Body(SomeDto::class)]` on a route, validated result in `$req->dto` | Validation L240-281, Available Validation Rules L754-765 |
| Auth | `GuardInterface`/`BaseGuard`, `#[UseGuards(SomeGuard::class)]` (class or method level) | Authentication L281-300 |
| Swagger | `App::enableSwagger()` (spec is lazily generated, only on `/docs`/`/docs/json`), `#[ApiDoc]`, `#[ApiTag]`, `#[QueryParam]` | Swagger Documentation L300-401 |
| Data layer | `BaseRepository` (CRUD, soft deletes, `create($data, reload:)`), `QueryBuilder` (fluent), `#[HasMany]`/`#[HasOne]`/`#[BelongsTo]`/`#[BelongsToMany]` + `with()` for eager loading | Repository Pattern L401-465 |
| Migrations | `Migration`, `#[Table]`/`#[Column]`/`#[PrimaryKey]`/`#[ForeignKey]`/`#[Unique]`/`#[Index]`/`#[Timestamps]`/`#[SoftDeletes]`, `MigrationRunner` | Migrations L465-529 |
| Templates | `Response::render()`, `@extends`/`@section`/`@yield`/`@include`/`@if`/`@foreach`, `{{ }}` escaped vs `{!! !!}` raw | Template Engine L570-631 |
| Caching | `App::cacheRoutes()`/`clearRouteCache()`, `Engine::setCachePath()` (both opt-in, off by default) | Performance Caching L631-661 |
| Config | `App::useConfig()`, `ConfigService::get/getInt/getBool/getFloat/getOrThrow/register/validate`, `.env` w/ `${VAR}` interpolation and end-of-line comments | Configuration L661-743 |

## Critical gotchas (non-obvious — do not skip)

- **`create($data, reload: false)`** skips defaults/triggers/`created_at` — only use when you don't need DB-generated values back.
- **`$fillable` on repositories**: if left empty, `BaseRepository` still sanitizes column names (regex-validated) but allows any request key through — **always declare `$fillable` explicitly** for anything backed by user input.
- **`App::cacheRoutes()` MUST be called before `useController()`** — calling it after throws `LogicException` (this used to silently drop routes; now it fails loudly).
- **Route/view caches are NOT auto-invalidated by content changes to controllers** (routes) — delete the cache file or call `App::clearRouteCache()` after adding/removing routes. View caching IS auto-invalidated (by source content hash, not mtime).
- **`InMemoryRateLimitStore` (the default) does not persist across requests** in PHP-FPM/Apache/`php -S` — each request may be a fresh process. Use `ApcuRateLimitStore` (or implement `RateLimitStore` yourself) for real rate limiting in production.
- **`/docs` and `/docs/json` flow through the full middleware pipeline** (CORS, rate limiting, etc.) — same as any other route.
- **Route matching is in declaration order**: a literal segment route (e.g. `/posts/trashed`) must be declared *before* a param route (`/posts/:id`), or the param route swallows it.
- **`{!! !!}` outputs raw, unescaped HTML** — never with unsanitized user input (XSS). Use `{{ }}` (escaped) by default.
- **PDO/SQLite bool binding**: binding a PHP `bool` into an `INTEGER` column can insert an empty string instead of `0`/`1` — cast explicitly to `(int)` before writing booleans.
- Guards/middleware/DTO validation all run **before** the controller method — a controller only ever sees a request that already passed auth + validation.
- `RelationLoader` can resolve related repositories with extra constructor dependencies via the DI Container (`ContainerAwareInterface`), but only if the repository was itself resolved through the Container (not `new SomeRepository($db)` directly).

## End-to-end: add a resource (e.g. "Product")

1. `vendor/bin/mikro-migrate make create_products_table` → edit the generated migration with `#[Column]`/`#[Timestamps]`/etc., then `vendor/bin/mikro-migrate migrate`.
2. `vendor/bin/mikro-migrate make:repository Product` → set `$table`, `$fillable`.
3. `vendor/bin/mikro-migrate make:dto CreateProduct` (and `UpdateProduct` if partial updates need different rules) → uncomment/add validation attributes.
4. `vendor/bin/mikro-migrate make:controller Product` → wire `#[Body(CreateProductDto::class)]` on `store()`, inject the repository via the constructor (DI autowires it), use `$req->dto` inside handlers.
5. Register in bootstrap: `$app->useController(ProductController::class);`.
6. If it needs relations to another resource, add `#[HasMany]`/`#[BelongsTo]` on a public property of the repository and use `$repo->with('relationName')` to eager-load.

Mirror `examples/crud-api/` for a complete, working reference of this exact flow (including soft deletes, pagination, and transactions).
