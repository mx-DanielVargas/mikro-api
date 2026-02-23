<?php
// core/Attributes/Validation/IsString.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class IsString
{
    public function __construct(public string $message = 'El campo :field debe ser una cadena de texto') {}
}
