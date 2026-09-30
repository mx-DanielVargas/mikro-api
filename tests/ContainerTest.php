<?php

namespace MikroApi\Tests;

use MikroApi\Attributes\Relation\HasMany;
use MikroApi\Container;
use MikroApi\Database\Database;
use MikroApi\Repository\BaseRepository;
use PHPUnit\Framework\TestCase;

class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testSetAndGet(): void
    {
        $this->container->set(ContainerDummyService::class);
        $a = $this->container->get(ContainerDummyService::class);
        $b = $this->container->get(ContainerDummyService::class);

        $this->assertInstanceOf(ContainerDummyService::class, $a);
        $this->assertNotSame($a, $b); // new instance each time
    }

    public function testSingleton(): void
    {
        $this->container->singleton(ContainerDummyService::class);
        $a = $this->container->get(ContainerDummyService::class);
        $b = $this->container->get(ContainerDummyService::class);

        $this->assertSame($a, $b);
    }

    public function testInstance(): void
    {
        $obj = new ContainerDummyService();
        $this->container->instance(ContainerDummyService::class, $obj);

        $this->assertSame($obj, $this->container->get(ContainerDummyService::class));
    }

    public function testHas(): void
    {
        $this->assertFalse($this->container->has(ContainerDummyService::class));
        $this->container->set(ContainerDummyService::class);
        $this->assertTrue($this->container->has(ContainerDummyService::class));
    }

    public function testFactory(): void
    {
        $this->container->set(ContainerDummyService::class, fn() => new ContainerDummyService());
        $this->assertInstanceOf(ContainerDummyService::class, $this->container->get(ContainerDummyService::class));
    }

    public function testAutowiring(): void
    {
        $this->container->singleton(ContainerDummyService::class);
        $this->container->set(ContainerDummyConsumer::class);

        $consumer = $this->container->get(ContainerDummyConsumer::class);

        $this->assertInstanceOf(ContainerDummyConsumer::class, $consumer);
        $this->assertInstanceOf(ContainerDummyService::class, $consumer->service);
    }

    public function testImplicitAutowiring(): void
    {
        // get() should autowire even without explicit registration
        $obj = $this->container->get(ContainerDummyService::class);
        $this->assertInstanceOf(ContainerDummyService::class, $obj);
    }

    public function testMake(): void
    {
        $obj = $this->container->make(ContainerDummyService::class);
        $this->assertInstanceOf(ContainerDummyService::class, $obj);
    }

    public function testInterfaceBinding(): void
    {
        $this->container->set(ContainerDummyInterface::class, ContainerDummyImpl::class);
        $obj = $this->container->get(ContainerDummyInterface::class);
        $this->assertInstanceOf(ContainerDummyImpl::class, $obj);
    }

    public function testUnresolvableThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->container->get(ContainerDummyInterface::class); // interface, not registered
    }

    public function testUnresolvableParamThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->container->get(ContainerUnresolvable::class);
    }

    public function testSingletonWithFactory(): void
    {
        $calls = 0;
        $this->container->singleton(ContainerDummyService::class, function () use (&$calls) {
            $calls++;
            return new ContainerDummyService();
        });

        $this->container->get(ContainerDummyService::class);
        $this->container->get(ContainerDummyService::class);

        $this->assertEquals(1, $calls);
    }

    public function testDefaultParamResolution(): void
    {
        $obj = $this->container->get(ContainerWithDefault::class);
        $this->assertInstanceOf(ContainerWithDefault::class, $obj);
        $this->assertEquals(42, $obj->value);
    }

    public function testAutowireInjectsContainerIntoBaseRepository(): void
    {
        Database::reset();
        $db = Database::connect(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->container->instance(Database::class, $db);

        $repo = $this->container->get(ContainerDummyRepository::class);

        $this->assertInstanceOf(ContainerDummyRepository::class, $repo);
        $this->assertSame($this->container, $repo->getContainer());
    }

    public function testSetContainerRegistersOwnDatabaseIfMissing(): void
    {
        Database::reset();
        $db = Database::connect(['driver' => 'sqlite', 'database' => ':memory:']);

        // Repo creado directamente (sin pasar por el Container), como hacen
        // hoy los repos de la app y los tests existentes.
        $repo = new ContainerDummyRepository($db);

        $this->assertFalse($this->container->has(Database::class));

        $repo->setContainer($this->container);

        $this->assertTrue($this->container->has(Database::class));
        $this->assertSame($db, $this->container->get(Database::class));
    }

    public function testRelationLoaderResolvesRelatedRepositoryWithExtraDependencyViaContainer(): void
    {
        Database::reset();
        $db = Database::connect([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $db->execute('CREATE TABLE dummy_related (id INTEGER PRIMARY KEY, dummy_id INTEGER)');
        $db->execute('INSERT INTO dummy_related (dummy_id) VALUES (1)');

        // Repo "principal" creado directamente y luego conectado al Container,
        // igual que ocurriría si un repo fue resuelto vía Container::get()/make().
        $repo = new ContainerDummyRepositoryWithRelation($db);
        $repo->setContainer($this->container);

        // Si RelationLoader::makeRepo() cayera de vuelta a `new $repositoryClass($this->db)`
        // en lugar de usar el Container, esto lanzaría un TypeError porque el
        // repositorio relacionado requiere ContainerExtraService, no Database, como
        // primer parámetro de su constructor.
        $result = $repo->loadWith([['id' => 1]], ['related']);

        $this->assertArrayHasKey('related', $result[0]);
        $this->assertCount(1, $result[0]['related']);
        $this->assertEquals(1, $result[0]['related'][0]['dummy_id']);
    }
}

// ── Fixtures para AUD-009 (Container + BaseRepository + RelationLoader) ──

class ContainerDummyRepository extends BaseRepository
{
    protected string $table = 'dummy';

    public function getContainer(): ?Container
    {
        return $this->container;
    }
}

class ContainerExtraService {}

class ContainerRelatedRepositoryWithExtraDependency extends BaseRepository
{
    protected string $table = 'dummy_related';

    public function __construct(public ContainerExtraService $service, ?Database $db = null)
    {
        parent::__construct($db);
    }
}

class ContainerDummyRepositoryWithRelation extends BaseRepository
{
    protected string $table = 'dummy';

    #[HasMany(repository: ContainerRelatedRepositoryWithExtraDependency::class, foreignKey: 'dummy_id')]
    public array $related;
}

// ── Helpers ─────────────────────────────────────────────────────────────

class ContainerDummyService {}

class ContainerDummyConsumer
{
    public function __construct(public ContainerDummyService $service) {}
}

interface ContainerDummyInterface {}
class ContainerDummyImpl implements ContainerDummyInterface {}

class ContainerUnresolvable
{
    public function __construct(int $required) {}
}

class ContainerWithDefault
{
    public function __construct(public int $value = 42) {}
}
