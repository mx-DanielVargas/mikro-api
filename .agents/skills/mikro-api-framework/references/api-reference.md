# MikroAPI — Complete API Reference

Exhaustive reference of every public class, method, and attribute MikroAPI offers, with exact signatures. Read this file whenever you need a precise signature instead of guessing or reading framework source. Namespace root: `MikroApi\`.

## Routing

```php
#[Controller(string $prefix = '')]                 // class-level
#[Route(string $method, string $path)]             // method-level, repeatable (multiple routes per method)
#[UseGuards(string ...$guards)]                     // class or method level, repeatable
```
- Path params use `:name` syntax (e.g. `/posts/:id`) → captured into `$request->params['name']`.
- **Route matching is in declaration order** — a literal segment route (`/posts/trashed`) must be declared *before* a param route (`/posts/:id`) in the same controller, or the param route swallows it.
- A controller method receives one `Request` argument and must return a `Response` (or a value that gets auto-wrapped in `Response::json()`).

## Dependency Injection — `Container`

```php
class Container {
    set(string $id, callable|string|null $factory = null): void       // bind; null/string = autowire that class
    instance(string $id, object $instance): void                       // bind an already-built singleton
    singleton(string $id, callable|string|null $factory = null): void  // bind, built once, memoized
    has(string $id): bool
    get(string $id): mixed                                             // resolves bindings, falls back to autowiring if $id is a real class
    make(string $class): object                                        // always autowires, ignores existing bindings
}
```
- Autowiring resolves constructor params: non-builtin type-hinted params are resolved via `get()`; builtin-typed or defaulted params use their default value. Throws `RuntimeException` if a param can't be resolved.
- `ContainerAwareInterface` (`setContainer(Container $container): static`) is auto-invoked by `Container::autowire()` on any instance that implements it — `BaseRepository` implements it so `RelationLoader` can resolve related repositories with extra constructor dependencies via the Container (only works if the repository itself was resolved through the Container, not `new SomeRepository($db)`).

## `App` (bootstrap)

```php
class App {
    __construct(?Container $container = null)
    getContainer(): Container
    useMiddleware(MiddlewareInterface ...$middlewares): self     // first registered = outermost layer
    useViews(string $viewsPath, string $extension = '.php'): self
    useConfig(string $basePath, string $envFile = '.env'): self  // loads .env (+ .env.{APP_ENV} override)
    useController(string ...$controllers): self
    enableSwagger(array $config = [], array $excludeControllers = [], array $controllers = [], string $path = '/docs', string $jsonPath = '/docs/json', array $authGuards = []): self
    cacheRoutes(string $cacheFile): self       // MUST be called before useController(), else throws LogicException
    clearRouteCache(string $cacheFile): self
    run(): void
}
```
`run()` catches `MikroApi\Service\ServiceException` → `Response::error($e->getMessage(), $e->getStatusCode())`; any other `Throwable` → 500, message hidden when `APP_ENV=production` (checked across `$_ENV`/`$_SERVER`/`getenv()`).

## `Request` / `Response`

```php
class Request {
    public string $method; public string $path;
    public array $params = []; public array $query = []; public array $body = [];
    public ?object $dto = null;                          // populated by #[Body(...)] validation
    static capture(): self
    header(string $name): ?string                         // case-insensitive
    input(string $key, mixed $default = null): mixed      // reads from $body
}

class Response {
    static json(mixed $data, int $status = 200): self
    static text(string $data, int $status = 200): self
    static html(string $data, int $status = 200): self
    static error(string $message, int $status = 500): self
    static empty(int $status = 204): self
    static redirect(string $url, int $status = 302): self
    static render(string $view, array $data = [], int $status = 200): self   // requires a view engine set
    withHeader(string $name, string $value): self          // immutable, returns a clone
    withStatus(int $status): self
    getStatus(): int
    getBody(): string
    send(): void
    static setViewEngine(View\Engine $engine): void
    static getViewEngine(): ?View\Engine
}
```

## Guards (authentication/authorization)

```php
interface GuardInterface {
    canActivate(Request $request): bool;
    deny(): Response;
}
abstract class BaseGuard implements GuardInterface {
    deny(): Response   // default: Response::error('Unauthorized', 401)
}
```
Attach with `#[UseGuards(SomeGuard::class)]` on a controller class (applies to all methods) or a specific method (repeatable — multiple guards run in order, first failing one wins). Guards run **before** DTO validation and the controller method.

