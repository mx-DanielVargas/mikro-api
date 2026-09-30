<?php
/**
 * MikroAPI - Middleware Pipeline Example
 *
 * This example demonstrates the full middleware pipeline: CORS, rate
 * limiting (with both storage backends), JSON body validation, and two
 * custom middlewares (request logging and request-id enrichment).
 *
 * To run:
 * cd examples/middleware
 * php -S localhost:8000 index.php
 *
 * See README.md in this directory for curl examples and an explanation of
 * why the middlewares are registered in this particular order.
 */

require __DIR__ . '/../../vendor/autoload.php';

use MikroApi\App;
use MikroApi\Request;
use MikroApi\Response;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Route;
use MikroApi\Middleware\MiddlewareInterface;
use MikroApi\Middleware\CorsMiddleware;
use MikroApi\Middleware\JsonBodyMiddleware;
use MikroApi\Middleware\RateLimitMiddleware;
use MikroApi\Middleware\InMemoryRateLimitStore;
use MikroApi\Middleware\ApcuRateLimitStore;

// ────────────────────────────────────────────────────────────────────────────
// Custom middlewares
// ────────────────────────────────────────────────────────────────────────────

/**
 * Logs every request before it reaches the rest of the pipeline, and logs
 * the resulting status code after $next() returns. This demonstrates that
 * a single middleware can act both BEFORE and AFTER the downstream chain.
 *
 * We use error_log() instead of echo: echo would write directly into the
 * HTTP response body (since PHP's built-in server streams stdout to the
 * terminal, not the client, this specific case is "safe", but echoing from
 * a middleware is a bad habit in general - any output buffering, headers
 * already sent, etc. would corrupt the response). error_log() always goes
 * to the PHP error log / terminal running `php -S`, never to the response.
 */
class RequestLoggingMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $timestamp = date('c');
        error_log("[{$timestamp}] --> {$request->method} {$request->path}");

        $response = $next($request);

        error_log("[{$timestamp}] <-- {$request->method} {$request->path} => {$response->getStatus()}");

        return $response;
    }
}

/**
 * Generates a unique id per request and attaches it to the response as
 * `X-Request-Id`. This demonstrates how a middleware can enrich the
 * response on its way back out, without touching the controller at all -
 * useful for correlating logs/traces across services.
 */
class RequestIdMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $requestId = bin2hex(random_bytes(8));

        $response = $next($request);

        return $response->withHeader('X-Request-Id', $requestId);
    }
}

// ────────────────────────────────────────────────────────────────────────────
// Controller
// ────────────────────────────────────────────────────────────────────────────

#[Controller('')]
class DemoController
{
    #[Route('GET', '/api/ping')]
    public function ping(Request $req): Response
    {
        return Response::json([
            'pong' => true,
            'timestamp' => date('c'),
        ]);
    }

    /**
     * Deliberately has NO #[Body] attribute (no DTO validation), so we can
     * observe JsonBodyMiddleware's effect in isolation: it simply echoes
     * back whatever ended up in $req->body. Send valid JSON and you'll see
     * it echoed back; send malformed JSON and JsonBodyMiddleware will
     * short-circuit the pipeline with a 400 before this method ever runs.
     */
    #[Route('POST', '/api/echo')]
    public function echo(Request $req): Response
    {
        return Response::json([
            'echo' => $req->body,
        ]);
    }
}

// ────────────────────────────────────────────────────────────────────────────
// Application setup
// ────────────────────────────────────────────────────────────────────────────

$app = new App();

// Explicit (non-default) CORS config: a real app almost never wants the
// wildcard '*' - it typically has a known set of frontend origins, allowed
// methods, and allowed headers.
$corsMiddleware = new CorsMiddleware(
    origins: ['http://localhost:3000', 'https://example.com'],
    methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    headers: ['Content-Type', 'Authorization', 'X-Request-Id'],
    maxAge: 7200,
);

// Low limits on purpose (5 requests / 30s) so the 429 is easy to trigger
// while testing this example manually. In production you'd use something
// closer to the defaults (60 requests / 60s) or higher.
//
// IMPORTANT, verified while building this example: InMemoryRateLimitStore
// (the default) does NOT persist counters across requests even when
// running under PHP's built-in `php -S` server. `php -S` re-executes this
// entire script from scratch for every incoming request (much like
// traditional CGI/PHP-FPM) - it does not keep a single long-lived object
// graph the way a Node.js-style server would. So the array inside
// InMemoryRateLimitStore is recreated empty on every request, and the 429
// will never trigger this way. This is the exact same limitation the root
// README warns about for PHP-FPM/Apache deployments; it turns out to apply
// to `php -S` too. ApcuRateLimitStore avoids this because APCu is backed
// by shared memory that outlives any single script execution within the
// same server process. We pick the store at runtime depending on whether
// the `apcu` extension is loaded, so this example demonstrates the correct
// production pattern - and if apcu isn't available, running it still shows
// (accurately!) why InMemoryRateLimitStore is insufficient here.
$rateLimitStore = extension_loaded('apcu')
    ? new ApcuRateLimitStore()
    : new InMemoryRateLimitStore();

$rateLimitMiddleware = new RateLimitMiddleware(
    maxRequests: 5,
    windowSeconds: 30,
    store: $rateLimitStore,
);

// ────────────────────────────────────────────────────────────────────────────
// Middleware pipeline order - THIS MATTERS
//
// Middlewares wrap the request/response cycle in registration order: the
// first one registered is the outermost layer, the last one registered is
// the innermost layer (closest to the router). Here's why we chose this
// specific order:
//
// 1. CorsMiddleware - MUST be first (outermost). It intercepts OPTIONS
//    preflight requests and returns immediately, before any other
//    middleware runs. If it were registered later, a preflight request
//    could be consumed by rate limiting or JSON validation instead of
//    getting a proper CORS preflight response - or it could burn part of
//    a client's rate limit quota on a request that never touches the
//    actual API logic.
//
// 2. RequestIdMiddleware - registered early so that EVERY request gets a
//    unique id, including requests that will later be rejected by
//    JsonBodyMiddleware (400) or RateLimitMiddleware (429). This makes the
//    id useful for correlating logs even for failed/rejected requests.
//
// 3. RequestLoggingMiddleware - registered right after RequestId (and
//    still before JSON validation / rate limiting) so that the "request
//    received" log line is written for every request that reaches the
//    app, again including ones that will later be rejected downstream.
//
// 4. JsonBodyMiddleware - validates the JSON body (for POST/PUT/PATCH)
//    before the request reaches rate limiting or the controller.
//
// 5. RateLimitMiddleware - registered LAST (innermost) in this example.
//    Normally, rate limiting SHOULD be one of the very first middlewares
//    in the pipeline, so abusive traffic gets rejected as cheaply and as
//    early as possible, ideally before spending any work on parsing/
//    validating JSON bodies. We deliberately placed it last here purely
//    for demonstration purposes: it lets you see, in the server log, that
//    logging and request-id generation still happen for a request even
//    when that same request goes on to be rate-limited with a 429. In a
//    real application, prefer moving RateLimitMiddleware right after (or
//    even before) CorsMiddleware.
// ────────────────────────────────────────────────────────────────────────────
$app->useMiddleware(
    $corsMiddleware,
    new RequestIdMiddleware(),
    new RequestLoggingMiddleware(),
    new JsonBodyMiddleware(),
    $rateLimitMiddleware,
);

$app->useController(DemoController::class)->run();
