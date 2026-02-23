<?php
// core/Attributes/Schema/Index.php
namespace MikroApi\Attributes\Schema;

/**
 * Agrega un índice normal (no único) a la columna.
 *
 *   #[Index]
 *   public string $status;
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Index
{
    public function __construct(
        public ?string $name = null,  // nombre del índice (autogenerado si null)
    ) {}
}
