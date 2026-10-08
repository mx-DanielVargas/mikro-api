<?php

namespace MikroApi\Console;

/**
 * Plantillas de los archivos que genera el CLI. Los marcadores {{x}} se
 * reemplazan con render().
 */
final class Stubs
{
    /** @param array<string, string> $vars */
    public static function render(string $stub, array $vars): string
    {
        $map = [];
        foreach ($vars as $key => $value) {
            $map['{{' . $key . '}}'] = $value;
        }
        return \strtr(\constant(self::class . '::' . $stub), $map);
    }

    /* ------------------------------------------------------------------ */
    /*  Building blocks                                                      */
    /* ------------------------------------------------------------------ */

    public const MODULE = <<<'PHP'
<?php

namespace {{namespace}};

use MikroApi\Attributes\Module;

#[Module(
    imports: [],
    controllers: [],
    providers: [],
    exports: [],
)]
class {{class}}
{
}

PHP;

    public const CONTROLLER = <<<'PHP'
<?php

namespace {{namespace}};

use MikroApi\Attributes\Body;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Param;
use MikroApi\Attributes\Query;
use MikroApi\Attributes\Route;
use MikroApi\Response;

#[Controller('{{route}}')]
class {{class}}
{
    #[Route('GET', '/')]
    public function index(#[Query('page')] int $page = 1): array
    {
        return [];
    }

    #[Route('GET', '/:id')]
    public function show(#[Param('id')] int $id): array
    {
        return ['id' => $id];
    }

    #[Route('POST', '/')]
    public function store(#[Body] array $body): Response
    {
        return Response::json($body, 201);
    }

    #[Route('PUT', '/:id')]
    public function update(#[Param('id')] int $id, #[Body] array $body): array
    {
        return ['id' => $id] + $body;
    }

    #[Route('DELETE', '/:id')]
    public function destroy(#[Param('id')] int $id): Response
    {
        return Response::empty();
    }
}

PHP;

    public const SERVICE = <<<'PHP'
<?php

namespace {{namespace}};

use MikroApi\Service\BaseService;

class {{class}} extends BaseService
{
    public function __construct(
        // Inject repositories/services here — they are autowired.
    ) {}

    // $this->notFound(), $this->conflict(), $this->forbidden()... (BaseService)
    // or throw any MikroApi\Exception\HttpException to respond with an HTTP error.
}

PHP;

    public const REPOSITORY = <<<'PHP'
<?php

namespace {{namespace}};

use MikroApi\Repository\BaseRepository;

class {{class}} extends BaseRepository
{
    protected string $table = '{{table}}';

    /** @var string[] Columns that may be mass-assigned via create()/update() */
    protected array $fillable = [
        {{fillable}}
    ];

    // protected bool $useSoftDeletes = true;

    // #[HasMany(repository: SomeRelatedRepository::class, foreignKey: '{{foreignKey}}')]
    // public array $related;
}

PHP;

    public const DTO = <<<'PHP'
<?php

namespace {{namespace}};

use MikroApi\RequestDto;
use MikroApi\Attributes\Validation\IsString;
use MikroApi\Attributes\Validation\MaxLength;
use MikroApi\Attributes\Validation\Optional;
use MikroApi\Attributes\Validation\Required;

class {{class}} extends RequestDto
{
    #[{{presence}}]
    #[IsString]
    #[MaxLength(255)]
    public string $name;
}

PHP;

    public const GUARD = <<<'PHP'
<?php

namespace {{namespace}};

use MikroApi\BaseGuard;
use MikroApi\Exception\ForbiddenException;
use MikroApi\Reflector;
use MikroApi\Request;

/**
 * Attach with #[UseGuards({{class}}::class)] on a controller/method,
 * or globally with $app->useGlobalGuards({{class}}::class).
 */
class {{class}} extends BaseGuard
{
    public function __construct(private Reflector $reflector) {}

    public function canActivate(Request $request): bool
    {
        // $request->user     → authenticated user (set by JwtGuard)
        // $request->context  → controller/method being executed
        // $this->reflector->getAllAndOverride('key', $request->context) → route metadata

        // Return false to answer with deny() (401), or throw an HttpException:
        // throw new ForbiddenException('Not allowed');

        return true;
    }
}

PHP;

    public const INTERCEPTOR = <<<'PHP'
<?php

namespace {{namespace}};

use MikroApi\ExecutionContext;
use MikroApi\Interceptor\InterceptorInterface;
use MikroApi\Response;

/**
 * Attach with #[UseInterceptors({{class}}::class)] on a controller/method,
 * or globally with $app->useGlobalInterceptors({{class}}::class).
 */
class {{class}} implements InterceptorInterface
{
    public function intercept(ExecutionContext $context, callable $next): mixed
    {
        // Before the handler: $context->getClass(), $context->getHandler(), $context->getRequest()

        $result = $next();

        // After the handler: transform $result (array or Response) or return it as is
        return $result;
    }
}

PHP;

    public const FILTER = <<<'PHP'
<?php

namespace {{namespace}};

use MikroApi\Attributes\Catches;
use MikroApi\Exception\ExceptionFilterInterface;
use MikroApi\Exception\HttpException;
use MikroApi\ExecutionContext;
use MikroApi\Request;
use MikroApi\Response;

/**
 * Attach with #[UseFilters({{class}}::class)] on a controller/method,
 * or globally with $app->useGlobalFilters({{class}}::class).
 */
#[Catches(HttpException::class)]
class {{class}} implements ExceptionFilterInterface
{
    public function catch(\Throwable $exception, Request $request, ?ExecutionContext $context): ?Response
    {
        /** @var HttpException $exception */
        $response = Response::json([
            'statusCode' => $exception->getStatusCode(),
            'error'      => $exception->getMessage(),
            'path'       => $request->path,
        ] + $exception->getBody(), $exception->getStatusCode());

        foreach ($exception->getHeaders() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response; // return null to delegate to the next filter
    }
}

PHP;

    public const MIDDLEWARE = <<<'PHP'
<?php

namespace {{namespace}};

use MikroApi\Middleware\MiddlewareInterface;
use MikroApi\Request;
use MikroApi\Response;

/**
 * Register with $app->useMiddleware(new {{class}}()).
 */
class {{class}} implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        // Runs BEFORE the rest of the pipeline (routing included)

        $response = $next($request);

        // Runs AFTER — e.g. return $response->withHeader('X-Foo', 'bar');
        return $response;
    }
}

PHP;

    public const ATTRIBUTE = <<<'PHP'
<?php

namespace {{namespace}};

use MikroApi\Attributes\SetMetadata;

/**
 * Route metadata. Read it in a guard/interceptor/filter with:
 *   $this->reflector->getAllAndOverride({{class}}::KEY, $request->context)
 *
 *   #[{{class}}('value')]
 *   #[Route('GET', '/')]
 *   public function index() { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class {{class}} extends SetMetadata
{
    public const KEY = '{{key}}';

    public function __construct(string ...$values)
    {
        parent::__construct(self::KEY, $values);
    }
}

PHP;

    public const MIGRATION = <<<'PHP'
<?php

namespace Database\Migrations;

use MikroApi\Database\Migration;
use MikroApi\Attributes\Schema\Column;
use MikroApi\Attributes\Schema\ForeignKey;
use MikroApi\Attributes\Schema\Index;
use MikroApi\Attributes\Schema\PrimaryKey;
use MikroApi\Attributes\Schema\SoftDeletes;
use MikroApi\Attributes\Schema\Table;
use MikroApi\Attributes\Schema\Timestamps;
use MikroApi\Attributes\Schema\Unique;

#[Table('{{table}}')]
#[Timestamps]
class {{class}} extends Migration
{
    #[PrimaryKey]
    #[Column(type: 'int')]
    public int $id;
{{columns}}
}

PHP;

    public const VIEW = <<<'PHP'
<?php /* Render with Response::render('{{view}}', ['title' => '...']) */ ?>
<h1>{{ $title ?? '{{name}}' }}</h1>

PHP;

    public const TEST = <<<'PHP'
<?php

namespace {{namespace}};

use {{appModule}};
use MikroApi\App;
use MikroApi\Database\Database;
use MikroApi\Request;
use PHPUnit\Framework\TestCase;

class {{class}} extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = App::create({{appModuleShort}}::class);

        // Routes that use repositories need a Database. For example, an
        // in-memory SQLite one (create the tables your test needs first):
        // Database::reset();
        // $db = Database::connect(['driver' => 'sqlite', 'database' => ':memory:']);
        // $db->execute('CREATE TABLE ...');
        // $this->app->getContainer()->instance(Database::class, $db);
    }

    public function testGet(): void
    {
        $response = $this->app->handle(Request::create('GET', '{{route}}'));

        $this->assertSame(200, $response->getStatus());
    }
}

PHP;

    /* ------------------------------------------------------------------ */
    /*  Resource (make:resource) — full CRUD wired through a module          */
    /* ------------------------------------------------------------------ */

    public const RESOURCE_MODULE = <<<'PHP'
<?php

namespace {{namespace}};

use MikroApi\Attributes\Module;

#[Module(
    controllers: [{{controller}}::class],
    providers: [{{service}}::class, {{repository}}::class],
    exports: [{{service}}::class],
)]
class {{class}}
{
}

