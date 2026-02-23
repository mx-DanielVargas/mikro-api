<?php
// core/Service/ServiceException.php

namespace MikroApi\Service;

/**
 * Excepción lanzada por los servicios cuando ocurre un error de negocio.
 * Lleva un código HTTP para que el controlador pueda responder correctamente.
 *
 * Uso en un servicio:
 *   $this->fail('Email ya registrado', 409);
 *   $this->notFound('Usuario no encontrado');
 *
 * Captura en el controlador (o en App::run automáticamente):
 *   try {
 *       $user = $this->userService->create($dto);
 *   } catch (ServiceException $e) {
 *       return Response::error($e->getMessage(), $e->getStatusCode());
 *   }
 */
class ServiceException extends \RuntimeException
{
    public function __construct(
        string $message,
        private int $statusCode = 400,
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
