<?php

namespace MikroApi\Console;

use MikroApi\Config\ConfigService;
use MikroApi\Database\Database;
use MikroApi\Database\MigrationRunner;
use MikroApi\Router;
use MikroApi\Swagger\SwaggerGenerator;

/**
 * CLI de MikroAPI (`vendor/bin/mikro`).
 *
 *   mikro list                              Todos los comandos
 *   mikro new my-api                        Proyecto nuevo
 *   mikro make:resource products            CRUD completo en un módulo
 *   mikro g controller Report --module=Products
 *   mikro migrate
 *   mikro route:list
 */
final class Application
{
    public const VERSION = '2.0.0';

    /** Alias de comandos (incluye los nombres del antiguo mikro-migrate) */
    private const ALIASES = [
        'init'     => 'new',
        'help'     => 'list',
        'make'     => 'make:migration',
        'rollback' => 'migrate:rollback',
        'reset'    => 'migrate:reset',
        'status'   => 'migrate:status',
        'fresh'    => 'migrate:fresh',
    ];

    private Output $out;

    /** @param resource|null $stream */
    public function __construct(private string $root, $stream = null)
    {
        $this->root = \rtrim($root, '/');
        $this->out  = new Output($stream);
    }

    /** @param string[] $argv argv completo (incluye el nombre del script) */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'list';
        $input   = new Input(\array_slice($argv, 2));

        if (\in_array($command, ['--version', '-V', 'version'], true)) {
            $this->out->line('MikroAPI CLI ' . self::VERSION);
            return 0;
        }
        if (\in_array($command, ['--help', '-h'], true)) {
            $command = 'list';
        }

        $command = self::ALIASES[$command] ?? $command;

        // `mikro g controller X` / `mikro generate controller X` → make:controller X
        if ($command === 'g' || $command === 'generate') {
            $type = $input->argument(0);
            if ($type === null) {
                return $this->usageError('mikro g <type> <name>  (types: ' . \implode(', ', \array_keys(Generator::TYPES)) . ')');
            }
            $command = "make:{$type}";
            \array_shift($input->arguments);
        }

        // .env antes que nada: config/database.php puede leer $_ENV
        new ConfigService($this->root);

