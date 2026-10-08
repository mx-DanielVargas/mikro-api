<?php

namespace MikroApi\Exception;

use MikroApi\Attributes\Catches;
use MikroApi\Container;
use MikroApi\ExecutionContext;
use MikroApi\Request;
use MikroApi\Response;

/**
 * Convierte cualquier excepción en una Response: primero prueba los
 * filtros aplicables (en el orden recibido) y, si ninguno la atiende,
 * aplica el manejo por defecto:
 *
 *   - HttpException (y ServiceException) → su status, body y headers.
 *   - Cualquier otra → 500; el mensaje real se oculta en producción.
 */
class ExceptionHandler
{
    /** @var array<string, string[]> caché de tipos declarados en #[Catches] por clase de filtro */
    private static array $catchesCache = [];

    /**
     * @param string[] $filterClasses Filtros a probar, del más específico al más general
     */
    public static function handle(
        \Throwable $exception,
        Request $request,
        ?ExecutionContext $context = null,
        array $filterClasses = [],
        ?Container $container = null,
    ): Response {
        foreach ($filterClasses as $filterClass) {
            if (!self::filterCatches($filterClass, $exception)) {
                continue;
            }

            /** @var ExceptionFilterInterface $filter */
            $filter   = $container !== null ? $container->get($filterClass) : new $filterClass();
            $response = $filter->catch($exception, $request, $context);

            if ($response !== null) {
                return $response;
            }
        }

        return self::defaultResponse($exception);
    }

    public static function defaultResponse(\Throwable $exception): Response
    {
        if ($exception instanceof HttpException) {
            $response = Response::json($exception->getBody(), $exception->getStatusCode());
            foreach ($exception->getHeaders() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
            return $response;
        }

        $message = self::isProduction() ? 'Internal Server Error' : $exception->getMessage();
        return Response::error($message, 500);
    }

    /**
     * Determina si el entorno actual es de producción.
     *
     * ConfigService::loadEnvFile() escribe APP_ENV en $_ENV y putenv(),
     * nunca en $_SERVER, así que hay que revisar las tres fuentes para
     * no filtrar mensajes de error internos cuando APP_ENV se define
     * únicamente vía .env (AUD-001).
     */
    public static function isProduction(): bool
    {
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? null;

        if ($env === null) {
            $fromGetenv = \getenv('APP_ENV');
            $env = $fromGetenv !== false ? $fromGetenv : null;
        }

        return $env === 'production';
    }

    private static function filterCatches(string $filterClass, \Throwable $exception): bool
    {
        if (!isset(self::$catchesCache[$filterClass])) {
            $types = [];
            foreach ((new \ReflectionClass($filterClass))->getAttributes(Catches::class) as $attr) {
                $types = \array_merge($types, $attr->newInstance()->exceptions);
            }
            self::$catchesCache[$filterClass] = $types;
        }

        $types = self::$catchesCache[$filterClass];
        if (empty($types)) {
            return true; // sin #[Catches] → atiende todas
        }

        foreach ($types as $type) {
            if ($exception instanceof $type) {
                return true;
            }
        }
        return false;
    }
}
