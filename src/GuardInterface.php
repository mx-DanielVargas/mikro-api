<?php
// core/GuardInterface.php
namespace MikroApi;

interface GuardInterface
{
    /**
     * Retorna true si la petición puede continuar,
     * false si debe ser rechazada (401 / 403).
     */
    public function canActivate(Request $request): bool;

    /**
     * Respuesta a enviar cuando canActivate() retorna false.
     */
    public function deny(): Response;
}

