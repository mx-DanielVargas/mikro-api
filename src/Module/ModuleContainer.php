<?php

namespace MikroApi\Module;

use MikroApi\Container;

/**
 * Container de un módulo. Resuelve, en este orden:
 *
 *   1. Providers propios del módulo (singletons).
 *   2. Exports de los módulos importados.
 *   3. Exports de los módulos globales.
 *   4. Lo registrado en el container raíz de App (compatibilidad).
 *   5. Autowiring, salvo que el ID sea provider de otro módulo no
 *      accesible: en ese caso falla con un mensaje explicativo.
 */
class ModuleContainer extends Container
{
    public function __construct(
        private ModuleRef $module,
        private Container $root,
        private ModuleLoader $loader,
    ) {}

    public function get(string $id): mixed
    {
        if (parent::has($id)) {
            return parent::get($id);
        }

        $provider = $this->findExporter($id);
        if ($provider !== null) {
            return $provider->container->get($id);
        }

        if ($this->root->has($id)) {
            return $this->root->get($id);
        }

        $owners = $this->loader->ownersOf($id);
        if (!empty($owners)) {
            $names = \implode(', ', \array_map(fn(ModuleRef $m) => $m->class, $owners));
            throw new \RuntimeException(
                "No se puede resolver {$id} en {$this->module->class}: es provider de {$names}. "
                . "Agrégalo a 'exports' de ese módulo e impórtalo en {$this->module->class} "
                . "(o márcalo como global)."
            );
        }

        return parent::get($id); // autowiring
    }

    public function has(string $id): bool
    {
        return parent::has($id) || $this->findExporter($id) !== null || $this->root->has($id);
    }

    /** ¿Es provider propio de este módulo? */
    public function hasOwn(string $id): bool
    {
        return parent::has($id);
    }

    private function findExporter(string $id): ?ModuleRef
    {
        foreach ($this->module->imports as $import) {
            if ($import->exportsId($id)) {
                return $import->resolveExporter($id);
            }
        }
        foreach ($this->loader->globalModules() as $global) {
            if ($global !== $this->module && $global->exportsId($id)) {
                return $global->resolveExporter($id);
            }
        }
        return null;
    }
}
