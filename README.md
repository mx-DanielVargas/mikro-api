# MikroAPI

**MikroAPI** is a minimalist PHP framework inspired by NestJS, built with pure PHP 8.1+ without external dependencies.

## Features

- Attribute-Based Routing
- Automatic DTO Validation
- Built-in JWT Authentication
- Dependency Injection Container with Autowiring
- Middleware Pipeline (CORS, Rate Limiting, JSON Body validation)
- Immutable Response Objects
- Repository Pattern with Query Builder & SQL Injection Protection
- Database Transactions
- Attribute-Driven Migrations
- Zero External Dependencies
- Environment Configuration (.env) with Typed Accessors
<<<<<<< feat/view-engine-and-fixes
- Template Engine with Layouts, Sections & Includes
=======
>>>>>>> master
- Swagger Documentation with Query Parameters
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

## Dependency Injection

MikroAPI includes a DI container with autowiring. Controllers and guards are resolved automatically through the container, so constructor dependencies are injected.

### Register Dependencies

```php
use MikroApi\App;
use MikroApi\Container;
use MikroApi\Database\Database;
use App\Repositories\UserRepository;
use App\Services\UserService;
use App\Controllers\UserController;

$container = new Container();

// Register a singleton with a factory
$container->singleton(Database::class, fn() => Database::connect([
    'driver'   => 'sqlite',
    'database' => __DIR__ . '/database.sqlite',
]));

// Register classes (autowired from constructor type-hints)
$container->singleton(UserRepository::class);
$container->singleton(UserService::class);

$app = new App($container);
$app->useController(UserController::class);
$app->run();
```

### Controller with Injected Dependencies

```php
#[Controller('/api/users')]
class UserController
{
    public function __construct(
        private UserService $userService,
    ) {}

    #[Route('GET', '/')]
    public function index(Request $req): Response
    {
        return Response::json($this->userService->findAll());
    }
}
```

The container resolves the full dependency tree: `UserController` ← `UserService` ← `UserRepository` ← `Database`.

### Container API

```php
$container->set(MyClass::class);                          // Autowired, new instance each time
$container->set(Interface::class, ConcreteClass::class);  // Bind interface to implementation
$container->set(MyClass::class, fn($c) => new MyClass()); // Custom factory
$container->singleton(MyClass::class);                    // Autowired, single instance
$container->instance(MyClass::class, $obj);               // Pre-built instance
$container->get(MyClass::class);                          // Resolve
$container->has(MyClass::class);                          // Check if registered
```

## Middleware

MikroAPI supports a middleware pipeline. Middlewares wrap the request/response cycle and can modify both.

### Create a Middleware

```php
<?php
namespace App\Middleware;

use MikroApi\Middleware\MiddlewareInterface;
use MikroApi\Request;
use MikroApi\Response;

class LoggingMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        $elapsed = round((microtime(true) - $start) * 1000, 2);
        return $response->withHeader('X-Response-Time', "{$elapsed}ms");
    }
}
```

### Register Middleware

```php
use App\Middleware\LoggingMiddleware;
use MikroApi\Middleware\CorsMiddleware;

$app = new App();
$app->useMiddleware(
    new CorsMiddleware(),
    new LoggingMiddleware(),
);
$app->useController(UserController::class);
$app->run();
```

Middlewares execute in registration order. Each calls `$next($request)` to pass to the next middleware (or the router).

### Built-in CORS Middleware

```php
use MikroApi\Middleware\CorsMiddleware;

// Default: allows all origins
$app->useMiddleware(new CorsMiddleware());

// Restricted
$app->useMiddleware(new CorsMiddleware(
    origins: ['https://example.com', 'https://app.example.com'],
    methods: ['GET', 'POST', 'PUT', 'DELETE'],
    headers: ['Content-Type', 'Authorization'],
    maxAge:  7200,
));
```

Handles `OPTIONS` preflight requests automatically.

### Built-in Rate Limiting

```php
use MikroApi\Middleware\RateLimitMiddleware;

// 60 requests per minute (default)
$app->useMiddleware(new RateLimitMiddleware());

// Custom limits
$app->useMiddleware(new RateLimitMiddleware(
    maxRequests:   100,
    windowSeconds: 120,
));
```