## Middleware

```php
interface MiddlewareInterface {
    handle(Request $request, callable $next): Response;
}

class CorsMiddleware implements MiddlewareInterface {
    __construct(array $origins = ['*'], array $methods = ['GET','POST','PUT','DELETE','PATCH','OPTIONS'], array $headers = ['Content-Type','Authorization'], int $maxAge = 3600)
}
// Handles OPTIONS preflight itself; should usually be registered first (outermost).

class JsonBodyMiddleware implements MiddlewareInterface {}
// Rejects malformed JSON bodies (POST/PUT/PATCH + Content-Type: application/json) with 400 before routing.

class RateLimitMiddleware implements MiddlewareInterface {
    __construct(int $maxRequests = 60, int $windowSeconds = 60, ?RateLimitStore $store = null)
}
interface RateLimitStore {
    increment(string $key, int $windowSeconds): array{count: int, reset: int};
}
class InMemoryRateLimitStore implements RateLimitStore {}   // DEFAULT — process memory only, does NOT persist across requests in PHP-FPM/Apache/`php -S`
class ApcuRateLimitStore implements RateLimitStore {
    __construct(string $prefix = 'mikroapi_rl_')   // requires ext-apcu; atomic via apcu_add()/apcu_inc()
}
```
Registration order in `App::useMiddleware(...)` matters: the first middleware wraps everything else (runs first on the way in, last on the way out). `/docs` and `/docs/json` (Swagger) flow through the same pipeline as any other route.

## Validation

```php
abstract class RequestDto {
    toArray(): array
    static fromArray(array $data): static   // no validation, direct hydration
}
```
Attach with `#[Body(SomeDto::class)]` on a route method. On failure, the framework auto-returns `422` with `{"error": "Validation failed", "errors": {...}}` before the controller runs; on success, the validated+cast DTO is set on `$request->dto`.

All validation attributes live in `MikroApi\Attributes\Validation\` and accept an optional `string $message` override (with `:field`/`:min`/`:max`/`:values`/`:type` placeholders where relevant):

| Attribute | Purpose |
|---|---|
| `#[Required]` | field must be present and non-empty |
| `#[Optional]` | skips all other rules if the field is absent from the payload |
| `#[IsString]` / `#[IsInt]` / `#[IsFloat]` / `#[IsBool]` / `#[IsArray]` | type checks |
| `#[IsEmail]` / `#[IsUrl]` | format checks (`filter_var`) |
| `#[Matches(string $pattern)]` | regex match |
| `#[IsIn(array $values)]` | enum-style whitelist |
| `#[MinLength(int $min)]` / `#[MaxLength(int $max)]` / `#[Length(int $min, int $max)]` | string length (multibyte-safe) |
| `#[Min(float $min)]` / `#[Max(float $max)]` | numeric range |
| `#[ArrayUnique]` | array values must be unique |
| `#[ArrayOf(string $type)]` | every array element must match `$type` (`string`/`int`/`float`/`bool`) |

DTO properties are cast to their declared PHP type (`int`/`float`/`bool`/`string`) after validation passes.

## Swagger / OpenAPI

```php
App::enableSwagger(
    array $config = [],              // 'title', 'version', 'description', 'servers'
    array $excludeControllers = [],
    array $controllers = [],         // defaults to everything passed to useController()
    string $path = '/docs',
    string $jsonPath = '/docs/json',
    array $authGuards = [],          // guard classes that should mark endpoints as Bearer-secured in the spec
): self
```
```php
#[ApiDoc(?string $summary = null, ?string $description = null, bool $deprecated = false, array $responses = [], bool $exclude = false)]
#[ApiTag(string $name, ?string $description = null)]                                    // class-level, groups endpoints
#[QueryParam(string $name, string $type = 'string', string $description = '', bool $required = false, mixed $example = null)]  // method-level, repeatable
```
The OpenAPI spec is generated **lazily** — only the first time `/docs` or `/docs/json` is actually requested (memoized afterward), not on every request.

## Data layer — `BaseRepository` / `QueryBuilder`

