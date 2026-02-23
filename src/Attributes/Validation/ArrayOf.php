<?php
// core/Attributes/Validation/ArrayOf.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class ArrayOf
{
    public function __construct(
        public string $type,
        public string $message = 'Los elementos de :field deben ser de tipo :type',
    ) {}
}
