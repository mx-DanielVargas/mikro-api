# MikroAPI — Complete API Reference

Exhaustive reference of every public class, method, and attribute MikroAPI offers, with exact signatures. Read this file whenever you need a precise signature instead of guessing or reading framework source. Namespace root: `MikroApi\`.

## Routing

```php
#[Controller(string $prefix = '')]                 // class-level
#[Route(string $method, string $path)]             // method-level, repeatable (multiple routes per method)
#[UseGuards(string ...$guards)]                     // class or method level, repeatable
#[UseInterceptors(string ...$interceptors)]         // class or method level, repeatable
#[UseFilters(string ...$filters)]                   // class or method level, repeatable
```
- Path params use `:name` syntax (e.g. `/posts/:id`) → captured into `$request->params['name']`.
- **Route matching is in declaration order** — a literal segment route (`/posts/trashed`) must be declared *before* a param route (`/posts/:id`) in the same controller, or the param route swallows it.
- A controller method returns a `Response` or any value (auto-wrapped in `Response::json()`). Its arguments are injected (see "Handler parameter injection"); the classic single `Request $req` argument keeps working.
- Unknown path → `NotFoundException` (404). Known path with another method → `MethodNotAllowedException` (405, `Allow` header). Both go through global exception filters.
- Request pipeline: middlewares (App) → guards (global → class → method) → interceptors (global → class → method, first = outermost) → method-level `#[Body(Dto)]` validation + argument resolution → handler. Any exception after routing goes through exception filters (method → class → global) and then `ExceptionHandler`'s default. `Router::dispatch()` always returns a `Response`.

## Handler parameter injection

```php
#[Param(?string $name = null)]        // route param; no name → all params (array) or validated DTO (type-hinted class)
#[Query(?string $name = null)]        // query string; no name → whole query (array) or validated DTO
#[Body(?string $dtoClass = null)]     // on a parameter: class → validated DTO; other string → body field; null → DTO from type-hint, or raw array
#[Headers(?string $name = null)]      // header (case-insensitive) or all headers (array)
#[CurrentUser(?string $name = null)]  // $request->user, or one key of it (401 if user missing and param not nullable)
// By type, no attribute: Request (also untyped/mixed params), ExecutionContext. Other params need an attribute, a default or be nullable,
// otherwise registerController() throws LogicException.
```
- Conversion to the declared type: `int`, `float`, `bool` (`true/false/1/0/yes/no/on/off`), `string`, `array`, backed enums (by value) and pure enums (by case name). Failure → `BadRequestException` 400 (`"El parámetro de ruta 'id' debe ser un número entero"`).
- Missing value (or `''` for non-string types): default value if any → `null` if nullable → otherwise 400 (`"... es obligatorio"`).
- DTO targets are validated with `Validator` → `ValidationException` (422, `{"error":"Validation failed","errors":{...}}`). `#[Body] Dto $dto` also sets `$request->dto`.
- Arg specs are computed once at registration (`ArgumentResolver::describe()`) and stored in the route cache; caches generated before this feature (no `args` key) fall back to `handler(Request $req)`.

## Dependency Injection — `Container`

```php
class Container {
    set(string $id, callable|string|null $factory = null): void       // bind; null/string = autowire that class
    instance(string $id, object $instance): void                       // bind an already-built singleton
    singleton(string $id, callable|string|null $factory = null): void  // bind, built once, memoized
    has(string $id): bool
    get(string $id): mixed                                             // resolves bindings, falls back to autowiring if $id is a real class
    make(string $class): object                                        // always autowires, ignores existing bindings
    isResolved(string $id): bool                                       // singleton already instantiated? (doesn't create it)
}
```
- Autowiring resolves constructor params: non-builtin type-hinted params are resolved via `get()`; builtin-typed or defaulted params use their default value. Throws `RuntimeException` if a param can't be resolved.
- `ContainerAwareInterface` (`setContainer(Container $container): static`) is auto-invoked by `Container::autowire()` on any instance that implements it — `BaseRepository` implements it so `RelationLoader` can resolve related repositories with extra constructor dependencies via the Container (only works if the repository itself was resolved through the Container, not `new SomeRepository($db)`).

