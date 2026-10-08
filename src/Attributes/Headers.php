<?php

namespace MikroApi\Attributes;

/**
 * Inyecta un header de la petición (case-insensitive) o todos (array) si no se da nombre.
 *
 *   public function show(#[Headers('X-Tenant')] ?string $tenant)
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
class Headers
{
    public function __construct(public ?string $name = null) {}
}
