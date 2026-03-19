<?php

namespace MikroApi;

/**
 * Contenedor de inyección de dependencias con autowiring.
 *
 * Uso:
 *   $container = new Container();
 *   $container->set(DatabaseInterface::class, fn($c) => new Database($config));
 *   $container->set(UserRepository::class);  // autowiring automático
 *
 *   $controller = $container->make(UserController::class);
 */
class Container
{
    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /**
     * Registra un binding. Si $factory es null, se resuelve por autowiring.
     */
    public function set(string $id, callable|string|null $factory = null): void
    {
        if ($factory === null || is_string($factory)) {
            $class = is_string($factory) ? $factory : $id;
            $this->factories[$id] = fn() => $this->autowire($class);
        } else {
            $this->factories[$id] = $factory;
        }
    }

    /**
     * Registra una instancia singleton ya creada.
     */
    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /**
     * Registra un binding singleton (se instancia una sola vez).
     */
    public function singleton(string $id, callable|string|null $factory = null): void
    {
        $original = $factory;
        if ($original === null || is_string($original)) {
            $class = is_string($original) ? $original : $id;
            $original = fn() => $this->autowire($class);
        }

        $this->factories[$id] = function () use ($id, $original) {
            if (!isset($this->instances[$id])) {
                $this->instances[$id] = $original($this);
            }
            return $this->instances[$id];
        };
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    /**
     * Resuelve una dependencia por su ID.
     */
    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->factories[$id])) {
            return ($this->factories[$id])($this);
        }

        // Autowiring implícito si la clase existe
        if (class_exists($id)) {
            return $this->autowire($id);
        }

        throw new \RuntimeException("No se puede resolver: {$id}");
    }

    /**
     * Crea una instancia resolviendo dependencias del constructor.
     */
    public function make(string $class): object
    {
        return $this->autowire($class);
    }

    private function autowire(string $class): object
    {
        $ref = new \ReflectionClass($class);

        if (!$ref->isInstantiable()) {
            throw new \RuntimeException("La clase {$class} no es instanciable.");
        }

        $constructor = $ref->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->get($type->getName());
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new \RuntimeException(
                    "No se puede resolver el parámetro \${$param->getName()} de {$class}."
                );
            }
        }

        return $ref->newInstanceArgs($args);
    }
}
