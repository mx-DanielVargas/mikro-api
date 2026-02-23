<?php

namespace MikroApi\Tests;

use MikroApi\Validator;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    public function testRequiredFieldValidation(): void
    {
        $dto = new class {
            #[\MikroApi\Attributes\Validation\Required]
            public string $name;
        };

        $result = $this->validator->validate($dto::class, []);

        $this->assertTrue($this->validator->hasErrors());
        $this->assertArrayHasKey('name', $this->validator->getErrors());
    }

    public function testOptionalFieldCanBeOmitted(): void
    {
        $dto = new class {
            #[\MikroApi\Attributes\Validation\Optional]
            #[\MikroApi\Attributes\Validation\IsString]
            public ?string $bio;
        };

        $result = $this->validator->validate($dto::class, []);

        $this->assertFalse($this->validator->hasErrors());
    }

    public function testIsStringValidation(): void
    {
        $dto = new class {
            #[\MikroApi\Attributes\Validation\IsString]
            public string $name;
        };

        $result = $this->validator->validate($dto::class, ['name' => 123]);

        $this->assertTrue($this->validator->hasErrors());
    }

    public function testIsIntValidation(): void
    {
        $dto = new class {
            #[\MikroApi\Attributes\Validation\IsInt]
            public int $age;
        };

        $result = $this->validator->validate($dto::class, ['age' => '25']);
        $this->assertFalse($this->validator->hasErrors());
        $this->assertSame(25, $result->age);

        $result = $this->validator->validate($dto::class, ['age' => 'invalid']);
        $this->assertTrue($this->validator->hasErrors());
    }

    public function testIsEmailValidation(): void
    {
        $dto = new class {
            #[\MikroApi\Attributes\Validation\IsEmail]
            public string $email;
        };

        $result = $this->validator->validate($dto::class, ['email' => 'test@example.com']);
        $this->assertFalse($this->validator->hasErrors());

        $result = $this->validator->validate($dto::class, ['email' => 'invalid-email']);
        $this->assertTrue($this->validator->hasErrors());
    }

    public function testMinMaxLengthValidation(): void
    {
        $dto = new class {
            #[\MikroApi\Attributes\Validation\MinLength(3)]
            #[\MikroApi\Attributes\Validation\MaxLength(10)]
            public string $username;
        };

        $result = $this->validator->validate($dto::class, ['username' => 'ab']);
        $this->assertTrue($this->validator->hasErrors());

        $result = $this->validator->validate($dto::class, ['username' => 'validuser']);
        $this->assertFalse($this->validator->hasErrors());

        $result = $this->validator->validate($dto::class, ['username' => 'toolongusername']);
        $this->assertTrue($this->validator->hasErrors());
    }

    public function testMinMaxNumericValidation(): void
    {
        $dto = new class {
            #[\MikroApi\Attributes\Validation\Min(18)]
            #[\MikroApi\Attributes\Validation\Max(100)]
            public int $age;
        };

        $result = $this->validator->validate($dto::class, ['age' => 17]);
        $this->assertTrue($this->validator->hasErrors());

        $result = $this->validator->validate($dto::class, ['age' => 25]);
        $this->assertFalse($this->validator->hasErrors());

        $result = $this->validator->validate($dto::class, ['age' => 101]);
        $this->assertTrue($this->validator->hasErrors());
    }

    public function testIsInValidation(): void
    {
        $dto = new class {
            #[\MikroApi\Attributes\Validation\IsIn(['admin', 'user', 'guest'])]
            public string $role;
        };

        $result = $this->validator->validate($dto::class, ['role' => 'admin']);
        $this->assertFalse($this->validator->hasErrors());

        $result = $this->validator->validate($dto::class, ['role' => 'superadmin']);
        $this->assertTrue($this->validator->hasErrors());
    }

    public function testArrayValidation(): void
    {
        $dto = new class {
            #[\MikroApi\Attributes\Validation\IsArray]
            #[\MikroApi\Attributes\Validation\ArrayOf('string')]
            public array $tags;
        };

        $result = $this->validator->validate($dto::class, ['tags' => ['php', 'api']]);
        $this->assertFalse($this->validator->hasErrors());

        $result = $this->validator->validate($dto::class, ['tags' => ['php', 123]]);
        $this->assertTrue($this->validator->hasErrors());
    }

    public function testArrayUniqueValidation(): void
    {
        $dto = new class {
            #[\MikroApi\Attributes\Validation\IsArray]
            #[\MikroApi\Attributes\Validation\ArrayUnique]
            public array $items;
        };

        $result = $this->validator->validate($dto::class, ['items' => [1, 2, 3]]);
        $this->assertFalse($this->validator->hasErrors());

        $result = $this->validator->validate($dto::class, ['items' => [1, 2, 2, 3]]);
        $this->assertTrue($this->validator->hasErrors());
    }

    public function testMatchesPatternValidation(): void
    {
        $dto = new class {
            #[\MikroApi\Attributes\Validation\Matches('/^[A-Z][a-z]+$/')]
            public string $name;
        };

        $result = $this->validator->validate($dto::class, ['name' => 'John']);
        $this->assertFalse($this->validator->hasErrors());

        $result = $this->validator->validate($dto::class, ['name' => 'john']);
        $this->assertTrue($this->validator->hasErrors());
    }

    public function testMultipleValidationRules(): void
    {
        $dto = new class {
            #[\MikroApi\Attributes\Validation\Required]
            #[\MikroApi\Attributes\Validation\IsString]
            #[\MikroApi\Attributes\Validation\MinLength(3)]
            #[\MikroApi\Attributes\Validation\MaxLength(50)]
            #[\MikroApi\Attributes\Validation\IsEmail]
            public string $email;
        };

        $result = $this->validator->validate($dto::class, ['email' => 'test@example.com']);
        $this->assertFalse($this->validator->hasErrors());

        $result = $this->validator->validate($dto::class, ['email' => 'ab']);
        $this->assertTrue($this->validator->hasErrors());
        $errors = $this->validator->getErrors();
        $this->assertGreaterThan(1, count($errors['email']));
    }

    public function testTypeCasting(): void
    {
        $dto = new class {
            #[\MikroApi\Attributes\Validation\IsInt]
            public int $age;

            #[\MikroApi\Attributes\Validation\IsBool]
            public bool $active;

            #[\MikroApi\Attributes\Validation\IsFloat]
            public float $price;
        };

        $result = $this->validator->validate($dto::class, [
            'age' => '25',
            'active' => '1',
            'price' => '19.99'
        ]);

        $this->assertFalse($this->validator->hasErrors());
        $this->assertSame(25, $result->age);
        $this->assertTrue($result->active);
        $this->assertSame(19.99, $result->price);
    }
}
