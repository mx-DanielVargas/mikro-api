<?php
// core/RequestDto.php
namespace MikroApi;

/**
 * Clase base para todos los DTOs de validación.
 *
 * Los DTOs son clases simples donde cada propiedad pública
 * tiene atributos de validación:
 *
 *   class CreateUserDto extends RequestDto
 *   {
 *       #[Required]
 *       #[IsString]
 *       #[MinLength(2)]
 *       public string $name;
 *
 *       #[Required]
 *       #[IsEmail]
 *       public string $email;
 *
 *       #[Optional]
 *       #[IsInt]
 *       #[Min(1)]
 *       public ?int $age;
 *   }
 */
abstract class RequestDto
{
    /**
     * Accede a las propiedades como array.
     * Útil para pasar el DTO a funciones que esperan array.
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * Crea una instancia del DTO a partir de un array,
     * sin pasar por el validador (uso interno).
     */
    public static function fromArray(array $data): static
    {
        $ref = new \ReflectionClass(static::class);
        $dto = $ref->newInstanceWithoutConstructor();
        foreach ($ref->getProperties() as $prop) {
            $field = $prop->getName();
            if (array_key_exists($field, $data)) {
                $prop->setAccessible(true);
                $prop->setValue($dto, $data[$field]);
            }
        }
        return $dto;
    }
}
