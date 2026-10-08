<?php

namespace MikroApi\Attributes;

/**
 * Marca un controlador o método como público: JwtGuard lo deja pasar sin
 * token. Útil cuando JwtGuard se registra como guard global.
 *
 *   $app->useGlobalGuards(JwtGuard::class);
 *
 *   #[PublicRoute]
 *   #[Route('POST', '/login')]
 *   public function login(...) { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class PublicRoute extends SetMetadata
{
    public const KEY = 'isPublic';

    public function __construct()
    {
        parent::__construct(self::KEY, true);
    }
}
