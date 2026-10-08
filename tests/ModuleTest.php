<?php

namespace MikroApi\Tests\Modules;

use MikroApi\App;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Module;
use MikroApi\Attributes\Param;
use MikroApi\Attributes\Route;
use MikroApi\Attributes\UseGuards;
use MikroApi\GuardInterface;
use MikroApi\Module\DynamicModule;
use MikroApi\Module\OnApplicationShutdown;
use MikroApi\Module\OnModuleInit;
use MikroApi\Request;
use MikroApi\Response;
use PHPUnit\Framework\TestCase;

class ModuleTest extends TestCase
{
    protected function setUp(): void
    {
        Lifecycle::$events = [];
        Counter::$instances = 0;
    }

    private function json(Response $response): mixed
    {
        return \json_decode($response->getBody(), true);
    }

    public function testControllersOfImportedModulesAreRouted(): void
    {
        $app = App::create(AppModule::class);

        $this->assertSame(['id' => 5, 'greeting' => 'hola usuario 5'], $this->json($app->handle(Request::create('GET', '/users/5'))));
        $this->assertSame(['status' => 'ok'], $this->json($app->handle(Request::create('GET', '/health'))));
    }

    public function testProvidersAreSingletonsWithinTheModule(): void
    {
        $app = App::create(AppModule::class);
        $app->handle(Request::create('GET', '/users/1'));
        $app->handle(Request::create('GET', '/users/2'));

        $this->assertSame(1, Counter::$instances);
        $this->assertSame(
            $app->getModuleContainer(UsersModule::class)->get(UserService::class),
            $app->getModuleContainer(AppModule::class)->get(UserService::class), // re-exportado
        );
    }

    public function testUseValueUseClassUseFactoryAndUseExisting(): void
    {
        $c = App::create(UsersModule::class)->getModuleContainer(UsersModule::class);

        $this->assertSame('hola', $c->get('greeting.prefix'));
        $this->assertInstanceOf(InMemoryUserRepo::class, $c->get(UserRepo::class));
        $this->assertSame('factory:hola', $c->get('factory.value'));
        $this->assertSame($c->get(UserRepo::class), $c->get('repo.alias'));
    }

    public function testNonExportedProviderIsNotAccessibleFromOtherModule(): void
    {
        $app = App::create(AppModule::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('es provider de ' . UsersModule::class);
        $app->getModuleContainer(HealthModule::class)->get(UserRepo::class);
    }

    public function testProviderOfModuleNotImportedFailsWithHelpfulMessage(): void
    {
        $app = App::create(LeakyModule::class, UsersModule::class);

        $res = $app->handle(Request::create('GET', '/leaky'));

        $this->assertSame(500, $res->getStatus());
        $this->assertStringContainsString("Agrégalo a 'exports'", $this->json($res)['error']);
    }

    public function testGlobalModuleExportsAreVisibleEverywhere(): void
    {
        $app = App::create(AppModule::class);

        $this->assertSame('v1.2', $this->json($app->handle(Request::create('GET', '/health/version')))['version']);
    }

    public function testRootContainerRegistrationsAreVisibleInModules(): void
    {
        $app = new App();
        $app->getContainer()->instance(AppVersion::class, new AppVersion('root'));
        $app->useModule(HealthModule::class);

        $this->assertSame('root', $this->json($app->handle(Request::create('GET', '/health/version')))['version']);
    }

    public function testDynamicModuleConfiguresImportedModule(): void
    {
        $app = App::create(ConfigModule::forRoot(['version' => 'v9']), HealthModule::class);

        $this->assertSame('v9', $this->json($app->handle(Request::create('GET', '/health/version')))['version']);
    }

    public function testDynamicModuleAfterImportThrows(): void
    {
        $this->expectException(\LogicException::class);

        App::create(AppModule::class, ConfigModule::forRoot(['version' => 'x']));
    }

    public function testGuardsAreResolvedWithinTheControllersModule(): void
    {
        $app = App::create(AppModule::class);

        $this->assertSame(403, $app->handle(Request::create('GET', '/users/secret/x'))->getStatus());
        $this->assertSame(200, $app->handle(Request::create('GET', '/users/secret/letmein'))->getStatus());
    }

    public function testCircularImportsAreAllowed(): void
    {
        $app = App::create(CycleAModule::class);

        $this->assertSame('B', $app->getModuleContainer(CycleAModule::class)->get(ServiceB::class)->name());
        $this->assertSame('A', $app->getModuleContainer(CycleBModule::class)->get(ServiceA::class)->name());
    }

    public function testLifecycleHooksRunInDependencyOrder(): void
    {
        $app = App::create(AppModule::class);

        $this->assertSame(['UserService:init', 'UsersModule:init', 'AppModule:init'], Lifecycle::$events);

        Lifecycle::$events = [];
        $app->close();
        $app->close(); // idempotente

        $this->assertSame(['AppModule:shutdown', 'UserService:shutdown'], Lifecycle::$events);
    }

    public function testInvalidExportThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("exporta 'Nope'");

        App::create(BadExportModule::class);
    }

