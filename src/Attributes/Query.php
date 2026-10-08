<?php

namespace MikroApi\Attributes;

/**
 * Inyecta un valor del query string, convertido al tipo declarado (equivalente a @Query).
 * Faltante y sin default/nullable → 400. Tipo inválido → 400.
 *
 *   public function index(#[Query('page')] int $page = 1, #[Query('q')] ?string $q = null)
 *
 * Sin nombre inyecta todo el query (array) o un DTO validado (422 si falla):
 *   public function index(#[Query] ListUsersQuery $query)
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
class Query
{
    public function __construct(public ?string $name = null) {}
}
