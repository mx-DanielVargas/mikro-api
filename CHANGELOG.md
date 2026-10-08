# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added
- **Modules**: `#[Module(imports, controllers, providers, exports, global)]`, `App::create()`/`App::useModule()`, per-module `ModuleContainer` with encapsulation (non-exported providers of other modules fail with an explanatory error), re-exports, circular imports, providers as class / `useClass` / `useValue` / `useFactory` (+ `inject`) / `useExisting`, `DynamicModule` (`forRoot()`-style configuration, closures allowed), `OnModuleInit` / `OnApplicationShutdown` lifecycle hooks, `App::close()` and `App::getModuleContainer()`
- **Handler parameter injection**: `#[Param]`, `#[Query]`, `#[Headers]`, `#[CurrentUser]` and `#[Body]` on parameters (plus `Request` / `ExecutionContext` by type), with automatic conversion to `int`/`float`/`bool`/`string`/`array`/enums (`400` on invalid or missing values) — the classic `handler(Request $req)` signature keeps working, including with route caches generated before this change
- **DTO validation for query strings and route params**: `#[Query] SomeDto $q` / `#[Param] SomeDto $p` (`422` on failure, same format as body validation)
- **HTTP exceptions**: `HttpException` with `BadRequest`, `Unauthorized`, `Forbidden`, `NotFound`, `MethodNotAllowed`, `Conflict`, `UnprocessableEntity`, `Validation`, `TooManyRequests` and `InternalServerError` subclasses, automatically rendered as JSON (custom body and headers supported)
- **Exception filters**: `ExceptionFilterInterface`, `#[Catches]`, `#[UseFilters]` (method/class) and `App::useGlobalFilters()` (also catches 404/405 and middleware errors); a filter can return `null` to delegate
- **Interceptors**: `InterceptorInterface`, `#[UseInterceptors]` (method/class) and `App::useGlobalInterceptors()`; can run code around the handler, transform its result or short-circuit it
- **Global guards**: `App::useGlobalGuards()`
- **Route metadata**: `ExecutionContext` (available as `$request->context`), `#[SetMetadata]` (extendable for custom attributes) and `Reflector` (`getAllAndOverride`, `getAllAndMerge`, `getAttribute`, ...)
- **Built-in auth**: `JwtService` (HS256/384/512 sign/verify with `exp`/`nbf`/`iss`/leeway), `JwtGuard` (sets `$request->user`, honors `#[PublicRoute]`), `RolesGuard` + `#[Roles]`
- **PostgreSQL driver** (`'pgsql'`, aliases `'postgres'`/`'postgresql'`): identifier quoting translation, `INSERT ... RETURNING` in `BaseRepository::create()`, boolean parameter normalization, and a PostgreSQL dialect for migrations (identity keys, `BOOLEAN`, `JSONB`, `UUID`, `NUMERIC`, `COMMENT ON`, `ALTER TABLE`), migrations table and schema introspection
- `Database::statement()` (prepare + execute through the dialect layer), `Database::toDialect()`, `Database::normalizeDriver()`
- `405 Method Not Allowed` with `Allow` header when the path exists for other methods
- `App::handle(Request): Response` (full pipeline without sending, for tests), `Request::create()` factory, `Request::headers()`, `Request::$user`, `Response::getHeaders()`/`getHeader()`, `Container::isResolved()`
- Swagger: documents typed `#[Param]`/`#[Query]` parameters, `#[Query] Dto` properties, `#[Body]` on parameters, `#[PublicRoute]` (no security) and `#[Roles]` (403)
- `ConfigModule::forRoot(envFilePath, envFile, isGlobal, load, validate)`: `@nestjs/config`-style module exposing `ConfigService` to all modules, with namespaced config (`load`) and fail-fast validation
- **CLI generators**: `make:resource` (module + CRUD controller + service + repository + create/update DTOs + migration, registered in `AppModule`), `make:module`, `make:interceptor`, `make:filter`, `make:attribute`, `make:migration`, `make:view`, `make:test`, plus the existing controller/service/repository/dto/guard/middleware (now using parameter injection); `g <type> <name>` shorthand; `--module=<Name>` generates inside a module and registers the class in its `#[Module]`; `--force`
- **CLI commands**: `serve`, `key:generate`, `migrate:fresh`, `route:list`, `route:clear`, `docs:export`, `--version`
- `Router::getRoutes()`
- `examples/modules/`: runnable app combining all of the above (JWT secret loaded from `.env` through `ConfigModule`)
- `ConfigService`: `.env` loading with typed accessors (`get`, `getOrThrow`, `getInt`, `getBool`, `getFloat`), namespaced config sections via `register()`, and required-key validation via `validate()`
- Repeatable `#[Route]` attributes: multiple HTTP routes can now be declared on the same controller method
- Optional compiled route caching: `App::cacheRoutes()`, `Router::loadFromCache()`, `Router::cacheTo()`, and `App::clearRouteCache()` for manual invalidation
- `Engine::setCachePath()`: optional on-disk caching of compiled view templates
- `RateLimitStore` interface with pluggable `InMemoryRateLimitStore` (default) and `ApcuRateLimitStore` implementations for `RateLimitMiddleware`
- `BaseRepository::create()` gains an optional `$reload` parameter (`create(array $data, bool $reload = true)`) to skip the post-INSERT `SELECT` round-trip
- `RelationLoader` can now resolve related repositories with extra constructor dependencies through the DI `Container` (falls back to the previous behavior when no container is available)
- `ContainerAwareInterface`: generic hook (`setContainer(Container $container): static`) for any class that needs a reference to the `Container` after being autowired
- `tests/AppTest.php`: test coverage for `App` (`isProduction()` detection, fluent method wiring) without mocking the full HTTP request/response cycle

