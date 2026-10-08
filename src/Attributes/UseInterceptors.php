<?php

namespace MikroApi\Attributes;

/**
 * Aplica interceptors a un controlador o método.
 * Orden de ejecución (de afuera hacia adentro): globales → clase → método.
 *
 *   #[UseInterceptors(LoggingInterceptor::class)]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class UseInterceptors
{
    /** @var string[] */
    public array $interceptors;

    public function __construct(string ...$interceptors)
    {
        $this->interceptors = $interceptors;
    }
}
