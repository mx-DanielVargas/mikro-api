<?php

namespace MikroApi\Middleware;

use MikroApi\Request;
use MikroApi\Response;

/**
 * Rate limiter por IP con almacenamiento intercambiable (RateLimitStore).
 *
 * Por defecto usa InMemoryRateLimitStore (memoria de proceso), que NO
 * persiste de forma confiable entre requests en despliegues PHP-FPM/Apache
 * sin proceso persistente (ver AUD-004). Para producción con múltiples
 * workers/servidores, pasa un store persistente:
 *
 *   new RateLimitMiddleware(60, 60, new ApcuRateLimitStore());
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private RateLimitStore $store;

    public function __construct(
        private int $maxRequests = 60,
        private int $windowSeconds = 60,
        ?RateLimitStore $store = null,
    ) {
        $this->store = $store ?? new InMemoryRateLimitStore();
    }

    public function handle(Request $request, callable $next): Response
    {
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $state = $this->store->increment($ip, $this->windowSeconds);
        $remaining = \max(0, $this->maxRequests - $state['count']);

        if ($state['count'] > $this->maxRequests) {
            return Response::json(['error' => 'Too Many Requests'], 429)
                ->withHeader('Retry-After', (string)($state['reset'] - \time()))
                ->withHeader('X-RateLimit-Limit', (string)$this->maxRequests)
                ->withHeader('X-RateLimit-Remaining', '0');
        }

        return $next($request)
            ->withHeader('X-RateLimit-Limit', (string)$this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string)$remaining);
    }
}
