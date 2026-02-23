<?php
// core/Attributes/Validation/Required.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Required
{
    public function __construct(public string $message = 'El campo :field es obligatorio') {}
}
