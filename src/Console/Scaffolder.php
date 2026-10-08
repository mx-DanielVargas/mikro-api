<?php

namespace MikroApi\Console;

/**
 * Crea la estructura de un proyecto nuevo (mikro new / mikro init).
 * Seguro de re-ejecutar: nunca sobrescribe archivos existentes.
 */
final class Scaffolder
{
    private string $root = '';

    public function __construct(private Output $out) {}

    public function run(string $root): bool
    {
        $root    = \rtrim($root, '/');
        $this->root = $root;
        $appName = \ucwords(\str_replace(['-', '_'], ' ', \basename($root)));

        $this->out->title("Creating a MikroAPI project in {$root}/");
        $this->out->line();

        foreach (['src', 'database/migrations', 'views', 'public', 'tests', 'config'] as $dir) {
            if (!\is_dir("{$root}/{$dir}")) {
                \mkdir("{$root}/{$dir}", 0755, true);
            }
        }

        $composerPath = "{$root}/composer.json";
        if (\file_exists($composerPath)) {
            $this->mergeComposerJson($composerPath);
        } else {
            $this->write($composerPath, Stubs::PROJECT_COMPOSER);
        }

        $rootNs = (new Generator($root, $this->out))->rootNamespace();
        $ns     = \rtrim($rootNs, '\\');

        $this->write("{$root}/public/index.php", Stubs::render('PROJECT_INDEX', [
            'rootNamespace' => $rootNs,
            'appName'       => $appName,
        ]));
        $this->write("{$root}/src/AppModule.php", Stubs::render('PROJECT_APP_MODULE', ['namespace' => $ns]));
        $this->write("{$root}/src/AppController.php", Stubs::render('PROJECT_APP_CONTROLLER', ['namespace' => $ns]));
        $this->write("{$root}/config/database.php", Stubs::PROJECT_DATABASE_CONFIG);
        $this->write("{$root}/views/home.php", Stubs::render('VIEW', ['view' => 'home', 'name' => 'Welcome']));
        $this->write("{$root}/.env", Stubs::render('PROJECT_ENV', [
            'appName'   => $appName,
            'jwtSecret' => \bin2hex(\random_bytes(32)),
        ]));
        $this->write("{$root}/.env.example", Stubs::render('PROJECT_ENV', [
            'appName'   => $appName,
            'jwtSecret' => 'change-me',
        ]));
        $this->write("{$root}/.gitignore", Stubs::PROJECT_GITIGNORE);
        $this->write("{$root}/README.md", Stubs::render('PROJECT_README', ['appName' => $appName]));

        $this->out->line();
        $this->out->info('Done! Next steps:');
        $this->out->line('  composer install            (or composer dump-autoload if composer.json already existed)');
        $this->out->line('  vendor/bin/mikro make:resource products');
        $this->out->line('  vendor/bin/mikro migrate');
        $this->out->line('  vendor/bin/mikro serve');
        return true;
    }

    private function write(string $path, string $contents): void
    {
        $relative = \substr($path, \strlen($this->root) + 1);

        if (\file_exists($path)) {
            $this->out->skip("Exists, skipped: {$relative}");
            return;
        }
        if (!\is_dir(\dirname($path))) {
            \mkdir(\dirname($path), 0755, true);
        }
        \file_put_contents($path, $contents);
        $this->out->success("Created: {$relative}");
    }

    /**
     * Si composer.json ya existía (ej. se corrió `composer require
     * mikro-api/mikro-api` antes), agrega el mapeo psr-4 "App\\" => "src/" y
     * el require del framework cuando falten. Sin el mapeo, nada de src/ se
     * puede autocargar ("Class ... does not exist" al registrar controladores).
     */
    private function mergeComposerJson(string $path): void
    {
        $data = \json_decode((string) \file_get_contents($path), true);

        if (!\is_array($data)) {
            $this->out->error('composer.json exists but is not valid JSON: add "App\\\\": "src/" to autoload.psr-4 and require mikro-api/mikro-api manually.');
            return;
        }

        $changed = false;
        $psr4    = \is_array($data['autoload']['psr-4'] ?? null) ? $data['autoload']['psr-4'] : [];

        $hasSrc = false;
        foreach ($psr4 as $mapped) {
            foreach ((array) $mapped as $p) {
                if (\in_array(\rtrim((string) $p, '/'), ['src', './src'], true)) {
                    $hasSrc = true;
                }
            }
        }
        if (!$hasSrc) {
            $psr4['App\\'] = 'src/';
            $changed = true;
        }
        $data['autoload']['psr-4'] = $psr4;

        if (!\is_array($data['require'] ?? null)) {
            $data['require'] = [];
        }
        if (!isset($data['require']['mikro-api/mikro-api']) && ($data['name'] ?? '') !== 'mikro-api/mikro-api') {
            $data['require']['mikro-api/mikro-api'] = '^1.0';
            $changed = true;
        }

        if (!$changed) {
            $this->out->skip('composer.json already has the required autoload/require entries');
            return;
        }

        \file_put_contents($path, \json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->out->success('Updated: composer.json (run `composer dump-autoload` next)');
    }
}
