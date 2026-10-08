<?php

namespace MikroApi\Tests\Console;

use MikroApi\App;
use MikroApi\Console\Application;
use MikroApi\Console\ModuleRegistrar;
use MikroApi\Console\Naming;
use MikroApi\Database\Database;
use MikroApi\Request;
use PHPUnit\Framework\TestCase;

class ConsoleTest extends TestCase
{
    private string $dir;

    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        $this->dir    = \sys_get_temp_dir() . '/mikro-cli-' . \bin2hex(\random_bytes(4));
        $this->stream = \fopen('php://memory', 'w+');
        \mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        Database::reset();
        $this->removeDir($this->dir);
    }

    /** @return array{0: int, 1: string} [exit code, output] */
    private function mikro(string ...$args): array
    {
        \ftruncate($this->stream, 0);
        \rewind($this->stream);

        \ob_start(); // MigrationRunner escribe con echo
        $code = (new Application($this->dir, $this->stream))->run(['mikro', ...$args]);
        $echoed = (string) \ob_get_clean();

        \rewind($this->stream);
        return [$code, \stream_get_contents($this->stream) . $echoed];
    }

    private function file(string $relative): string
    {
        return (string) \file_get_contents("{$this->dir}/{$relative}");
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) return;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $f) {
            $f->isDir() ? \rmdir($f->getPathname()) : \unlink($f->getPathname());
        }
        \rmdir($dir);
    }

    private function assertValidPhp(string $relative): void
    {
        \exec(\escapeshellarg(PHP_BINARY) . ' -l ' . \escapeshellarg("{$this->dir}/{$relative}") . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, "{$relative}: " . \implode("\n", $out));
    }

    /* ------------------------------------------------------------------ */
    /*  Naming                                                              */
    /* ------------------------------------------------------------------ */

    public function testNaming(): void
    {
        $this->assertSame('BlogPost', Naming::studly('blog_post'));
        $this->assertSame('BlogPost', Naming::studly('blog-post'));
        $this->assertSame('BlogPost', Naming::studly('blogPost'));
        $this->assertSame('blog_post', Naming::snake('BlogPost'));
        $this->assertSame('blog-posts', Naming::kebab(Naming::plural('BlogPost')));
        $this->assertSame('Categories', Naming::plural('Category'));
        $this->assertSame('Category', Naming::singular('Categories'));
        $this->assertSame('Statuses', Naming::plural('Status'));
        $this->assertSame('Status', Naming::singular('Statuses'));
        $this->assertSame('Products', Naming::plural('Products'));
        $this->assertSame('Product', Naming::withoutSuffix('ProductController', 'Controller'));
        $this->assertSame('ProductController', Naming::withSuffix('ProductController', 'Controller'));
    }

    /* ------------------------------------------------------------------ */
    /*  ModuleRegistrar                                                     */
    /* ------------------------------------------------------------------ */

    private function moduleFile(string $attribute): string
    {
        $path = "{$this->dir}/M.php";
        \file_put_contents($path, "<?php\n\nnamespace App\\Shop;\n\nuse MikroApi\\Attributes\\Module;\n\n{$attribute}\nclass ShopModule {}\n");
        return $path;
    }

    public function testRegistrarAppendsToMultilineList(): void
    {
        $path = $this->moduleFile("#[Module(\n    providers: [\n        A::class,\n        ['provide' => 'x', 'useValue' => [1, 2]],\n    ],\n)]");

        $this->assertSame(ModuleRegistrar::ADDED, ModuleRegistrar::register($path, 'providers', 'App\\Shop\\B'));
        $this->assertStringContainsString("['provide' => 'x', 'useValue' => [1, 2]],\n        B::class,\n    ],", \file_get_contents($path));
        $this->assertSame(ModuleRegistrar::EXISTS, ModuleRegistrar::register($path, 'providers', 'App\\Shop\\B'));
    }

    public function testRegistrarHandlesSingleLineAndEmptyLists(): void
    {
        $path = $this->moduleFile('#[Module(imports: [], controllers: [A::class])]');

        ModuleRegistrar::register($path, 'controllers', 'App\\Shop\\B');
        ModuleRegistrar::register($path, 'imports', 'App\\Other\\OtherModule');

        $src = \file_get_contents($path);
        $this->assertStringContainsString('#[Module(imports: [OtherModule::class], controllers: [A::class, B::class])]', $src);
        $this->assertStringContainsString("use App\\Other\\OtherModule;\n", $src); // otro namespace → use
        $this->assertStringNotContainsString('use App\\Shop\\B;', $src);         // mismo namespace → sin use
    }

    public function testRegistrarAddsMissingKeyAndBareAttribute(): void
    {
        $path = $this->moduleFile("#[Module(\n    imports: [X::class],\n)]");
        ModuleRegistrar::register($path, 'providers', 'App\\Shop\\Svc');
        $this->assertStringContainsString("#[Module(\n    providers: [Svc::class],\n    imports: [X::class],", \file_get_contents($path));

        $path = $this->moduleFile('#[Module]');
        ModuleRegistrar::register($path, 'controllers', 'App\\Shop\\C');
        $this->assertStringContainsString('#[Module(controllers: [C::class])]', \file_get_contents($path));
    }

    public function testRegistrarFailsWithoutModuleAttribute(): void
    {
        $path = "{$this->dir}/N.php";
        \file_put_contents($path, "<?php\nclass N {}\n");

        $this->assertSame(ModuleRegistrar::FAILED, ModuleRegistrar::register($path, 'imports', 'App\\X'));
    }

    /* ------------------------------------------------------------------ */
    /*  Commands                                                            */
    /* ------------------------------------------------------------------ */

    public function testListVersionAndUnknownCommand(): void
    {
        [$code, $out] = $this->mikro('list');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('make:resource <name>', $out);
        $this->assertStringContainsString('migrate:fresh', $out);

        [$code, $out] = $this->mikro('--version');
        $this->assertStringContainsString(Application::VERSION, $out);

        [$code, $out] = $this->mikro('nope');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unknown command: nope', $out);
    }

    public function testNewScaffoldsModularProject(): void
    {
        [$code, $out] = $this->mikro('new', 'shop-api');

        $this->assertSame(0, $code, $out);
        foreach (['public/index.php', 'src/AppModule.php', 'src/AppController.php', 'config/database.php', 'views/home.php'] as $f) {
            $this->assertFileExists("{$this->dir}/shop-api/{$f}");
            $this->assertValidPhp("shop-api/{$f}");
        }
        $this->assertMatchesRegularExpression('/^JWT_SECRET=[0-9a-f]{64}$/m', $this->file('shop-api/.env'));
        $this->assertStringContainsString('JWT_SECRET=change-me', $this->file('shop-api/.env.example'));
        $this->assertStringContainsString('APP_NAME="Shop Api"', $this->file('shop-api/.env'));
        $this->assertStringContainsString('"App\\\\": "src/"', $this->file('shop-api/composer.json'));

        // Re-ejecutar no sobrescribe
        \file_put_contents("{$this->dir}/shop-api/src/AppModule.php", '<?php // custom');
        $this->mikro('init', 'shop-api');
        $this->assertSame('<?php // custom', $this->file('shop-api/src/AppModule.php'));
    }

    public function testNewMergesExistingComposerJson(): void
    {
        \file_put_contents("{$this->dir}/composer.json", \json_encode(['name' => 'acme/app', 'require' => ['php' => '>=8.1']]));

        $this->mikro('new');

        $composer = \json_decode($this->file('composer.json'), true);
        $this->assertSame('src/', $composer['autoload']['psr-4']['App\\']);
        $this->assertArrayHasKey('mikro-api/mikro-api', $composer['require']);
        $this->assertSame('acme/app', $composer['name']);
    }

    public function testEveryGeneratorProducesValidPhp(): void
    {
        $this->mikro('new');
        $this->mikro('make:module', 'Billing');

        $cases = [
            ['make:controller', 'Invoice', 'src/Controllers/InvoiceController.php'],
            ['make:service', 'Invoice', 'src/Services/InvoiceService.php'],
            ['make:repository', 'Invoice', 'src/Repositories/InvoiceRepository.php'],
            ['make:dto', 'CreateInvoice', 'src/DTOs/CreateInvoiceDto.php'],
            ['make:guard', 'Admin', 'src/Guards/AdminGuard.php'],
            ['make:interceptor', 'Timing', 'src/Interceptors/TimingInterceptor.php'],
            ['make:filter', 'HttpError', 'src/Filters/HttpErrorFilter.php'],
            ['make:middleware', 'RequestId', 'src/Middleware/RequestIdMiddleware.php'],
            ['make:attribute', 'Permissions', 'src/Attributes/Permissions.php'],
            ['make:view', 'emails.welcome', 'views/emails/welcome.php'],
            ['make:test', 'Home', 'tests/HomeTest.php'],
            ['make:controller', 'Payment', 'src/Billing/PaymentController.php', '--module=Billing'],
            ['make:dto', 'UpdatePayment', 'src/Billing/Dto/UpdatePaymentDto.php', '--module=Billing'],
        ];

        foreach ($cases as $case) {
            [$code, $out] = $this->mikro($case[0], $case[1], ...\array_slice($case, 3));
            $this->assertSame(0, $code, "{$case[0]}: {$out}");
            $this->assertFileExists("{$this->dir}/{$case[2]}");
            $this->assertValidPhp($case[2]);
        }

        $this->assertStringContainsString("#[Controller('/invoices')]", $this->file('src/Controllers/InvoiceController.php'));
        $this->assertStringContainsString("protected string \$table = 'invoices';", $this->file('src/Repositories/InvoiceRepository.php'));
        $this->assertStringContainsString("public const KEY = 'permissions';", $this->file('src/Attributes/Permissions.php'));
        $this->assertStringContainsString('#[Optional]', $this->file('src/Billing/Dto/UpdatePaymentDto.php'));
        $this->assertStringContainsString('namespace App\\Billing\\Dto;', $this->file('src/Billing/Dto/UpdatePaymentDto.php'));
        $this->assertStringContainsString('controllers: [PaymentController::class]', $this->file('src/Billing/BillingModule.php'));
        $this->assertStringContainsString('BillingModule::class', $this->file('src/AppModule.php'));

        $migrations = \glob("{$this->dir}/database/migrations/*.php");
        $this->assertCount(0, $migrations);
        [$code] = $this->mikro('make', 'add_total_to_invoices'); // alias antiguo
        $this->assertSame(0, $code);
        $this->assertCount(1, \glob("{$this->dir}/database/migrations/*_add_total_to_invoices.php"));
    }

    public function testGAliasAndModuleRegistration(): void
    {
        $this->mikro('new');
        $this->mikro('make:module', 'Catalog');

        [$code, $out] = $this->mikro('g', 'service', 'Pricing', '--module=Catalog');

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('providers: [PricingService::class]', $this->file('src/Catalog/CatalogModule.php'));
    }

    public function testExistingFileIsNotOverwrittenWithoutForce(): void
    {
        $this->mikro('make:guard', 'Admin');
        \file_put_contents("{$this->dir}/src/Guards/AdminGuard.php", '<?php // mine');

        [$code, $out] = $this->mikro('make:guard', 'Admin');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Already exists', $out);
        $this->assertSame('<?php // mine', $this->file('src/Guards/AdminGuard.php'));

        [$code] = $this->mikro('make:guard', 'Admin', '--force');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('class AdminGuard', $this->file('src/Guards/AdminGuard.php'));
    }

    public function testUnknownModuleFailsBeforeWritingAnything(): void
    {
        [$code, $out] = $this->mikro('make:controller', 'X', '--module=Ghost');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('make:module Ghost', $out);
        $this->assertDirectoryDoesNotExist("{$this->dir}/src/Ghost");
    }

    public function testMissingNameShowsUsage(): void
    {
        [$code, $out] = $this->mikro('make:controller');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Usage: mikro make:controller <name>', $out);
    }

    public function testKeyGenerate(): void
    {
        \file_put_contents("{$this->dir}/.env", "APP_NAME=x\nJWT_SECRET=change-me\n");

        [$code] = $this->mikro('key:generate');
        $this->assertSame(0, $code);
        \preg_match('/^JWT_SECRET=(.+)$/m', $this->file('.env'), $first);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first[1]);
        $this->assertStringContainsString('APP_NAME=x', $this->file('.env'));

        [$code, $out] = $this->mikro('key:generate');
        $this->assertSame(1, $code, 'no debe pisar un secreto real sin --force');

        $this->mikro('key:generate', '--force');
        \preg_match('/^JWT_SECRET=(.+)$/m', $this->file('.env'), $second);
        $this->assertNotSame($first[1], $second[1]);

        [, $out] = $this->mikro('key:generate', '--show');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', \trim($out));
    }

    public function testRouteClear(): void
    {
        \mkdir("{$this->dir}/cache");
        \file_put_contents("{$this->dir}/cache/routes.php", '<?php return [];');

        [$code, $out] = $this->mikro('route:clear');

        $this->assertSame(0, $code);
        $this->assertFileDoesNotExist("{$this->dir}/cache/routes.php");
        $this->assertStringContainsString('cleared', $out);
    }

    public function testMigrateWithoutConfigFails(): void
    {
        [$code, $out] = $this->mikro('migrate');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('config/database.php not found', $out);
    }

    /**
     * Flujo completo: new → make:resource → migrate → route:list → docs:export
     * y el CRUD generado respondiendo vía App::handle().
     */
    public function testResourceEndToEnd(): void
    {
        $this->mikro('new');
        \file_put_contents("{$this->dir}/config/database.php", "<?php return ['driver' => 'sqlite', 'database' => __DIR__ . '/../database/test.sqlite'];");

        [$code, $out] = $this->mikro('make:resource', 'gadgets');
        $this->assertSame(0, $code, $out);
        foreach ([
            'src/Gadgets/GadgetsModule.php', 'src/Gadgets/GadgetsController.php', 'src/Gadgets/GadgetsService.php',
            'src/Gadgets/GadgetsRepository.php', 'src/Gadgets/Dto/CreateGadgetDto.php', 'src/Gadgets/Dto/UpdateGadgetDto.php',
        ] as $f) {
            $this->assertValidPhp($f);
        }
        $this->assertStringContainsString('imports: [GadgetsModule::class]', $this->file('src/AppModule.php'));

        // Autoload del proyecto temporal (App\ → src/)
        $src = "{$this->dir}/src/";
        $autoload = function (string $class) use ($src) {
            if (\str_starts_with($class, 'App\\') && \is_file($f = $src . \str_replace('\\', '/', \substr($class, 4)) . '.php')) {
                require $f;
            }
        };
        \spl_autoload_register($autoload);

        try {
            Database::reset();
            [$code, $out] = $this->mikro('migrate');
            $this->assertSame(0, $code, $out);
            $this->assertStringContainsString('create_gadgets_table', $out);

            [$code, $out] = $this->mikro('route:list');
            $this->assertSame(0, $code, $out);
            $this->assertMatchesRegularExpression('/DELETE\s+\/gadgets\/:id\s+GadgetsController::destroy/', $out);

            [$code, $out] = $this->mikro('docs:export', 'docs/openapi.json');
            $this->assertSame(0, $code, $out);
            $spec = \json_decode($this->file('docs/openapi.json'), true);
            $this->assertArrayHasKey('/gadgets/{id}', $spec['paths']);
            $this->assertArrayHasKey('CreateGadgetDto', $spec['components']['schemas']);

            // El CRUD generado funciona
            $app = App::create('App\\AppModule');
            $app->getContainer()->instance(Database::class, Database::getInstance());

            $res = $app->handle(Request::create('POST', '/gadgets', [], ['name' => 'Lamp']));
            $this->assertSame(201, $res->getStatus(), $res->getBody());
            $id = \json_decode($res->getBody(), true)['id'];

            $this->assertSame(422, $app->handle(Request::create('POST', '/gadgets', [], []))->getStatus());
            $this->assertSame('Desk lamp', \json_decode($app->handle(
                Request::create('PUT', "/gadgets/{$id}", [], ['name' => 'Desk lamp'])
            )->getBody(), true)['name']);
            $this->assertSame(1, \json_decode($app->handle(Request::create('GET', '/gadgets'))->getBody(), true)['total']);
            $this->assertSame(204, $app->handle(Request::create('DELETE', "/gadgets/{$id}"))->getStatus());
            $this->assertSame(404, $app->handle(Request::create('GET', "/gadgets/{$id}"))->getStatus());

            [$code, $out] = $this->mikro('migrate:fresh');
            $this->assertSame(0, $code, $out);
            $this->assertStringContainsString('Revirtiendo', $out);
        } finally {
            \spl_autoload_unregister($autoload);
        }
    }
}
