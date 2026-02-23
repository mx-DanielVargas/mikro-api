<?php
// core/Attributes/Relation/HasMany.php

namespace MikroApi\Attributes\Relation;

/**
 * Relación uno a muchos.
 * El registro actual tiene muchos registros en otra tabla.
 *
 * Ejemplo: Un User tiene muchos Posts
 *
 *   #[HasMany(repository: PostRepository::class, foreignKey: 'user_id')]
 *   public array $posts;
 *
 * Uso en repositorio:
 *   $repo->with('posts')->findById(1);
 *   // resultado: ['id' => 1, 'name' => '...', 'posts' => [...]]
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class HasMany
{
    public function __construct(
        public string  $repository,           // clase del repositorio relacionado
        public string  $foreignKey,           // FK en la tabla relacionada
        public ?string $localKey    = null,   // PK local (null = usa el primaryKey del repo)
        public ?string $as          = null,   // nombre de la clave en el resultado (default = nombre de la propiedad)
    ) {}
}
