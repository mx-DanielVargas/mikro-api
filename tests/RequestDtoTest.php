<?php

namespace MikroApi\Tests;

use MikroApi\RequestDto;
use PHPUnit\Framework\TestCase;

class RequestDtoTest extends TestCase
{
    public function testToArray(): void
    {
        $dto = new TestRequestDto();
        $dto->name = 'John';
        $dto->email = 'john@example.com';

        $arr = $dto->toArray();

        $this->assertEquals('John', $arr['name']);
        $this->assertEquals('john@example.com', $arr['email']);
    }

    public function testFromArray(): void
    {
        $dto = TestRequestDto::fromArray([
            'name' => 'Jane',
            'email' => 'jane@example.com',
        ]);

        $this->assertEquals('Jane', $dto->name);
        $this->assertEquals('jane@example.com', $dto->email);
    }

    public function testFromArrayIgnoresUnknownKeys(): void
    {
        $dto = TestRequestDto::fromArray([
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'unknown' => 'ignored',
        ]);

        $this->assertEquals('Jane', $dto->name);
    }
}

class TestRequestDto extends RequestDto
{
    public string $name;
    public string $email;
}
