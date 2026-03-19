<?php

namespace MikroApi\Middleware;

use MikroApi\Request;
use MikroApi\Response;

/**
 * Middleware CORS configurable.
 *
 * Uso:
 *   $app->useMiddleware(new CorsMiddleware());                    // defaults
 *   $app->useMiddleware(new CorsMiddleware(
 *       origins: ['https://example.com'],
 *       methods: ['GET', 'POST'],
 *       headers: ['Content-Type', 'Authorization', 'X-Custom'],
 *       maxAge:  7200,
 *   ));
 */
class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private array  $origins = ['*'],
        private array  $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
        private array  $headers = ['Content-Type', 'Authorization'],
        private int    $maxAge  = 3600,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        // Preflight
        if ($request->method === 'OPTIONS') {
            return $this->preflight();
        }

        $response = $next($request);
        return $this->addCorsHeaders($response);
    }

    private function preflight(): Response
    {
        $res = Response::text('', 204);
        return $this->addCorsHeaders($res);
    }

    private function addCorsHeaders(Response $response): Response
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', implode(', ', $this->origins))
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->methods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->headers))
            ->withHeader('Access-Control-Max-Age', (string) $this->maxAge);
    }
}
