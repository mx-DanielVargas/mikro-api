<?php
// core/Attributes/Validation/Min.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Min
{
    public function __construct(
        public int|float $min,
        public string    $message = 'El campo :field debe ser mayor o igual a :min',
    ) {}
}
