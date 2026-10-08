# MikroAPI

**MikroAPI** is a minimalist PHP framework inspired by NestJS, built with pure PHP 8.1+ without external dependencies.

## Features

- Attribute-Based Routing
- Modules with encapsulated providers, dynamic modules and lifecycle hooks
- Handler parameter injection (`#[Param]`, `#[Query]`, `#[Body]`, `#[Headers]`, `#[CurrentUser]`) with automatic type conversion
- Automatic DTO Validation (body, query string and route params)
- Built-in JWT Authentication (`JwtService`, `JwtGuard`) and role-based authorization (`#[Roles]`, `RolesGuard`)
- Guards, Interceptors and Exception Filters (per method, per controller or global)
- Custom route metadata (`#[SetMetadata]`) readable through `Reflector`
- `HttpException` hierarchy with automatic JSON responses, `405 Method Not Allowed` with `Allow` header
- Dependency Injection Container with Autowiring
- Middleware Pipeline (CORS, Rate Limiting, JSON Body validation)
- Immutable Response Objects
- Repository Pattern with Query Builder & SQL Injection Protection
- SQLite, MySQL/MariaDB, PostgreSQL and Turso/libSQL
- Database Transactions
- Attribute-Driven Migrations
- Zero External Dependencies
- Environment Configuration (.env) with Typed Accessors
- Template Engine with Layouts, Sections & Includes
- Swagger Documentation with Query Parameters
- Soft Deletes Support
- `mikro` CLI: project scaffolding and generators for every building block (including full CRUD resources)

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

> ⚠️ By default, `RateLimitMiddleware` stores counters in process memory (`InMemoryRateLimitStore`), which does **not** reliably persist across requests in PHP-FPM/Apache deployments without a persistent process — each request may be handled by a different worker/process. For production deployments with multiple workers or servers, pass a persistent store instead:
>
> ```php
> $app->useMiddleware(new RateLimitMiddleware(60, 60, new ApcuRateLimitStore()));
> ```
>
> `ApcuRateLimitStore` requires the `apcu` PHP extension. You can also implement `RateLimitStore` yourself to back it with Redis or another shared cache.

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

## Parameter Injection

Handlers can declare exactly what they need instead of reading `$req` (the classic `function (Request $req)` signature keeps working). Scalar types are converted automatically; a value that can't be converted returns `400`, and DTOs are validated (`422` on failure).

```php
use MikroApi\Attributes\{Param, Query, Body, Headers, CurrentUser};

enum Status: string { case Active = 'active'; case Archived = 'archived'; }

#[Controller('/api/products')]
class ProductController
{
    #[Route('GET', '/')]
    public function index(
        #[Query('page')] int $page = 1,                 // ?page=3 → 3 (int); missing → default
        #[Query('status')] Status $status = Status::Active, // backed enums are supported (400 if invalid)
        #[Query('q')] ?string $q = null,                // nullable → null when missing
    ): array {
        return [...];                                   // non-Response values are sent as JSON
    }

    #[Route('GET', '/search')]
    public function search(#[Query] SearchQuery $query): array { ... } // whole query string validated against a DTO

    #[Route('PUT', '/:id')]
    public function update(
        #[Param('id')] int $id,                         // "abc" → 400
        #[Body] UpdateProductDto $dto,                  // validated body (also available as $req->dto)
        #[Headers('X-Tenant')] ?string $tenant,
        #[CurrentUser('sub')] int $userId,              // from $request->user (set by JwtGuard)
        Request $req,                                   // the Request is still injectable
    ): array { ... }
}
```

| Attribute | Source | Without a name |
|---|---|---|
| `#[Param('id')]` | route params | all params (`array`) or a validated DTO |
| `#[Query('page')]` | query string | whole query (`array`) or a validated DTO |
| `#[Body('email')]` | body field | `#[Body] Dto $dto` validated DTO, `#[Body] array $body` raw body |
| `#[Headers('X-Tenant')]` | headers (case-insensitive) | all headers (`array`) |
| `#[CurrentUser('sub')]` | `$request->user` | the whole user (401 if missing and not nullable) |

