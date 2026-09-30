# Middleware Pipeline Example

This example demonstrates the full MikroAPI middleware pipeline: configurable
CORS, rate limiting (both storage backends), JSON body validation, and two
custom middlewares that show how to run logic both before and after the rest
of the chain.

## Features Demonstrated

- ✅ Configurable CORS (custom origins, methods, headers, `maxAge`) instead of the `*` default
- ✅ Custom middleware acting before **and** after the downstream chain (`RequestLoggingMiddleware`)
- ✅ Custom middleware enriching the response (`RequestIdMiddleware` adds `X-Request-Id`)
- ✅ Rate Limiting with both storage backends (`InMemoryRateLimitStore` / `ApcuRateLimitStore`), selected at runtime
- ✅ JSON body validation (`JsonBodyMiddleware`) rejecting malformed JSON with `400`
- ✅ Explicit, documented middleware registration order

## Running the Example

```bash
cd examples/middleware
php -S localhost:8000 index.php
```

Watch the terminal running `php -S` — `RequestLoggingMiddleware` writes a log
line (via `error_log()`) for every request that enters the pipeline, and
another one once a response is produced.

## Testing It

### CORS preflight (`OPTIONS`)

```bash
curl -i -X OPTIONS http://localhost:8000/api/ping \
  -H "Origin: http://localhost:3000" \
  -H "Access-Control-Request-Method: GET"
```

You should get a `204` with the configured CORS headers, e.g.:

```
HTTP/1.1 204 No Content
Access-Control-Allow-Origin: http://localhost:3000, https://example.com
Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS
Access-Control-Allow-Headers: Content-Type, Authorization, X-Request-Id
Access-Control-Max-Age: 7200
```

Note that `RequestIdMiddleware`, `RequestLoggingMiddleware`,
`JsonBodyMiddleware` and `RateLimitMiddleware` never run for this request —
`CorsMiddleware` short-circuits preflight requests immediately because it is
registered first (see "Why the order matters" below).

### Normal request (`GET /api/ping`)

```bash
curl -i http://localhost:8000/api/ping
```

```
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 9f2c1a7b3e4d5f60
X-RateLimit-Limit: 5
X-RateLimit-Remaining: 4
...

{"pong":true,"timestamp":"2026-09-29T12:00:00+00:00"}
```

Each request gets a fresh `X-Request-Id`, and `X-RateLimit-Remaining`
decreases as you make more requests.

### Triggering the rate limit (`429`)

The demo configures `RateLimitMiddleware` with a very low limit (5 requests
per 30 seconds) so it's easy to trigger manually:

```bash
for i in 1 2 3 4 5 6 7; do
  curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8000/api/ping
done
```

**If the `apcu` extension is loaded**, expect the first 5 requests to
succeed and the rest to be rejected:

```
200
200
200
200
200
429
429
```

The `429` response includes `Retry-After`, `X-RateLimit-Limit: 5`, and
`X-RateLimit-Remaining: 0`. Note that it still carries `X-Request-Id` and was
still logged by `RequestLoggingMiddleware` — see "Why the order matters".

**If `apcu` is NOT loaded**, this example falls back to
`InMemoryRateLimitStore` — and you will see **all `200`s, no `429`**, even
past request #5. This is not a bug in the example: it's an accurate,
hands-on demonstration of the exact limitation documented below and in the
root [`README.md`](../../README.md). Verified while building this example:
PHP's built-in `php -S` server re-executes the whole script from scratch for
every request (like traditional CGI/PHP-FPM), so the in-memory counters
never survive between requests. Install/enable `apcu` (e.g. `pecl install
apcu`) and re-run the server to see the `429` behavior.

### JSON body validation (`POST /api/echo`)

Valid JSON is echoed back as-is:

```bash
curl -i -X POST http://localhost:8000/api/echo \
  -H "Content-Type: application/json" \
  -d '{"hello":"world"}'
```

