<?php
/**
 * Modules Example
 *
 * Shows the NestJS-style features working together:
 *   - ConfigModule::forRoot() loading .env (JWT_SECRET, JWT_TTL) with validation
 *   - Modules with encapsulated providers and a factory provider that injects ConfigService
 *   - Built-in JWT authentication (JwtService/JwtGuard) registered globally + #[PublicRoute]
 *   - Role-based authorization with #[Roles] + RolesGuard
 *   - Handler parameter injection (#[Param], #[Query], #[Body], #[CurrentUser])
 *   - An interceptor that wraps every response
 *   - A global exception filter that formats every error
 *   - OnModuleInit lifecycle hook
 *
 * Run: php -S localhost:8000 index.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MikroApi\App;
use MikroApi\ExecutionContext;
use MikroApi\Request;
use MikroApi\Response;
use MikroApi\Attributes\Body;
use MikroApi\Attributes\Catches;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\CurrentUser;
use MikroApi\Attributes\Module;
use MikroApi\Attributes\Param;
use MikroApi\Attributes\PublicRoute;
use MikroApi\Attributes\Query;
use MikroApi\Attributes\Roles;
use MikroApi\Attributes\Route;
use MikroApi\Attributes\UseInterceptors;
use MikroApi\Attributes\Validation\IsEmail;
use MikroApi\Attributes\Validation\MinLength;
use MikroApi\Attributes\Validation\Required;
use MikroApi\Auth\JwtGuard;
use MikroApi\Auth\JwtService;
use MikroApi\Auth\RolesGuard;
use MikroApi\Config\ConfigModule;
use MikroApi\Config\ConfigService;
use MikroApi\Exception\ExceptionFilterInterface;
use MikroApi\Exception\HttpException;
use MikroApi\Exception\NotFoundException;
use MikroApi\Exception\UnauthorizedException;
use MikroApi\Interceptor\InterceptorInterface;
use MikroApi\Module\OnModuleInit;

// ── Auth module (global) ────────────────────────────────────────────────

class LoginDto
{
    #[Required]
    #[IsEmail]
    public string $email;

    #[Required]
    #[MinLength(6)]
    public string $password;
}

#[Controller('/auth')]
class AuthController
{
    public function __construct(private JwtService $jwt) {}

    #[Route('POST', '/login')]
    #[PublicRoute]
    public function login(#[Body] LoginDto $dto): array
    {
        if ($dto->password !== 'secret123') {
            throw new UnauthorizedException('Invalid credentials');
        }

        // Demo: any email starting with "admin" gets the admin role
        $roles = str_starts_with($dto->email, 'admin') ? ['admin'] : ['user'];

        return ['token' => $this->jwt->sign(['sub' => 1, 'email' => $dto->email, 'roles' => $roles])];
    }

    #[Route('GET', '/me')]
    public function me(#[CurrentUser] array $user): array
    {
        return $user;
    }
}

#[Module(
    controllers: [AuthController::class],
    providers: [
        // JwtService needs the secret from .env: build it with a factory that receives ConfigService
        ['provide' => JwtService::class, 'useFactory' => [AuthModule::class, 'createJwt'], 'inject' => [ConfigService::class]],
    ],
    exports: [JwtService::class],
    global: true, // JwtGuard (global) can resolve JwtService from any module
)]
class AuthModule
{
    public static function createJwt(ConfigService $config): JwtService
    {
        return new JwtService(
            secret: $config->get('jwt.secret'),
            ttl:    $config->get('jwt.ttl'),
        );
    }
}

// ── Products module ─────────────────────────────────────────────────────

enum ProductStatus: string
{
    case Active   = 'active';
    case Archived = 'archived';
}

class ProductService implements OnModuleInit
{
    private array $products = [];

    public function onModuleInit(): void
    {
        $this->products = [
            1 => ['id' => 1, 'name' => 'Keyboard', 'status' => 'active'],
            2 => ['id' => 2, 'name' => 'Mouse',    'status' => 'active'],
            3 => ['id' => 3, 'name' => 'CRT',      'status' => 'archived'],
        ];
    }

    public function list(ProductStatus $status): array
    {
        return array_values(array_filter($this->products, fn($p) => $p['status'] === $status->value));
    }

    public function find(int $id): array
    {
        return $this->products[$id] ?? throw new NotFoundException("Product {$id} not found");
    }

    public function delete(int $id): void
    {
        $this->find($id);
        unset($this->products[$id]);
    }
}

/** Wraps every successful result as {"data": ..., "handler": "..."} */
class EnvelopeInterceptor implements InterceptorInterface
{
    public function intercept(ExecutionContext $context, callable $next): mixed
    {
        $result = $next();
        return $result instanceof Response
            ? $result
            : ['data' => $result, 'handler' => $context->getHandler()];
    }
}

#[Controller('/products')]
#[UseInterceptors(EnvelopeInterceptor::class)]
class ProductController
{
    public function __construct(private ProductService $products) {}

    #[Route('GET', '/')]
    #[PublicRoute]
    public function index(#[Query('status')] ProductStatus $status = ProductStatus::Active): array
    {
        return $this->products->list($status);
    }

    #[Route('GET', '/:id')]
    public function show(#[Param('id')] int $id): array
    {
        return $this->products->find($id);
    }

    #[Route('DELETE', '/:id')]
    #[Roles('admin')]
    public function delete(#[Param('id')] int $id): Response
    {
        $this->products->delete($id);
        return Response::empty();
    }
}

#[Module(controllers: [ProductController::class], providers: [ProductService::class])]
class ProductsModule {}

#[Module(imports: [AuthModule::class, ProductsModule::class])]
class AppModule {}

// ── Global error format ─────────────────────────────────────────────────

#[Catches(HttpException::class)]
class HttpErrorFilter implements ExceptionFilterInterface
{
    public function catch(\Throwable $exception, Request $request, ?ExecutionContext $context): ?Response
    {
        /** @var HttpException $exception */
        $body = $exception->getBody() + [
            'statusCode' => $exception->getStatusCode(),
            'path'       => $request->path,
        ];

        $response = Response::json($body, $exception->getStatusCode());
        foreach ($exception->getHeaders() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}

// ── Bootstrap ───────────────────────────────────────────────────────────

App::create(
    // Must come before the modules that use ConfigService
    ConfigModule::forRoot(
        envFilePath: __DIR__,
        load: [
            'jwt' => fn(ConfigService $c) => [
                'secret' => $c->getOrThrow('JWT_SECRET'),
                'ttl'    => $c->getInt('JWT_TTL', 3600),
            ],
        ],
        validate: ['JWT_SECRET'], // fail at startup if missing
    ),
    AppModule::class,
)
    ->useGlobalGuards(JwtGuard::class, RolesGuard::class)
    ->useGlobalFilters(HttpErrorFilter::class)
    ->run();