Also injectable by type: `Request` and `ExecutionContext`. Supported conversions: `int`, `float`, `bool`, `string`, `array` and backed/pure enums. An empty value (`?page=`) counts as missing for non-string types. A parameter that can't be injected throws `LogicException` when the controller is registered.

## Authentication

MikroAPI ships a dependency-free JWT implementation (HS256/HS384/HS512).

```php
use MikroApi\Auth\{JwtService, JwtGuard, RolesGuard};
use MikroApi\Attributes\{UseGuards, Roles, PublicRoute};

// Bootstrap: the secret can't be autowired, register the service explicitly
$app->getContainer()->singleton(JwtService::class, fn() => new JwtService(
    secret: $config->getOrThrow('JWT_SECRET'),
    ttl:    3600,          // seconds, 0 = no expiration
    // algorithm: 'HS256', leeway: 0, issuer: null
));

#[Controller('/auth')]
class AuthController
{
    public function __construct(private JwtService $jwt) {}

    #[Route('POST', '/login')]
    #[PublicRoute]                                     // skipped by JwtGuard
    public function login(#[Body] LoginDto $dto): array
    {
        // ... check credentials ...
        return ['token' => $this->jwt->sign(['sub' => $user['id'], 'roles' => ['admin']])];
    }
}

#[Controller('/api/admin')]
#[UseGuards(JwtGuard::class, RolesGuard::class)]
class AdminController
{
    #[Route('GET', '/me')]
    public function me(#[CurrentUser] array $user): array
    {
        return $user;                                  // the verified JWT payload
    }

    #[Route('DELETE', '/users/:id')]
    #[Roles('admin')]                                  // RolesGuard → 403 without the role
    public function delete(#[Param('id')] int $id): Response { ... }
}
```

- `JwtGuard` reads `Authorization: Bearer <token>`, verifies signature, `exp`, `nbf` (and `iss` if configured) and stores the payload in `$request->user`. Failures return `401` with the reason (`Token no proporcionado`, `Token expirado`, `Token inválido`). Tokens signed with a different algorithm (including `none`) are rejected.
- `RolesGuard` compares `#[Roles(...)]` (method, or class if the method has none) against `$request->user['roles']` (array) or `['role']` (string). Having any one of the roles is enough.
- To protect everything by default, register it globally and opt out with `#[PublicRoute]`:

```php
$app->useGlobalGuards(JwtGuard::class, RolesGuard::class);
```

Custom guards keep implementing `GuardInterface` (`canActivate(Request)` + `deny()`); they can also throw any `HttpException` to respond with a specific status and message.

### Custom Metadata (`SetMetadata` + `Reflector`)

Guards, interceptors and filters can read metadata from the current route through `$request->context` (an `ExecutionContext`) and `Reflector`:

```php
use MikroApi\Attributes\SetMetadata;
use MikroApi\Reflector;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Permissions extends SetMetadata
{
    public function __construct(string ...$perms) { parent::__construct('permissions', $perms); }
}

class PermissionsGuard extends BaseGuard
{
    public function __construct(private Reflector $reflector) {}

    public function canActivate(Request $request): bool
    {
        $required = $this->reflector->getAllAndOverride('permissions', $request->context) ?? [];
        return empty(array_diff($required, $request->user['permissions'] ?? []));
    }
}

#[Permissions('users:write')]
#[Route('PUT', '/:id')]
public function update(...) { ... }
```

`Reflector` methods: `getAllAndOverride($key, $ctx)` (method value, else class value), `getAllAndMerge($key, $ctx)` (both, flattened), `getHandlerMetadata`, `getClassMetadata`, `has`, and `getAttribute(SomeAttribute::class, $ctx)` for any attribute class. `#[SetMetadata('key', $value)]` can also be used directly.

## Interceptors

Interceptors wrap the handler: they run after guards, before parameter validation, and can act before/after the handler, transform its result or short-circuit it.

