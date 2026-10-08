<?php

namespace MikroApi\Exception;

use MikroApi\ExecutionContext;
use MikroApi\Request;
use MikroApi\Response;

/**
 * Filtro de excepciones (equivalente a ExceptionFilter de NestJS).
 *
 * Declara qué excepciones atiende con #[Catches(...)] en la clase (sin el
 * atributo atiende todas). Se registra con #[UseFilters] en un controlador
 * o método, o globalmente con App::useGlobalFilters().
 *
 *   #[Catches(NotFoundException::class)]
 *   class NotFoundFilter implements ExceptionFilterInterface
 *   {
 *       public function catch(\Throwable $e, Request $request, ?ExecutionContext $context): ?Response
 *       {
 *           return Response::json(['message' => $e->getMessage(), 'path' => $request->path], 404);
 *       }
 *   }
 */
interface ExceptionFilterInterface
{
    /**
     * Convierte la excepción en una respuesta. Retornar null delega la
     * excepción al siguiente filtro aplicable (o al manejador por defecto).
     *
     * $context es null cuando la excepción ocurre fuera de una ruta
     * (middleware, 404/405).
     */
    public function catch(\Throwable $exception, Request $request, ?ExecutionContext $context): ?Response;
}
