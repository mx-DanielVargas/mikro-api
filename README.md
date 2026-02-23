# MikroAPI

**MikroAPI** is a minimalist PHP framework inspired by NestJS, built with pure PHP 8+ without external dependencies.

## Features

- Attribute-Based Routing
- Automatic DTO Validation
- Built-in JWT Authentication
- Repository Pattern with Query Builder
- Attribute-Driven Migrations
- Zero External Dependencies
- Swagger Documentation
- Soft Deletes Support

## Installation

```bash
composer require mikro-api/mikro-api
```

## Quick Start

### Create a Controller

```php
<?php
namespace App\Controllers;

use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Route;
use MikroApi\Request;
use MikroApi\Response;

#[Controller('/api/products')]
class ProductController
{
    #[Route('GET', '/')]
    public function index(Request $req): Response
    {
        return Response::json(['products' => []]);
    }

    #[Route('GET', '/:id')]
    public function show(Request $req): Response
    {
        return Response::json(['id' => $req->params['id']]);
    }
}
```

### Bootstrap Application

```php
<?php
// index.php
require_once __DIR__ . '/vendor/autoload.php';

use MikroApi\App;
use App\Controllers\ProductController;

$app = new App();
$app->useController(ProductController::class)->run();
```

### Start Server

```bash
php -S localhost:8000 index.php
```

## Validation with DTOs

```php
<?php
namespace App\Dto;

use MikroApi\RequestDto;
use MikroApi\Attributes\Validation\Required;
use MikroApi\Attributes\Validation\IsEmail;
use MikroApi\Attributes\Validation\MinLength;

class CreateUserDto extends RequestDto
{
    #[Required]
    #[MinLength(3)]
    public string $name;

    #[Required]
    #[IsEmail]
    public string $email;

    #[Required]
    #[MinLength(8)]
    public string $password;
}
```

Use in controller:

```php
use MikroApi\Attributes\Body;

#[Route('POST', '/')]
#[Body(CreateUserDto::class)]
public function create(Request $req): Response
{
    $dto = $req->dto; // Already validated!
    return Response::json(['name' => $dto->name], 201);
}
```

## Authentication

```php
use MikroApi\Attributes\UseGuards;
use App\Guards\JwtGuard;

#[Controller('/api/admin')]
#[UseGuards(JwtGuard::class)]
class AdminController
{
    #[Route('GET', '/users')]
    public function users(Request $req): Response
    {
        $auth = $req->params['_auth'];
        return Response::json(['user' => $auth]);
    }
}
```

## Swagger Documentation

MikroAPI automatically generates OpenAPI 3.0 documentation from your controllers and DTOs.

### Enable Swagger

```php
<?php
// index.php
require_once __DIR__ . '/vendor/autoload.php';

use MikroApi\App;
use App\Controllers\UserController;
use App\Controllers\ProductController;

$app = new App();

$app->useController(
    UserController::class,
    ProductController::class
);

// Enable Swagger UI
$app->enableSwagger(
    config: [
        'title'       => 'My API',
        'version'     => '1.0.0',
        'description' => 'API documentation for My Application',
        'servers'     => [
            ['url' => 'http://localhost:8000', 'description' => 'Local'],
            ['url' => 'https://api.example.com', 'description' => 'Production'],
        ],
    ],
    path: '/docs',           // Swagger UI path (default: /docs)
    jsonPath: '/docs/json',  // OpenAPI JSON path (default: /docs/json)
    authGuards: [JwtGuard::class], // Guards that require Bearer auth
);

$app->run();
```

### Enrich Documentation with Attributes

```php
<?php
namespace App\Controllers;

use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Route;
use MikroApi\Attributes\Body;
use MikroApi\Attributes\ApiTag;
use MikroApi\Attributes\ApiDoc;
use MikroApi\Attributes\UseGuards;
use App\Dto\CreateUserDto;
use App\Dto\UpdateUserDto;
use App\Guards\JwtGuard;

#[Controller('/api/users')]
#[ApiTag(name: 'Users', description: 'User management endpoints')]
class UserController
{
    #[Route('GET', '/')]
    #[ApiDoc(
        summary: 'List all users',
        description: 'Returns a paginated list of users',
        responses: [
            200 => 'Success',
            401 => 'Unauthorized',
        ]
    )]
    #[UseGuards(JwtGuard::class)]
    public function index(Request $req): Response
    {
        return Response::json(['users' => []]);
    }

    #[Route('POST', '/')]
    #[Body(CreateUserDto::class)]
    #[ApiDoc(
        summary: 'Create a new user',
        description: 'Creates a new user with the provided data'
    )]
    public function create(Request $req): Response
    {
        return Response::json(['user' => $req->dto], 201);
    }

    #[Route('GET', '/:id')]
    #[ApiDoc(summary: 'Get user by ID')]
    #[UseGuards(JwtGuard::class)]
    public function show(Request $req): Response
    {
        return Response::json(['id' => $req->params['id']]);
    }

    #[Route('PUT', '/:id')]
    #[Body(UpdateUserDto::class)]
    #[ApiDoc(summary: 'Update user')]
    #[UseGuards(JwtGuard::class)]
    public function update(Request $req): Response
    {
        return Response::json(['updated' => true]);
    }

    #[Route('DELETE', '/:id')]
    #[ApiDoc(
        summary: 'Delete user',
        deprecated: true
    )]
    #[UseGuards(JwtGuard::class)]
    public function delete(Request $req): Response
    {
        return Response::json(['deleted' => true]);
    }

    #[Route('GET', '/internal/stats')]
    #[ApiDoc(exclude: true)] // Exclude from documentation
    public function internalStats(Request $req): Response
    {
        return Response::json(['stats' => []]);
    }
}
```

