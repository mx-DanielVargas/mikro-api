<?php

namespace MikroApi\Tests\Auth;

use MikroApi\App;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\CurrentUser;
use MikroApi\Attributes\Param;
use MikroApi\Attributes\PublicRoute;
use MikroApi\Attributes\Query;
use MikroApi\Attributes\Body;
use MikroApi\Attributes\Roles;
use MikroApi\Attributes\Route;
use MikroApi\Attributes\UseGuards;
use MikroApi\Attributes\Validation\IsEmail;
use MikroApi\Attributes\Validation\Required;
use MikroApi\Auth\JwtGuard;
use MikroApi\Auth\JwtService;
use MikroApi\Auth\RolesGuard;
use MikroApi\Exception\UnauthorizedException;
use MikroApi\Request;
use MikroApi\Response;
use MikroApi\Swagger\SwaggerGenerator;
use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    private const SECRET = 'test-secret-que-es-suficientemente-largo';

    private JwtService $jwt;

    protected function setUp(): void
    {
        $this->jwt = new JwtService(self::SECRET);
    }

    private function app(string ...$controllers): App
    {
        $app = new App();
        $app->getContainer()->instance(JwtService::class, $this->jwt);
        return $app->useController(...$controllers);
    }

    private function bearer(string $method, string $path, array $claims): Request
    {
        return Request::create($method, $path, [], [], ['Authorization' => 'Bearer ' . $this->jwt->sign($claims)]);
    }

    /* ------------------------------------------------------------------ */
    /*  JwtService                                                          */
    /* ------------------------------------------------------------------ */

    public function testSignAndVerifyRoundTrip(): void
    {
        $payload = $this->jwt->verify($this->jwt->sign(['sub' => 7, 'name' => 'Ñandú']));

        $this->assertSame(7, $payload['sub']);
        $this->assertSame('Ñandú', $payload['name']);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertArrayHasKey('exp', $payload);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = $this->jwt->sign(['sub' => 1, 'exp' => \time() - 10]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Token expirado');
        $this->jwt->verify($token);
    }

    public function testNotBeforeIsRespected(): void
    {
        $token = $this->jwt->sign(['sub' => 1, 'nbf' => \time() + 60]);

        $this->expectExceptionMessage('Token aún no válido');
        $this->jwt->verify($token);
    }

    public function testTamperedPayloadIsRejected(): void
    {
        [$h, , $s] = \explode('.', $this->jwt->sign(['sub' => 1, 'roles' => ['user']]));
        $forged    = \rtrim(\strtr(\base64_encode(\json_encode(['sub' => 1, 'roles' => ['admin']])), '+/', '-_'), '=');

        $this->expectExceptionMessage('Token inválido');
        $this->jwt->verify("{$h}.{$forged}.{$s}");
    }

    public function testWrongSecretIsRejected(): void
    {
        $token = (new JwtService('otro-secreto'))->sign(['sub' => 1]);

        $this->expectException(UnauthorizedException::class);
        $this->jwt->verify($token);
    }

    public function testAlgNoneIsRejected(): void
    {
        $enc   = fn(array $d) => \rtrim(\strtr(\base64_encode(\json_encode($d)), '+/', '-_'), '=');
        $token = $enc(['alg' => 'none', 'typ' => 'JWT']) . '.' . $enc(['sub' => 1]) . '.';

        $this->expectExceptionMessage('Token inválido');
        $this->jwt->verify($token);
    }

    public function testIssuerIsCheckedWhenConfigured(): void
    {
        $issuerJwt = new JwtService(self::SECRET, issuer: 'mikro');
        $this->assertSame('mikro', $issuerJwt->verify($issuerJwt->sign(['sub' => 1]))['iss']);

        $this->expectException(UnauthorizedException::class);
        $issuerJwt->verify($this->jwt->sign(['sub' => 1])); // sin iss
    }

    public function testInvalidConfigurationThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JwtService(self::SECRET, 'RS256');
    }

    /* ------------------------------------------------------------------ */
    /*  JwtGuard / RolesGuard / PublicRoute                                 */
    /* ------------------------------------------------------------------ */

    public function testJwtGuardRejectsMissingToken(): void
    {
        $res = $this->app(AdminController::class)->handle(Request::create('GET', '/admin/me'));

        $this->assertSame(401, $res->getStatus());
        $this->assertSame(['error' => 'Token no proporcionado'], \json_decode($res->getBody(), true));
    }

    public function testJwtGuardSetsUserForCurrentUser(): void
    {
        $res = $this->app(AdminController::class)->handle($this->bearer('GET', '/admin/me', ['sub' => 99]));

        $this->assertSame(200, $res->getStatus());
        $this->assertSame(['sub' => 99], \json_decode($res->getBody(), true));
    }

    public function testRolesGuardForbidsWithoutRole(): void
    {
        $res = $this->app(AdminController::class)
            ->handle($this->bearer('DELETE', '/admin/users/1', ['sub' => 1, 'roles' => ['user']]));

        $this->assertSame(403, $res->getStatus());
    }

    public function testRolesGuardAllowsWithRoleArrayOrSingleRole(): void
    {
        $app = $this->app(AdminController::class);

        $res = $app->handle($this->bearer('DELETE', '/admin/users/1', ['sub' => 1, 'roles' => ['admin']]));
        $this->assertSame(204, $res->getStatus());

        $res = $app->handle($this->bearer('DELETE', '/admin/users/1', ['sub' => 1, 'role' => 'superadmin']));
        $this->assertSame(204, $res->getStatus());
    }

    public function testGlobalJwtGuardWithPublicRoute(): void
    {
        $app = $this->app(SessionController::class)->useGlobalGuards(JwtGuard::class);

        $this->assertSame(200, $app->handle(Request::create('POST', '/session/login', [], ['email' => 'a@b.co']))->getStatus());
        $this->assertSame(401, $app->handle(Request::create('GET', '/session/profile'))->getStatus());
        $this->assertSame(200, $app->handle($this->bearer('GET', '/session/profile', ['sub' => 1]))->getStatus());
    }

    /* ------------------------------------------------------------------ */
    /*  Swagger                                                             */
    /* ------------------------------------------------------------------ */

    public function testSwaggerDocumentsParameterAttributes(): void
    {
        $spec = (new SwaggerGenerator())->generate([AdminController::class, SessionController::class], [], []);

        $delete = $spec['paths']['/admin/users/{id}']['delete'];
        $this->assertSame(['type' => 'integer'], $delete['parameters'][0]['schema']);
        $this->assertArrayHasKey('403', $delete['responses']);
        $this->assertArrayHasKey('401', $delete['responses']);

        $search = $spec['paths']['/admin/users']['get'];
        $byName = \array_column($search['parameters'], null, 'name');
        $this->assertSame(['type' => 'integer'], $byName['page']['schema']);
        $this->assertFalse($byName['page']['required']);

        $login = $spec['paths']['/session/login']['post'];
        $this->assertSame('#/components/schemas/LoginDto', $login['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertArrayHasKey('LoginDto', $spec['components']['schemas']);
        $this->assertArrayNotHasKey('security', $login); // #[PublicRoute]
    }
}

class LoginDto
{
    #[Required]
    #[IsEmail]
    public string $email;
}

#[Controller('/admin')]
#[UseGuards(JwtGuard::class, RolesGuard::class)]
class AdminController
{
    #[Route('GET', '/me')]
    public function me(#[CurrentUser('sub')] int $id): array
    {
        return ['sub' => $id];
    }

    #[Route('GET', '/users')]
    public function list(#[Query('page')] int $page = 1): array
    {
        return [];
    }

    #[Route('DELETE', '/users/:id')]
    #[Roles('admin', 'superadmin')]
    public function delete(#[Param('id')] int $id): Response
    {
        return Response::empty();
    }
}

#[Controller('/session')]
class SessionController
{
    #[Route('POST', '/login')]
    #[PublicRoute]
    #[UseGuards(JwtGuard::class)]
    public function login(#[Body] LoginDto $dto): array
    {
        return ['token' => 'x'];
    }

    #[Route('GET', '/profile')]
    public function profile(#[CurrentUser] array $user): array
    {
        return $user;
    }
}
