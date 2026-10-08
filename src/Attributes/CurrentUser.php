<?php

namespace MikroApi\Attributes;

/**
 * Inyecta el usuario autenticado ($request->user, lo setea JwtGuard).
 * Con nombre, inyecta solo esa clave del usuario.
 *
 *   public function me(#[CurrentUser] array $user)
 *   public function me(#[CurrentUser('sub')] int $userId)
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
class CurrentUser
{
    public function __construct(public ?string $name = null) {}
}
