<?php
// core/Attributes/Validation/IsInt.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class IsInt
{
    public function __construct(public string $message = 'El campo :field debe ser un entero') {}
}
