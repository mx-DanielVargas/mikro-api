<?php
/**
 * MikroAPI - Config & Caching Example
 *
 * This example demonstrates a "production ready" setup combining:
 *   - ConfigService: .env loading, typed accessors, namespaced config,
 *     validation, variable interpolation, and end-of-line comment support.
 *   - Route Caching: App::cacheRoutes() / App::clearRouteCache() to skip
 *     controller reflection on every request.
 *
 * To run:
 * cd examples/config-and-caching
 * php -S localhost:8000 index.php
 *
 * Then visit:
 * - GET http://localhost:8000/api/config
 * - GET http://localhost:8000/api/cache/clear
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MikroApi\App;
use MikroApi\Config\ConfigService;
use MikroApi\Request;
use MikroApi\Response;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Route;

// ────────────────────────────────────────────────────────────────────────────
// 1. Bootstrap the app and load configuration
// ────────────────────────────────────────────────────────────────────────────

$app = new App();

// useConfig() loads .env (and, if APP_ENV is set, .env.{APP_ENV} as an
// override) from the given base path, and registers ConfigService in the
// container so it can be injected/resolved elsewhere.
$app->useConfig(__DIR__);

// Fetch the ConfigService instance registered by useConfig() above.
$config = $app->getContainer()->get(ConfigService::class);

// ────────────────────────────────────────────────────────────────────────────
// 2. Namespaced config (like @nestjs/config's registerAs)
//
// Grouping related values under a namespace lets consumers use dot-notation
// (e.g. `app.name`) instead of scattering raw env keys everywhere.
// ────────────────────────────────────────────────────────────────────────────

$config->register('app', [
    'name'  => $config->get('APP_NAME', 'MikroAPI'),
    'env'   => $config->get('APP_ENV', 'production'),
    // getBool() uses FILTER_VALIDATE_BOOLEAN under the hood, so "true"/"false"
    // strings from the .env file are converted to real booleans.
    'debug' => $config->getBool('APP_DEBUG', false),
    // getInt() casts the raw string value to an int.
    'port'  => $config->getInt('APP_PORT', 8000),
]);

$config->register('database', [
    'host' => $config->get('DB_HOST', 'localhost'),
    'port' => $config->getInt('DB_PORT', 3306),
    'name' => $config->get('DB_NAME', 'app'),
]);

// getFloat() is also available for numeric ratios/thresholds; not backed by
// a dedicated .env key in this demo, so it falls back to its default here.
$sampleRatio = $config->getFloat('SAMPLE_RATIO', 0.5);

// ────────────────────────────────────────────────────────────────────────────
// 3. Validation
//
// validate() throws a RuntimeException listing every missing key. Wrap it in
// try/catch at startup so a misconfigured deployment fails fast with a clear
// message instead of surfacing confusing errors deeper in the request cycle.
// ────────────────────────────────────────────────────────────────────────────

try {
    $config->validate(['APP_NAME', 'APP_ENV']);
} catch (\RuntimeException $e) {
    // In a real app you'd likely log this and exit(1) before serving traffic.
    // All keys are present in this demo's .env, so this branch won't run —
    // it's here to document the expected failure-handling pattern.
    Response::error('Configuration error: ' . $e->getMessage(), 500)->send();
    exit(1);
}

// ────────────────────────────────────────────────────────────────────────────
// 4. Route caching
//
// cacheRoutes() MUST be called BEFORE any useController() call. If a valid
// cache file already exists at the given path, routes are loaded from it
// (skipping reflection over every controller class). Otherwise, the compiled
// route table is written to that file once run() executes, after all
// controllers below have been registered.
// ────────────────────────────────────────────────────────────────────────────

$app->cacheRoutes(__DIR__ . '/cache/routes.php');

// Make the App instance itself resolvable via DI, so the controller below
// can call $app->clearRouteCache() without any global state.
$app->getContainer()->instance(App::class, $app);

// ────────────────────────────────────────────────────────────────────────────
// Controller (registered AFTER cacheRoutes(), as required)
// ────────────────────────────────────────────────────────────────────────────

#[Controller('/api')]
class ConfigController
{
    public function __construct(
        private ConfigService $config,
        private App $app,
    ) {}

    #[Route('GET', '/config')]
    public function show(Request $req): Response
    {
        // Dot-notation reads from the namespaces registered above.
        return Response::json([
            'app' => [
                'name'  => $this->config->get('app.name'),
                'env'   => $this->config->get('app.env'),
                'debug' => $this->config->get('app.debug'),
                'port'  => $this->config->get('app.port'),
            ],
            'database' => [
                'host' => $this->config->get('database.host'),
                'port' => $this->config->get('database.port'),
                'name' => $this->config->get('database.name'),
            ],
            'raw' => [
                // Demonstrates ${VAR} interpolation and end-of-line comment
                // stripping in ConfigService::loadEnvFile().
                'greeting'              => $this->config->get('GREETING'),
                'feature_flag_new_ui'   => $this->config->getBool('FEATURE_FLAG_NEW_UI'),
                'cache_ttl'             => $this->config->getInt('CACHE_TTL'),
                'rate_limit_max'        => $this->config->getInt('RATE_LIMIT_MAX'),
            ],
        ]);
    }

    #[Route('GET', '/cache/clear')]
    public function clearCache(Request $req): Response
    {
        $cacheFile = __DIR__ . '/cache/routes.php';

        // Note: cacheRoutes() already ran earlier in THIS request's bootstrap
        // (before run() writes the pending cache to disk), so deleting the
        // file here has no effect on the routes already loaded/serving the
        // current request. The cache file will be regenerated automatically
        // the next time the app boots and cacheRoutes() finds no existing
        // file to load from (i.e. on the next `php -S` restart, or the next
        // request under a long-running worker that re-bootstraps the app).
        $existed = is_file($cacheFile);
        $this->app->clearRouteCache($cacheFile);

        return Response::json([
            'message' => $existed
                ? 'Route cache file deleted. It will be regenerated on the next app boot.'
                : 'No route cache file was present to delete.',
            'cache_file' => $cacheFile,
        ]);
    }
}

$app->useController(ConfigController::class);

// ────────────────────────────────────────────────────────────────────────────
// 5. Run
// ────────────────────────────────────────────────────────────────────────────

$app->run();