### Exclude Controllers from Documentation

```php
$app->enableSwagger(
    config: ['title' => 'My API', 'version' => '1.0.0'],
    excludeControllers: [
        InternalController::class,
        DebugController::class,
    ]
);
```

### Access Documentation

Once enabled, visit:
- **Swagger UI**: `http://localhost:8000/docs`
- **OpenAPI JSON**: `http://localhost:8000/docs/json`

### Automatic Features

Swagger automatically detects:
- ✅ Route paths and HTTP methods
- ✅ Path parameters (`:id` → `{id}`)
- ✅ Request body schemas from DTOs
- ✅ Validation rules as schema constraints
- ✅ Authentication requirements from guards
- ✅ Response codes (200, 201, 401, 422, etc.)

### DTO Schema Generation

DTOs are automatically converted to OpenAPI schemas:

```php
class CreateProductDto extends RequestDto
{
    #[Required]
    #[MinLength(3)]
    #[MaxLength(100)]
    public string $name;

    #[Required]
    #[Min(0)]
    public float $price;

    #[Optional]
    #[MaxLength(500)]
    public ?string $description;

    #[Required]
    #[IsIn(['active', 'inactive', 'draft'])]
    public string $status;

    #[Optional]
    #[IsArray]
    #[ArrayOf('string')]
    public array $tags;
}
```

Generates:

```json
{
  "CreateProductDto": {
    "type": "object",
    "required": ["name", "price", "status"],
    "properties": {
      "name": {
        "type": "string",
        "minLength": 3,
        "maxLength": 100
      },
      "price": {
        "type": "number",
        "minimum": 0
      },
      "description": {
        "type": "string",
        "maxLength": 500
      },
      "status": {
        "type": "string",
        "enum": ["active", "inactive", "draft"]
      },
      "tags": {
        "type": "array",
        "items": {
          "type": "string"
        }
      }
    }
  }
}
```

## Repository Pattern

```php
<?php
namespace App\Repositories;

use MikroApi\Repository\BaseRepository;

class UserRepository extends BaseRepository
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email', 'password'];
    
    public function findByEmail(string $email): ?array
    {
        return $this->findOneBy('email', $email);
    }
}
```

## Migrations

### Create Migration

```bash
# Using bin command
vendor/bin/mikro-migrate make create_users_table

# Or using composer script
composer exec mikro-migrate make create_users_table
```

### Define Schema

```php
<?php
namespace Database\Migrations;

use MikroApi\Database\Migration;
use MikroApi\Attributes\Schema\Table;
use MikroApi\Attributes\Schema\Column;
use MikroApi\Attributes\Schema\PrimaryKey;
use MikroApi\Attributes\Schema\Timestamps;

#[Table('users')]
#[Timestamps]
class CreateUsersTable extends Migration
{
    #[PrimaryKey]
    #[Column(type: 'int')]
    public int $id;

    #[Column(type: 'varchar', length: 100)]
    public string $name;

    #[Column(type: 'varchar', length: 150)]
    public string $email;
}
```

### Run Migrations

```bash
# Run pending migrations
vendor/bin/mikro-migrate migrate

# Rollback last migration
vendor/bin/mikro-migrate rollback

# Check migration status
vendor/bin/mikro-migrate status

# Reset all migrations
vendor/bin/mikro-migrate reset
```

### Using Composer Scripts

```bash
composer exec mikro-migrate migrate
composer exec mikro-migrate rollback
composer exec mikro-migrate status
composer exec mikro-migrate reset
```

## Database Configuration

Create `config/database.php`:

```php
<?php
return [
    'driver'   => 'mysql',
    'host'     => getenv('DB_HOST') ?: 'localhost',
    'port'     => 3306,
    'database' => getenv('DB_DATABASE') ?: 'myapp',
    'username' => getenv('DB_USERNAME') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'charset'  => 'utf8mb4',
];
```

For SQLite:

```php
<?php
return [
    'driver'   => 'sqlite',
    'database' => __DIR__ . '/../database/database.sqlite',
];
```

## Available Validation Rules

| Category | Rules |
|----------|-------|
| **Presence** | `Required`, `Optional` |
| **Types** | `IsString`, `IsInt`, `IsFloat`, `IsBool`, `IsArray` |
| **Format** | `IsEmail`, `IsUrl`, `Matches(pattern)`, `IsIn([...])` |
| **Length** | `MinLength(n)`, `MaxLength(n)`, `Length(min, max)` |
| **Range** | `Min(n)`, `Max(n)` |
| **Arrays** | `ArrayUnique`, `ArrayOf(type)` |

## Examples

Check the `examples/` directory for complete working examples:

- `examples/basic/` - Simple API with CRUD operations
- `examples/auth/` - JWT Authentication implementation
- `examples/swagger/` - Complete Swagger/OpenAPI documentation example

## License

MIT

## Contributing

Contributions are welcome! Please see CONTRIBUTING.md for details.
