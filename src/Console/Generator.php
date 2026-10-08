<?php

namespace MikroApi\Console;

/**
 * Generadores de código del CLI (make:*).
 *
 * Sin --module, los archivos van a carpetas por tipo (src/Controllers,
 * src/Services, ...). Con --module=X van a la carpeta del módulo
 * (src/X/) y, si corresponde, se registran en su #[Module]:
 * controladores en `controllers`, servicios y repositorios en `providers`.
 */
final class Generator
{
    public const TYPES = [
        'resource'    => 'Module + controller + service + repository + DTOs + migration (CRUD)',
        'module'      => 'Feature module (registered in AppModule imports)',
        'controller'  => 'Controller with CRUD routes',
        'service'     => 'Service (business logic)',
        'repository'  => 'Repository (BaseRepository)',
        'dto'         => 'Validation DTO',
        'guard'       => 'Guard',
        'interceptor' => 'Interceptor',
        'filter'      => 'Exception filter',
        'middleware'  => 'Middleware',
        'attribute'   => 'Route metadata attribute (SetMetadata)',
        'migration'   => 'Database migration',
        'view'        => 'Template view',
        'test'        => 'PHPUnit HTTP test',
    ];

    private ?string $rootNamespace = null;

    public function __construct(
        private string $root,
        private Output $out,
        private bool $force = false,
    ) {}