### Fixed
- `App::run()` no longer leaks internal exception messages in production when `APP_ENV` is only set via `.env` — new `App::isProduction()` checks `$_ENV`, `$_SERVER`, and `getenv()` instead of only `$_SERVER`
- `BaseRepository`: column names derived from request data are now validated against a safe SQL identifier pattern (`assertValidColumnName()`), preventing SQL injection through `INSERT`/`UPDATE` when `$fillable` is not declared
- Swagger/OpenAPI spec generation is now lazy and memoized (only runs, at most once, when `/docs` or `/docs/json` is actually requested) instead of eagerly on every request
- `/docs` and `/docs/json` now flow through the same middleware pipeline (CORS, rate limiting, etc.) as any other route, instead of bypassing it
- `.env` parser now supports end-of-line comments on unquoted values (`KEY=value # comment`), while preserving a literal `#` inside quoted values or glued to the value (e.g. URL fragments)
- Initialized `$__layout`/`$__sections` before `eval()` in `View\Engine::evaluate()` to remove static-analysis false positives without changing behavior
- `RateLimitMiddleware` storage is now pluggable via `RateLimitStore`, and `ApcuRateLimitStore` now uses atomic `apcu_add()`/`apcu_inc()` instead of fetch-then-store, fixing a race condition that could lose increments under concurrency
- Route cache writes are now atomic (temp file + `rename()`) and `Router::loadFromCache()` validates the cached structure and tolerates a corrupted/invalid cache file instead of crashing the bootstrap; `App::cacheRoutes()` now throws `LogicException` if called after `useController()` to prevent silently discarding already-registered routes
- `BaseRepository::create()` now normalizes the `id` to `int` when purely numeric on the `reload: false` path, so its type is consistent with the `reload: true` (default) path; the returned array also no longer lets a homonymous key in `$data` override the real id
- `BaseRepository::setContainer()` now emits an `E_USER_WARNING` instead of silently rebinding when a different `Database` instance is already registered in the `Container`
- View compilation cache (`Engine::setCachePath()`) is now invalidated by an MD5 hash of the source content instead of file `mtime`, avoiding a 1-second collision window where stale compiled output could be served
- Resolved leftover merge-conflict markers (`<<<<<<<`/`=======`/`>>>>>>>`) in `README.md`

### Changed
- **CLI renamed to `vendor/bin/mikro`** (`bin/mikro-migrate` removed) and rewritten as testable classes in `MikroApi\Console`. Old command names keep working as aliases (`init`, `make <name>`, `rollback`, `reset`, `status`). `MIGRATION_CLI.md` replaced by `CLI.md`
- `mikro new`/`init` now scaffolds a modular project (`AppModule`, `AppController`, `ConfigModule::forRoot()`, lazy `Database` binding, Swagger at `/docs`, random `JWT_SECRET`) instead of the classic `src/Controllers|...` layout with a users migration
- `ConfigService::get()` falls back to the real process environment (`$_ENV` / `getenv()`) for keys not present in the loaded `.env` files (values from `.env` still take precedence)
- `ServiceException` now extends `HttpException` (same constructor and `getStatusCode()`), so it can be handled by exception filters
- Body DTO validation failures are raised as `ValidationException` (same `422` JSON body as before) and run after guards and inside the interceptor chain
- `Router::dispatch()` always returns a `Response`: exceptions are converted by filters / the default handler inside the router; production masking of non-HTTP errors moved to `ExceptionHandler::isProduction()`
- `BaseRepository` and `MigrationRunner` execute SQL through `Database::statement()` instead of `getPdo()->prepare()`
- `Container::autowire()` no longer depends directly on `Repository\BaseRepository`; it now checks for the generic `ContainerAwareInterface` instead, so any future class (service, controller, etc.) can opt into container injection without coupling `Container` to a specific layer

## [1.0.0] - 2024-01-01

### Added
- Initial release
- Attribute-based routing system
- DTO validation with attributes
- JWT authentication guard
- Repository pattern with query builder
- Database migrations with schema attributes
- Soft deletes support
- Eager loading for relations
- Swagger/OpenAPI documentation generation
- Zero external dependencies