```php
use MikroApi\Interceptor\InterceptorInterface;
use MikroApi\ExecutionContext;

class TimingInterceptor implements InterceptorInterface
{
    public function intercept(ExecutionContext $context, callable $next): mixed
    {
        $start  = microtime(true);
        $result = $next();                         // rest of the chain + handler
        $ms     = round((microtime(true) - $start) * 1000, 2);

        $response = $result instanceof Response ? $result : Response::json($result);
        return $response->withHeader('X-Response-Time', "{$ms}ms");
    }
}

class WrapDataInterceptor implements InterceptorInterface
{
    public function intercept(ExecutionContext $context, callable $next): mixed
    {
        return ['data' => $next()];               // transform the raw handler result
    }
}

#[UseInterceptors(TimingInterceptor::class)]       // class or method level
class ProductController { ... }

$app->useGlobalInterceptors(TimingInterceptor::class);
```

Order (outermost first): global → controller → method. Request pipeline: middlewares → guards → interceptors → parameter validation/injection → handler; exceptions from any of these go through the exception filters.

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
- ✅ Path parameters (`:id` → `{id}`), typed from `#[Param('id')] int $id`
- ✅ Query parameters from `#[QueryParam]`, typed `#[Query('page')]` parameters and `#[Query] Dto` properties
- ✅ Request body schemas from DTOs (`#[Body(Dto::class)]` on the method or `#[Body] Dto $dto` on a parameter)
- ✅ Validation rules as schema constraints
- ✅ Authentication requirements from guards (routes marked `#[PublicRoute]` are documented without security)
- ✅ Response codes (200, 201, 400, 401, 403, 422, etc.)

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

## CLI

`vendor/bin/mikro` scaffolds projects, generates every building block and runs migrations. Full reference: [`CLI.md`](./CLI.md).

```bash
vendor/bin/mikro new                              # modular project: AppModule + ConfigModule, .env with JWT_SECRET, Swagger at /docs
vendor/bin/mikro make:resource products           # module + CRUD controller + service + repository + DTOs + migration, registered in AppModule
vendor/bin/mikro g controller Invoice --module=Billing   # generate inside a module and register it there
vendor/bin/mikro make:guard Admin                 # also: module, service, repository, dto, interceptor, filter,
                                                  #       middleware, attribute, migration, view, test
vendor/bin/mikro migrate                          # migrate:status | migrate:rollback | migrate:reset | migrate:fresh
vendor/bin/mikro route:list                       # every route with its guards/interceptors
vendor/bin/mikro docs:export openapi.json         # OpenAPI spec without running the server
vendor/bin/mikro key:generate                     # random JWT_SECRET in .env
vendor/bin/mikro serve                            # dev server
```

## Migrations

### Create Migration

