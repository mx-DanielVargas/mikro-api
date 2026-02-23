<?php
// core/Validator.php
namespace MikroApi;

use MikroApi\Attributes\Validation\Required;
use MikroApi\Attributes\Validation\Optional;
use MikroApi\Attributes\Validation\IsString;
use MikroApi\Attributes\Validation\IsInt;
use MikroApi\Attributes\Validation\IsFloat;
use MikroApi\Attributes\Validation\IsBool;
use MikroApi\Attributes\Validation\IsArray;
use MikroApi\Attributes\Validation\IsEmail;
use MikroApi\Attributes\Validation\IsUrl;
use MikroApi\Attributes\Validation\Matches;
use MikroApi\Attributes\Validation\IsIn;
use MikroApi\Attributes\Validation\MinLength;
use MikroApi\Attributes\Validation\MaxLength;
use MikroApi\Attributes\Validation\Length;
use MikroApi\Attributes\Validation\Min;
use MikroApi\Attributes\Validation\Max;
use MikroApi\Attributes\Validation\ArrayUnique;
use MikroApi\Attributes\Validation\ArrayOf;

class Validator
{
    /** @var array<string, string[]> campo => mensajes de error */
    private array $errors = [];

    /* ------------------------------------------------------------------ */
    /*  API pública                                                         */
    /* ------------------------------------------------------------------ */

