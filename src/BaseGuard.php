<?php
// core/BaseGuard.php
namespace MikroApi;

/**
 * Clase base opcional para guards.
 * Extiende esta clase si no quieres implementar deny() manualmente.
 * El comportamiento por defecto es retornar un 401 Unauthorized.
 */
abstract class BaseGuard implements GuardInterface
{
    public function deny(): Response
    {
        return Response::error('Unauthorized', 401);
    }
}
