<?php

namespace MikroApi\Middleware;

use MikroApi\Request;
use MikroApi\Response;

/**
 * Rejects requests with malformed JSON bodies (returns 400).
 * Only checks POST/PUT/PATCH with Content-Type: application/json.
 */
class JsonBodyMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (!\in_array($request->method, ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $contentType = $request->header('CONTENT-TYPE') ?? '';
        if (!\str_contains($contentType, 'application/json')) {
            return $next($request);
        }

        if (empty($request->body) && \json_last_error() !== JSON_ERROR_NONE) {
            return Response::json([
                'error'  => 'Invalid JSON',
                'detail' => \json_last_error_msg(),
            ], 400);
        }

        return $next($request);
    }
}