## `App` (bootstrap)

```php
class App {
    __construct(?Container $container = null)
    static create(string|DynamicModule ...$modules): self         // new App + useModule()
    getContainer(): Container
    useModule(string|DynamicModule ...$modules): self             // loads module graph, registers controllers, runs OnModuleInit
    getModuleContainer(string $moduleClass): Container
    useGlobalGuards(string ...$guards): self                       // run before class/method guards, on every route
    useGlobalInterceptors(string ...$interceptors): self           // outermost interceptors
    useGlobalFilters(string ...$filters): self                     // tried after method/class filters; also 404/405 and middleware errors
    useMiddleware(MiddlewareInterface ...$middlewares): self     // first registered = outermost layer
    useViews(string $viewsPath, string $extension = '.php'): self
    useConfig(string $basePath, string $envFile = '.env'): self  // loads .env (+ .env.{APP_ENV} override)
    useController(string ...$controllers): self
    enableSwagger(array $config = [], array $excludeControllers = [], array $controllers = [], string $path = '/docs', string $jsonPath = '/docs/json', array $authGuards = []): self
    cacheRoutes(string $cacheFile): self       // MUST be called before useController(), else throws LogicException
    clearRouteCache(string $cacheFile): self
    handle(Request $request): Response         // full pipeline without sending — use in tests with Request::create()
    run(): void                                // handle(Request::capture())->send(), then close()
    close(): void                              // OnApplicationShutdown hooks (idempotent)
}
```
Errors: `HttpException` (incl. `ServiceException`) → its status/body/headers; any other `Throwable` → 500, message hidden when `APP_ENV=production` (`ExceptionHandler::isProduction()` checks `$_ENV`/`$_SERVER`/`getenv()`).

## Errors — `MikroApi\Exception\`

```php
class HttpException extends \RuntimeException {
    __construct(string $message = '', int $statusCode = 500, ?array $body = null, array $headers = [], ?\Throwable $previous = null)
    getStatusCode(): int
    getBody(): array             // default ['error' => $message]
    getHeaders(): array
}
// Subclasses: __construct(string $message = '<default>', ?array $body = null, array $headers = [], ?\Throwable $previous = null)
BadRequestException (400) · UnauthorizedException (401) · ForbiddenException (403) · NotFoundException (404) · ConflictException (409)
UnprocessableEntityException (422) · TooManyRequestsException (429) · InternalServerErrorException (500)
MethodNotAllowedException(array $allowedMethods = [], ...)      // 405 + Allow header
ValidationException(array $errors, string $message = 'Validation failed')   // 422, getErrors()

interface ExceptionFilterInterface {
    catch(\Throwable $exception, Request $request, ?ExecutionContext $context): ?Response;   // null → next filter / default
}
#[Catches(string ...$exceptionClasses)]    // on the filter class; omitted = catches everything
class ExceptionHandler { static handle(...); static defaultResponse(\Throwable): Response; static isProduction(): bool }
```

## Interceptors — `MikroApi\Interceptor\InterceptorInterface`