Adds `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers. Returns `429 Too Many Requests` with `Retry-After` when exceeded.

### JSON Body Validation

```php
use MikroApi\Middleware\JsonBodyMiddleware;

$app->useMiddleware(new JsonBodyMiddleware());
```

Rejects `POST`/`PUT`/`PATCH` requests with malformed JSON bodies (returns `400 Invalid JSON`).

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
$app = new App();

$app->useController(
    UserController::class,
    ProductController::class
);

$app->enableSwagger(
    config: [
        'title'       => 'My API',
        'version'     => '1.0.0',
        'description' => 'API documentation',
        'servers'     => [
            ['url' => 'http://localhost:8000', 'description' => 'Local'],
        ],
    ],
    path: '/docs',
    jsonPath: '/docs/json',
    authGuards: [JwtGuard::class],
);

$app->run();
```

### Query Parameters

Document query parameters with the `#[QueryParam]` attribute:

```php
use MikroApi\Attributes\QueryParam;

#[Route('GET', '/')]
#[QueryParam('page', type: 'integer', description: 'Page number', example: 1)]
#[QueryParam('limit', type: 'integer', description: 'Items per page', example: 15)]
#[QueryParam('search', description: 'Search term')]
#[ApiDoc(summary: 'List all users')]
public function index(Request $req): Response
{
    $page  = (int) ($req->query['page'] ?? 1);
    $limit = (int) ($req->query['limit'] ?? 15);
    return Response::json($this->userService->paginate($page, $limit));
}
```

### Enrich Documentation with Attributes

```php
use MikroApi\Attributes\ApiTag;
use MikroApi\Attributes\ApiDoc;

#[Controller('/api/users')]
#[ApiTag(name: 'Users', description: 'User management endpoints')]
class UserController
{
    #[Route('GET', '/')]
    #[ApiDoc(
        summary: 'List all users',
        description: 'Returns a paginated list of users',
        responses: [200 => 'Success', 401 => 'Unauthorized']
    )]
    #[UseGuards(JwtGuard::class)]
    public function index(Request $req): Response { /* ... */ }

    #[Route('GET', '/internal/stats')]
    #[ApiDoc(exclude: true)] // Exclude from documentation
    public function internalStats(Request $req): Response { /* ... */ }
}
```

### Exclude Controllers from Documentation

```php
$app->enableSwagger(
    config: ['title' => 'My API', 'version' => '1.0.0'],
    excludeControllers: [InternalController::class],
);
```

### Access Documentation

- **Swagger UI**: `http://localhost:8000/docs`
- **OpenAPI JSON**: `http://localhost:8000/docs/json`

### Automatic Features

Swagger automatically detects:
- ✅ Route paths and HTTP methods
- ✅ Path parameters (`:id` → `{id}`)
- ✅ Query parameters from `#[QueryParam]`
- ✅ Request body schemas from DTOs
- ✅ Validation rules as schema constraints
- ✅ Authentication requirements from guards
- ✅ Response codes (200, 201, 401, 422, etc.)

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

The repository supports `mixed` primary keys (int, UUID, string).

### Query Builder

```php
$repo->query()
    ->select('id', 'name', 'email')
    ->where('active', 1)
    ->where('role', 'admin')
    ->orWhere('role', 'moderator')
    ->orderBy('name')
    ->limit(10)
    ->offset(20)
    ->get();

// Pagination
$repo->paginate(page: 1, perPage: 15);

// Soft deletes
$repo->query()->withTrashed()->get();
```

### Relations

```php
use MikroApi\Attributes\Relation\HasMany;
use MikroApi\Attributes\Relation\BelongsTo;

class UserRepository extends BaseRepository
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email'];

    #[HasMany(repository: PostRepository::class, foreignKey: 'user_id')]
    public array $posts;
}

// Eager loading (avoids N+1)
$repo->with('posts')->findAll();
$repo->with('posts.comments')->findById(1);
```

Relations respect soft deletes automatically.

## Migrations

### Create Migration

```bash
vendor/bin/mikro-migrate make create_users_table
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
vendor/bin/mikro-migrate migrate      # Run pending
vendor/bin/mikro-migrate rollback     # Rollback last
vendor/bin/mikro-migrate status       # Check status
vendor/bin/mikro-migrate reset        # Reset all
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

## Database Transactions

```php
$db = Database::getInstance();

