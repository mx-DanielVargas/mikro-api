<?php

namespace MikroApi\Attributes;

/**
 * Inyecta un parámetro de ruta, convertido al tipo declarado (equivalente a @Param).
 * Si el tipo no coincide (ej. "abc" para int) se responde 400.
 *
 *   #[Route('GET', '/:id')]
 *   public function show(#[Param('id')] int $id): array
 *
 * Sin nombre inyecta todos los parámetros (array) o un DTO validado:
 *   public function show(#[Param] array $params)
 *   public function show(#[Param] ShowParamsDto $params)
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
class Param
{
    public function __construct(public ?string $name = null) {}
}
