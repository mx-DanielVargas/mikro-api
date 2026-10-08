<?php

namespace MikroApi;

/**
 * Contexto de ejecución de la ruta actual (equivalente a ExecutionContext
 * de NestJS). Lo reciben los interceptors y exception filters, y queda
 * disponible en guards vía $request->context.
 *
 * Permite saber qué controlador/método atenderá la petición y leer sus
 * atributos (ver Reflector).
 */
final class ExecutionContext
{
    private ?\ReflectionMethod $handlerRef = null;
    private ?\ReflectionClass  $classRef   = null;

    public function __construct(
        private Request $request,
        private string  $controllerClass,
        private string  $handlerName,
    ) {}

    public function getRequest(): Request
    {
        return $this->request;
    }

    /** Clase del controlador que atiende la ruta. */
    public function getClass(): string
    {
        return $this->controllerClass;
    }

    /** Nombre del método del controlador que atiende la ruta. */
    public function getHandler(): string
    {
        return $this->handlerName;
    }

    public function getClassReflection(): \ReflectionClass
    {
        return $this->classRef ??= new \ReflectionClass($this->controllerClass);
    }

    public function getHandlerReflection(): \ReflectionMethod
    {
        return $this->handlerRef ??= new \ReflectionMethod($this->controllerClass, $this->handlerName);
    }
}
