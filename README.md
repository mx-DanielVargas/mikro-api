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

- `examples/basic/` - Simple API
- `examples/auth/` - JWT Authentication

## License

MIT

## Contributing

Contributions are welcome! Please see CONTRIBUTING.md for details.
