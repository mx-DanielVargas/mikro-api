<?php

namespace MikroApi\Attributes;

/**
 * Roles requeridos para acceder a un controlador o método. Lo evalúa
 * RolesGuard contra $request->user['roles'] (o ['role']).
 *
 *   #[UseGuards(JwtGuard::class, RolesGuard::class)]
 *   #[Roles('admin', 'editor')]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Roles extends SetMetadata
{
    public const KEY = 'roles';

    public function __construct(string ...$roles)
    {
        parent::__construct(self::KEY, $roles);
    }
}
