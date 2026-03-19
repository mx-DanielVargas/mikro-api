<?php

namespace MikroApi\Tests;

use MikroApi\Swagger\DtoSchemaBuilder;
use MikroApi\Attributes\Validation\Required;
use MikroApi\Attributes\Validation\Optional;
use MikroApi\Attributes\Validation\IsString;
use MikroApi\Attributes\Validation\IsInt;
use MikroApi\Attributes\Validation\IsEmail;
use MikroApi\Attributes\Validation\MinLength;
use MikroApi\Attributes\Validation\MaxLength;
use MikroApi\Attributes\Validation\Min;
use MikroApi\Attributes\Validation\Max;
use MikroApi\Attributes\Validation\IsIn;
use PHPUnit\Framework\TestCase;

class DtoSchemaBuilderTest extends TestCase
{
    private DtoSchemaBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new DtoSchemaBuilder();
    }

    public function testBasicSchema(): void
    {
        $schema = $this->builder->build(SchemaTestDto::class);

        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertArrayHasKey('email', $schema['properties']);
    }

    public function testRequiredFields(): void
    {
        $schema = $this->builder->build(SchemaTestDto::class);

        $this->assertContains('name', $schema['required']);
        $this->assertContains('email', $schema['required']);
    }

    public function testOptionalFieldNotRequired(): void
    {
        $schema = $this->builder->build(SchemaOptionalDto::class);

        $this->assertNotContains('bio', $schema['required'] ?? []);
    }

    public function testEmailFormat(): void
    {
        $schema = $this->builder->build(SchemaTestDto::class);

        $this->assertEquals('email', $schema['properties']['email']['format']);
    }

    public function testMinMaxLength(): void
    {
        $schema = $this->builder->build(SchemaTestDto::class);

        $this->assertEquals(2, $schema['properties']['name']['minLength']);
        $this->assertEquals(50, $schema['properties']['name']['maxLength']);
    }

    public function testNumericRange(): void
    {
        $schema = $this->builder->build(SchemaRangeDto::class);

        $this->assertEquals(0, $schema['properties']['age']['minimum']);
        $this->assertEquals(150, $schema['properties']['age']['maximum']);
    }

    public function testEnumValues(): void
    {
        $schema = $this->builder->build(SchemaEnumDto::class);

        $this->assertEquals(['admin', 'user'], $schema['properties']['role']['enum']);
    }

    public function testTypeInference(): void
    {
        $schema = $this->builder->build(SchemaTypedDto::class);

        $this->assertEquals('string', $schema['properties']['name']['type']);
        $this->assertEquals('integer', $schema['properties']['count']['type']);
        $this->assertEquals('boolean', $schema['properties']['active']['type']);
    }

    public function testNullable(): void
    {
        $schema = $this->builder->build(SchemaNullableDto::class);

        $this->assertTrue($schema['properties']['bio']['nullable']);
    }
}

// ── Test DTOs ───────────────────────────────────────────────────────────

class SchemaTestDto
{
    #[Required]
    #[IsString]
    #[MinLength(2)]
    #[MaxLength(50)]
    public string $name;

    #[Required]
    #[IsEmail]
    public string $email;
}

class SchemaOptionalDto
{
    #[Required]
    public string $name;

    #[Optional]
    public ?string $bio;
}

class SchemaRangeDto
{
    #[IsInt]
    #[Min(0)]
    #[Max(150)]
    public int $age;
}

class SchemaEnumDto
{
    #[IsIn(['admin', 'user'])]
    public string $role;
}

class SchemaTypedDto
{
    public string $name;
    public int $count;
    public bool $active;
}

class SchemaNullableDto
{
    public ?string $bio;
}
