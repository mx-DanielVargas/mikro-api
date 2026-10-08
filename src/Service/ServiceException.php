<?php
// core/Service/ServiceException.php

namespace MikroApi\Service;

use MikroApi\Exception\HttpException;

/**
 * Excepción lanzada por los servicios cuando ocurre un error de negocio.
 * Lleva un código HTTP para que el controlador pueda responder correctamente.
 *
 * Uso en un servicio:
 *   $this->fail('Email ya registrado', 409);
 *   $this->notFound('Usuario no encontrado');
 *
 * Extiende HttpException, así que se convierte automáticamente en una
 * respuesta con su código (y puede atenderse con un exception filter).
 *
 * Captura manual en el controlador (opcional):
 *   try {
 *       $user = $this->userService->create($dto);
 *   } catch (ServiceException $e) {
 *       return Response::error($e->getMessage(), $e->getStatusCode());
 *   }
 */
class ServiceException extends HttpException
{
    public function __construct(string $message, int $statusCode = 400)
    {
        parent::__construct($message, $statusCode);
    }
}
