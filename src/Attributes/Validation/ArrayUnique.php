<?php
// core/Attributes/Validation/ArrayUnique.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class ArrayUnique
{
    public function __construct(public string $message = 'El campo :field no debe contener duplicados') {}
}
