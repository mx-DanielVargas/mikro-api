<?php
// core/Attributes/Validation/Matches.php
namespace MikroApi\Attributes\Validation;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Matches
{
    public function __construct(
        public string $pattern,
        public string $message = 'El campo :field tiene un formato inválido',
    ) {}
}
