<?php

namespace MikroApi\Interceptor;

use MikroApi\ExecutionContext;

/**
 * Interceptor (equivalente a NestInterceptor). Envuelve la ejecución del
 * handler: puede actuar antes, después, transformar el resultado, cachearlo
 * o cortar la ejecución sin llamar a $next.
 *
 * Se ejecuta después de los guards y antes de la validación de parámetros.
 *
 *   class TimingInterceptor implements InterceptorInterface
 *   {
 *       public function intercept(ExecutionContext $context, callable $next): mixed
 *       {
 *           $start  = microtime(true);
 *           $result = $next();               // ejecuta el resto de la cadena + handler
 *           $ms     = (microtime(true) - $start) * 1000;
 *           return $result instanceof Response
 *               ? $result->withHeader('X-Response-Time', round($ms, 2) . 'ms')
 *               : $result;
 *       }
 *   }
 *
 * $next() retorna lo que retorne el handler (una Response o cualquier valor
 * serializable a JSON); el framework convierte el resultado final en Response.
 */
interface InterceptorInterface
{
    /** @param callable(): mixed $next */
    public function intercept(ExecutionContext $context, callable $next): mixed;
}
