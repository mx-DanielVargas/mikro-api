<?php
// core/Attributes/Relation/HasOne.php

namespace MikroApi\Attributes\Relation;

/**
 * Relación uno a uno.
 * El registro actual tiene exactamente un registro en otra tabla.
 *
 * Ejemplo: Un User tiene un Profile
 *
 *   #[HasOne(repository: ProfileRepository::class, foreignKey: 'user_id')]
 *   public array $profile;
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class HasOne
{
    public function __construct(
        public string  $repository,
        public string  $foreignKey,
        public ?string $localKey = null,
        public ?string $as       = null,
    ) {}
}
