<?php

namespace MikroApi\Attributes;

/**
 * Adjunta metadatos clave/valor a un controlador o método, para que guards,
 * interceptors o filtros los lean con Reflector (equivalente a SetMetadata
 * de NestJS).
 *
 * Uso directo:
 *   #[SetMetadata('roles', ['admin'])]
 *
 * O como base de atributos propios (lo recomendado):
 *   #[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
 *   class Permissions extends SetMetadata
 *   {
 *       public function __construct(string ...$perms) { parent::__construct('permissions', $perms); }
 *   }
 *
 *   #[Permissions('users:write')]
 *   public function update(...) { ... }
 *
 *   // En el guard:
 *   $perms = $this->reflector->getAllAndOverride('permissions', $request->context);
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class SetMetadata
{
    public function __construct(
        public string $key,
        public mixed $value = true,
    ) {}
}
