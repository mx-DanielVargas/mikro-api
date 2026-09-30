<?php

namespace MikroApi\Middleware;

/**
 * Almacenamiento de rate limiting respaldado por APCu.
 * A diferencia de InMemoryRateLimitStore, persiste entre requests dentro
 * del mismo servidor/pool de workers. Requiere la extensión `apcu`.
 *
 * Usa apcu_add() (para inicializar la ventana una sola vez, atómico) y
 * apcu_inc() (para el incremento del contador, atómico) en lugar de un
 * patrón fetch+store, que perdería incrementos bajo concurrencia alta.
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
        $countKey = $this->prefix . $key . ':count';
        $resetKey = $this->prefix . $key . ':reset';

        // apcu_add() solo escribe si la clave no existe (atómico a nivel
        // de APCu). La primera request de una ventana nueva "gana" la
        // inicialización y reinicia el contador; el resto de requests
        // dentro de la misma ventana simplemente leen el reset ya fijado.
        $reset = \time() + $windowSeconds;
        if (\apcu_add($resetKey, $reset, $windowSeconds)) {
            \apcu_store($countKey, 0, $windowSeconds);
        } else {
            $existingReset = \apcu_fetch($resetKey);
            $reset = $existingReset !== false ? (int) $existingReset : $reset;
        }

        // apcu_inc() es atómico: evita perder incrementos bajo concurrencia
        // (a diferencia de apcu_fetch()+apcu_store()).
        $count = \apcu_inc($countKey, 1, $success, $windowSeconds);
        if ($success === false) {
            // Edge case: la clave de contador expiró justo entre el
            // apcu_add del reset y este incremento (ventana de carrera muy
            // estrecha). La reinicializamos y reintentamos una vez.
            \apcu_add($countKey, 0, $windowSeconds);
            $count = \apcu_inc($countKey, 1, $success, $windowSeconds);
        }

        return ['count' => (int) $count, 'reset' => $reset];
    }
}
