<?php
// core/Attributes/Validation/IsFloat.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class IsFloat
{
    public function __construct(public string $message = 'El campo :field debe ser un número') {}
}
