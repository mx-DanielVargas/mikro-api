<?php

namespace MikroApi;

use MikroApi\Attributes\Body;
use MikroApi\Attributes\CurrentUser;
use MikroApi\Attributes\Headers;
use MikroApi\Attributes\Param;
use MikroApi\Attributes\Query;
use MikroApi\Exception\BadRequestException;
use MikroApi\Exception\UnauthorizedException;
use MikroApi\Exception\ValidationException;

/**
 * Resuelve los argumentos de un método de controlador a partir de la
 * petición (equivalente a los param decorators + pipes de NestJS).
 *
 * describe() se ejecuta una vez al registrar la ruta y produce una
 * especificación serializable con var_export (compatible con el caché
 * de rutas). resolve() la usa en cada petición, sin reflexión.
 *
 * Reglas por parámetro:
 *   #[Param('id')] int $id        → parámetro de ruta convertido a int (400 si no es válido)
 *   #[Query('page')] int $page = 1 → query string; default/nullable si falta, 400 si es requerido
 *   #[Query] ListDto $q           → todo el query validado contra el DTO (422 si falla)
 *   #[Body] CreateDto $dto        → body validado contra el DTO (422 si falla)
 *   #[Body('email')] string $e    → un campo del body
 *   #[Headers('X-Tenant')] ?string → un header
 *   #[CurrentUser] array $user    → $request->user
 *   Request $req / sin tipo       → la Request (compatibilidad con handlers clásicos)
 *   ExecutionContext $ctx         → el contexto de ejecución
 */
final class ArgumentResolver
{
    private const SOURCE_ATTRIBUTES = [
        Param::class       => 'param',
        Query::class       => 'query',
        Headers::class     => 'header',
        Body::class        => 'body',
        CurrentUser::class => 'user',
    ];

    private const SOURCE_LABELS = [
        'param'  => 'El parámetro de ruta',
        'query'  => 'El parámetro de query',
        'header' => 'El header',
        'body'   => 'El campo del body',
        'user'   => 'El campo del usuario',
    ];

    /**
     * @return array<int, array{name:string, source:string, key:?string, type:?string, builtin:bool, nullable:bool, optional:bool, default:mixed, dto:?string}>
     */
    public static function describe(\ReflectionMethod $method): array
    {
        $specs = [];

        foreach ($method->getParameters() as $param) {
            $type     = $param->getType();
            $named    = $type instanceof \ReflectionNamedType ? $type : null;
            $typeName = $named?->getName();
            $builtin  = $named === null || $named->isBuiltin();
            $optional = $param->isDefaultValueAvailable();

            $spec = [
                'name'     => $param->getName(),
                'source'   => null,
                'key'      => null,
                'type'     => $typeName,
                'builtin'  => $builtin,
                'nullable' => $type === null || $type->allowsNull(),
                'optional' => $optional,
                'default'  => $optional ? $param->getDefaultValue() : null,
                'dto'      => null,
            ];

            foreach (self::SOURCE_ATTRIBUTES as $attrClass => $source) {
                $attrs = $param->getAttributes($attrClass);
                if (empty($attrs)) continue;

                $attr           = $attrs[0]->newInstance();
                $spec['source'] = $source;
                $value          = $attr instanceof Body ? $attr->dtoClass : $attr->name;
                $isDtoType      = !$builtin && $typeName !== null && \class_exists($typeName) && !\enum_exists($typeName);

                if ($attr instanceof Body && $value !== null && \class_exists($value)) {
                    $spec['dto'] = $value;
                } elseif ($value !== null) {
                    $spec['key'] = $source === 'header' ? \strtoupper($value) : $value;
                } elseif ($isDtoType && $source !== 'user') {
                    $spec['dto'] = $typeName;
                }
                break;
            }

            if ($spec['source'] === null) {
                $spec['source'] = match (true) {
                    $typeName === null, $typeName === 'mixed',
                    $typeName === Request::class           => 'request',
                    $typeName === ExecutionContext::class  => 'context',
                    $optional || $spec['nullable']         => 'default',
                    default => throw new \LogicException(\sprintf(
                        'No se puede inyectar el parámetro $%s de %s::%s(): usa #[Param], #[Query], #[Body], '
                        . '#[Headers] o #[CurrentUser], o tipéalo como Request.',
                        $param->getName(),
                        $method->getDeclaringClass()->getName(),
                        $method->getName(),
                    )),
                };
            }

            $specs[] = $spec;
        }

        return $specs;
    }

