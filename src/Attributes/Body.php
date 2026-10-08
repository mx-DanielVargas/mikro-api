<?php
// core/Attributes/Body.php
namespace MikroApi\Attributes;

/**
 * Valida y/o inyecta el body de la petición.
 *
 * En el método (forma clásica):
 *   #[Route('POST', '/')]
 *   #[Body(CreateUserDto::class)]
 *   public function create(Request $req): Response { ... $req->dto ... }
 *
 * En un parámetro (estilo NestJS):
 *   public function create(#[Body] CreateUserDto $dto): array      // DTO validado (tipo del parámetro)
 *   public function create(#[Body] array $body): array             // body crudo
 *   public function create(#[Body('email')] string $email): array  // un campo del body, convertido al tipo
 *
 * Si la validación falla se responde 422 antes de llegar al controlador.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_PARAMETER)]
class Body
{
    /**
     * @param string|null $dtoClass En el método: clase DTO. En un parámetro:
     *                              clase DTO o nombre de un campo del body.
     */
    public function __construct(public ?string $dtoClass = null) {}
}
