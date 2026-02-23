<?php
// core/Service/BaseService.php

namespace MikroApi\Service;

use MikroApi\Repository\BaseRepository;

/**
 * Clase base para servicios.
 * Un servicio encapsula la lógica de negocio y orquesta repositorios.
 *
 * Ejemplo:
 *   class UserService extends BaseService
 *   {
 *       public function __construct(private UserRepository $users) {}
 *   }
 */
abstract class BaseService
{
    /**
     * Lanza una excepción de negocio con un mensaje y código HTTP.
     * El controlador puede capturarla y convertirla a Response.
     */
    protected function fail(string $message, int $statusCode = 400): never
    {
        throw new ServiceException($message, $statusCode);
    }

    protected function notFound(string $message = 'Recurso no encontrado'): never
    {
        $this->fail($message, 404);
    }

    protected function unauthorized(string $message = 'No autorizado'): never
    {
        $this->fail($message, 401);
    }

    protected function forbidden(string $message = 'Acceso denegado'): never
    {
        $this->fail($message, 403);
    }

    protected function conflict(string $message = 'Conflicto con el estado actual'): never
    {
        $this->fail($message, 409);
    }
}
