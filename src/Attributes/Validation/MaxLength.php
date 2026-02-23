<?php
// core/Attributes/Validation/MaxLength.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class MaxLength
{
    public function __construct(
        public int    $max,
        public string $message = 'El campo :field debe tener máximo :max caracteres',
    ) {}
}
