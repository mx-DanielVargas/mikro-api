<?php
// core/Attributes/Validation/IsArray.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class IsArray
{
    public function __construct(public string $message = 'El campo :field debe ser un arreglo') {}
}
