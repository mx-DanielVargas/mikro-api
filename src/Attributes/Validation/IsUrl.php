<?php
// core/Attributes/Validation/IsUrl.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class IsUrl
{
    public function __construct(public string $message = 'El campo :field debe ser una URL válida') {}
}
