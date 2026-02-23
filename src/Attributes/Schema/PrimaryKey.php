<?php
// core/Attributes/Schema/PrimaryKey.php
namespace MikroApi\Attributes\Schema;

/**
 * Marca la propiedad como clave primaria.
 * Por defecto autoincremental (AUTO_INCREMENT).
 *
 *   #[PrimaryKey]
 *   public int $id;
 *
 *   // Sin autoincremento (UUID, etc.)
 *   #[PrimaryKey(autoIncrement: false)]
 *   #[Column(type: 'varchar', length: 36)]
 *   public string $id;
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class PrimaryKey
{
    public function __construct(
        public bool $autoIncrement = true,
    ) {}
}