```bash
vendor/bin/mikro make:migration create_users_table
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
vendor/bin/mikro migrate              # Run pending
vendor/bin/mikro migrate:rollback     # Rollback last
vendor/bin/mikro migrate:status       # Check status
vendor/bin/mikro migrate:reset        # Reset all
vendor/bin/mikro migrate:fresh        # Reset + migrate
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

### PostgreSQL

Requires the `pdo_pgsql` extension. The driver also accepts `'postgres'` / `'postgresql'`.

```php
<?php
return [
    'driver'   => 'pgsql',
    'host'     => $_ENV['DB_HOST'] ?? 'localhost',
    'port'     => 5432,
    'database' => $_ENV['DB_DATABASE'] ?? 'myapp',
    'username' => $_ENV['DB_USERNAME'] ?? 'postgres',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
    'schema'   => 'public',   // optional, sets search_path
    'sslmode'  => 'prefer',   // optional
];
```

Repositories, the query builder, relations and migrations work unchanged:

- Identifiers are written with backticks internally and translated to `"double quotes"` for PostgreSQL (string literals are left untouched). If you write raw SQL, use `Database::query()/queryOne()/statement()/execute()` rather than `getPdo()` so the translation applies.
- Migrations: auto-increment keys become `GENERATED BY DEFAULT AS IDENTITY`, `boolean` → `BOOLEAN`, `json` → `JSONB`, `uuid` → `UUID`, `decimal` → `NUMERIC`, `datetime` → `TIMESTAMP`, column/table comments → `COMMENT ON`. `ALTER TABLE` migrations work as with MySQL.
- `BaseRepository::create()` uses `INSERT ... RETURNING` (works with UUID/natural keys). PHP booleans are bound as `1`/`0`, which PostgreSQL accepts for both `BOOLEAN` and integer columns.
- `updated_at` has no `ON UPDATE` in PostgreSQL; `BaseRepository::update()` sets it (same as SQLite).

### Turso / libSQL

MikroAPI also supports [Turso](https://turso.tech)/[libSQL](https://turso.tech/libsql) as a third driver — a hosted libSQL database (SQLite with native read-replica support). Since Turso speaks the SQLite SQL dialect and ships a `Libsql\PDO` class that is a genuine drop-in `\PDO` subclass, every repository, `QueryBuilder` query, relation, transaction, and migration keeps working unchanged — it's purely a config swap.

```bash
composer require turso/libsql
```

```php
<?php
return [
    'driver'        => 'turso',
    'database'      => __DIR__ . '/../database/database.sqlite', // local replica path; omit/null for remote-only
    'url'           => $_ENV['TURSO_DATABASE_URL'] ?? null,       // omit for a purely local file, no cloud needed
    'auth_token'    => $_ENV['TURSO_AUTH_TOKEN'] ?? null,         // omit for a purely local file
    'sync_interval' => (int) ($_ENV['TURSO_SYNC_INTERVAL'] ?? 0), // seconds; 0 = no periodic background sync
];
```

Which keys you set determines the mode:

| Mode | `database` | `url` | `auth_token` |
|---|---|---|---|
| Local file only (no cloud account needed) | set | omitted | omitted |
| Remote only (every query hits Turso Cloud) | omitted | set | set |
| Embedded replica (local file synced with a remote primary) | set | set | set |

> ⚠️ **Requirements**: PHP **>= 8.3** and the **FFI extension**, with `ffi.enable=true` explicitly set in `php.ini` (or via `-d ffi.enable=true`). PHP's `ffi.enable` directive defaults to `"preload"`, which does **not** cover the built-in dev server (`php -S`) or a typical FPM/Apache request — without setting it explicitly, connecting throws `FFI\Exception: FFI API is restricted by "ffi.enable" configuration directive`. `turso/libsql` is an optional dependency, resolved lazily via `class_exists()` — if it's missing, `Database::connect()` throws a clear `RuntimeException` telling you to `composer require turso/libsql` instead of failing with an FFI/autoload error.

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

## Performance Caching

MikroAPI offers opt-in caching for two of its more reflection-heavy paths: route registration and template compilation. Both are disabled by default (zero behavior change) and safe to enable only in production.

### Route Caching

```php
$app = new App();
$app->cacheRoutes(__DIR__ . '/cache/routes.php')  // must be called BEFORE useController()
    ->useController(UserController::class, PostController::class)
    ->run();
```

On first run, the compiled route table is written to the given file. Subsequent requests load routes from that file instead of re-reflecting every controller class.

> ⚠️ `cacheRoutes()` must be called **before** any `useController()` call, or it will throw a `LogicException` (calling it after could otherwise silently drop routes registered in the same request if a stale cache file already exists).
>
> ⚠️ The cache is **not** automatically invalidated when controllers/routes change. After adding, removing, or modifying routes, delete the cache file (or call `$app->clearRouteCache($path)`) so it regenerates.
>
> ⚠️ Store the cache file outside your public docroot — it contains internal controller class names and route patterns.

### Template Compilation Caching

```php
$app->useViews(__DIR__ . '/views');
Response::getViewEngine()->setCachePath(__DIR__ . '/cache/views');
```

When enabled, compiled view output is cached on disk and automatically invalidated by comparing a hash of the source template's content — no manual cache-clearing needed for views, and no risk of serving stale content due to filesystem mtime resolution.

## Configuration

MikroAPI includes a configuration service inspired by `@nestjs/config` for managing environment variables.

### Setup

```php
$app = new App();
$app->useConfig(__DIR__); // loads .env from project root
```

This loads your `.env` file and registers `ConfigService` in the container for injection.

When the app is organized in modules, use `ConfigModule::forRoot()` instead (same `ConfigService`, plus namespaced `load` and startup `validate`) — see [Modules](#modules). Keys not present in `.env` fall back to the real process environment.

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

### HTTP Exceptions

Throw an `HttpException` from anywhere (controller, service, guard, interceptor, middleware) and it becomes a JSON response with its status code:

```php
use MikroApi\Exception\{NotFoundException, ConflictException, HttpException};