```
HTTP/1.1 200 OK
...

{"echo":{"hello":"world"}}
```

Malformed JSON is rejected by `JsonBodyMiddleware` before it ever reaches the
controller:

```bash
curl -i -X POST http://localhost:8000/api/echo \
  -H "Content-Type: application/json" \
  -d '{invalid'
```

```
HTTP/1.1 400 Bad Request
...

{"error":"Invalid JSON","detail":"Syntax error"}
```

## Why the Order Matters

Middlewares wrap the request/response cycle in **registration order**: the
first one registered is the outermost layer (runs first on the way in, last
on the way out), and the last one registered is the innermost layer, right
next to the router. This example registers them as:

```
CorsMiddleware → RequestIdMiddleware → RequestLoggingMiddleware → JsonBodyMiddleware → RateLimitMiddleware
```

1. **`CorsMiddleware` is first (outermost)** because it must intercept
   `OPTIONS` preflight requests before anything else runs. If it were placed
   later, a preflight request could be consumed by rate limiting or JSON
   validation instead of getting a proper CORS response, and it could even
   burn part of a client's rate-limit quota on a request that never touches
   real API logic.

2. **`RequestIdMiddleware` runs early** so that *every* request gets a
   unique id — including requests later rejected by `JsonBodyMiddleware`
   (`400`) or `RateLimitMiddleware` (`429`). That makes the id useful for
   correlating logs even for failed/rejected requests.

3. **`RequestLoggingMiddleware` runs right after**, still before JSON
   validation and rate limiting, so the "request received"/"request
   finished" log lines are written for every request that reaches the app,
   again including the ones that will later be rejected downstream.

4. **`JsonBodyMiddleware` validates the body** before the request reaches
   rate limiting or the controller, rejecting malformed JSON early with a
   `400`.

5. **`RateLimitMiddleware` is last (innermost) — deliberately, for this demo.**
   In a real application, rate limiting should normally be one of the
   *first* middlewares in the pipeline, so abusive traffic is rejected as
   cheaply and as early as possible — ideally before spending any work
   parsing or validating JSON bodies. It was placed last here purely so
   you can observe, in the server log, that request-id generation and
   logging still happen for a request even when that same request goes on
   to be rate-limited with a `429`. Prefer moving `RateLimitMiddleware`
   right after (or even before) `CorsMiddleware` in production.

## Rate Limit Storage Backends

By default `RateLimitMiddleware` uses `InMemoryRateLimitStore`, which keeps
counters in process memory. That does **not** persist reliably across
requests in typical PHP-FPM/Apache deployments, since each request may be
handled by a different worker process.

Perhaps surprisingly, the same limitation applies to PHP's built-in
development server (`php -S`) used by this example: it re-executes the
entire script from scratch for every incoming request (much like
traditional CGI/PHP-FPM), rather than keeping a single long-lived object
graph the way a persistent Node.js-style server would. So
`InMemoryRateLimitStore`'s counters are reset on every request when running
this example via `php -S`, and the `429` will never trigger with the default
store — see the note in "Triggering the rate limit" above.

For production with multiple workers/servers (and to make the `429` in this
very demo actually trigger), this example picks `ApcuRateLimitStore`
automatically at runtime, if the `apcu` extension is loaded. APCu is backed
by shared memory that outlives any single script execution within the same
server process, so counters persist correctly across requests:

```php
$rateLimitStore = extension_loaded('apcu')
    ? new ApcuRateLimitStore()
    : new InMemoryRateLimitStore();

$rateLimitMiddleware = new RateLimitMiddleware(
    maxRequests: 5,
    windowSeconds: 30,
    store: $rateLimitStore,
);
```

This keeps the example runnable in any environment while still illustrating
the pattern recommended in the root [`README.md`](../../README.md) for
production deployments. You can also implement `RateLimitStore` yourself to
back it with Redis or another shared cache.
