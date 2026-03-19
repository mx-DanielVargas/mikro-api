<?php

namespace MikroApi\Tests;

use MikroApi\Container;
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
