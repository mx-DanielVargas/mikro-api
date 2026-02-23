<?php
// core/Attributes/Schema/Column.php
namespace MikroApi\Attributes\Schema;

/**
 * Define una columna de la tabla.
 *
 * Tipos soportados: int, bigint, tinyint, smallint,
 *                   varchar, text, mediumtext, longtext,
 *                   decimal, float, double,
 *                   boolean, date, datetime, timestamp, json
 *
 * Ejemplo:
 *   #[Column(type: 'varchar', length: 255)]
 *   #[Column(type: 'decimal', precision: 10, scale: 2)]
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Column
{
    public function __construct(
        public string  $type      = 'varchar',
        public ?int    $length    = null,
        public ?int    $precision = null,
        public ?int    $scale     = null,
        public bool    $nullable  = false,
        public mixed   $default   = '__NONE__',
        public ?string $comment   = null,
        public ?string $name      = null,   // nombre personalizado de la columna
    ) {}
}
