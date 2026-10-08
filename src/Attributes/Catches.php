<?php

namespace MikroApi\Attributes;

/**
 * Declara qué excepciones atiende un filtro (equivalente a @Catch de NestJS).
 * Sin este atributo, el filtro atiende cualquier excepción.
 *
 *   #[Catches(NotFoundException::class, ConflictException::class)]
 *   class MyFilter implements ExceptionFilterInterface { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Catches
{
    /** @var class-string<\Throwable>[] */
    public array $exceptions;

    public function __construct(string ...$exceptions)
    {
        $this->exceptions = $exceptions;
    }
}