        try {
            if (\str_starts_with($command, 'make:')) {
                return $this->make(\substr($command, 5), $input);
            }

            return match ($command) {
                'list'             => $this->list($input),
                'new'              => $this->newProject($input),
                'serve'            => $this->serve($input),
                'key:generate'     => $this->keyGenerate($input),
                'migrate'          => $this->migrations('migrate'),
                'migrate:rollback' => $this->migrations('rollback'),
                'migrate:reset'    => $this->migrations('reset'),
                'migrate:status'   => $this->migrations('status'),
                'migrate:fresh'    => $this->migrations('fresh'),
                'route:list'       => $this->routeList(),
                'route:clear'      => $this->routeClear($input),
                'docs:export'      => $this->docsExport($input),
                default            => $this->unknown($command),
            };
        } catch (\Throwable $e) {
            $this->out->error($e->getMessage());
            return 1;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Commands                                                             */
    /* ------------------------------------------------------------------ */

    private function list(Input $input): int
    {
        $o = $this->out;
        $o->title('MikroAPI CLI ' . self::VERSION);
        $o->line();
        $o->line('Usage: vendor/bin/mikro <command> [arguments] [--options]');

        $groups = [
            'Project' => [
                'new [path]'                => 'Create a new project (alias: init)',
                'serve'                     => 'Start the dev server  --host=127.0.0.1 --port=8000',
                'key:generate'              => 'Write a random JWT_SECRET to .env  --force --show',
            ],
            'Generators  (make:<type> <name>, or: g <type> <name>)' => [],
            'Database' => [
                'migrate'                   => 'Run pending migrations',
                'migrate:status'            => 'Show the status of every migration (alias: status)',
                'migrate:rollback'          => 'Roll back the last migration (alias: rollback)',
                'migrate:reset'             => 'Roll back all migrations (alias: reset)',
                'migrate:fresh'             => 'Roll back everything and migrate again',
            ],
            'Routes & docs' => [
                'route:list'                => 'List every route found in src/',
                'route:clear [file]'        => 'Delete the compiled route cache (default: cache/routes.php)',
                'docs:export [file]'        => 'Write the OpenAPI spec (default: openapi.json, "-" = stdout)  --title= --api-version=',
            ],
        ];
        foreach (Generator::TYPES as $type => $description) {
            $groups['Generators  (make:<type> <name>, or: g <type> <name>)']["make:{$type} <name>"] = $description;
        }

        foreach ($groups as $group => $commands) {
            $o->line();
            $o->info($group);
            foreach ($commands as $name => $description) {
                $o->line('  ' . $o->highlight(\str_pad($name, 28)) . $description);
            }
        }

        $o->line();
        $o->info('Generator options');
        $o->line('  ' . $o->highlight(\str_pad('--module=<Name>', 28)) . 'Generate inside src/<Name>/ and register it in <Name>Module');
        $o->line('  ' . $o->highlight(\str_pad('--force', 28)) . 'Overwrite existing files');
        $o->line();
        $o->line($o->comment('Examples:'));
        $o->line($o->comment('  vendor/bin/mikro new my-api'));
        $o->line($o->comment('  vendor/bin/mikro make:resource products'));
        $o->line($o->comment('  vendor/bin/mikro g service Pricing --module=Products'));
        $o->line($o->comment('  vendor/bin/mikro make:migration add_price_to_products'));
        return 0;
    }

    private function make(string $type, Input $input): int
    {
        if (!isset(Generator::TYPES[$type])) {
            return $this->unknown("make:{$type}");
        }

        $name = $input->argument(0);
        if ($name === null || $name === '') {
            return $this->usageError("mikro make:{$type} <name>" . ($type === 'resource' ? '' : ' [--module=Name]'));
        }

        $module    = $input->option('module');
        $generator = new Generator($this->root, $this->out, $input->flag('force', 'f'));
        $ok        = $generator->make($type, $name, \is_string($module) ? $module : null, [
            'route' => \is_string($input->option('route')) ? $input->option('route') : null,
        ]);

        return $ok ? 0 : 1;
    }

    private function newProject(Input $input): int
    {
        $target = $input->argument(0, '.');
        $root   = $target === '.' ? $this->root
            : (\str_starts_with($target, '/') ? $target : "{$this->root}/{$target}");

        return (new Scaffolder($this->out))->run($root) ? 0 : 1;
    }

    private function serve(Input $input): int
    {
        $host  = (string) $input->option('host', '127.0.0.1');
        $port  = (string) $input->option('port', '8000');
        $entry = "{$this->root}/public/index.php";

        if (!\is_file($entry)) {
            $this->out->error('public/index.php not found. Run this from the project root (or create one with: mikro new).');
            return 1;
        }

        $this->out->info("MikroAPI dev server: http://{$host}:{$port}  (Ctrl+C to stop)");
        $cmd = \sprintf(
            '%s -S %s -t %s %s',
            \escapeshellarg(PHP_BINARY),
            \escapeshellarg("{$host}:{$port}"),
            \escapeshellarg("{$this->root}/public"),
            \escapeshellarg($entry),
        );
        \passthru($cmd, $code);
        return $code;
    }

    private function keyGenerate(Input $input): int
    {
        $key = \bin2hex(\random_bytes(32));

        if ($input->flag('show')) {
            $this->out->line($key);
            return 0;
        }

        $env      = "{$this->root}/.env";
        $contents = \is_file($env) ? (string) \file_get_contents($env) : '';

        if (\preg_match('/^JWT_SECRET=(.*)$/m', $contents, $m)) {
            $current = \trim($m[1], " \"'");
            if ($current !== '' && $current !== 'change-me' && !$input->flag('force')) {
                $this->out->error('JWT_SECRET already set in .env. Use --force to replace it (existing tokens stop working).');
                return 1;
            }
            $contents = \preg_replace('/^JWT_SECRET=.*$/m', "JWT_SECRET={$key}", $contents, 1);
        } else {
            $contents = \rtrim($contents) . ($contents === '' ? '' : "\n") . "JWT_SECRET={$key}\n";
        }

        \file_put_contents($env, $contents);
        $this->out->success('JWT_SECRET written to .env');
        return 0;
    }

    private function migrations(string $action): int
    {
        $configPath = "{$this->root}/config/database.php";
        if (!\is_file($configPath)) {
            $this->out->error('config/database.php not found (create a project with: mikro new).');
            return 1;
        }

        $db = Database::connect(require $configPath);
        $runner = new MigrationRunner(
            db: $db,
            migrationsPath: "{$this->root}/database/migrations",
            migrationsNamespace: 'Database\\Migrations',
        );

        match ($action) {
            'migrate'  => $runner->migrate(),
            'rollback' => $runner->rollback(),
            'reset'    => $runner->reset(),
            'status'   => $runner->status(),
            'fresh'    => (function () use ($runner) { $runner->reset(); $runner->migrate(); })(),
        };
        return 0;
    }

    private function routeList(): int
    {
        $router = new Router();
        foreach (ControllerScanner::scan("{$this->root}/src") as $controller) {
            $router->registerController($controller);
        }

        $routes = $router->getRoutes();
        if (empty($routes)) {
            $this->out->line('No routes found in src/.');
            return 0;
        }

        $short = fn(string $class) => \substr($class, (int) \strrpos($class, '\\') + 1) ?: $class;
        $rows  = [];
        foreach ($routes as $route) {
            $rows[] = [
                $route['method'],
                $route['pattern'],
                $short($route['controller']) . '::' . $route['action'],
                \implode(', ', \array_map($short, \array_merge($route['guards'], $route['interceptors'] ?? []))),
            ];
        }

        $this->out->table(['METHOD', 'PATH', 'HANDLER', 'GUARDS / INTERCEPTORS'], $rows);
        $this->out->line();
        $this->out->line($this->out->comment(\count($rows) . ' route(s). Global guards/interceptors registered in bootstrap are not shown.'));
        return 0;
    }

    private function routeClear(Input $input): int
    {
        $file = $input->argument(0, 'cache/routes.php');
        $path = \str_starts_with($file, '/') ? $file : "{$this->root}/{$file}";

        if (!\is_file($path)) {
            $this->out->skip("No route cache at {$file}");
            return 0;
        }
        \unlink($path);
        $this->out->success("Route cache cleared: {$file}");
        return 0;
    }

    private function docsExport(Input $input): int
    {
        $controllers = ControllerScanner::scan("{$this->root}/src");
        $spec = (new SwaggerGenerator())->generate($controllers, [], [
            'title'   => (string) $input->option('title', (new ConfigService($this->root))->get('APP_NAME', 'API')),
            'version' => (string) $input->option('api-version', '1.0.0'),
        ]);
        $json = \json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        $file = $input->argument(0, 'openapi.json');
        if ($file === '-') {
            $this->out->line(\rtrim($json));
            return 0;
        }

        $path = \str_starts_with($file, '/') ? $file : "{$this->root}/{$file}";
        if (!\is_dir(\dirname($path))) {
            \mkdir(\dirname($path), 0755, true);
        }
        \file_put_contents($path, $json);
        $this->out->success("OpenAPI spec written: {$file} (" . \count($spec['paths']) . ' paths)');
        return 0;
    }

    /* ------------------------------------------------------------------ */

    private function unknown(string $command): int
    {
        $this->out->error("Unknown command: {$command}");
        $this->out->line('Run `vendor/bin/mikro list` to see all commands.');
        return 1;
    }

    private function usageError(string $usage): int
    {
        $this->out->error("Usage: {$usage}");
        return 1;
    }
}
