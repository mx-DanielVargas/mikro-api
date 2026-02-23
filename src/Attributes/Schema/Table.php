<?php
// core/Attributes/Schema/Table.php
namespace MikroApi\Attributes\Schema;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Table
{
    public function __construct(
        public string  $name,
        public ?string $comment = null,
    ) {}
}
