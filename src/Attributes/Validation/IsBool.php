<?php
// core/Attributes/Validation/IsBool.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class IsBool
{
    public function __construct(public string $message = 'El campo :field debe ser booleano') {}
}
