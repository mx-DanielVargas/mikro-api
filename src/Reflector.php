<?php

namespace MikroApi;

use MikroApi\Attributes\SetMetadata;

/**
 * Lee metadatos declarados con #[SetMetadata] (o atributos que lo extienden)
 * en el controlador/método de la ruta actual. Equivalente a Reflector de
 * NestJS; se inyecta por constructor en guards, interceptors o filtros.
 *
 *   class RolesGuard implements GuardInterface
 *   {
 *       public function __construct(private Reflector $reflector) {}
 *
 *       public function canActivate(Request $request): bool
 *       {
 *           $roles = $this->reflector->getAllAndOverride('roles', $request->context);
 *           ...
 *       }
 *   }
 */
class Reflector
{
    /** @var array<string, array<string, mixed[]>> "Clase::metodo" → [key → valores] */
    private static array $cache = [];

    /**
     * Valor del método si existe; si no, el de la clase; si no, null.
     */
    public function getAllAndOverride(string $key, ?ExecutionContext $context): mixed
    {
        if ($context === null) return null;

        $handler = $this->getHandlerMetadata($key, $context);
        if ($handler !== null) return $handler;

        return $this->getClassMetadata($key, $context);
    }

    /** Alias de getAllAndOverride(). */
    public function get(string $key, ?ExecutionContext $context): mixed
    {
        return $this->getAllAndOverride($key, $context);
    }

    /**
     * Combina los valores del método y de la clase en una sola lista
     * (los arrays se aplanan; los escalares se agregan tal cual).
     */
    public function getAllAndMerge(string $key, ?ExecutionContext $context): array
    {
        if ($context === null) return [];

        $merged = [];
        foreach ([$this->collect($key, $context, true), $this->collect($key, $context, false)] as $values) {
            foreach ($values as $value) {
                \is_array($value) ? \array_push($merged, ...\array_values($value)) : $merged[] = $value;
            }
        }
        return $merged;
    }

    public function has(string $key, ?ExecutionContext $context): bool
    {
        return $context !== null
            && (!empty($this->collect($key, $context, true)) || !empty($this->collect($key, $context, false)));
    }

    public function getHandlerMetadata(string $key, ExecutionContext $context): mixed
    {
        return $this->collect($key, $context, true)[0] ?? null;
    }

    public function getClassMetadata(string $key, ExecutionContext $context): mixed
    {
        return $this->collect($key, $context, false)[0] ?? null;
    }

    /**
     * Primera instancia de un atributo arbitrario (no necesariamente
     * SetMetadata) en el método, o en la clase si el método no lo tiene.
     *
     * @template T of object
     * @param class-string<T> $attributeClass
     * @return T|null
     */
    public function getAttribute(string $attributeClass, ?ExecutionContext $context): ?object
    {
        if ($context === null) return null;

        foreach ([$context->getHandlerReflection(), $context->getClassReflection()] as $ref) {
            $attrs = $ref->getAttributes($attributeClass, \ReflectionAttribute::IS_INSTANCEOF);
            if (!empty($attrs)) {
                return $attrs[0]->newInstance();
            }
        }
        return null;
    }

    /** @return mixed[] valores de $key en el método ($handler) o en la clase */
    private function collect(string $key, ExecutionContext $context, bool $handler): array
    {
        $cacheKey = $context->getClass() . ($handler ? '::' . $context->getHandler() : '');

        if (!isset(self::$cache[$cacheKey])) {
            $ref = $handler ? $context->getHandlerReflection() : $context->getClassReflection();
            $map = [];
            foreach ($ref->getAttributes(SetMetadata::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                /** @var SetMetadata $meta */
                $meta = $attr->newInstance();
                $map[$meta->key][] = $meta->value;
            }
            self::$cache[$cacheKey] = $map;
        }

        return self::$cache[$cacheKey][$key] ?? [];
    }
}
