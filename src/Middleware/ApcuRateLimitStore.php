<?php

namespace MikroApi\Middleware;

/**
 * Almacenamiento de rate limiting respaldado por APCu.
 * A diferencia de InMemoryRateLimitStore, persiste entre requests dentro
 * del mismo servidor/pool de workers. Requiere la extensión `apcu`.
 *
 * Uso:
 *   new RateLimitMiddleware(60, 60, new ApcuRateLimitStore());
 */
class ApcuRateLimitStore implements RateLimitStore
{
    public function __construct(private string $prefix = 'mikroapi_rl_')
    {
        if (!\function_exists('apcu_fetch')) {
            throw new \RuntimeException('ApcuRateLimitStore requiere la extensión "apcu".');
        }
    }

    public function increment(string $key, int $windowSeconds): array
    {
        $cacheKey = $this->prefix . $key;
        $now      = \time();

        $data = \apcu_fetch($cacheKey);
        if ($data === false || !\is_array($data) || $data['reset'] <= $now) {
            $data = ['count' => 0, 'reset' => $now + $windowSeconds];
        }

        $data['count']++;
        \apcu_store($cacheKey, $data, $windowSeconds);

        return $data;
    }
}
