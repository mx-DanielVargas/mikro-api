---
name: mikro-api-framework
description: Deep, self-contained knowledge of every feature the MikroAPI PHP micro-framework offers (attribute-based routing, modules, DI container, parameter injection, DTO validation, guards/JWT/roles, interceptors, exception filters, Repository/QueryBuilder/migrations on SQLite/MySQL/PostgreSQL/Turso, middleware, Swagger, template engine, ConfigService) and the `vendor/bin/mikro` CLI (new, make:resource and every other make:* generator, migrations, route:list, docs:export, key:generate, serve). Use when building, extending, scaffolding, or debugging an API/project with MikroAPI — whether this repo IS the framework or just depends on it — or when explaining how one of its features works.
---

# MikroAPI Framework Expert

MikroAPI is a minimalist, zero-external-dependency PHP 8.1+ framework (NestJS-inspired): attribute-based routing, modules, DI with autowiring, handler parameter injection, DTO validation, guards (built-in JWT + roles), interceptors, exception filters, a lightweight Repository/QueryBuilder ORM, attribute-driven migrations, middleware pipeline, Swagger/OpenAPI generation, a Blade-like template engine, and a `ConfigService`. Namespace root: `MikroApi\`.

**Full exhaustive API reference (every class, method, and attribute with exact signatures) lives in `references/api-reference.md` in this skill's directory — read it whenever you need a precise signature. You should rarely need to read the framework's own source to know what's available; this file plus the reference below cover the entire public API.**

## Where does MikroAPI actually live in THIS project?

Check which context you're in before reading anything else:

- **This repo IS the framework itself** (has `src/App.php`, `composer.json` named `mikro-api/mikro-api`): docs are at the repo root — `README.md`, `CLI.md`, `examples/`, `.roadmap/AUDIT.md`.
- **This repo just DEPENDS on MikroAPI** (installed via `composer require mikro-api/mikro-api`): the framework's own source and docs live under `vendor/mikro-api/mikro-api/` — e.g. `vendor/mikro-api/mikro-api/examples/crud-api/`, `vendor/mikro-api/mikro-api/README.md`. Your actual application code lives in `src/`, `public/`, `config/`, `database/migrations/`, `views/` at the project root (this is exactly what `vendor/bin/mikro new` scaffolds). **Prefer this skill's `references/api-reference.md` over grepping through `vendor/` — it's faster and already covers 100% of the public API.**

## Fastest path to help the user

| User wants to... | Do this |
|---|---|
| Start a brand-new project | `vendor/bin/mikro new [path]` (alias `init`) — modular scaffold: `public/index.php` (`App::create(ConfigModule::forRoot(...), AppModule::class)`, lazy `Database` binding, CORS/JSON middlewares, Swagger at `/docs`), `src/AppModule.php`, `src/AppController.php`, `config/database.php`, `.env` (random `JWT_SECRET`), `composer.json`. Safe to re-run (never overwrites). If `composer.json` already existed, it merges the required autoload/require entries — **remind the user to run `composer dump-autoload` afterward**. |
| Add a full CRUD resource | `vendor/bin/mikro make:resource products` → `src/Products/{ProductsModule, ProductsController, ProductsService, ProductsRepository, Dto/CreateProductDto, Dto/UpdateProductDto}` + `create_products_table` migration, module auto-registered in `AppModule` imports. Then edit the migration/DTOs/`$fillable` (they start with a single `name` column) and `vendor/bin/mikro migrate`. |
| Just scaffold one piece | `vendor/bin/mikro make:<type> <Name>` or `vendor/bin/mikro g <type> <Name>` — types: `module, controller, service, repository, dto, guard, interceptor, filter, middleware, attribute, migration, view, test`. `--module=X` writes into `src/X/` and registers controllers (`controllers`) / services+repositories (`providers`) in `XModule` (module must exist). Never overwrites without `--force` (exit 1 — safe for scripts). |
| Inspect / operate | `mikro route:list`, `mikro docs:export [file]`, `mikro migrate[:status\|:rollback\|:reset\|:fresh]`, `mikro key:generate [--force]`, `mikro serve [--port=]`, `mikro list`. Old `mikro-migrate` names (`init`, `make <name>`, `rollback`, `reset`, `status`) still work as aliases. |
| Know the exact signature of something | Read `references/api-reference.md` in this skill's directory — it covers routing, DI, Request/Response, guards, middleware, every validation attribute, Swagger, the full Repository/QueryBuilder API, every migration/relation attribute, Database, the template engine, ConfigService, and the full CLI. |
| See real working code for a feature | 8 runnable example apps ship with the framework: `basic`, `auth` (hand-written JWT guard), `modules` (modules + built-in JwtGuard/RolesGuard + param injection + interceptor + global filter), `swagger`, `crud-api` (repositories/migrations/relations/soft-deletes/pagination/transactions), `middleware` (CORS/rate-limit/JSON body/custom), `templates` (full view engine syntax + caching), `config-and-caching` (ConfigService + route caching). Path is `examples/<name>/` in the framework repo, or `vendor/mikro-api/mikro-api/examples/<name>/` when used as a dependency. Each has its own `README.md`. |
| Know what's already been fixed/known limitations | `.roadmap/AUDIT.md` (framework repo) or `vendor/mikro-api/mikro-api/.roadmap/AUDIT.md` (dependency) — 22+ audited findings with file:line, fix, and commit hash. Check here before "fixing" something that's already a documented, accepted tradeoff. |

## Core building blocks (index — see `references/api-reference.md` for full signatures)

| Concern | Key classes/attributes |
|---|---|
| Routing | `#[Controller('/prefix')]`, `#[Route('GET','/:id')]` (repeatable), `Router` — matches in declaration order; 404 / 405 (+`Allow`) |
| Modules | `#[Module(imports, controllers, providers, exports, global)]`, `App::create()`/`useModule()`, `DynamicModule` (forRoot), `ConfigModule::forRoot(envFilePath, load, validate)`, `OnModuleInit`/`OnApplicationShutdown` |
| Handler params | `#[Param('id')] int $id`, `#[Query('page')] int $page = 1`, `#[Query] Dto`, `#[Body] Dto`, `#[Body('field')]`, `#[Headers('X')]`, `#[CurrentUser]`, `Request`, `ExecutionContext` — auto type conversion (400) / DTO validation (422) |
| Errors | `HttpException` + subclasses (`NotFoundException`, `ForbiddenException`, `ValidationException`, ...), `ExceptionFilterInterface` + `#[Catches]` + `#[UseFilters]` / `App::useGlobalFilters()` |
| Interceptors | `InterceptorInterface::intercept(ExecutionContext, callable $next)`, `#[UseInterceptors]` / `App::useGlobalInterceptors()` |
| Metadata | `#[SetMetadata(key, value)]` (extendable), `Reflector::getAllAndOverride/getAllAndMerge/getAttribute`, `$request->context` |
| DI Container | `Container::set/singleton/instance/has/get/make`, autowiring by constructor type-hints, `ContainerAwareInterface` |
| Request/Response | `Request` (`method`,`path`,`params`,`query`,`body`,`dto`,`header()`,`input()`), `Response` (`json/text/html/error/empty/redirect/render`, `withHeader/withStatus`) |
| Guards | `GuardInterface`/`BaseGuard`, `#[UseGuards(SomeGuard::class)]` (class or method level, repeatable), `App::useGlobalGuards()`; built-in `JwtService` + `JwtGuard` (`$request->user`, `#[PublicRoute]`) + `RolesGuard` (`#[Roles('admin')]`) |
| Middleware | `MiddlewareInterface`, `CorsMiddleware`, `RateLimitMiddleware` + `RateLimitStore` (`InMemoryRateLimitStore`/`ApcuRateLimitStore`), `JsonBodyMiddleware`; `App::useMiddleware()` order = outer→inner |
| Validation | `RequestDto` + 17 validation attributes (`#[Required]`, `#[IsEmail]`, `#[MinLength]`, `#[IsIn]`, `#[ArrayOf]`, ...), `#[Body(SomeDto::class)]` → `$req->dto`, auto-422 on failure |
| Swagger | `App::enableSwagger()` (lazy spec generation), `#[ApiDoc]`, `#[ApiTag]`, `#[QueryParam]` |
| Data layer | `BaseRepository` (full CRUD, soft deletes, `create($data, reload:)`), `QueryBuilder` (fluent), `#[HasMany]`/`#[HasOne]`/`#[BelongsTo]`/`#[BelongsToMany]` + `with()` for eager loading |
| Migrations | `Migration`, `#[Table]`/`#[Column]`/`#[PrimaryKey]`/`#[ForeignKey]`/`#[Unique]`/`#[Index]`/`#[Timestamps]`/`#[SoftDeletes]`, `MigrationRunner` |
| Database | `Database::connect/getInstance/query/queryOne/statement/execute/transaction`; drivers `sqlite`, `mysql`, `pgsql` (`postgres`), `turso` |
| Templates | `Response::render()`, `@extends`/`@section`/`@yield`/`@include`/`@if`/`@foreach`, `{{ }}` escaped vs `{!! !!}` raw, `Engine::setCachePath()` |
| Caching | `App::cacheRoutes()`/`clearRouteCache()`, `Engine::setCachePath()` (both opt-in, off by default) |
| Config | `App::useConfig()` (classic) or `ConfigModule::forRoot()` (modules), `ConfigService::get/getInt/getBool/getFloat/getOrThrow/register/validate`, `.env` w/ `${VAR}` interpolation, falls back to process env for keys not in `.env` |
| Business logic | `BaseService` (`fail/notFound/unauthorized/forbidden/conflict`), `ServiceException` (extends `HttpException`) auto-converted to the right HTTP status |
| Testing | `$app->handle(Request::create('GET', '/x', query: [...], body: [...], headers: [...]))` returns the `Response` without sending it |

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
- Pipeline order: middlewares → guards → interceptors → DTO validation/argument conversion → handler. A controller only ever sees a request that already passed auth + validation; interceptors run before validation, so they also see (and can catch) `ValidationException`.
- **Handler parameters without an attribute** must be `Request`, `ExecutionContext`, untyped, nullable or have a default — anything else throws `LogicException` when the controller is registered (fail-fast, not per request).
- **A guard returning `false` responds with its `deny()` directly, bypassing exception filters.** Throw an `HttpException` (`UnauthorizedException`, `ForbiddenException`) instead when a global filter should format the error — the built-in `JwtGuard`/`RolesGuard` do this.
- **Modules enforce encapsulation**: a provider of module A is only injectable in B if A exports it and B imports A (or A is `global`). Things registered on `$app->getContainer()` stay visible everywhere. Global guards/interceptors/filters are resolved in the *route's* module, so their dependencies (e.g. `JwtService` for a global `JwtGuard`) must live in a global module or the root container.
- **`DynamicModule` (forRoot) must be passed to `useModule()`/`App::create()` before any module that imports it**; otherwise the plain version is already loaded and a `LogicException` is thrown.
- **PostgreSQL**: write raw SQL through `Database::query()/queryOne()/statement()/execute()` (backticks are translated to double quotes); `getPdo()->prepare()` bypasses the translation.
- `RelationLoader` can resolve related repositories with extra constructor dependencies via the DI Container (`ContainerAwareInterface`), but only if the repository was itself resolved through the Container (not `new SomeRepository($db)` directly).
- **After `vendor/bin/mikro new` on a project whose `composer.json` already existed**, always tell the user to run `composer dump-autoload` (or `composer install`) next — `init` merges the required `autoload.psr-4`/`require` entries into the file, but Composer only regenerates its autoloader on install/update/dump-autoload, not on a plain `composer.json` edit. Skipping this step causes `Uncaught ReflectionException: Class "App\Controllers\...Controller" does not exist` in `Router.php`.
- **`Database` also supports a `'turso'` driver** (Turso/libSQL) — same SQL dialect as SQLite (`getDriver()` reports it as `'sqlite'` to the rest of the framework), zero application-code changes needed. Requires the optional `turso/libsql` package (PHP >= 8.3 + FFI) and, critically, `ffi.enable=true` set **explicitly** in `php.ini` — its default (`"preload"`) does not cover `php -S` or typical FPM/CLI execution, so without it `Libsql\PDO` throws `FFI\Exception: FFI API is restricted by "ffi.enable" configuration directive`.

## End-to-end: add a resource (e.g. "Product")

1. `vendor/bin/mikro make:resource products` — generates the module, CRUD controller (param injection, `#[Body] CreateProductDto`), service (404 via `notFound()`), repository, DTOs and migration, and registers `ProductsModule` in `AppModule`.
2. Edit the migration columns, the DTO validation attributes and the repository `$fillable` to match the real fields, then `vendor/bin/mikro migrate`.
3. Extra pieces inside the module: `vendor/bin/mikro g service Pricing --module=Products` (auto-registered as provider), `g controller Report --module=Products`, `g dto UpdateStock --module=Products`.
4. Relations: add `#[HasMany]`/`#[BelongsTo]` on a public property of the repository and use `$repo->with('relationName')`.
5. Check with `vendor/bin/mikro route:list`; try it with `vendor/bin/mikro serve` (Swagger at `/docs`).

Classic (non-module) apps: `make:controller/service/repository/dto` without `--module` write to `src/Controllers|Services|Repositories|DTOs` and you register the controller with `$app->useController(...)`.

Mirror the `crud-api` example (`examples/crud-api/` or `vendor/mikro-api/mikro-api/examples/crud-api/`) for a complete, working reference of this exact flow (including soft deletes, pagination, and transactions).