$db->transaction(function () use ($userRepo, $orderRepo, $data) {
    $user  = $userRepo->create($data['user']);
    $order = $orderRepo->create(['user_id' => $user['id'], ...$data['order']]);
    return $order;
});
```

Automatically rolls back on exception and re-throws.

<<<<<<< feat/view-engine-and-fixes
## Template Engine

MikroAPI includes a built-in template engine with Blade-like syntax.

### Setup

```php
$app = new App();
$app->useViews(__DIR__ . '/views');
```

### Use in Controllers

```php
#[Route('GET', '/')]
public function index(Request $req): Response
{
    return Response::render('home', ['title' => 'Welcome', 'items' => ['A', 'B']]);
}
```

### Template Syntax

```php
// views/layout.php
<html>
<head><title>{{ $title }}</title></head>
<body>
    @include('partials.nav')
    @yield('content')
</body>
</html>

// views/home.php
@extends('layout')
@section('content')
    <h1>{{ $title }}</h1>
    @foreach($items as $item)
        <p>{{ $item }}</p>
    @endforeach
    @if($items)
        <span>{{ count($items) }} items</span>
    @else
        <span>No items</span>
    @endif
@endsection
```

### Directives

| Directive | Description |
|-----------|-------------|
| `{{ $var }}` | Escaped output (XSS-safe) |
| `{!! $var !!}` | Raw output (no escaping) |
| `@if` / `@elseif` / `@else` / `@endif` | Conditionals |
| `@foreach($items as $item)` / `@endforeach` | Loops |
| `@include('partial.name')` | Include sub-template (dot notation) |
| `@extends('layout')` | Inherit from a layout |
| `@section('name')` / `@endsection` | Define a section |
| `@yield('name')` | Render a section in layout |

=======
>>>>>>> master
## Configuration

MikroAPI includes a configuration service inspired by `@nestjs/config` for managing environment variables.

### Setup

```php
$app = new App();
$app->useConfig(__DIR__); // loads .env from project root
```

This loads your `.env` file and registers `ConfigService` in the container for injection.

### .env File

```env
APP_ENV=development
DB_HOST=localhost
DB_PORT=3306
DB_NAME=myapp
JWT_SECRET=my-secret-key

# Variable interpolation
APP_URL=http://${DB_HOST}:8000
```

Environment-specific overrides are loaded automatically: if `APP_ENV=production`, then `.env.production` is also loaded.

### Use in Services

```php
use MikroApi\Config\ConfigService;

class MailService
{
    public function __construct(private ConfigService $config) {}

    public function send(string $to, string $subject, string $body): bool
    {
        $host = $this->config->get('SMTP_HOST', 'localhost');
        $port = $this->config->getInt('SMTP_PORT', 587);
        // ...
    }
}
```

### Typed Accessors

```php
$config->get('KEY');                  // string|null
$config->get('KEY', 'default');       // with default
$config->getOrThrow('KEY');           // throws if missing
$config->getInt('PORT', 3306);        // int
$config->getBool('DEBUG', false);     // bool
$config->getFloat('RATE', 0.5);      // float
$config->set('KEY', 'value');         // runtime override
```

### Namespaced Config

Group related config with `register()`, then access via dot-notation:

```php
$config->register('database', [
    'host' => $config->get('DB_HOST', 'localhost'),
    'port' => $config->getInt('DB_PORT', 3306),
    'name' => $config->getOrThrow('DB_NAME'),
]);

$config->get('database.host');  // 'localhost'
$config->get('database.port');  // 3306
```

### Validation

Ensure required variables are present at startup:

```php
$config->validate(['DB_HOST', 'DB_NAME', 'JWT_SECRET']);
// Throws RuntimeException listing all missing keys
```

## Error Handling

In production, set `APP_ENV=production` to hide internal error details:

```php
// .env or server config
APP_ENV=production
```

In development, full error messages are returned. In production, only `"Internal Server Error"` is shown for unhandled exceptions. `ServiceException` messages are always returned with their status code.

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
- `examples/views/` - Template engine with layouts and partials

## License

MIT

## Contributing

Contributions are welcome! Please see CONTRIBUTING.md for details.
