<?php

namespace MikroApi\Tests;

use MikroApi\Service\BaseService;
use MikroApi\Service\ServiceException;
use PHPUnit\Framework\TestCase;

class BaseServiceTest extends TestCase
{
    private TestService $service;

    protected function setUp(): void
    {
        $this->service = new TestService();
    }

    public function testFail(): void
    {
        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Something went wrong');
        $this->service->doFail('Something went wrong', 400);
    }

    public function testFailStatusCode(): void
    {
        try {
            $this->service->doFail('Bad', 422);
        } catch (ServiceException $e) {
            $this->assertEquals(422, $e->getStatusCode());
            $this->assertEquals('Bad', $e->getMessage());
            return;
        }
        $this->fail('Expected ServiceException');
    }

    public function testNotFound(): void
    {
        try {
            $this->service->doNotFound();
        } catch (ServiceException $e) {
            $this->assertEquals(404, $e->getStatusCode());
            return;
        }
        $this->fail('Expected ServiceException');
    }

    public function testUnauthorized(): void
    {
        try {
            $this->service->doUnauthorized();
        } catch (ServiceException $e) {
            $this->assertEquals(401, $e->getStatusCode());
            return;
        }
        $this->fail('Expected ServiceException');
    }

    public function testForbidden(): void
    {
        try {
            $this->service->doForbidden();
        } catch (ServiceException $e) {
            $this->assertEquals(403, $e->getStatusCode());
            return;
        }
        $this->fail('Expected ServiceException');
    }

    public function testConflict(): void
    {
        try {
            $this->service->doConflict();
        } catch (ServiceException $e) {
            $this->assertEquals(409, $e->getStatusCode());
            return;
        }
        $this->fail('Expected ServiceException');
    }
}

class TestService extends BaseService
{
    public function doFail(string $msg, int $code): void { $this->fail($msg, $code); }
    public function doNotFound(): void { $this->notFound(); }
    public function doUnauthorized(): void { $this->unauthorized(); }
    public function doForbidden(): void { $this->forbidden(); }
    public function doConflict(): void { $this->conflict(); }
}
