<?php
// core/Attributes/Body.php
namespace MikroApi\Attributes;

/**
 * Indica que el parámetro del método del controlador
 * debe ser validado contra el DTO indicado.
 *
 * Uso:
 *   #[Route('POST', '/')]
 *   #[Body(CreateUserDto::class)]
 *   public function create(Request $req): Response { ... }
 *
 * Si la validación falla, el framework retorna 422 automáticamente
 * antes de llegar al método del controlador.
 * Si pasa, el DTO validado y casteado estará en $req->dto
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class Body
{
    public function __construct(public string $dtoClass) {}
}
