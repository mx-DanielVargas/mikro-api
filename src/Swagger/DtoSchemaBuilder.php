<?php

namespace MikroApi\Swagger;

use MikroApi\Attributes\Validation\IsArray;
use MikroApi\Attributes\Validation\IsBool;
use MikroApi\Attributes\Validation\IsEmail;
use MikroApi\Attributes\Validation\IsFloat;
use MikroApi\Attributes\Validation\IsIn;
use MikroApi\Attributes\Validation\IsInt;
use MikroApi\Attributes\Validation\IsString;
use MikroApi\Attributes\Validation\IsUrl;
use MikroApi\Attributes\Validation\Length;
use MikroApi\Attributes\Validation\Matches;
use MikroApi\Attributes\Validation\Max;
use MikroApi\Attributes\Validation\MaxLength;
use MikroApi\Attributes\Validation\Min;
use MikroApi\Attributes\Validation\MinLength;
use MikroApi\Attributes\Validation\Optional;
use MikroApi\Attributes\Validation\Required;

/**
 * Convierte un DTO decorado con atributos de validación
 * en un JSON Schema compatible con OpenAPI 3.0.
 *
 * Los atributos de validación existentes se mapean directamente:
 *   #[IsEmail]      → "format": "email"
 *   #[MinLength(2)] → "minLength": 2
 *   #[IsIn([...])]  → "enum": [...]
 *   etc.
 */
class DtoSchemaBuilder
{
    /**
     * Genera el objeto `schema` de OpenAPI para un DTO.
     *
     * @return array{type: string, required: string[], properties: array}
     */
    public function build(string $dtoClass): array
    {
        $ref        = new \ReflectionClass($dtoClass);
        $required   = [];
        $properties = [];

        foreach ($ref->getProperties() as $prop) {
            $name       = $prop->getName();
            $attrs      = $prop->getAttributes();
            $propSchema = [];

            $isRequired = false;
            $isOptional = false;

            foreach ($attrs as $attr) {
                $rule = $attr->newInstance();

                // ── Presencia ────────────────────────────────────────
                if ($rule instanceof Required) {
                    $isRequired = true;
                } elseif ($rule instanceof Optional) {
                    $isOptional = true;

                // ── Tipos ────────────────────────────────────────────
                } elseif ($rule instanceof IsString) {
                    $propSchema['type'] = 'string';
                } elseif ($rule instanceof IsInt) {
                    $propSchema['type'] = 'integer';
                } elseif ($rule instanceof IsFloat) {
                    $propSchema['type'] = 'number';
                } elseif ($rule instanceof IsBool) {
                    $propSchema['type'] = 'boolean';
                } elseif ($rule instanceof IsArray) {
                    $propSchema['type'] = 'array';

                // ── Formato ──────────────────────────────────────────
                } elseif ($rule instanceof IsEmail) {
                    $propSchema['type']   = 'string';
                    $propSchema['format'] = 'email';
                } elseif ($rule instanceof IsUrl) {
                    $propSchema['type']   = 'string';
                    $propSchema['format'] = 'uri';
                } elseif ($rule instanceof Matches) {
                    $propSchema['pattern'] = $rule->pattern;
                } elseif ($rule instanceof IsIn) {
                    $propSchema['enum'] = $rule->values;

                // ── Longitud ─────────────────────────────────────────
                } elseif ($rule instanceof Length) {
                    $propSchema['minLength'] = $rule->min;
                    $propSchema['maxLength'] = $rule->max;
                } elseif ($rule instanceof MinLength) {
                    $propSchema['minLength'] = $rule->min;
                } elseif ($rule instanceof MaxLength) {
                    $propSchema['maxLength'] = $rule->max;

                // ── Rango numérico ───────────────────────────────────
                } elseif ($rule instanceof Min) {
                    $propSchema['minimum'] = $rule->min;
                } elseif ($rule instanceof Max) {
                    $propSchema['maximum'] = $rule->max;
                }
            }

            // Inferir tipo desde el tipo PHP si no se definió explícitamente
            if (!isset($propSchema['type'])) {
                $propSchema = \array_merge($propSchema, $this->inferTypeFromPhp($prop));
            }

            // Marcar como nullable si el tipo PHP es nullable (?string, ?int...)
            if ($this->isNullable($prop)) {
                $propSchema['nullable'] = true;
            }

            // Incluir valor por defecto si existe
            if ($prop->hasDefaultValue() && $prop->getDefaultValue() !== null) {
                $propSchema['default'] = $prop->getDefaultValue();
            }

            $properties[$name] = $propSchema ?: ['type' => 'string'];

            if ($isRequired && !$isOptional) {
                $required[] = $name;
            }
        }

        $schema = [
            'type'       => 'object',
            'properties' => $properties,
        ];

        if (!empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function inferTypeFromPhp(\ReflectionProperty $prop): array
    {
        $type = $prop->getType();
        if (!$type instanceof \ReflectionNamedType) return [];

        return match ($type->getName()) {
            'string' => ['type' => 'string'],
            'int'    => ['type' => 'integer'],
            'float'  => ['type' => 'number'],
            'bool'   => ['type' => 'boolean'],
            'array'  => ['type' => 'array'],
            default  => [],
        };
    }

    private function isNullable(\ReflectionProperty $prop): bool
    {
        $type = $prop->getType();
        return $type !== null && $type->allowsNull();
    }
}