<?php
// core/Attributes/Validation/MinLength.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class MinLength
{
    public function __construct(
        public int    $min,
        public string $message = 'El campo :field debe tener al menos :min caracteres',
    ) {}
}