    public function validate(string $dtoClass, array $data): ?object
    {
        $this->errors = [];
        $ref = new \ReflectionClass($dtoClass);
        $dto = $ref->newInstanceWithoutConstructor();

        // Primer pase: solo recopilar errores, sin tocar el DTO
        foreach ($ref->getProperties() as $prop) {
            $field      = $prop->getName();
            $value      = $data[$field] ?? null;
            $isOptional = !empty($prop->getAttributes(Optional::class));
            $isPresent  = array_key_exists($field, $data);

            if ($isOptional && !$isPresent) {
                continue;
            }

            foreach ($prop->getAttributes() as $attr) {
                $rule = $attr->newInstance();
                $this->applyRule($field, $value, $rule);
            }
        }

        // Si hay errores, retornar null sin tocar el DTO
        if (!empty($this->errors)) {
            return null;
        }

        // Segundo pase: asignar valores solo si todo es válido
        foreach ($ref->getProperties() as $prop) {
            $field      = $prop->getName();
            $value      = $data[$field] ?? null;
            $isOptional = !empty($prop->getAttributes(Optional::class));
            $isPresent  = array_key_exists($field, $data);

            if ($isOptional && !$isPresent) {
                continue;
            }

            if ($isPresent) {
                $prop->setValue($dto, $this->castValue($prop, $value));
            }
        }

        return $dto;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /* ------------------------------------------------------------------ */
    /*  Motor de reglas                                                     */
    /* ------------------------------------------------------------------ */

    private function applyRule(string $field, mixed $value, object $rule): void
    {
        $error = match (true) {

            // ── Presencia ──────────────────────────────────────────────
            $rule instanceof Required =>
                ($value === null || $value === '')
                    ? $this->msg($rule->message, $field)
                    : null,

            // ── Tipos ──────────────────────────────────────────────────
            $rule instanceof IsString =>
                ($value !== null && !\is_string($value))
                    ? $this->msg($rule->message, $field)
                    : null,

            $rule instanceof IsInt =>
                ($value !== null && \filter_var($value, FILTER_VALIDATE_INT) === false)
                    ? $this->msg($rule->message, $field)
                    : null,

            $rule instanceof IsFloat =>
                ($value !== null && \filter_var($value, FILTER_VALIDATE_FLOAT) === false)
                    ? $this->msg($rule->message, $field)
                    : null,

            $rule instanceof IsBool =>
                ($value !== null && !\in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true))
                    ? $this->msg($rule->message, $field)
                    : null,

            $rule instanceof IsArray =>
                ($value !== null && !\is_array($value))
                    ? $this->msg($rule->message, $field)
                    : null,

            // ── Formato ────────────────────────────────────────────────
            $rule instanceof IsEmail =>
                ($value !== null && !\filter_var($value, FILTER_VALIDATE_EMAIL))
                    ? $this->msg($rule->message, $field)
                    : null,

            $rule instanceof IsUrl =>
                ($value !== null && !\filter_var($value, FILTER_VALIDATE_URL))
                    ? $this->msg($rule->message, $field)
                    : null,

            $rule instanceof Matches =>
                ($value !== null && !\preg_match($rule->pattern, (string) $value))
                    ? $this->msg($rule->message, $field)
                    : null,

            $rule instanceof IsIn =>
                ($value !== null && !\in_array($value, $rule->values, true))
                    ? $this->msg(
                        \str_replace(':values', \implode(', ', $rule->values), $rule->message),
                        $field
                      )
                    : null,

            // ── Longitud ───────────────────────────────────────────────
            $rule instanceof MinLength =>
                ($value !== null && $this->strLen((string) $value) < $rule->min)
                    ? $this->msg(\str_replace(':min', (string) $rule->min, $rule->message), $field)
                    : null,

            $rule instanceof MaxLength =>
                ($value !== null && $this->strLen((string) $value) > $rule->max)
                    ? $this->msg(\str_replace(':max', (string) $rule->max, $rule->message), $field)
                    : null,

            $rule instanceof Length =>
                ($value !== null && ($this->strLen((string) $value) < $rule->min || $this->strLen((string) $value) > $rule->max))
                    ? $this->msg(
                        \str_replace([':min', ':max'], [$rule->min, $rule->max], $rule->message),
                        $field
                      )
                    : null,

            // ── Rango numérico ─────────────────────────────────────────
            $rule instanceof Min =>
                ($value !== null && (float) $value < $rule->min)
                    ? $this->msg(\str_replace(':min', (string) $rule->min, $rule->message), $field)
                    : null,

            $rule instanceof Max =>
                ($value !== null && (float) $value > $rule->max)
                    ? $this->msg(\str_replace(':max', (string) $rule->max, $rule->message), $field)
                    : null,

            // ── Arrays ─────────────────────────────────────────────────
            $rule instanceof ArrayUnique =>
                ($value !== null && \is_array($value) && \count($value) !== \count(\array_unique($value)))
                    ? $this->msg($rule->message, $field)
                    : null,

            $rule instanceof ArrayOf =>
                ($value !== null && \is_array($value) && !$this->arrayAllOfType($value, $rule->type))
                    ? $this->msg(\str_replace(':type', $rule->type, $rule->message), $field)
                    : null,

            default => null,
        };

        if ($error !== null) {
            $this->errors[$field][] = $error;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function msg(string $template, string $field): string
    {
        return \str_replace(':field', $field, $template);
    }

    private function arrayAllOfType(array $arr, string $type): bool
    {
        foreach ($arr as $item) {
            $ok = match ($type) {
                'string' => \is_string($item),
                'int'    => \is_int($item) || \filter_var($item, FILTER_VALIDATE_INT) !== false,
                'float'  => \is_float($item) || \filter_var($item, FILTER_VALIDATE_FLOAT) !== false,
                'bool'   => \is_bool($item),
                default  => true,
            };
            if (!$ok) return false;
        }
        return true;
    }

    private function castValue(\ReflectionProperty $prop, mixed $value): mixed
    {
        if ($value === null) return null;

        $type = $prop->getType();
        if (!$type instanceof \ReflectionNamedType) return $value;

        return match ($type->getName()) {
            'int'    => (int)    $value,
            'float'  => (float)  $value,
            'bool'   => \filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $value,
            default  => $value,
        };
    }
    /**
     * Cuenta caracteres de forma segura sin requerir mbstring.
     * Usa iconv_strlen si está disponible, sino strlen.
     */
    private function strLen(string $value): int
    {
        if (\function_exists('mb_strlen')) {
            return \mb_strlen($value);
        }
        if (\function_exists('iconv_strlen')) {
            return \iconv_strlen($value, 'UTF-8') ?: \strlen($value);
        }
        return \strlen($value);
    }
}
