<?php

namespace MikroApi\Module;

use MikroApi\Attributes\Module;
use MikroApi\Container;

/**
 * Recorre el grafo de módulos a partir de uno raíz, crea un ModuleContainer
 * por módulo, registra sus providers y valida los exports. Las importaciones
 * circulares están permitidas: la resolución de dependencias es perezosa.
 *
 * @internal Se usa a través de App::useModule().
 */
final class ModuleLoader
{
    /** @var array<string, ModuleRef> */
    private array $modules = [];

    /** @var ModuleRef[] en orden de dependencias (importados primero) */
    private array $order = [];

    /** @var array<string, DynamicModule> configuraciones registradas por clase */
    private array $dynamic = [];

    /** @var array<string, ModuleRef[]> ID de provider → módulos que lo declaran */
    private array $owners = [];

    /** @var ModuleRef[] */
    private array $globals = [];

    public function __construct(private Container $root) {}

    /**
     * Carga un módulo (y todo lo que importa). Idempotente por clase.
     * Retorna los módulos cargados por esta llamada, en orden de dependencias.
     *
     * @return ModuleRef[]
     */
    public function load(string|DynamicModule $module): array
    {
        if ($module instanceof DynamicModule) {
            if (isset($this->modules[$module->module])) {
                throw new \LogicException(
                    "El módulo {$module->module} ya fue cargado: registra su versión dinámica "
                    . '(forRoot) antes que los módulos que lo importan.'
                );
            }
            $this->dynamic[$module->module] = $module;
            $class = $module->module;
        } else {
            $class = $module;
        }

        $before = \count($this->order);
        $this->loadClass($class);
        return \array_slice($this->order, $before);
    }

    /** @return ModuleRef[] */
    public function modules(): array
    {
        return $this->order;
    }

    /** @return ModuleRef[] */
    public function globalModules(): array
    {
        return $this->globals;
    }

    /** @return ModuleRef[] módulos que declaran $id como provider */
    public function ownersOf(string $id): array
    {
        return $this->owners[$id] ?? [];
    }

    /* ------------------------------------------------------------------ */

    private function loadClass(string $class): ModuleRef
    {
        if (isset($this->modules[$class])) {
            return $this->modules[$class];
        }

        if (!\class_exists($class)) {
            throw new \RuntimeException("Módulo no encontrado: {$class}");
        }

        $meta    = $this->metadata($class);
        $ref     = new ModuleRef($class, $meta['global']);
        $ref->container   = new ModuleContainer($ref, $this->root, $this);
        $ref->controllers = $meta['controllers'];
        // La clase del módulo es singleton en su propio container (hooks de ciclo de vida)
        $ref->container->singleton($class);
        $this->modules[$class] = $ref; // antes de las imports: permite ciclos

        if ($ref->global) {
            $this->globals[] = $ref;
        }

        foreach ($meta['providers'] as $provider) {
            $this->registerProvider($ref, $provider);
        }

        foreach ($meta['imports'] as $import) {
            if ($import instanceof DynamicModule) {
                $this->dynamic[$import->module] ??= $import;
                $import = $import->module;
            }
            $ref->imports[] = $this->loadClass($import);
        }

        $this->registerExports($ref, $meta['exports']);
        $this->order[] = $ref;

        return $ref;
    }

    /**
     * Combina el atributo #[Module] con la configuración dinámica (si existe).
     *
     * @return array{imports:array, controllers:array, providers:array, exports:array, global:bool}
     */
    private function metadata(string $class): array
    {
        $attrs   = (new \ReflectionClass($class))->getAttributes(Module::class);
        $dynamic = $this->dynamic[$class] ?? null;

        if (empty($attrs) && $dynamic === null) {
            throw new \LogicException("{$class} no es un módulo: falta el atributo #[Module].");
        }

        /** @var Module|null $attr */
        $attr = empty($attrs) ? null : $attrs[0]->newInstance();

        return [
            'imports'     => \array_merge($attr->imports ?? [], $dynamic->imports ?? []),
            'controllers' => \array_merge($attr->controllers ?? [], $dynamic->controllers ?? []),
            'providers'   => \array_merge($attr->providers ?? [], $dynamic->providers ?? []),
            'exports'     => \array_merge($attr->exports ?? [], $dynamic->exports ?? []),
            'global'      => $dynamic?->global ?? $attr?->global ?? false,
        ];
    }

    private function registerProvider(ModuleRef $ref, string|array $provider): void
    {
        $container = $ref->container;

        if (\is_string($provider)) {
            $provider = ['provide' => $provider, 'useClass' => $provider];
        }

        $id = $provider['provide'] ?? null;
        if (!\is_string($id) || $id === '') {
            throw new \LogicException("Provider inválido en {$ref->class}: falta 'provide'.");
        }

        unset($ref->providerClasses[$id]); // por si redefine un provider anterior

        if (\array_key_exists('useValue', $provider)) {
            $value = $provider['useValue'];
            $container->singleton($id, fn() => $value);
            if (\is_object($value)) {
                $ref->providerClasses[$id] = $value::class;
            }
        } elseif (isset($provider['useFactory'])) {
            $factory = $provider['useFactory'];
            $inject  = $provider['inject'] ?? [];
            if (!\is_callable($factory)) {
                throw new \LogicException("useFactory de '{$id}' en {$ref->class} no es invocable.");
            }
            $container->singleton($id, fn(Container $c) => $factory(...\array_map(fn($dep) => $c->get($dep), $inject)));
        } elseif (isset($provider['useExisting'])) {
            $existing = $provider['useExisting'];
            $container->singleton($id, fn(Container $c) => $c->get($existing));
        } else {
            $class = $provider['useClass'] ?? $id;
            if (!\class_exists($class)) {
                throw new \LogicException("Provider '{$id}' en {$ref->class}: la clase {$class} no existe.");
            }
            $container->singleton($id, $class);
            $ref->providerClasses[$id] = $class;
        }

        // Un ID repetido (ej. forRoot sobrescribiendo el atributo) reemplaza al anterior
        if (!\in_array($id, $ref->providerIds, true)) {
            $ref->providerIds[]  = $id;
            $this->owners[$id][] = $ref;
        }
    }

    private function registerExports(ModuleRef $ref, array $exports): void
    {
        $importsByClass = [];
        foreach ($ref->imports as $import) {
            $importsByClass[$import->class] = $import;
        }

        foreach ($exports as $export) {
            if ($export instanceof DynamicModule) {
                $export = $export->module;
            }

            if (\in_array($export, $ref->providerIds, true)) {
                $ref->exportedProviders[] = $export;
            } elseif (isset($importsByClass[$export])) {
                $ref->exportedModules[] = $importsByClass[$export];
            } else {
                throw new \LogicException(
                    "{$ref->class} exporta '{$export}', que no es uno de sus providers ni un módulo importado."
                );
            }
        }
    }
}
