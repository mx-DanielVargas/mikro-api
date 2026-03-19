<?php

namespace MikroApi\Attributes;

/**
 * Documenta query parameters para Swagger.
 *
 * Uso:
 *   #[Route('GET', '/')]
 *   #[QueryParam('page', type: 'integer', description: 'Page number')]
 *   #[QueryParam('limit', type: 'integer', description: 'Items per page')]
 *   public function index(Request $req): Response { ... }
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class QueryParam
{
    public function __construct(
        public string  $name,
        public string  $type        = 'string',
        public string  $description = '',
        public bool    $required    = false,
        public mixed   $example     = null,
    ) {}
}