```php
interface InterceptorInterface {
    intercept(ExecutionContext $context, callable $next): mixed;   // $next() runs the rest of the chain + handler and returns its raw result
}
```
Run after guards and before argument validation. Can transform the result (array or `Response`), short-circuit (don't call `$next`) or catch exceptions from the handler. The final value is converted with `Response::json()` if it isn't a `Response`.

## Execution context & metadata

```php
final class ExecutionContext {
    getRequest(): Request; getClass(): string; getHandler(): string;
    getClassReflection(): \ReflectionClass; getHandlerReflection(): \ReflectionMethod;
}
#[SetMetadata(string $key, mixed $value = true)]   // class/method, repeatable; extend it for custom attributes (declare your own #[\Attribute] flags)
class Reflector {
    getAllAndOverride(string $key, ?ExecutionContext $ctx): mixed   // method value, else class value, else null (alias: get())
    getAllAndMerge(string $key, ?ExecutionContext $ctx): array      // method + class values, arrays flattened
    getHandlerMetadata(string $key, ExecutionContext $ctx): mixed
    getClassMetadata(string $key, ExecutionContext $ctx): mixed
    has(string $key, ?ExecutionContext $ctx): bool
    getAttribute(string $attributeClass, ?ExecutionContext $ctx): ?object   // any attribute (IS_INSTANCEOF), method first then class
}
```
The router sets `$request->context` before guards run; guards read metadata with an injected `Reflector`. Built-in metadata attributes: `#[Roles(string ...$roles)]` (key `'roles'`), `#[PublicRoute]` (key `'isPublic'`).

## Modules — `#[Module]`, `MikroApi\Module\`

```php
#[Module(array $imports = [], array $controllers = [], array $providers = [], array $exports = [], bool $global = false)]

// providers entries:
SomeService::class                                                       // singleton, autowired
['provide' => Iface::class, 'useClass' => Impl::class]
['provide' => 'key', 'useValue' => $value]
['provide' => X::class, 'useFactory' => [Factory::class, 'make'], 'inject' => [Dep::class, 'key']]   // any callable in DynamicModule
['provide' => 'alias', 'useExisting' => X::class]
// exports: own provider IDs or imported module classes (re-export). Anything else → LogicException at load.

final class DynamicModule {
    __construct(string $module, array $imports = [], array $controllers = [], array $providers = [], array $exports = [], ?bool $global = null)
}   // merged with the module's #[Module] attribute; pass to App::useModule()/create() BEFORE modules importing it (else LogicException)

interface OnModuleInit { onModuleInit(): void; }                   // providers (class/useClass/object useValue) + module classes, imports first
interface OnApplicationShutdown { onApplicationShutdown(): void; } // App::close(), reverse order, only already-instantiated providers
```
- `ModuleContainer::get()` order: own providers → exports of imported modules → exports of global modules → App root container (`$app->getContainer()` bindings, `useConfig()`'s ConfigService, etc.) → autowiring. A provider owned by a module that isn't imported/doesn't export it → `RuntimeException` explaining what to export/import.
- Providers are singletons per module. Controllers, their guards, interceptors and filters (including global ones) are resolved in the controller's module container — dependencies of global guards (e.g. `JwtService` for a global `JwtGuard`) must be in a global module or the root container.
- Circular imports are allowed (resolution is lazy). The module class itself is a singleton in its own container.

```php
// MikroApi\Config\ConfigModule — @nestjs/config equivalent
ConfigModule::forRoot(
    ?string $envFilePath = null,   // dir with .env (also loads .env.{APP_ENV}); null → process env only
    string $envFile = '.env',
    bool $isGlobal = true,
    array $load = [],              // ['jwt' => fn(ConfigService $c) => [...]] → $config->get('jwt.secret')
    array $validate = [],          // required keys, checked BEFORE load; RuntimeException at startup
): DynamicModule                   // ConfigService provided as useValue (loaded immediately)
// Importing ConfigModule::class without forRoot() → ConfigService reading only the process env.
// Typical consumer: ['provide' => JwtService::class, 'useFactory' => [AuthModule::class, 'createJwt'], 'inject' => [ConfigService::class]]
```

## `Request` / `Response`

```php
class Request {
    public string $method; public string $path;
    public array $params = []; public array $query = []; public array $body = [];
    public ?object $dto = null;                          // populated by #[Body(...)] validation
    public mixed $user = null;                           // set by JwtGuard (payload array)
    public ?ExecutionContext $context = null;            // set by the router before guards
    static capture(): self
    static create(string $method, string $path, array $query = [], array $body = [], array $headers = []): self   // for tests/CLI
    headers(): array                                      // all headers, UPPERCASE names
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
    getHeaders(): array
    getHeader(string $name): ?string                       // case-insensitive
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
Attach with `#[UseGuards(SomeGuard::class)]` on a controller class (applies to all methods) or a specific method (repeatable — multiple guards run in order, first failing one wins), or globally with `App::useGlobalGuards()`. Guards run **before** interceptors, DTO validation and the controller method. Returning `false` sends `deny()`'s response directly (bypasses filters); throwing an `HttpException` goes through exception filters (preferred). Guards are resolved through the container (constructor injection works, e.g. `Reflector`).

### Built-in auth — `MikroApi\Auth\`

```php
class JwtService {
    __construct(string $secret, string $algorithm = 'HS256', int $ttl = 3600, int $leeway = 0, ?string $issuer = null)  // HS256|HS384|HS512
    sign(array $payload, ?int $ttl = null): string     // adds iat, exp (ttl > 0) and iss unless present
    verify(string $token): array                       // UnauthorizedException: 'Token malformado' | 'Token inválido' | 'Token expirado' | 'Token aún no válido'
}
class JwtGuard extends BaseGuard {      // __construct(JwtService $jwt, Reflector $reflector)
    // Authorization: Bearer <token> → $request->user = payload; #[PublicRoute] routes pass; missing token → 401 'Token no proporcionado'
}
class RolesGuard implements GuardInterface {   // __construct(Reflector $reflector)
    // #[Roles(...)] (method overrides class) vs $request->user['roles'] (array) or ['role'] (string); any match passes; none → ForbiddenException 403
}
```
`JwtService` must be registered explicitly (the secret can't be autowired): `$container->singleton(JwtService::class, fn() => new JwtService($secret))` or a module provider.

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
Attach with `#[Body(SomeDto::class)]` on a route method, or `#[Body] SomeDto $dto` on a parameter (also `#[Query] Dto` / `#[Param] Dto` for query strings and route params). On failure, a `ValidationException` produces `422` with `{"error": "Validation failed", "errors": {...}}` before the controller runs; on success, the validated+cast DTO is injected (and set on `$request->dto` for bodies). Query/param values are strings, so use `#[IsInt]`/`#[IsBool]` etc. and typed properties to get them cast.

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
    static connect(array $config): self   // config: driver ('sqlite'|'mysql'|'pgsql'|'turso'), database, host, port, username, password, charset; pgsql: schema, sslmode
    static getInstance(): self
    static reset(): void                  // mainly for tests
    static normalizeDriver(string $driver): string        // 'postgres'/'postgresql'/'pg' → 'pgsql'
    static toDialect(string $sql, string $driver): string // pgsql: `ident` → "ident" (outside '...' literals); others unchanged
    getDriver(): string                   // 'turso' is reported as 'sqlite' (same SQL dialect) to every consumer
    getPdo(): \PDO                        // raw PDO: NO dialect translation — prefer the methods below
    execute(string $sql): void
    statement(string $sql, array $params = []): \PDOStatement   // prepare + execute (rowCount(), fetch()...)
    query(string $sql, array $params = []): array
    queryOne(string $sql, array $params = []): ?array
    lastInsertId(?string $name = null): string
    transaction(callable $callback): mixed   // commits on success, rolls back and re-throws on any Throwable
}
```
- Note: binding a PHP `bool` into an `INTEGER`/`TINYINT` column via PDO can insert an empty string instead of `0`/`1` on SQLite — cast explicitly to `(int)` before writing booleans. (On `pgsql`, `Database` normalizes bools to `1`/`0` automatically.)
- **`'pgsql'` driver** (needs `pdo_pgsql`): framework SQL uses backticks and is translated automatically when it goes through `Database` methods. `BaseRepository::create()` uses `INSERT ... RETURNING <pk>`. Migrations: int auto-increment PK → `GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY`, `boolean` → `BOOLEAN` (defaults `TRUE`/`FALSE`), `json` → `JSONB`, `uuid` → `UUID`, `decimal` → `NUMERIC(p,s)`, `float` → `REAL`, `double` → `DOUBLE PRECISION`, `tinyint` → `SMALLINT`, `datetime`/`timestamp` → `TIMESTAMP`, comments → `COMMENT ON`, ALTER uses one statement (new NOT NULL columns without default are added as NULL, like SQLite). No `ON UPDATE` for `updated_at` (set by `BaseRepository::update()`).
- **`'turso'` driver** (Turso/libSQL — SQLite with native read-replica support): config needs `database` (local replica path, or omit for remote-only), `url`/`auth_token` (Turso Cloud; omit both for a purely local file), `sync_interval` (seconds, 0 = none). Requires the optional `turso/libsql` Composer package (PHP >= 8.3 + FFI) — resolved lazily via `class_exists()`; `connect()` throws a clear `RuntimeException` if it's missing. **Gotcha:** `ffi.enable` defaults to `"preload"`, which does NOT cover `php -S`/typical FPM — you must set `ffi.enable=true` explicitly in `php.ini` or via `-d`, or `Libsql\PDO`'s constructor throws `FFI\Exception`.

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
class ServiceException extends HttpException { __construct(string $message, int $statusCode = 400) }
```
Throwing from inside a service bubbles up through the exception filters and is converted into the matching HTTP error response. In new code you can also throw the specific `HttpException` subclasses directly.

## CLI — `vendor/bin/mikro` (`bin/mikro`, classes in `MikroApi\Console\`)

```
new [path]                      (alias init) modular project scaffold; never overwrites; merges composer.json
serve [--host=127.0.0.1] [--port=8000]
key:generate [--force] [--show] random JWT_SECRET in .env (replaces 'change-me' freely, a real one only with --force)

make:<type> <name> [--module=X] [--force]      short form: g <type> <name>  (also: generate)
  resource      src/<Plural>/{<Plural>Module,Controller,Service,Repository}.php + Dto/{Create,Update}<Singular>Dto.php
                + database/migrations/<ts>_create_<plural>_table.php; registers module in src/AppModule.php imports
  module        src/<Name>/<Name>Module.php; registered in AppModule imports
  controller    src/Controllers/<Name>Controller.php      (--module: src/X/, added to XModule controllers)
  service       src/Services/<Name>Service.php            (--module: added to XModule providers)
  repository    src/Repositories/<Name>Repository.php     (--module: added to XModule providers)
  dto           src/DTOs/<Name>Dto.php                    (--module: src/X/Dto/); Update* names → #[Optional]
  guard | interceptor | filter      src/Guards|Interceptors|Filters/<Name><Suffix>.php
  middleware    src/Middleware/<Name>Middleware.php
  attribute     src/Attributes/<Name>.php                 SetMetadata subclass with KEY const
  migration     database/migrations/<Y_m_d_His>_<snake>.php   (create_x_table → table x)
  view          views/<a/b>.php (from a.b)
  test          tests/<Name>Test.php  [--route=/path]   PHPUnit + App::create(AppModule)->handle(Request::create(...))

migrate | migrate:status | migrate:rollback | migrate:reset | migrate:fresh     (reads config/database.php after loading .env)
route:list                      routes of every #[Controller] under src/ (global guards not shown)
route:clear [cache/routes.php]
docs:export [openapi.json|-] [--title=] [--api-version=]
list | --version
Aliases from the old mikro-migrate: init, make <name>, rollback, reset, status
```
- Root namespace comes from composer.json psr-4 → `src/` (default `App\`). Names are normalized (`blog_post` = `BlogPost`), suffixes added if missing, naive English plural/singular.
- `--module=X` requires `src/X/XModule.php` to exist — otherwise it fails before writing anything. Registration edits the `#[Module(...)]` list (adds `use` when the namespace differs); if it can't parse it, it prints what to add manually.
- Generators never overwrite without `--force` (exit 1). `new` skips existing files. After `new` merges into a pre-existing `composer.json`, you MUST run `composer dump-autoload`.
