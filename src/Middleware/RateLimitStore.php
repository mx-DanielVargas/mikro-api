<?php

namespace MikroApi\Middleware;

/**
 * Abstrae el almacenamiento de contadores de rate limiting, permitiendo
 * intercambiar la implementación (memoria de proceso, APCu, Redis, etc.)
 * sin modificar RateLimitMiddleware.
 */
interface RateLimitStore
{
    /**
     * Incrementa el contador asociado a $key y retorna el estado actual.
     *
     * @return array{count: int, reset: int}
     */
    public function increment(string $key, int $windowSeconds): array;
}