    public function testClassWithoutModuleAttributeThrows(): void
    {
        $this->expectException(\LogicException::class);
        App::create(UserService::class);
    }
}

/* ======================================================================= */
/*  Fixtures                                                                */
/* ======================================================================= */

class Lifecycle
{
    public static array $events = [];
}

class Counter
{
    public static int $instances = 0;
}

interface UserRepo
{
    public function find(int $id): array;
}

class InMemoryUserRepo implements UserRepo
{
    public function find(int $id): array
    {
        return ['id' => $id];
    }
}

class UserService implements OnModuleInit, OnApplicationShutdown
{
    public function __construct(private UserRepo $repo, private GreetingFactory $greetings)
    {
        Counter::$instances++;
    }

    public function greet(int $id): array
    {
        return $this->repo->find($id) + ['greeting' => $this->greetings->prefix . " usuario {$id}"];
    }

    public function onModuleInit(): void
    {
        Lifecycle::$events[] = 'UserService:init';
    }

    public function onApplicationShutdown(): void
    {
        Lifecycle::$events[] = 'UserService:shutdown';
    }
}

class GreetingFactory
{
    public function __construct(public string $prefix) {}

    public static function create(string $prefix): self
    {
        return new self($prefix);
    }

    public static function value(string $prefix): string
    {
        return "factory:{$prefix}";
    }
}

class SecretGuard implements GuardInterface
{
    public function __construct(private UserRepo $repo) {} // provider privado del módulo

    public function canActivate(Request $request): bool
    {
        return ($request->params['code'] ?? '') === 'letmein';
    }

    public function deny(): Response
    {
        return Response::error('Forbidden', 403);
    }
}

#[Controller('/users')]
class UserController
{
    public function __construct(private UserService $users) {}

    #[Route('GET', '/:id')]
    public function show(#[Param('id')] int $id): array
    {
        return $this->users->greet($id);
    }

    #[Route('GET', '/secret/:code')]
    #[UseGuards(SecretGuard::class)]
    public function secret(): array
    {
        return ['ok' => true];
    }
}

#[Module(
    controllers: [UserController::class],
    providers: [
        UserService::class,
        ['provide' => UserRepo::class, 'useClass' => InMemoryUserRepo::class],
        ['provide' => 'greeting.prefix', 'useValue' => 'hola'],
        ['provide' => GreetingFactory::class, 'useFactory' => [GreetingFactory::class, 'create'], 'inject' => ['greeting.prefix']],
        ['provide' => 'factory.value', 'useFactory' => [GreetingFactory::class, 'value'], 'inject' => ['greeting.prefix']],
        ['provide' => 'repo.alias', 'useExisting' => UserRepo::class],
    ],
    exports: [UserService::class],
)]
class UsersModule implements OnModuleInit
{
    public function onModuleInit(): void
    {
        Lifecycle::$events[] = 'UsersModule:init';
    }
}

class AppVersion
{
    public function __construct(public string $value = 'v1.2') {}
}

#[Module(providers: [AppVersion::class], exports: [AppVersion::class], global: true)]
class ConfigModule
{
    public static function forRoot(array $options): DynamicModule
    {
        return new DynamicModule(
            module: self::class,
            providers: [['provide' => AppVersion::class, 'useValue' => new AppVersion($options['version'])]],
        );
    }
}

#[Controller('/health')]
class HealthController
{
    public function __construct(private AppVersion $version) {}

    #[Route('GET', '/')]
    public function index(): array
    {
        return ['status' => 'ok'];
    }

    #[Route('GET', '/version')]
    public function version(): array
    {
        return ['version' => $this->version->value];
    }
}

#[Module(controllers: [HealthController::class])]
class HealthModule {}

#[Module(imports: [UsersModule::class, HealthModule::class, ConfigModule::class], exports: [UsersModule::class])]
class AppModule implements OnModuleInit, OnApplicationShutdown
{
    public function onModuleInit(): void
    {
        Lifecycle::$events[] = 'AppModule:init';
    }

    public function onApplicationShutdown(): void
    {
        Lifecycle::$events[] = 'AppModule:shutdown';
    }
}

#[Controller('/leaky')]
class LeakyController
{
    public function __construct(private UserRepo $repo) {}

    #[Route('GET', '/')]
    public function index(): array
    {
        return [];
    }
}

#[Module(controllers: [LeakyController::class])]
class LeakyModule {}

class ServiceA
{
    public function name(): string { return 'A'; }
}

class ServiceB
{
    public function name(): string { return 'B'; }
}

#[Module(imports: [CycleBModule::class], providers: [ServiceA::class], exports: [ServiceA::class])]
class CycleAModule {}

#[Module(imports: [CycleAModule::class], providers: [ServiceB::class], exports: [ServiceB::class])]
class CycleBModule {}

#[Module(providers: [ServiceA::class], exports: ['Nope'])]
class BadExportModule {}
