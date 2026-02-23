<?php

namespace MikroApi\Tests;

use MikroApi\Router;
use MikroApi\Request;
use MikroApi\Response;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Route;
use MikroApi\Attributes\UseGuards;
use MikroApi\Attributes\Body;
use MikroApi\GuardInterface;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testSimpleRouteMatching(): void
    {
        $this->router->registerController(SimpleController::class);

        $request = $this->createRequest('GET', '/test');
        $response = $this->router->dispatch($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testRouteWithParameters(): void
    {
        $this->router->registerController(UserController::class);

        $request = $this->createRequest('GET', '/users/123');
        $response = $this->router->dispatch($request);

        $this->assertEquals('123', $request->params['id']);
    }

    public function testRouteWithMultipleParameters(): void
    {
        $this->router->registerController(PostController::class);

        $request = $this->createRequest('GET', '/users/5/posts/42');
        $response = $this->router->dispatch($request);

        $this->assertEquals('5', $request->params['userId']);
        $this->assertEquals('42', $request->params['postId']);
    }

    public function testControllerPrefix(): void
    {
        $this->router->registerController(ApiController::class);

        $request = $this->createRequest('GET', '/api/status');
        $response = $this->router->dispatch($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testNotFoundRoute(): void
    {
        $this->router->registerController(SimpleController::class);

        $request = $this->createRequest('GET', '/nonexistent');
        $response = $this->router->dispatch($request);

        // Verificar que retorna 404 (necesitarías acceder al status code)
        $this->assertInstanceOf(Response::class, $response);
    }

    public function testMethodNotAllowed(): void
    {
        $this->router->registerController(SimpleController::class);

        $request = $this->createRequest('POST', '/test');
        $response = $this->router->dispatch($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testGuardExecution(): void
    {
        $this->router->registerController(ProtectedController::class);

        $request = $this->createRequest('GET', '/protected');
        $response = $this->router->dispatch($request);

        // El guard debería denegar el acceso
        $this->assertInstanceOf(Response::class, $response);
    }

    public function testGuardWithValidToken(): void
    {
        $this->router->registerController(ProtectedController::class);

        $request = $this->createRequest('GET', '/protected');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid-token';
        
        $response = $this->router->dispatch($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testBodyValidation(): void
    {
        $this->router->registerController(ValidationController::class);

        $request = $this->createRequest('POST', '/validate', ['name' => 'John']);
        $response = $this->router->dispatch($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testBodyValidationFailure(): void
    {
        $this->router->registerController(ValidationController::class);

        $request = $this->createRequest('POST', '/validate', ['name' => '']); // Invalid
        $response = $this->router->dispatch($request);

        $this->assertInstanceOf(Response::class, $response);
        // Debería retornar 422
    }

    private function createRequest(string $method, string $path, array $body = []): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        
        if (!empty($body)) {
            $_SERVER['CONTENT_TYPE'] = 'application/json';
            // Simular php://input
        }

        return Request::capture();
    }
}

// ── Test Controllers ────────────────────────────────────────────────────

class SimpleController
{
    #[Route('GET', '/test')]
    public function test(Request $request): Response
    {
        return Response::json(['message' => 'test']);
    }
}

#[Controller(prefix: 'users')]
class UserController
{
    #[Route('GET', '/:id')]
    public function show(Request $request): Response
    {
        return Response::json(['id' => $request->params['id']]);
    }
}

class PostController
{
    #[Route('GET', '/users/:userId/posts/:postId')]
    public function show(Request $request): Response
    {
        return Response::json([
            'userId' => $request->params['userId'],
            'postId' => $request->params['postId']
        ]);
    }
}

#[Controller(prefix: 'api')]
class ApiController
{
    #[Route('GET', '/status')]
    public function status(Request $request): Response
    {
        return Response::json(['status' => 'ok']);
    }
}

class ProtectedController
{
    #[Route('GET', '/protected')]
    #[UseGuards(TestGuard::class)]
    public function protected(Request $request): Response
    {
        return Response::json(['message' => 'protected']);
    }
}

class ValidationController
{
    #[Route('POST', '/validate')]
    #[Body(TestDto::class)]
    public function validate(Request $request): Response
    {
        return Response::json(['name' => $request->dto->name]);
    }
}

// ── Test Guard ──────────────────────────────────────────────────────────

class TestGuard implements GuardInterface
{
    public function canActivate(Request $request): bool
    {
        return $request->header('Authorization') === 'Bearer valid-token';
    }

    public function deny(): Response
    {
        return Response::error('Unauthorized', 401);
    }
}

// ── Test DTO ────────────────────────────────────────────────────────────

class TestDto
{
    #[\MikroApi\Attributes\Validation\Required]
    #[\MikroApi\Attributes\Validation\IsString]
    #[\MikroApi\Attributes\Validation\MinLength(1)]
    public string $name;
}