throw new NotFoundException('Product not found');           // 404 {"error":"Product not found"}
throw new ConflictException('Email already registered');     // 409
throw new HttpException('Payment required', 402, body: ['code' => 'PAYMENT'], headers: ['Retry-After' => '60']);
```

Available: `BadRequestException` (400), `UnauthorizedException` (401), `ForbiddenException` (403), `NotFoundException` (404), `MethodNotAllowedException` (405), `ConflictException` (409), `UnprocessableEntityException` (422), `ValidationException` (422, `{"error":"Validation failed","errors":{...}}`), `TooManyRequestsException` (429), `InternalServerErrorException` (500). `ServiceException` (used by `BaseService`) now extends `HttpException`.

Unknown paths return `404`; a known path with the wrong method returns `405` with an `Allow` header.

### Exception Filters

Filters turn exceptions into custom responses. Declare which exceptions a filter handles with `#[Catches]` (none = all of them); return `null` to let the next filter (or the default handler) deal with it.

```php
use MikroApi\Attributes\{Catches, UseFilters};
use MikroApi\Exception\{ExceptionFilterInterface, HttpException};

#[Catches(HttpException::class)]
class ApiErrorFilter implements ExceptionFilterInterface
{
    public function catch(\Throwable $e, Request $request, ?ExecutionContext $context): ?Response
    {
        return Response::json([
            'statusCode' => $e->getStatusCode(),
            'message'    => $e->getMessage(),
            'path'       => $request->path,
        ], $e->getStatusCode());
    }
}

#[UseFilters(ApiErrorFilter::class)]               // class or method level
class ProductController { ... }

$app->useGlobalFilters(ApiErrorFilter::class);     // also catches 404/405 and middleware errors
```

Filters are tried from the most specific to the most general: method → controller → global. `$context` is `null` for errors outside a route (middlewares, 404/405).

### Production Mode

In production, set `APP_ENV=production` to hide internal error details:

```php
// .env or server config
APP_ENV=production
```

In development, full error messages are returned. In production, only `"Internal Server Error"` is shown for unhandled (non-HTTP) exceptions. `HttpException`/`ServiceException` messages are always returned with their status code.

## Modules

Modules group controllers and providers and control what's visible to the rest of the app, like NestJS modules.

```php
use MikroApi\Attributes\Module;

#[Module(
    imports:     [DatabaseModule::class],
    controllers: [UserController::class],
    providers:   [
        UserService::class,                                            // class → singleton within the module
        ['provide' => UserRepositoryInterface::class, 'useClass' => SqlUserRepository::class],
        ['provide' => 'users.pageSize', 'useValue' => 20],
        ['provide' => Mailer::class, 'useFactory' => [MailerFactory::class, 'create'], 'inject' => [ConfigService::class]],
        ['provide' => 'mailer', 'useExisting' => Mailer::class],      // alias
    ],
    exports:     [UserService::class],
)]
class UsersModule {}

#[Module(imports: [UsersModule::class, HealthModule::class])]
class AppModule {}

App::create(AppModule::class)       // or (new App())->useModule(AppModule::class)
    ->useGlobalFilters(ApiErrorFilter::class)
    ->run();
```

