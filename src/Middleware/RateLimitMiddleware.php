<?php

namespace MikroApi\Middleware;

use MikroApi\Request;
use MikroApi\Response;

/**
 * Simple in-memory rate limiter per IP.
 * For production, replace the storage with Redis/APCu.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    /** @var array<string, array{count: int, reset: int}> */
    private static array $store = [];

    public function __construct(
        private int $maxRequests = 60,
        private int $windowSeconds = 60,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $now = time();

        if (!isset(self::$store[$ip]) || self::$store[$ip]['reset'] <= $now) {
            self::$store[$ip] = ['count' => 0, 'reset' => $now + $this->windowSeconds];
        }

        self::$store[$ip]['count']++;
        $remaining = max(0, $this->maxRequests - self::$store[$ip]['count']);

        if (self::$store[$ip]['count'] > $this->maxRequests) {
            return Response::json(['error' => 'Too Many Requests'], 429)
                ->withHeader('Retry-After', (string)(self::$store[$ip]['reset'] - $now))
                ->withHeader('X-RateLimit-Limit', (string)$this->maxRequests)
                ->withHeader('X-RateLimit-Remaining', '0');
        }

        return $next($request)
            ->withHeader('X-RateLimit-Limit', (string)$this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string)$remaining);
    }
}