    /**
     * @param array<int, array> $specs Resultado de describe()
     * @return array<int, mixed>
     */
    public static function resolve(array $specs, Request $request, ?ExecutionContext $context): array
    {
        $args = [];
        foreach ($specs as $spec) {
            $args[] = self::resolveOne($spec, $request, $context);
        }
        return $args;
    }

    private static function resolveOne(array $spec, Request $request, ?ExecutionContext $context): mixed
    {
        switch ($spec['source']) {
            case 'request': return $request;
            case 'context': return $context;
            case 'default': return $spec['default'];
        }

        if ($spec['source'] === 'user') {
            $user = $request->user;
            if ($spec['key'] !== null) {
                $user = \is_array($user) ? ($user[$spec['key']] ?? null)
                      : (\is_object($user) ? ($user->{$spec['key']} ?? null) : null);
                return self::convert($user, $spec);
            }
            if ($user === null && !$spec['nullable']) {
                throw new UnauthorizedException();
            }
            return $user;
        }

        $data = match ($spec['source']) {
            'param'  => $request->params,
            'query'  => $request->query,
            'header' => $request->headers(),
            'body'   => $request->body,
        };

        if ($spec['dto'] !== null) {
            $validator = new Validator();
            $dto       = $validator->validate($spec['dto'], $data);
            if ($validator->hasErrors()) {
                throw new ValidationException($validator->getErrors());
            }
            if ($spec['source'] === 'body') {
                $request->dto = $dto;
            }
            return $dto;
        }

        if ($spec['key'] === null) {
            return $data;
        }

        return self::convert($data[$spec['key']] ?? null, $spec);
    }

    /**
     * Convierte un valor crudo (normalmente string) al tipo declarado del
     * parámetro. Lanza 400 si falta siendo requerido o si no es convertible.
     */
    private static function convert(mixed $raw, array $spec): mixed
    {
        $type  = $spec['type'];
        $label = self::SOURCE_LABELS[$spec['source']] . " '{$spec['key']}'";

        // Un string vacío cuenta como ausente para tipos no-string (?page=)
        $missing = $raw === null || ($raw === '' && $type !== null && $type !== 'string' && $type !== 'mixed');
        if ($missing) {
            if ($spec['optional']) return $spec['default'];
            if ($spec['nullable']) return null;
            throw new BadRequestException("{$label} es obligatorio");
        }

        if ($type === null || $type === 'mixed') {
            return $raw;
        }

        switch ($type) {
            case 'int':
                if (\is_int($raw)) return $raw;
                $value = \is_string($raw) ? \filter_var($raw, FILTER_VALIDATE_INT) : false;
                if ($value === false) throw new BadRequestException("{$label} debe ser un número entero");
                return $value;

            case 'float':
                if (\is_float($raw) || \is_int($raw)) return (float) $raw;
                $value = \is_string($raw) ? \filter_var($raw, FILTER_VALIDATE_FLOAT) : false;
                if ($value === false) throw new BadRequestException("{$label} debe ser un número");
                return $value;

            case 'bool':
                if (\is_bool($raw)) return $raw;
                $value = \is_scalar($raw) ? \filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
                if ($value === null) throw new BadRequestException("{$label} debe ser un booleano");
                return $value;

            case 'string':
                if (!\is_scalar($raw)) throw new BadRequestException("{$label} debe ser un texto");
                return (string) $raw;

            case 'array':
                if (!\is_array($raw)) throw new BadRequestException("{$label} debe ser un arreglo");
                return $raw;
        }

        if (\enum_exists($type)) {
            return self::convertEnum($raw, $type, $label);
        }

        return $raw;
    }

    private static function convertEnum(mixed $raw, string $enumClass, string $label): \UnitEnum
    {
        if ($raw instanceof $enumClass) {
            return $raw;
        }

        $ref = new \ReflectionEnum($enumClass);
        if ($ref->isBacked()) {
            $backing = (string) $ref->getBackingType();
            $value   = $backing === 'int' && \is_string($raw) && \filter_var($raw, FILTER_VALIDATE_INT) !== false
                ? (int) $raw
                : $raw;
            $case = \is_scalar($value) ? $enumClass::tryFrom($value) : null;
            $allowed = \array_map(fn($c) => (string) $c->value, $enumClass::cases());
        } else {
            $case = null;
            foreach ($enumClass::cases() as $c) {
                if ($c->name === $raw) { $case = $c; break; }
            }
            $allowed = \array_map(fn($c) => $c->name, $enumClass::cases());
        }

        if ($case === null) {
            throw new BadRequestException("{$label} debe ser uno de: " . \implode(', ', $allowed));
        }
        return $case;
    }
}
