<?php

namespace MikroApi\Middleware;

/**
 * Almacenamiento en memoria de proceso (comportamiento por defecto,
 * preserva compatibilidad con versiones anteriores de RateLimitMiddleware).
 *
 * ADVERTENCIA: en PHP-FPM/Apache (sin proceso persistente) el contador
 * NO persiste de forma confiable entre requests, ya que cada request
 * puede ser atendido por un worker/proceso distinto. Para producción
 * con múltiples workers o servidores, usa ApcuRateLimitStore u otra
 * implementación respaldada por Redis/Memcached.
 */
class InMemoryRateLimitStore implements RateLimitStore
{
    /** @var array<string, array{count: int, reset: int}> */
    private array $store = [];

    public function increment(string $key, int $windowSeconds): array
    {
        $now = \time();

        if (!isset($this->store[$key]) || $this->store[$key]['reset'] <= $now) {
            $this->store[$key] = ['count' => 0, 'reset' => $now + $windowSeconds];
        }

        $this->store[$key]['count']++;

        return $this->store[$key];
    }
}
