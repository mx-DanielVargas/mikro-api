<?php
// core/Attributes/Validation/Length.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Length
{
    public function __construct(
        public int    $min,
        public int    $max,
        public string $message = 'El campo :field debe tener entre :min y :max caracteres',
    ) {}
}
