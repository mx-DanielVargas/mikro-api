<?php

namespace MikroApi\Attributes;

/**
 * Aplica filtros de excepciones a un controlador o método.
 * Se prueban del más específico (método) al más general (clase, luego globales).
 *
 *   #[UseFilters(NotFoundFilter::class)]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class UseFilters
{
    /** @var string[] */
    public array $filters;

    public function __construct(string ...$filters)
    {
        $this->filters = $filters;
    }
}
