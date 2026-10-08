<?php

namespace MikroApi\Tests\Pipeline;

use MikroApi\App;
use MikroApi\Attributes\Body;
use MikroApi\Attributes\Catches;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\CurrentUser;
use MikroApi\Attributes\Headers;
use MikroApi\Attributes\Param;
use MikroApi\Attributes\Query;
use MikroApi\Attributes\Route;
use MikroApi\Attributes\SetMetadata;
use MikroApi\Attributes\UseFilters;
use MikroApi\Attributes\UseGuards;
use MikroApi\Attributes\UseInterceptors;
use MikroApi\Attributes\Validation\IsInt;
use MikroApi\Attributes\Validation\Max;
use MikroApi\Attributes\Validation\Min;
use MikroApi\Attributes\Validation\MinLength;
use MikroApi\Attributes\Validation\Optional;
use MikroApi\Attributes\Validation\Required;
use MikroApi\Exception\ConflictException;
use MikroApi\Exception\ExceptionFilterInterface;
use MikroApi\Exception\HttpException;
use MikroApi\Exception\NotFoundException;
use MikroApi\ExecutionContext;
use MikroApi\GuardInterface;
use MikroApi\Interceptor\InterceptorInterface;
use MikroApi\Middleware\MiddlewareInterface;
use MikroApi\Reflector;
use MikroApi\Request;
use MikroApi\Response;
use MikroApi\Router;
use MikroApi\Service\ServiceException;
use PHPUnit\Framework\TestCase;

class HttpPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        InterceptorLog::$calls = [];
        unset($_ENV['APP_ENV']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_ENV']);
    }

    private function app(string ...$controllers): App
    {
        return (new App())->useController(...$controllers);
    }

    private function json(Response $response): mixed
    {
        return \json_decode($response->getBody(), true);
    }

    /* ------------------------------------------------------------------ */
    /*  404 / 405                                                           */
    /* ------------------------------------------------------------------ */

    public function testUnknownPathReturns404(): void
    {
        $res = $this->app(ItemsController::class)->handle(Request::create('GET', '/nope'));

        $this->assertSame(404, $res->getStatus());
        $this->assertSame(['error' => 'Not Found'], $this->json($res));
    }

    public function testWrongMethodReturns405WithAllowHeader(): void
    {
        $res = $this->app(ItemsController::class)->handle(Request::create('DELETE', '/items/5'));

        $this->assertSame(405, $res->getStatus());
        $this->assertSame('GET, PUT', $res->getHeader('Allow'));
    }

    /* ------------------------------------------------------------------ */
    /*  HttpException y filtros                                             */
    /* ------------------------------------------------------------------ */

    public function testHttpExceptionBecomesResponse(): void
    {
        $res = $this->app(ErrorsController::class)->handle(Request::create('GET', '/errors/conflict'));

        $this->assertSame(409, $res->getStatus());
        $this->assertSame(['error' => 'duplicado'], $this->json($res));
    }

    public function testHttpExceptionCustomBodyAndHeaders(): void
    {
        $res = $this->app(ErrorsController::class)->handle(Request::create('GET', '/errors/teapot'));

        $this->assertSame(418, $res->getStatus());
        $this->assertSame(['code' => 'TEAPOT'], $this->json($res));
        $this->assertSame('1', $res->getHeader('X-Teapot'));
    }

    public function testServiceExceptionStillWorks(): void
    {
        $res = $this->app(ErrorsController::class)->handle(Request::create('GET', '/errors/service'));

        $this->assertSame(402, $res->getStatus());
        $this->assertSame(['error' => 'pago requerido'], $this->json($res));
        $this->assertInstanceOf(HttpException::class, new ServiceException('x'));
    }

    public function testGenericExceptionIsMaskedInProduction(): void
    {
        $app = $this->app(ErrorsController::class);

        $res = $app->handle(Request::create('GET', '/errors/boom'));
        $this->assertSame(['error' => 'secreto interno'], $this->json($res));

        $_ENV['APP_ENV'] = 'production';
        $res = $app->handle(Request::create('GET', '/errors/boom'));
        $this->assertSame(500, $res->getStatus());
        $this->assertSame(['error' => 'Internal Server Error'], $this->json($res));
    }

    public function testMethodFilterWinsOverClassFilter(): void
    {
        $res = $this->app(FilteredController::class)->handle(Request::create('GET', '/filtered/method'));

        $this->assertSame(['filter' => 'method', 'message' => 'no está'], $this->json($res));
    }

    public function testClassFilterUsedWhenMethodFilterDoesNotCatchType(): void
    {
        // El filtro del método solo atiende NotFoundException; ConflictException
        // cae al filtro de clase (sin #[Catches] → atiende todo).
        $res = $this->app(FilteredController::class)->handle(Request::create('GET', '/filtered/conflict'));

        $this->assertSame(['filter' => 'class', 'message' => 'choque'], $this->json($res));
    }

    public function testFilterReturningNullDelegatesToDefault(): void
    {
        $res = $this->app(ErrorsController::class)
            ->useGlobalFilters(PassThroughFilter::class)
            ->handle(Request::create('GET', '/errors/conflict'));

        $this->assertSame(409, $res->getStatus());
        $this->assertSame(['error' => 'duplicado'], $this->json($res));
    }

    public function testGlobalFilterCatches404(): void
    {
        $res = $this->app(ItemsController::class)
            ->useGlobalFilters(MethodNotFoundFilter::class)
            ->handle(Request::create('GET', '/nope'));

        $this->assertSame(['filter' => 'method', 'message' => 'Not Found'], $this->json($res));
    }

    public function testGlobalFilterCatchesMiddlewareException(): void
    {
        $res = $this->app(ItemsController::class)
            ->useMiddleware(new ThrowingMiddleware())
            ->useGlobalFilters(ClassAnyFilter::class)
            ->handle(Request::create('GET', '/items/1'));

        $this->assertSame(['filter' => 'class', 'message' => 'middleware roto'], $this->json($res));
    }

    /* ------------------------------------------------------------------ */
    /*  Inyección y conversión de parámetros                                */
    /* ------------------------------------------------------------------ */

    public function testParamIsConvertedToInt(): void
    {
        $res = $this->app(ItemsController::class)->handle(Request::create('GET', '/items/42'));

        $this->assertSame(200, $res->getStatus());
        $this->assertSame(['id' => 42, 'type' => 'integer'], $this->json($res));
    }

    public function testInvalidIntParamReturns400(): void
    {
        $res = $this->app(ItemsController::class)->handle(Request::create('GET', '/items/abc'));

        $this->assertSame(400, $res->getStatus());
        $this->assertSame(['error' => "El parámetro de ruta 'id' debe ser un número entero"], $this->json($res));
    }

    public function testQueryDefaultsNullableEnumAndBool(): void
    {
        $app = $this->app(ItemsController::class);

        $res = $app->handle(Request::create('GET', '/items'));
        $this->assertSame(['page' => 1, 'q' => null, 'status' => 'active', 'archived' => false], $this->json($res));

        $res = $app->handle(Request::create('GET', '/items', [
            'page' => '3', 'q' => 'abc', 'status' => 'archived', 'archived' => 'true',
        ]));
        $this->assertSame(['page' => 3, 'q' => 'abc', 'status' => 'archived', 'archived' => true], $this->json($res));
    }

    public function testEmptyQueryStringUsesDefault(): void
    {
        $res = $this->app(ItemsController::class)->handle(Request::create('GET', '/items', ['page' => '']));

        $this->assertSame(1, $this->json($res)['page']);
    }

    public function testInvalidEnumReturns400(): void
    {
        $res = $this->app(ItemsController::class)->handle(Request::create('GET', '/items', ['status' => 'zzz']));

        $this->assertSame(400, $res->getStatus());
        $this->assertStringContainsString('active, archived', $this->json($res)['error']);
    }

    public function testMissingRequiredQueryReturns400(): void
    {
        $res = $this->app(ItemsController::class)->handle(Request::create('GET', '/items/search'));

        $this->assertSame(400, $res->getStatus());
        $this->assertSame(['error' => "El parámetro de query 'term' es obligatorio"], $this->json($res));
    }

    public function testBodyDtoParameterValidatesAndInjects(): void
    {
        $app = $this->app(ItemsController::class);

        $res = $app->handle(Request::create('PUT', '/items/7', [], ['name' => 'Lámpara', 'stock' => '5']));
        $this->assertSame(['id' => 7, 'name' => 'Lámpara', 'stock' => 5, 'sameAsReqDto' => true], $this->json($res));

        $res = $app->handle(Request::create('PUT', '/items/7', [], ['name' => 'x']));
        $this->assertSame(422, $res->getStatus());
        $body = $this->json($res);
        $this->assertSame('Validation failed', $body['error']);
        $this->assertArrayHasKey('name', $body['errors']);
        $this->assertArrayHasKey('stock', $body['errors']);
    }

    public function testQueryDtoIsValidated(): void
    {
        $app = $this->app(ItemsController::class);

        $res = $app->handle(Request::create('GET', '/items/paged', ['page' => '2', 'perPage' => '10']));
        $this->assertSame(['page' => 2, 'perPage' => 10], $this->json($res));

        $res = $app->handle(Request::create('GET', '/items/paged', ['page' => '0']));
        $this->assertSame(422, $res->getStatus());
        $this->assertArrayHasKey('page', $this->json($res)['errors']);
    }

    public function testParamDtoIsValidated(): void
    {
        $app = $this->app(ItemsController::class);

        $this->assertSame(['id' => 9], $this->json($app->handle(Request::create('GET', '/items/dto/9'))));
        $this->assertSame(422, $app->handle(Request::create('GET', '/items/dto/nine'))->getStatus());
    }

    public function testBodyFieldHeaderUserRequestAndContextInjection(): void
    {
        $req = Request::create('POST', '/items/misc', [], ['email' => 'a@b.c'], ['X-Tenant' => 'acme']);
        $req->user = ['sub' => '15', 'roles' => ['admin']];

        $res = $this->app(ItemsController::class)->handle($req);

        $this->assertSame([
            'email'   => 'a@b.c',
            'tenant'  => 'acme',
            'userId'  => 15,
            'path'    => '/items/misc',
            'handler' => ItemsController::class . '::misc',
        ], $this->json($res));
    }

    public function testClassicHandlerSignatureStillWorks(): void
    {
        $res = $this->app(ItemsController::class)->handle(Request::create('GET', '/items/classic/3'));

        $this->assertSame(['id' => '3'], $this->json($res));
    }

    public function testUninjectableParameterFailsAtRegistration(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('$repo');

        (new Router())->registerController(BadSignatureController::class);
    }

    public function testArgsSurviveRouteCache(): void
    {
        $file = \sys_get_temp_dir() . '/mikro-args-' . \bin2hex(\random_bytes(4)) . '.php';

        try {
            $warm = (new App())->cacheRoutes($file)->useController(ItemsController::class);
            $warm->handle(Request::create('GET', '/items/1')); // escribe el caché

            $cold = (new App())->cacheRoutes($file)->useController(ItemsController::class);
            $res  = $cold->handle(Request::create('GET', '/items', ['page' => '4', 'status' => 'archived']));

            $this->assertSame(4, $this->json($res)['page']);
            $this->assertSame('archived', $this->json($res)['status']);
        } finally {
            @\unlink($file);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Interceptors                                                        */
    /* ------------------------------------------------------------------ */

    public function testInterceptorsRunInOrderAndTransformResult(): void
    {
        $res = $this->app(InterceptedController::class)
            ->useGlobalInterceptors(GlobalInterceptor::class)
            ->handle(Request::create('GET', '/intercepted'));

        $this->assertSame(
            ['global:before', 'class:before', 'method:before', 'handler', 'method:after', 'class:after', 'global:after'],
            InterceptorLog::$calls,
        );
        $this->assertSame(['data' => ['value' => 1]], $this->json($res));
        $this->assertSame('yes', $res->getHeader('X-Global'));
    }

    public function testInterceptorCanShortCircuit(): void
    {
        $res = $this->app(InterceptedController::class)->handle(Request::create('GET', '/intercepted/cached'));

        $this->assertSame(['cached' => true], $this->json($res));
        $this->assertNotContains('handler', InterceptorLog::$calls);
    }

    public function testInterceptorRunsAfterGuards(): void
    {
        $res = $this->app(InterceptedController::class)->handle(Request::create('GET', '/intercepted/guarded'));

        $this->assertSame(401, $res->getStatus());
        $this->assertSame([], InterceptorLog::$calls);
    }

    /* ------------------------------------------------------------------ */
    /*  Metadatos / Reflector                                               */
    /* ------------------------------------------------------------------ */

    public function testReflectorOverrideAndMerge(): void
    {
        $reflector = new Reflector();
        $method    = new ExecutionContext(Request::create('GET', '/'), MetaController::class, 'withOwn');
        $inherit   = new ExecutionContext(Request::create('GET', '/'), MetaController::class, 'inherits');

        $this->assertSame(['editor'], $reflector->getAllAndOverride('roles', $method));
        $this->assertSame(['admin'], $reflector->getAllAndOverride('roles', $inherit));
        $this->assertSame(['editor', 'admin'], $reflector->getAllAndMerge('roles', $method));
        $this->assertSame(['users:write'], $reflector->getAllAndOverride('permissions', $method));
        $this->assertNull($reflector->getAllAndOverride('missing', $method));
        $this->assertFalse($reflector->has('missing', $method));
        $this->assertInstanceOf(Permissions::class, $reflector->getAttribute(Permissions::class, $method));
    }

    public function testGuardReadsMetadataThroughContext(): void
    {
        $app = $this->app(MetaController::class);

        $this->assertSame(403, $app->handle(Request::create('GET', '/meta/own'))->getStatus());

        $req = Request::create('GET', '/meta/own', [], [], ['X-Perms' => 'users:write']);
        $this->assertSame(200, $app->handle($req)->getStatus());
    }
}

/* ======================================================================= */
/*  Fixtures                                                                */
/* ======================================================================= */

enum ItemStatus: string
{
    case Active   = 'active';
    case Archived = 'archived';
}

class UpdateItemDto
{
    #[Required]
    #[MinLength(2)]
    public string $name;

    #[Required]
    #[IsInt]
    public int $stock;
}

class PageQuery
{
    #[Required]
    #[IsInt]
    #[Min(1)]
    public int $page;

    #[Optional]
    #[IsInt]
    #[Max(100)]
    public int $perPage = 15;
}

class IdParams
{
    #[Required]
    #[IsInt]
    public int $id;
}

#[Controller('/items')]
class ItemsController
{
    #[Route('GET', '/')]
    public function index(
        #[Query('page')] int $page = 1,
        #[Query('q')] ?string $q = null,
        #[Query('status')] ItemStatus $status = ItemStatus::Active,
        #[Query('archived')] bool $archived = false,
    ): array {
        return ['page' => $page, 'q' => $q, 'status' => $status->value, 'archived' => $archived];
    }

    #[Route('GET', '/search')]
    public function search(#[Query('term')] string $term): array
    {
        return ['term' => $term];
    }

    #[Route('GET', '/paged')]
    public function paged(#[Query] PageQuery $query): array
    {
        return ['page' => $query->page, 'perPage' => $query->perPage];
    }

    #[Route('GET', '/dto/:id')]
    public function byDto(#[Param] IdParams $params): array
    {
        return ['id' => $params->id];
    }

    #[Route('POST', '/misc')]
    public function misc(
        #[Body('email')] string $email,
        #[Headers('x-tenant')] ?string $tenant,
        #[CurrentUser('sub')] int $userId,
        Request $request,
        ExecutionContext $context,
    ): array {
        return [
            'email'   => $email,
            'tenant'  => $tenant,
            'userId'  => $userId,
            'path'    => $request->path,
            'handler' => $context->getClass() . '::' . $context->getHandler(),
        ];
    }

    #[Route('GET', '/classic/:id')]
    public function classic(Request $req): Response
    {
        return Response::json(['id' => $req->params['id']]);
    }

    #[Route('GET', '/:id')]
    public function show(#[Param('id')] int $id): array
    {
        return ['id' => $id, 'type' => \gettype($id)];
    }

    #[Route('PUT', '/:id')]
    public function update(#[Param('id')] int $id, #[Body] UpdateItemDto $dto, Request $req): array
    {
        return ['id' => $id, 'name' => $dto->name, 'stock' => $dto->stock, 'sameAsReqDto' => $req->dto === $dto];
    }
}

class BadSignatureController
{
    #[Route('GET', '/bad')]
    public function bad(\ArrayObject $repo): array
    {
        return [];
    }
}

#[Controller('/errors')]
class ErrorsController
{
    #[Route('GET', '/conflict')]
    public function conflict(): never
    {
        throw new ConflictException('duplicado');
    }

    #[Route('GET', '/teapot')]
    public function teapot(): never
    {
        throw new HttpException('teapot', 418, ['code' => 'TEAPOT'], ['X-Teapot' => '1']);
    }

    #[Route('GET', '/service')]
    public function service(): never
    {
        throw new ServiceException('pago requerido', 402);
    }

    #[Route('GET', '/boom')]
    public function boom(): never
    {
        throw new \RuntimeException('secreto interno');
    }
}

#[Catches(NotFoundException::class)]
class MethodNotFoundFilter implements ExceptionFilterInterface
{
    public function catch(\Throwable $exception, Request $request, ?ExecutionContext $context): ?Response
    {
        return Response::json(['filter' => 'method', 'message' => $exception->getMessage()], 404);
    }
}

class ClassAnyFilter implements ExceptionFilterInterface
{
    public function catch(\Throwable $exception, Request $request, ?ExecutionContext $context): ?Response
    {
        return Response::json(['filter' => 'class', 'message' => $exception->getMessage()], 500);
    }
}

class PassThroughFilter implements ExceptionFilterInterface
{
    public function catch(\Throwable $exception, Request $request, ?ExecutionContext $context): ?Response
    {
        return null;
    }
}

#[Controller('/filtered')]
#[UseFilters(ClassAnyFilter::class)]
class FilteredController
{
    #[Route('GET', '/method')]
    #[UseFilters(MethodNotFoundFilter::class)]
    public function method(): never
    {
        throw new NotFoundException('no está');
    }

    #[Route('GET', '/conflict')]
    #[UseFilters(MethodNotFoundFilter::class)]
    public function conflict(): never
    {
        throw new ConflictException('choque');
    }
}

class ThrowingMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        throw new \RuntimeException('middleware roto');
    }
}

class InterceptorLog
{
    public static array $calls = [];
}

abstract class LoggingInterceptor implements InterceptorInterface
{
    abstract protected function name(): string;

    public function intercept(ExecutionContext $context, callable $next): mixed
    {
        InterceptorLog::$calls[] = $this->name() . ':before';
        $result = $next();
        InterceptorLog::$calls[] = $this->name() . ':after';
        return $result;
    }
}

class GlobalInterceptor extends LoggingInterceptor
{
    protected function name(): string { return 'global'; }

    public function intercept(ExecutionContext $context, callable $next): mixed
    {
        $result = parent::intercept($context, $next);
        return Response::json($result)->withHeader('X-Global', 'yes');
    }
}

class ClassInterceptor extends LoggingInterceptor
{
    protected function name(): string { return 'class'; }
}

class WrapDataInterceptor extends LoggingInterceptor
{
    protected function name(): string { return 'method'; }

    public function intercept(ExecutionContext $context, callable $next): mixed
    {
        return ['data' => parent::intercept($context, $next)];
    }
}

class CacheInterceptor implements InterceptorInterface
{
    public function intercept(ExecutionContext $context, callable $next): mixed
    {
        return ['cached' => true];
    }
}

class DenyGuard implements GuardInterface
{
    public function canActivate(Request $request): bool { return false; }
    public function deny(): Response { return Response::error('Unauthorized', 401); }
}

#[Controller('/intercepted')]
#[UseInterceptors(ClassInterceptor::class)]
class InterceptedController
{
    #[Route('GET', '/')]
    #[UseInterceptors(WrapDataInterceptor::class)]
    public function index(): array
    {
        InterceptorLog::$calls[] = 'handler';
        return ['value' => 1];
    }

    #[Route('GET', '/cached')]
    #[UseInterceptors(CacheInterceptor::class)]
    public function cached(): array
    {
        InterceptorLog::$calls[] = 'handler';
        return ['cached' => false];
    }

    #[Route('GET', '/guarded')]
    #[UseGuards(DenyGuard::class)]
    public function guarded(): array
    {
        return [];
    }
}

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Permissions extends SetMetadata
{
    public function __construct(string ...$perms)
    {
        parent::__construct('permissions', $perms);
    }
}

class PermissionsGuard implements GuardInterface
{
    public function __construct(private Reflector $reflector) {}

    public function canActivate(Request $request): bool
    {
        $required = $this->reflector->getAllAndOverride('permissions', $request->context) ?? [];
        $granted  = \array_filter(\explode(',', $request->header('X-Perms') ?? ''));
        return empty(\array_diff($required, $granted));
    }

    public function deny(): Response
    {
        return Response::error('Forbidden', 403);
    }
}

#[Controller('/meta')]
#[SetMetadata('roles', ['admin'])]
#[UseGuards(PermissionsGuard::class)]
class MetaController
{
    #[Route('GET', '/own')]
    #[SetMetadata('roles', ['editor'])]
    #[Permissions('users:write')]
    public function withOwn(): array
    {
        return ['ok' => true];
    }

    #[Route('GET', '/inherits')]
    public function inherits(): array
    {
        return ['ok' => true];
    }
}
