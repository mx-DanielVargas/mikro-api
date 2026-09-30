---
name: mikro-api-framework
description: Deep, self-contained knowledge of every feature the MikroAPI PHP micro-framework offers (attribute-based routing, DI container, DTO validation, Repository/QueryBuilder/migrations, middleware, Swagger, template engine, ConfigService) and the bin/mikro-migrate CLI (init/make:* scaffolding). Use when building, extending, scaffolding, or debugging an API/project with MikroAPI — whether this repo IS the framework or just depends on it — or when explaining how one of its features works.
---

# MikroAPI Framework Expert

MikroAPI is a minimalist, zero-external-dependency PHP 8.1+ framework (NestJS-inspired): attribute-based routing, DI with autowiring, DTO validation, a lightweight Repository/QueryBuilder ORM, attribute-driven migrations, middleware pipeline, Swagger/OpenAPI generation, a Blade-like template engine, and a `ConfigService`. Namespace root: `MikroApi\`.

**Full exhaustive API reference (every class, method, and attribute with exact signatures) lives in `references/api-reference.md` in this skill's directory — read it whenever you need a precise signature. You should rarely need to read the framework's own source to know what's available; this file plus the reference below cover the entire public API.**

## Where does MikroAPI actually live in THIS project?

Check which context you're in before reading anything else:

- **This repo IS the framework itself** (has `src/App.php`, `composer.json` named `mikro-api/mikro-api`): docs are at the repo root — `README.md`, `MIGRATION_CLI.md`, `examples/`, `.roadmap/AUDIT.md`.
- **This repo just DEPENDS on MikroAPI** (installed via `composer require mikro-api/mikro-api`): the framework's own source and docs live under `vendor/mikro-api/mikro-api/` — e.g. `vendor/mikro-api/mikro-api/examples/crud-api/`, `vendor/mikro-api/mikro-api/README.md`. Your actual application code lives in `src/`, `public/`, `config/`, `database/migrations/`, `views/` at the project root (this is exactly what `vendor/bin/mikro-migrate init` scaffolds). **Prefer this skill's `references/api-reference.md` over grepping through `vendor/` — it's faster and already covers 100% of the public API.**

## Fastest path to help the user

| User wants to... | Do this |
|---|---|
| Start a brand-new project | `vendor/bin/mikro-migrate init [path]` — scaffolds `public/index.php`, `src/{Controllers,Repositories,DTOs,Middleware,Guards,Services}`, `config/database.php`, a starter migration, `.env`, `composer.json`. Safe to re-run (never overwrites). If `composer.json` already existed, it merges the required autoload/require entries — **remind the user to run `composer dump-autoload` afterward**. |
| Add a full CRUD resource | Run the workflow in "End-to-end: add a resource" below. |
| Just scaffold one piece | `make:controller\|make:repository\|make:dto\|make:middleware\|make:guard\|make:service <Name>` (fails if the file already exists — safe for scripts). `make <name>` still creates a **migration** (legacy command, unchanged). |
| Know the exact signature of something | Read `references/api-reference.md` in this skill's directory — it covers routing, DI, Request/Response, guards, middleware, every validation attribute, Swagger, the full Repository/QueryBuilder API, every migration/relation attribute, Database, the template engine, ConfigService, and the full CLI. |
| See real working code for a feature | 7 runnable example apps ship with the framework: `basic`, `auth` (JWT), `swagger`, `crud-api` (repositories/migrations/relations/soft-deletes/pagination/transactions), `middleware` (CORS/rate-limit/JSON body/custom), `templates` (full view engine syntax + caching), `config-and-caching` (ConfigService + route caching). Path is `examples/<name>/` in the framework repo, or `vendor/mikro-api/mikro-api/examples/<name>/` when used as a dependency. Each has its own `README.md`. |
| Know what's already been fixed/known limitations | `.roadmap/AUDIT.md` (framework repo) or `vendor/mikro-api/mikro-api/.roadmap/AUDIT.md` (dependency) — 22+ audited findings with file:line, fix, and commit hash. Check here before "fixing" something that's already a documented, accepted tradeoff. |

## Core building blocks (index — see `references/api-reference.md` for full signatures)

| Concern | Key classes/attributes |
|---|---|
| Routing | `#[Controller('/prefix')]`, `#[Route('GET','/:id')]` (repeatable), `Router` — matches in declaration order |
| DI Container | `Container::set/singleton/instance/has/get/make`, autowiring by constructor type-hints, `ContainerAwareInterface` |
| Request/Response | `Request` (`method`,`path`,`params`,`query`,`body`,`dto`,`header()`,`input()`), `Response` (`json/text/html/error/empty/redirect/render`, `withHeader/withStatus`) |
| Guards | `GuardInterface`/`BaseGuard`, `#[UseGuards(SomeGuard::class)]` (class or method level, repeatable) |
| Middleware | `MiddlewareInterface`, `CorsMiddleware`, `RateLimitMiddleware` + `RateLimitStore` (`InMemoryRateLimitStore`/`ApcuRateLimitStore`), `JsonBodyMiddleware`; `App::useMiddleware()` order = outer→inner |
| Validation | `RequestDto` + 17 validation attributes (`#[Required]`, `#[IsEmail]`, `#[MinLength]`, `#[IsIn]`, `#[ArrayOf]`, ...), `#[Body(SomeDto::class)]` → `$req->dto`, auto-422 on failure |
| Swagger | `App::enableSwagger()` (lazy spec generation), `#[ApiDoc]`, `#[ApiTag]`, `#[QueryParam]` |
| Data layer | `BaseRepository` (full CRUD, soft deletes, `create($data, reload:)`), `QueryBuilder` (fluent), `#[HasMany]`/`#[HasOne]`/`#[BelongsTo]`/`#[BelongsToMany]` + `with()` for eager loading |
| Migrations | `Migration`, `#[Table]`/`#[Column]`/`#[PrimaryKey]`/`#[ForeignKey]`/`#[Unique]`/`#[Index]`/`#[Timestamps]`/`#[SoftDeletes]`, `MigrationRunner` |
| Database | `Database::connect/getInstance/query/queryOne/transaction` |
| Templates | `Response::render()`, `@extends`/`@section`/`@yield`/`@include`/`@if`/`@foreach`, `{{ }}` escaped vs `{!! !!}` raw, `Engine::setCachePath()` |
| Caching | `App::cacheRoutes()`/`clearRouteCache()`, `Engine::setCachePath()` (both opt-in, off by default) |
| Config | `App::useConfig()`, `ConfigService::get/getInt/getBool/getFloat/getOrThrow/register/validate`, `.env` w/ `${VAR}` interpolation |
| Business logic | `BaseService` (`fail/notFound/unauthorized/forbidden/conflict`), `ServiceException` auto-converted to the right HTTP status by `App::run()` |

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
- **After `vendor/bin/mikro-migrate init` on a project whose `composer.json` already existed**, always tell the user to run `composer dump-autoload` (or `composer install`) next — `init` merges the required `autoload.psr-4`/`require` entries into the file, but Composer only regenerates its autoloader on install/update/dump-autoload, not on a plain `composer.json` edit. Skipping this step causes `Uncaught ReflectionException: Class "App\Controllers\...Controller" does not exist` in `Router.php`.

## End-to-end: add a resource (e.g. "Product")

1. `vendor/bin/mikro-migrate make create_products_table` → edit the generated migration with `#[Column]`/`#[Timestamps]`/etc., then `vendor/bin/mikro-migrate migrate`.
2. `vendor/bin/mikro-migrate make:repository Product` → set `$table`, `$fillable`.
3. `vendor/bin/mikro-migrate make:dto CreateProduct` (and `UpdateProduct` if partial updates need different rules) → uncomment/add validation attributes.
4. `vendor/bin/mikro-migrate make:controller Product` → wire `#[Body(CreateProductDto::class)]` on `store()`, inject the repository via the constructor (DI autowires it), use `$req->dto` inside handlers.
5. Register in bootstrap: `$app->useController(ProductController::class);`.
6. If it needs relations to another resource, add `#[HasMany]`/`#[BelongsTo]` on a public property of the repository and use `$repo->with('relationName')` to eager-load.

Mirror the `crud-api` example (`examples/crud-api/` or `vendor/mikro-api/mikro-api/examples/crud-api/`) for a complete, working reference of this exact flow (including soft deletes, pagination, and transactions).
