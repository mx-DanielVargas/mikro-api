<?php

namespace MikroApi\Auth;

use MikroApi\Attributes\Roles;
use MikroApi\Exception\ForbiddenException;
use MikroApi\GuardInterface;
use MikroApi\Reflector;
use MikroApi\Request;
use MikroApi\Response;

/**
 * Autoriza según #[Roles(...)] del método (o de la clase si el método no
 * declara). Compara contra $request->user['roles'] (array) o
 * $request->user['role'] (string). Basta con tener uno de los roles.
 *
 * Debe ir después de un guard que setee $request->user (ej. JwtGuard):
 *
 *   #[UseGuards(JwtGuard::class, RolesGuard::class)]
 *   #[Roles('admin')]
 *
 * Sin #[Roles] en la ruta, deja pasar. Si no tiene el rol lanza
 * ForbiddenException (403), así los exception filters pueden formatearlo.
 */
class RolesGuard implements GuardInterface
{
    public function __construct(private Reflector $reflector) {}

    public function canActivate(Request $request): bool
    {
        $required = $this->reflector->getAllAndOverride(Roles::KEY, $request->context);
        if (empty($required)) {
            return true;
        }

        $user      = $request->user;
        $userRoles = match (true) {
            \is_array($user)  => $user['roles'] ?? $user['role'] ?? [],
            \is_object($user) => $user->roles ?? $user->role ?? [],
            default           => [],
        };
        $userRoles = (array) $userRoles;

        if (empty(\array_intersect($required, $userRoles))) {
            throw new ForbiddenException();
        }
        return true;
    }

    public function deny(): Response
    {
        return Response::error('Forbidden', 403);
    }
}
