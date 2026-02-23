<?php
// core/Attributes/Schema/ForeignKey.php
namespace MikroApi\Attributes\Schema;

/**
 * Define una clave foránea.
 *
 *   #[ForeignKey(references: 'users', on: 'id')]
 *   public int $userId;
 *
 *   #[ForeignKey(references: 'categories', on: 'id', onDelete: 'SET NULL', onUpdate: 'CASCADE')]
 *   public ?int $categoryId;
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class ForeignKey
{
    public function __construct(
        public string  $references,              // tabla referenciada
        public string  $on         = 'id',       // columna referenciada
        public string  $onDelete   = 'CASCADE',  // CASCADE | SET NULL | RESTRICT | NO ACTION
        public string  $onUpdate   = 'CASCADE',
        public ?string $name       = null,        // nombre del constraint (autogenerado si null)
    ) {}
}
