<?php
// core/Attributes/Schema/Unique.php
namespace MikroApi\Attributes\Schema;

/**
 * Agrega un índice UNIQUE a la columna.
 *
 *   #[Unique]
 *   public string $email;
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Unique
{
    public function __construct(
        public ?string $name = null,  // nombre del índice (autogenerado si null)
    ) {}
}
