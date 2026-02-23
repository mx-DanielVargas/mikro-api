<?php
// core/Attributes/Relation/BelongsToMany.php

namespace MikroApi\Attributes\Relation;

/**
 * Relación muchos a muchos mediante tabla pivot.
 *
 * Ejemplo: Un User tiene muchos Roles y un Role pertenece a muchos Users
 *
 *   #[BelongsToMany(
 *       repository:      RoleRepository::class,
 *       pivotTable:      'user_roles',
 *       foreignKey:      'user_id',      // FK de este modelo en el pivot
 *       relatedKey:      'role_id',      // FK del modelo relacionado en el pivot
 *       pivotColumns:    ['assigned_at'] // columnas extra del pivot a incluir (opcional)
 *   )]
 *   public array $roles;
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class BelongsToMany
{
    public function __construct(
        public string  $repository,
        public string  $pivotTable,
        public string  $foreignKey,
        public string  $relatedKey,
        public array   $pivotColumns = [],   // columnas extra del pivot a incluir en el resultado
        public ?string $localKey     = null,
        public ?string $relatedPk    = null, // PK del repo relacionado (null = primaryKey del repo)
        public ?string $as           = null,
    ) {}
}