    /** @return bool true si todo se generó */
    public function make(string $type, string $name, ?string $module = null, array $options = []): bool
    {
        return match ($type) {
            'resource'    => $this->resource($name),
            'module'      => $this->module($name),
            'controller'  => $this->controller($name, $module),
            'service'     => $this->service($name, $module),
            'repository'  => $this->repository($name, $module),
            'dto'         => $this->dto($name, $module),
            'guard'       => $this->simple('GUARD', 'Guard', 'Guards', $name, $module),
            'interceptor' => $this->simple('INTERCEPTOR', 'Interceptor', 'Interceptors', $name, $module),
            'filter'      => $this->simple('FILTER', 'Filter', 'Filters', $name, $module),
            'middleware'  => $this->simple('MIDDLEWARE', 'Middleware', 'Middleware', $name, null),
            'attribute'   => $this->attribute($name, $module),
            'migration'   => $this->migration($name),
            'view'        => $this->view($name),
            'test'        => $this->test($name, $options['route'] ?? null),
            default       => throw new \InvalidArgumentException("Unknown generator type: {$type}"),
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Generators                                                           */
    /* ------------------------------------------------------------------ */

    public function resource(string $name): bool
    {
        $singular = Naming::singular(Naming::studly($name));
        $plural   = Naming::plural($singular);
        $dir      = "src/{$plural}";
        $ns       = $this->ns($plural);

        if (\is_file("{$this->root}/{$dir}/{$plural}Module.php") && !$this->force) {
            $this->out->error("Module {$plural}Module already exists ({$dir}/). Use --force to overwrite.");
            return false;
        }

        $vars = [
            'namespace'  => $ns,
            'controller' => "{$plural}Controller",
            'service'    => "{$plural}Service",
            'serviceVar' => Naming::camel($plural),
            'repository' => "{$plural}Repository",
            'createDto'  => "Create{$singular}Dto",
            'updateDto'  => "Update{$singular}Dto",
            'singular'   => $singular,
            'route'      => '/' . Naming::kebab($plural),
        ];

        $ok = $this->write("{$dir}/{$plural}Module.php", Stubs::render('RESOURCE_MODULE', $vars + ['class' => "{$plural}Module"]));
        $ok = $this->write("{$dir}/{$plural}Controller.php", Stubs::render('RESOURCE_CONTROLLER', $vars + ['class' => "{$plural}Controller"])) && $ok;
        $ok = $this->write("{$dir}/{$plural}Service.php", Stubs::render('RESOURCE_SERVICE', $vars + ['class' => "{$plural}Service"])) && $ok;
        $ok = $this->write("{$dir}/{$plural}Repository.php", Stubs::render('REPOSITORY', [
            'namespace'  => $ns,
            'class'      => "{$plural}Repository",
            'table'      => Naming::snake($plural),
            'fillable'   => "'name',",
            'foreignKey' => Naming::snake($singular) . '_id',
        ])) && $ok;
        $ok = $this->write("{$dir}/Dto/Create{$singular}Dto.php", Stubs::render('DTO', [
            'namespace' => "{$ns}\\Dto", 'class' => "Create{$singular}Dto", 'presence' => 'Required',
        ])) && $ok;
        $ok = $this->write("{$dir}/Dto/Update{$singular}Dto.php", Stubs::render('DTO', [
            'namespace' => "{$ns}\\Dto", 'class' => "Update{$singular}Dto", 'presence' => 'Optional',
        ])) && $ok;
        $ok = $this->migration('create_' . Naming::snake($plural) . '_table', <<<'PHP'

    #[Column(type: 'varchar', length: 255)]
    public string $name;
PHP) && $ok;

        $this->registerInAppModule("{$ns}\\{$plural}Module");

        $this->out->line();
        $this->out->info("Next: vendor/bin/mikro migrate — then try GET {$vars['route']}");
        return $ok;
    }

    public function module(string $name): bool
    {
        $base  = Naming::withoutSuffix(Naming::studly($name), 'Module');
        $class = "{$base}Module";

        $ok = $this->write("src/{$base}/{$class}.php", Stubs::render('MODULE', [
            'namespace' => $this->ns($base),
            'class'     => $class,
        ]));

        if ($ok) {
            $this->registerInAppModule($this->ns($base) . "\\{$class}");
        }
        return $ok;
    }

    public function controller(string $name, ?string $module = null): bool
    {
        $class = Naming::withSuffix(Naming::studly($name), 'Controller');
        $base  = Naming::withoutSuffix($class, 'Controller');
        [$dir, $ns] = $this->target('Controllers', $module);

        $ok = $this->write("{$dir}/{$class}.php", Stubs::render('CONTROLLER', [
            'namespace' => $ns,
            'class'     => $class,
            'route'     => '/' . Naming::kebab(Naming::plural($base)),
        ]));

        return $ok && $this->registerInModule($module, 'controllers', "{$ns}\\{$class}");
    }

    public function service(string $name, ?string $module = null): bool
    {
        $class = Naming::withSuffix(Naming::studly($name), 'Service');
        [$dir, $ns] = $this->target('Services', $module);

        $ok = $this->write("{$dir}/{$class}.php", Stubs::render('SERVICE', ['namespace' => $ns, 'class' => $class]));

        return $ok && $this->registerInModule($module, 'providers', "{$ns}\\{$class}");
    }

    public function repository(string $name, ?string $module = null): bool
    {
        $class = Naming::withSuffix(Naming::studly($name), 'Repository');
        $base  = Naming::withoutSuffix($class, 'Repository');
        [$dir, $ns] = $this->target('Repositories', $module);

        $ok = $this->write("{$dir}/{$class}.php", Stubs::render('REPOSITORY', [
            'namespace'  => $ns,
            'class'      => $class,
            'table'      => Naming::snake(Naming::plural($base)),
            'fillable'   => "// 'name', 'email', ...",
            'foreignKey' => Naming::snake(Naming::singular($base)) . '_id',
        ]));

        return $ok && $this->registerInModule($module, 'providers', "{$ns}\\{$class}");
    }

    public function dto(string $name, ?string $module = null): bool
    {
        $class = Naming::withSuffix(Naming::studly($name), 'Dto');
        [$dir, $ns] = $this->target('DTOs', $module, 'Dto');

        return $this->write("{$dir}/{$class}.php", Stubs::render('DTO', [
            'namespace' => $ns,
            'class'     => $class,
            'presence'  => \str_starts_with($class, 'Update') ? 'Optional' : 'Required',
        ]));
    }

    public function attribute(string $name, ?string $module = null): bool
    {
        $class = Naming::studly($name);
        [$dir, $ns] = $this->target('Attributes', $module);

        return $this->write("{$dir}/{$class}.php", Stubs::render('ATTRIBUTE', [
            'namespace' => $ns,
            'class'     => $class,
            'key'       => Naming::camel($class),
        ]));
    }

    public function migration(string $name, string $columns = ''): bool
    {
        $snake = Naming::snake($name);
        $table = \preg_match('/^create_(\w+)_table$/', $snake, $m) ? $m[1] : $snake;
        $file  = 'database/migrations/' . \date('Y_m_d_His') . "_{$snake}.php";

        return $this->write($file, Stubs::render('MIGRATION', [
            'class'   => Naming::studly($snake),
            'table'   => $table,
            'columns' => $columns === '' ? "\n    // #[Column(type: 'varchar', length: 255)]\n    // public string \$name;" : $columns,
        ]));
    }

    public function view(string $name): bool
    {
        $view = \trim(\str_replace('.', '/', $name), '/');

        return $this->write("views/{$view}.php", Stubs::render('VIEW', [
            'view' => $view,
            'name' => Naming::studly(\basename($view)),
        ]));
    }

    public function test(string $name, ?string $route = null): bool
    {
        $class     = Naming::withSuffix(Naming::studly($name), 'Test');
        $appModule = $this->rootNamespace() . 'AppModule';

        return $this->write("tests/{$class}.php", Stubs::render('TEST', [
            'namespace'      => $this->rootNamespace() . 'Tests',
            'class'          => $class,
            'appModule'      => $appModule,
            'appModuleShort' => 'AppModule',
            'route'          => $route ?? '/',
        ]));
    }

    /** guard / interceptor / filter / middleware */
    private function simple(string $stub, string $suffix, string $dirName, string $name, ?string $module): bool
    {
        $class = Naming::withSuffix(Naming::studly($name), $suffix);
        [$dir, $ns] = $this->target($dirName, $module);

        return $this->write("{$dir}/{$class}.php", Stubs::render($stub, ['namespace' => $ns, 'class' => $class]));
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                              */
    /* ------------------------------------------------------------------ */

    /** Namespace raíz del proyecto según composer.json (psr-4 → src/), por defecto App\ */
    public function rootNamespace(): string
    {
        if ($this->rootNamespace !== null) {
            return $this->rootNamespace;
        }

        $composer = @\json_decode((string) @\file_get_contents("{$this->root}/composer.json"), true);
        foreach ($composer['autoload']['psr-4'] ?? [] as $prefix => $path) {
            foreach ((array) $path as $p) {
                if (\in_array(\rtrim($p, '/'), ['src', './src'], true)) {
                    return $this->rootNamespace = $prefix;
                }
            }
        }
        return $this->rootNamespace = 'App\\';
    }

    private function ns(string $sub = ''): string
    {
        return \rtrim($this->rootNamespace() . $sub, '\\');
    }

    /** @return array{0: string, 1: string} [directorio relativo, namespace] */
    private function target(string $defaultDir, ?string $module, ?string $moduleSubdir = null): array
    {
        if ($module === null) {
            return ["src/{$defaultDir}", $this->ns($defaultDir)];
        }

        $base = Naming::withoutSuffix(Naming::studly($module), 'Module');
        if (!\is_file("{$this->root}/src/{$base}/{$base}Module.php")) {
            // Validar antes de escribir nada, para no dejar archivos huérfanos
            throw new \RuntimeException(
                "Module file not found: src/{$base}/{$base}Module.php (create it with: mikro make:module {$base})"
            );
        }
        $dir  = "src/{$base}" . ($moduleSubdir ? "/{$moduleSubdir}" : '');
        $ns   = $this->ns($base) . ($moduleSubdir ? "\\{$moduleSubdir}" : '');
        return [$dir, $ns];
    }

    private function registerInModule(?string $module, string $key, string $fqcn): bool
    {
        if ($module === null) {
            return true;
        }

        $base = Naming::withoutSuffix(Naming::studly($module), 'Module');
        $file = "src/{$base}/{$base}Module.php";

        if (!\is_file("{$this->root}/{$file}")) {
            $this->out->error("Module file not found: {$file} (create it with: mikro make:module {$base})");
            return false;
        }

        return $this->report(ModuleRegistrar::register("{$this->root}/{$file}", $key, $fqcn), $fqcn, $file, $key);
    }

    private function registerInAppModule(string $fqcn): void
    {
        $file = 'src/AppModule.php';
        if (!\is_file("{$this->root}/{$file}")) {
            $short = \substr($fqcn, \strrpos($fqcn, '\\') + 1);
            $this->out->skip("No {$file} found: add {$short}::class to the imports of your root module.");
            return;
        }
        $this->report(ModuleRegistrar::register("{$this->root}/{$file}", 'imports', $fqcn), $fqcn, $file, 'imports');
    }

    private function report(string $result, string $fqcn, string $file, string $key): bool
    {
        $short = \substr($fqcn, \strrpos($fqcn, '\\') + 1);
        if ($result === ModuleRegistrar::ADDED) {
            $this->out->success("Registered {$short} in {$file} ({$key})");
            return true;
        }
        if ($result === ModuleRegistrar::EXISTS) {
            $this->out->skip("{$short} already registered in {$file}");
            return true;
        }
        $this->out->error("Could not update {$file}: add {$short}::class to '{$key}' manually.");
        return false;
    }

    private function write(string $relative, string $contents): bool
    {
        $path = "{$this->root}/{$relative}";

        if (\file_exists($path) && !$this->force) {
            $this->out->error("Already exists, skipped: {$relative} (use --force to overwrite)");
            return false;
        }

        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0755, true);
        }

        \file_put_contents($path, $contents);
        $this->out->success("Created: {$relative}");
        return true;
    }
}
