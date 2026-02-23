<?php
// core/Attributes/Validation/IsIn.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class IsIn
{
    public function __construct(
        public array  $values,
        public string $message = 'El campo :field debe ser uno de: :values',
    ) {}
}
