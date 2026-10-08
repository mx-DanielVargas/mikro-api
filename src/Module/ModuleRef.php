<?php

namespace MikroApi\Module;

/**
 * Representación cargada de un módulo: su metadata resuelta, su container
 * y referencias a los módulos que importa.
 *
 * @internal
 */
final class ModuleRef
{
    /** @var ModuleRef[] */
    public array $imports = [];

    /** @var string[] IDs de providers propios */
    public array $providerIds = [];

    /** @var array<string, string> ID → clase concreta (para hooks de ciclo de vida) */
    public array $providerClasses = [];

    /** @var string[] IDs de providers propios exportados */
    public array $exportedProviders = [];

    /** @var ModuleRef[] módulos importados que este re-exporta */
    public array $exportedModules = [];

    /** @var string[] */
    public array $controllers = [];

    public ModuleContainer $container;

    public function __construct(
        public readonly string $class,
        public bool $global = false,
    ) {}

    /** ¿Este módulo hace visible $id a quien lo importe? */
    public function exportsId(string $id, array $visited = []): bool
    {
        return $this->resolveExporter($id, $visited) !== null;
    }

    /** Módulo que realmente posee el provider exportado (sigue re-exports). */
    public function resolveExporter(string $id, array $visited = []): ?ModuleRef
    {
        if (isset($visited[$this->class])) {
            return null; // ciclo de re-exports
        }
        $visited[$this->class] = true;

        if (\in_array($id, $this->exportedProviders, true)) {
            return $this;
        }
        foreach ($this->exportedModules as $module) {
            if (($owner = $module->resolveExporter($id, $visited)) !== null) {
                return $owner;
            }
        }
        return null;
    }
}
