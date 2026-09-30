# Config & Caching Example

This example demonstrates a "production ready" MikroAPI setup: full use of
`ConfigService` (`.env` loading, typed accessors, namespaces, validation) and
Route Caching (`App::cacheRoutes()` / `App::clearRouteCache()`).

## Features Demonstrated

- ✅ `.env` file loading via `App::useConfig()`
- ✅ Typed accessors: `getInt()`, `getBool()`, `getFloat()`, `getOrThrow()`
- ✅ Namespaced config with `register()` and dot-notation access (`app.name`, `database.host`)
- ✅ Validation of required keys via `validate()`, with the `RuntimeException` failure-handling pattern
- ✅ Variable interpolation (`${VAR}` references inside `.env` values)
- ✅ End-of-line comments in `.env` files (e.g. `KEY=value # comment`)
- ✅ Route Caching with `App::cacheRoutes()` and `App::clearRouteCache()`

## Running the Example

```bash
cd examples/config-and-caching
php -S localhost:8000 index.php
```

## API Endpoints

- `GET /api/config` - Returns the registered `app` and `database` namespaces, plus a few raw values that showcase interpolation and comment stripping
- `GET /api/cache/clear` - Deletes the compiled route cache file (see note below)

### Example Request

```bash
curl http://localhost:8000/api/config
```

Response (values sourced from [`.env`](./.env)):

```json
{
  "app": {
    "name": "MikroAPI Config Demo",
    "env": "development",
    "debug": true,
    "port": 8000
  },
  "database": {
    "host": "localhost",
    "port": 3306,
    "name": "demo_db"
  },
  "raw": {
    "greeting": "Hello from MikroAPI Config Demo",
    "feature_flag_new_ui": false,
    "cache_ttl": 3600,
    "rate_limit_max": 100
  }
}
```

Note how:
- `greeting` shows `${APP_NAME}` correctly interpolated into `GREETING="Hello from ${APP_NAME}"`.
- `feature_flag_new_ui` is `false` (boolean, via `getBool()`) — the trailing `# This is an end-of-line comment, should be stripped` on that line in `.env` is **not** included in the value.
- `debug`, `port`, `cache_ttl`, and `rate_limit_max` are real `int`/`bool` types, not strings, thanks to the typed accessors.

## Configuration Files

- [`.env`](./.env) - the file actually loaded by this demo (`App::useConfig(__DIR__)` loads `.env` from the current directory)
- [`.env.example`](./.env.example) - documents the same variables for anyone setting up their own copy; in a real project this is the one you'd commit while `.env` stays git-ignored

## Observing the Route Cache

1. Make any request (e.g. `curl http://localhost:8000/api/config`).
2. Inspect [`examples/config-and-caching/cache/routes.php`](./cache/routes.php) — it now contains the compiled route table (controller classes, HTTP methods, paths) generated from `ConfigController`, written by `App::cacheRoutes()` after `run()` finishes bootstrapping.
3. Stop and restart the PHP server. On the next boot, `cacheRoutes()` finds the existing file and loads routes from it directly instead of re-reflecting `ConfigController`. The observable behavior is identical — this is purely a performance optimization for reflection-heavy apps with many controllers.
4. Call `GET /api/cache/clear` to delete the cache file. Because `cacheRoutes()` already ran earlier during the *current* request's bootstrap, this doesn't affect the routes serving that request — the file is simply removed from disk. The next time the app boots (e.g. the next `php -S` restart), `cacheRoutes()` won't find a file to load from, so it will regenerate it automatically.

## ⚠️ Important: Call Order

`cacheRoutes()` must be called **before** any `useController()` call, or it will throw a `LogicException`. Calling it afterward could otherwise silently drop routes already registered in the same request if a stale cache file exists. This example follows the correct order:

```php
$app->cacheRoutes(__DIR__ . '/cache/routes.php')  // 1. cache config first
    ->useController(ConfigController::class);      //  ... wired up below
```

Additional caveats (see the root [`README.md`](../../README.md#route-caching) for full details):

- The cache is **not** automatically invalidated when controllers/routes change. After adding, removing, or modifying routes, delete the cache file (or call `$app->clearRouteCache($path)`) so it regenerates.
- Store the cache file outside your public docroot — it contains internal controller class names and route patterns.

## Next Steps

- Add an `.env.production` file to see environment-specific overrides load automatically when `APP_ENV=production`
- Combine this with [`examples/middleware/`](../middleware/) for a fuller production-style bootstrap
- Explore `ConfigService::set()` for runtime overrides (e.g. in tests)