Resolution rules inside a module: its own providers → providers exported by the modules it imports → exports of `global: true` modules → anything registered directly in `$app->getContainer()` (so the classic bootstrap keeps working) → autowiring. Asking for a provider that belongs to another module which doesn't export it (or isn't imported) fails with an explanatory error. Controllers, guards, interceptors and filters of a route are resolved in the controller's module. Modules can re-export imported modules, and circular imports are allowed.

**Configuration (`ConfigModule`)**, like `@nestjs/config`: `ConfigModule::forRoot()` loads `.env` at startup and exposes `ConfigService` to every module (global by default). Providers that need configuration get it by constructor or through a factory with `inject`:

```php
use MikroApi\Config\{ConfigModule, ConfigService};

#[Module(
    controllers: [AuthController::class],
    providers: [
        ['provide' => JwtService::class, 'useFactory' => [AuthModule::class, 'createJwt'], 'inject' => [ConfigService::class]],
    ],
    exports: [JwtService::class],
    global: true,
)]
class AuthModule
{
    public static function createJwt(ConfigService $config): JwtService
    {
        return new JwtService($config->get('jwt.secret'), ttl: $config->get('jwt.ttl'));
    }
}

App::create(
    ConfigModule::forRoot(
        envFilePath: __DIR__ . '/..',          // directory with .env (also loads .env.{APP_ENV})
        load: [                                // namespaced config, read as 'jwt.secret'
            'jwt' => fn(ConfigService $c) => [
                'secret' => $c->getOrThrow('JWT_SECRET'),
                'ttl'    => $c->getInt('JWT_TTL', 3600),
            ],
        ],
        validate: ['JWT_SECRET'],              // fails at startup if missing
        // isGlobal: true, envFile: '.env'
    ),
    AppModule::class,
)->run();
```

Keys missing from `.env` fall back to the real process environment, so in production you can inject `JWT_SECRET` as an environment variable without shipping a `.env` file. Importing `ConfigModule::class` without `forRoot()` gives a `ConfigService` that only reads the process environment.

**Dynamic modules** in general are configured at bootstrap (closures are allowed here, unlike in attributes). Register them before the modules that import them:

```php
use MikroApi\Module\DynamicModule;

#[Module]
class MailModule
{
    public static function forRoot(array $options): DynamicModule
    {
        return new DynamicModule(
            module:    self::class,
            providers: [['provide' => 'mail.options', 'useValue' => $options], MailService::class],
            exports:   [MailService::class],
            global:    true,
        );
    }
}

App::create(MailModule::forRoot(['from' => 'no-reply@app.com']), AppModule::class)->run();
```

**Lifecycle hooks**: providers (class, `useClass` or object `useValue`) and module classes implementing `MikroApi\Module\OnModuleInit` get `onModuleInit()` after loading, imported modules first. `OnApplicationShutdown::onApplicationShutdown()` runs in reverse order in `App::close()`, which `run()` calls after sending the response (only for providers that were instantiated).

`$app->getModuleContainer(UsersModule::class)` returns a module's container (useful in tests and scripts).

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

Check the [`examples/`](./examples) directory for complete, runnable working examples:

- [`examples/basic/`](./examples/basic) - Minimal routing, the smallest possible app
- [`examples/auth/`](./examples/auth) - JWT Authentication implementation
- [`examples/modules/`](./examples/modules) - Modules, dynamic modules, built-in JWT/roles guards, parameter injection, interceptors and exception filters
- [`examples/swagger/`](./examples/swagger) - Complete Swagger/OpenAPI documentation example
- [`examples/crud-api/`](./examples/crud-api) - Repository pattern, migrations, relations, soft deletes, pagination & transactions ("mini blog" API)
- [`examples/middleware/`](./examples/middleware) - Full middleware pipeline: CORS, rate limiting, JSON body validation, custom middleware
- [`examples/templates/`](./examples/templates) - Template engine with layouts, sections, includes, directives & compiled caching
- [`examples/config-and-caching/`](./examples/config-and-caching) - `ConfigService` (.env) and route caching for production setups

## License

MIT

## Contributing

Contributions are welcome! Please see CONTRIBUTING.md for details.
