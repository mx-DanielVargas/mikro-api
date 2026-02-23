<?php
// core/Attributes/Validation/Max.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Max
{
    public function __construct(
        public int|float $max,
        public string    $message = 'El campo :field debe ser menor o igual a :max',
    ) {}
}
