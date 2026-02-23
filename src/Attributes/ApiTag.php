<?php

namespace MikroApi\Attributes;

/**
 * Agrupa un controlador bajo un tag en la documentación Swagger.
 * Si no se especifica, el tag se infiere del nombre del controlador.
 *
 *   #[Controller('/users')]
 *   #[ApiTag(name: 'Usuarios', description: 'Gestión de usuarios del sistema')]
 *   class UserController { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ApiTag
{
    public function __construct(
        public string  $name,
        public ?string $description = null,
    ) {}
}