```php
abstract class BaseRepository implements RepositoryInterface, ContainerAwareInterface {
    protected string $table;
    protected string $primaryKey = 'id';
    protected string $softDeleteColumn = 'deleted_at';
    protected bool   $useSoftDeletes = false;
    protected bool   $useTimestamps  = true;
    protected array  $fillable = [];   // ALWAYS declare explicitly for anything backed by request input

    __construct(?Database $db = null)   // defaults to Database::getInstance()
    with(string ...$relations): static           // eager-load; dot notation for nested: 'posts.comments'; returns a clone
    loadWith(array $records, array $relations): array
    query(): QueryBuilder
    findAll(): array
    findById(mixed $id): ?array
    findBy(string $column, mixed $value): array
    findOneBy(string $column, mixed $value): ?array
    findWhere(array $conditions): array
    count(array $conditions = []): int
    exists(string $column, mixed $value, mixed $excludeId = null): bool
    paginate(int $page, int $perPage = 15, array $conditions = []): array   // {data,total,per_page,current_page,last_page,from,to}
    create(array $data, bool $reload = true): array   // reload:false skips the post-INSERT SELECT (no defaults/triggers reflected, but id type is still normalized to int)
    update(mixed $id, array $data): ?array
    delete(mixed $id): bool          // soft-deletes automatically if $useSoftDeletes
    softDelete(mixed $id): bool
    restore(mixed $id): ?array
    getTable(): string
    getPrimaryKey(): string
    usesSoftDeletes(): bool
    getSoftDeleteColumn(): string
    setContainer(Container $container): static   // auto-invoked by the DI Container, not something you call manually
}

class QueryBuilder {
    select(string ...$columns): static
    join(string $table, string $first, string $operator, string $second): static
    leftJoin(string $table, string $first, string $operator, string $second): static
    where(string $column, mixed $value, string $operator = '='): static
    orWhere(string $column, mixed $value, string $operator = '='): static
    whereNull(string $column): static
    whereNotNull(string $column): static
    whereIn(string $column, array $values): static
    whereBetween(string $column, mixed $min, mixed $max): static
    whereLike(string $column, string $pattern): static
    orderBy(string $column, string $direction = 'ASC'): static
    limit(int $limit): static
    offset(int $offset): static
    page(int $page, int $perPage = 15): static
    withTrashed(): static
    get(): array
    first(): ?array
    count(): int
    exists(): bool
    paginate(int $page, int $perPage = 15): array
}
```

