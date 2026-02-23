<?php

namespace MikroApi\Attributes;

/**
 * Enriquece la documentación de un endpoint específico.
 * Todos los campos son opcionales — si no se definen se infieren
 * automáticamente del docblock, guards y DTO del método.
 *
 *   #[Route('POST', '/')]
 *   #[Body(CreateUserDto::class)]
 *   #[ApiDoc(
 *       summary:     'Crear usuario',
 *       description: 'Crea un nuevo usuario en el sistema',
 *       responses:   [
 *           201 => 'Usuario creado exitosamente',
 *           409 => 'El email ya está registrado',
 *           422 => 'Error de validación',
 *       ]
 *   )]
 *   public function create(Request $req): Response { ... }
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class ApiDoc
{
    public function __construct(
        public ?string $summary     = null,
        public ?string $description = null,
        public bool    $deprecated  = false,
        public array   $responses   = [],   // [statusCode => description]
        public bool    $exclude     = false, // excluir este endpoint del spec
    ) {}
}