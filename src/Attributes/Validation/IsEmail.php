<?php
// core/Attributes/Validation/IsEmail.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class IsEmail
{
    public function __construct(public string $message = 'El campo :field debe ser un email válido') {}
}
