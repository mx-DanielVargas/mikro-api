# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added
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