PHP;

    public const RESOURCE_CONTROLLER = <<<'PHP'
<?php

namespace {{namespace}};

use {{namespace}}\Dto\{{createDto}};
use {{namespace}}\Dto\{{updateDto}};
use MikroApi\Attributes\Body;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Param;
use MikroApi\Attributes\Query;
use MikroApi\Attributes\Route;
use MikroApi\Response;

#[Controller('{{route}}')]
class {{class}}
{
    public function __construct(private {{service}} ${{serviceVar}}) {}

    #[Route('GET', '/')]
    public function index(#[Query('page')] int $page = 1, #[Query('perPage')] int $perPage = 15): array
    {
        return $this->{{serviceVar}}->paginate($page, $perPage);
    }

    #[Route('GET', '/:id')]
    public function show(#[Param('id')] int $id): array
    {
        return $this->{{serviceVar}}->findOne($id);
    }

    #[Route('POST', '/')]
    public function store(#[Body] {{createDto}} $dto): Response
    {
        return Response::json($this->{{serviceVar}}->create($dto), 201);
    }

    #[Route('PUT', '/:id')]
    public function update(#[Param('id')] int $id, #[Body] {{updateDto}} $dto): array
    {
        return $this->{{serviceVar}}->update($id, $dto);
    }

    #[Route('DELETE', '/:id')]
    public function destroy(#[Param('id')] int $id): Response
    {
        $this->{{serviceVar}}->remove($id);
        return Response::empty();
    }
}

PHP;

    public const RESOURCE_SERVICE = <<<'PHP'
<?php

namespace {{namespace}};

use {{namespace}}\Dto\{{createDto}};
use {{namespace}}\Dto\{{updateDto}};
use MikroApi\Service\BaseService;

class {{class}} extends BaseService
{
    public function __construct(private {{repository}} $repository) {}

    public function paginate(int $page, int $perPage): array
    {
        return $this->repository->paginate(\max(1, $page), \min(100, \max(1, $perPage)));
    }

    public function findOne(int $id): array
    {
        return $this->repository->findById($id) ?? $this->notFound("{{singular}} {$id} not found");
    }

    public function create({{createDto}} $dto): array
    {
        return $this->repository->create($dto->toArray());
    }

    public function update(int $id, {{updateDto}} $dto): array
    {
        $this->findOne($id);
        return $this->repository->update($id, $dto->toArray());
    }

    public function remove(int $id): void
    {
        $this->findOne($id);
        $this->repository->delete($id);
    }
}

PHP;

    /* ------------------------------------------------------------------ */
    /*  Project (init)                                                       */
    /* ------------------------------------------------------------------ */

    public const PROJECT_INDEX = <<<'PHP'
<?php

/**
 * Front controller — run with: vendor/bin/mikro serve
 */

require __DIR__ . '/../vendor/autoload.php';

use {{rootNamespace}}AppModule;
use MikroApi\App;
use MikroApi\Config\ConfigModule;
use MikroApi\Database\Database;
use MikroApi\Middleware\CorsMiddleware;
use MikroApi\Middleware\JsonBodyMiddleware;

$app = App::create(
    // Loads .env (+ .env.{APP_ENV}) and exposes ConfigService to every module
    ConfigModule::forRoot(envFilePath: __DIR__ . '/..'),
    AppModule::class,
);

// Lazy database connection: only opened when a repository needs it
$app->getContainer()->singleton(
    Database::class,
    fn() => Database::connect(require __DIR__ . '/../config/database.php'),
);

$app->useViews(__DIR__ . '/../views')
    ->useMiddleware(new CorsMiddleware(), new JsonBodyMiddleware())
    ->enableSwagger(['title' => '{{appName}}', 'version' => '1.0.0']) // UI at /docs
    ->run();

PHP;

    public const PROJECT_APP_MODULE = <<<'PHP'
<?php

namespace {{namespace}};

use MikroApi\Attributes\Module;

/**
 * Root module. `vendor/bin/mikro make:resource <name>` and
 * `vendor/bin/mikro make:module <name>` add their modules to `imports`.
 */
#[Module(
    imports: [],
    controllers: [AppController::class],
)]
class AppModule
{
}

PHP;

    public const PROJECT_APP_CONTROLLER = <<<'PHP'
<?php

namespace {{namespace}};

use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Route;
use MikroApi\Config\ConfigService;

#[Controller('/')]
class AppController
{
    public function __construct(private ConfigService $config) {}

    #[Route('GET', '/')]
    public function index(): array
    {
        return [
            'app'       => $this->config->get('APP_NAME', 'MikroAPI'),
            'message'   => 'Welcome to your new MikroAPI project!',
            'docs'      => '/docs',
            'timestamp' => \date('c'),
        ];
    }
}

PHP;

    public const PROJECT_DATABASE_CONFIG = <<<'PHP'
<?php

// SQLite by default — zero external setup required.
return [
    'driver'   => 'sqlite',
    'database' => __DIR__ . '/../database/database.sqlite',
];

// MySQL/MariaDB alternative (uncomment and remove the block above).
// Values come from .env (loaded by ConfigModule over HTTP and by the CLI).
// return [
//     'driver'   => 'mysql',
//     'host'     => $_ENV['DB_HOST'] ?? 'localhost',
//     'port'     => (int) ($_ENV['DB_PORT'] ?? 3306),
//     'database' => $_ENV['DB_DATABASE'] ?? 'my_app',
//     'username' => $_ENV['DB_USERNAME'] ?? 'root',
//     'password' => $_ENV['DB_PASSWORD'] ?? '',
//     'charset'  => 'utf8mb4',
// ];

// PostgreSQL alternative (requires the pdo_pgsql extension).
// return [
//     'driver'   => 'pgsql',
//     'host'     => $_ENV['DB_HOST'] ?? 'localhost',
//     'port'     => (int) ($_ENV['DB_PORT'] ?? 5432),
//     'database' => $_ENV['DB_DATABASE'] ?? 'my_app',
//     'username' => $_ENV['DB_USERNAME'] ?? 'postgres',
//     'password' => $_ENV['DB_PASSWORD'] ?? '',
// ];

PHP;

    public const PROJECT_ENV = <<<'ENV'
APP_NAME="{{appName}}"
APP_ENV=development

# Generate a new one with: vendor/bin/mikro key:generate --force
JWT_SECRET={{jwtSecret}}
JWT_TTL=3600

# DB_* variables are only read if you switch config/database.php to MySQL/PostgreSQL.
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=my_app
DB_USERNAME=root
DB_PASSWORD=

ENV;

    public const PROJECT_GITIGNORE = <<<'TXT'
/vendor/
/cache/
/database/*.sqlite
/database/*.sqlite-*
.env
.DS_Store
.phpunit.result.cache
composer.lock

TXT;

    public const PROJECT_COMPOSER = <<<'JSON'
{
    "name": "your-vendor/your-app",
    "description": "A MikroAPI application",
    "type": "project",
    "require": {
        "php": ">=8.1",
        "mikro-api/mikro-api": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "App\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "serve": "mikro serve",
        "migrate": "mikro migrate"
    },
    "config": {
        "sort-packages": true
    }
}

JSON;

    public const PROJECT_README = <<<'MD'
# {{appName}}

Scaffolded with `vendor/bin/mikro new`.

## Getting Started

```bash
composer install
vendor/bin/mikro migrate
vendor/bin/mikro serve          # http://localhost:8000 — API docs at /docs
```

## Project Structure

```
├── public/index.php        Front controller (App::create(ConfigModule, AppModule))
├── src/
│   ├── AppModule.php        Root module — feature modules are added to its imports
│   ├── AppController.php
│   └── <Feature>/           One folder per feature module (make:resource / make:module)
├── database/migrations/     Attribute-driven schema migrations
├── config/database.php      Database connection config
├── views/                   Templates rendered via Response::render()
├── tests/                   PHPUnit tests (make:test)
└── .env                     Environment configuration (not committed)
```

## CLI

```bash
vendor/bin/mikro list                         # all commands
vendor/bin/mikro make:resource products       # module + controller + service + repository + DTOs + migration
vendor/bin/mikro g controller Report --module=Products
vendor/bin/mikro make:guard Admin
vendor/bin/mikro migrate
vendor/bin/mikro route:list
```

MD;
}