Relations, declared on a **public property** of a repository (in `MikroApi\Attributes\Relation\`):

```php
#[HasMany(repository: string, foreignKey: string, localKey: ?string = null, as: ?string = null)]
#[HasOne(repository: string, foreignKey: string, localKey: ?string = null, as: ?string = null)]
#[BelongsTo(repository: string, foreignKey: string, ownerKey: ?string = null, as: ?string = null)]
#[BelongsToMany(repository: string, pivotTable: string, foreignKey: string, relatedKey: string, pivotColumns: array = [], localKey: ?string = null, relatedPk: ?string = null, as: ?string = null)]
```
Resolved via `RelationLoader`, batched with a single `WHERE ... IN (...)` per relation (no N+1), and respects soft deletes on the related table automatically.

## Migrations

```php
abstract class Migration {
    __construct(string $driver = 'sqlite', ?PDO $pdo = null)
    up(): string     // CREATE TABLE, or auto-diffed ALTER TABLE if the table already exists
    down(): string   // DROP TABLE IF EXISTS
}
```
Schema attributes (`MikroApi\Attributes\Schema\`), on the migration class or its public properties:
```php
#[Table(string $name, ?string $comment = null)]                       // class-level
#[Timestamps]                                                          // class-level, adds created_at/updated_at
#[SoftDeletes]                                                         // class-level, adds deleted_at + index

#[Column(string $type = 'varchar', ?int $length = null, ?int $precision = null, ?int $scale = null, bool $nullable = false, mixed $default = '__NONE__', ?string $comment = null, ?string $name = null)]
// $type: int | bigint | tinyint | smallint | varchar | char | text | mediumtext | longtext | decimal | float | double | boolean | date | datetime | timestamp | json | uuid
#[PrimaryKey(bool $autoIncrement = true)]
#[ForeignKey(string $references, string $on = 'id', string $onDelete = 'CASCADE', string $onUpdate = 'CASCADE', ?string $name = null)]
#[Unique(?string $name = null)]
#[Index(?string $name = null)]
```
Supports SQLite and MySQL/MariaDB — `SchemaBuilder` translates column types per driver.

```php
class MigrationRunner {
    __construct(Database $db, string $migrationsPath = 'database/migrations', string $migrationsNamespace = 'Database\\Migrations')
    migrate(): void    // runs all pending, tracked in a `migrations` table
    rollback(): void   // reverts the single most recent migration
    reset(): void      // reverts all, in reverse order
    status(): void     // prints a table of executed vs pending
}
```

## Database

```php
class Database {
    static connect(array $config): self   // config: driver ('sqlite'|'mysql'), database, host, port, username, password, charset
    static getInstance(): self
    static reset(): void                  // mainly for tests
    getDriver(): string
    getPdo(): \PDO
    execute(string $sql): void
    query(string $sql, array $params = []): array
    queryOne(string $sql, array $params = []): ?array
    lastInsertId(): string
    transaction(callable $callback): mixed   // commits on success, rolls back and re-throws on any Throwable
}
```
Note: binding a PHP `bool` into an `INTEGER`/`TINYINT` column via PDO can insert an empty string instead of `0`/`1` on SQLite — cast explicitly to `(int)` before writing booleans.

## Template Engine — `View\Engine`

```php
class Engine {
    __construct(string $viewsPath, string $extension = '.php')
    setViewsPath(string $viewsPath): void
    getViewsPath(): string
    addGlobal(string $key, mixed $value): void
    setCachePath(?string $path): void      // opt-in, off by default; invalidated by SOURCE CONTENT HASH (not mtime)
    setFallbackPath(?string $path): void
    setFallbackPaths(array $paths): void
    render(string $view, array $data = []): string   // dot notation for nested view paths, e.g. 'partials.nav'
}
```
Directives: `{{ $var }}` (HTML-escaped), `{!! $var !!}` (raw — **never** with unsanitized user input, XSS risk), `@if`/`@elseif`/`@else`/`@endif`, `@foreach(...)`/`@endforeach`, `@include('dot.path')`, `@extends('layout')`, `@section('name')`/`@endsection`, `@yield('name')`.

## Configuration — `ConfigService`

```php
class ConfigService {
    __construct(?string $basePath = null, string $envFile = '.env')   // also auto-loads .env.{APP_ENV} as an override
    get(string $key, mixed $default = null): mixed        // dot-notation for registered namespaces, e.g. 'database.host'
    getOrThrow(string $key): mixed                          // throws RuntimeException if missing
    getInt(string $key, ?int $default = null): ?int
    getBool(string $key, ?bool $default = null): ?bool
    getFloat(string $key, ?float $default = null): ?float
    set(string $key, mixed $value): void                    // runtime override
    register(string $namespace, array $config): void        // groups values under a namespace for dot-notation access
    validate(array $keys): void                              // throws RuntimeException listing ALL missing keys at once
}
```
`.env` parsing supports `${VAR}` interpolation and end-of-line comments (`KEY=value # comment` — needs a space before `#`; quoted values preserve `#` literally inside the quotes).

## Business logic helpers — `BaseService`

```php
abstract class BaseService {
    protected fail(string $message, int $statusCode = 400): never
    protected notFound(string $message = 'Recurso no encontrado'): never       // 404
    protected unauthorized(string $message = 'No autorizado'): never           // 401
    protected forbidden(string $message = 'Acceso denegado'): never            // 403
    protected conflict(string $message = 'Conflicto con el estado actual'): never // 409
}
class ServiceException extends \RuntimeException { getStatusCode(): int }
```
Throwing from inside a service bubbles up and is caught by `App::run()`, converted straight into the matching HTTP error response.

## CLI — `bin/mikro-migrate` (a.k.a. `vendor/bin/mikro-migrate`)

```
init [path]                    Scaffold a full project structure (safe to re-run, never overwrites; merges
                                autoload.psr-4/require into an existing composer.json if present)
make:controller <Name>         src/Controllers/<Name>Controller.php
make:repository <Name>         src/Repositories/<Name>Repository.php
make:dto <Name>                src/DTOs/<Name>Dto.php
make:middleware <Name>         src/Middleware/<Name>Middleware.php
make:guard <Name>              src/Guards/<Name>Guard.php
make:service <Name>            src/Services/<Name>Service.php
make <name>                    Create a new migration file (legacy name, unrelated to make:*)
migrate / rollback / reset / status   Standard migration lifecycle commands
```
`make:*` generators refuse to overwrite an existing file (non-zero exit); `init` always skips existing files silently. After `init` merges into a pre-existing `composer.json`, you MUST run `composer dump-autoload` (or `composer install`) — editing `composer.json` alone does not regenerate Composer's autoloader files.
