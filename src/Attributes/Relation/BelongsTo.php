<?php
// core/Attributes/Relation/BelongsTo.php

namespace MikroApi\Attributes\Relation;

/**
 * Relación inversa de HasMany / HasOne.
 * Este registro pertenece a otro.
 *
 * Ejemplo: Un Post pertenece a un User
 *
 *   #[BelongsTo(repository: UserRepository::class, foreignKey: 'user_id')]
 *   public array $user;
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class BelongsTo
{
    public function __construct(
        public string  $repository,
        public string  $foreignKey,           // FK en esta tabla
        public ?string $ownerKey  = null,     // PK en la tabla padre (null = primaryKey del repo)
        public ?string $as        = null,
    ) {}
}
