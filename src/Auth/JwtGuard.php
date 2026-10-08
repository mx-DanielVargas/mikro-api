<?php

namespace MikroApi\Auth;

use MikroApi\Attributes\PublicRoute;
use MikroApi\BaseGuard;
use MikroApi\Exception\UnauthorizedException;
use MikroApi\Reflector;
use MikroApi\Request;

/**
 * Guard de autenticación Bearer JWT. Verifica el header
 * `Authorization: Bearer <token>` con JwtService y deja el payload en
 * $request->user (inyectable con #[CurrentUser]).
 *
 * Responde 401 con el motivo ("Token expirado", "Token inválido"...).
 * Las rutas marcadas con #[PublicRoute] pasan sin token, lo que permite
 * registrarlo como guard global:
 *
 *   $app->useGlobalGuards(JwtGuard::class);
 *
 * Requiere JwtService registrado en el container (ver JwtService).
 */
class JwtGuard extends BaseGuard
{
    public function __construct(
        private JwtService $jwt,
        private Reflector $reflector,
    ) {}

    public function canActivate(Request $request): bool
    {
        if ($this->reflector->getAllAndOverride(PublicRoute::KEY, $request->context) === true) {
            return true;
        }

        $header = $request->header('Authorization') ?? '';
        if (!\preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            throw new UnauthorizedException('Token no proporcionado');
        }

        $request->user = $this->jwt->verify($m[1]);
        return true;
    }
}
