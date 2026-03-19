<?php

namespace MikroApi\Middleware;

use MikroApi\Request;
use MikroApi\Response;

interface MiddlewareInterface
{
    /**
     * Procesa la petición. Llama a $next($request) para continuar el pipeline.
     *
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response;
}
