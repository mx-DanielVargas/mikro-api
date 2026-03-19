<?php

namespace MikroApi\Tests;

use MikroApi\Swagger\SwaggerGenerator;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Route;
use MikroApi\Attributes\Body;
use MikroApi\Attributes\ApiDoc;
use MikroApi\Attributes\ApiTag;
use MikroApi\Attributes\QueryParam;
use MikroApi\Attributes\UseGuards;
use MikroApi\Attributes\Validation\Required;
use MikroApi\Attributes\Validation\IsString;
use MikroApi\Request;
use MikroApi\Response;
use PHPUnit\Framework\TestCase;

class SwaggerGeneratorTest extends TestCase
{
    private SwaggerGenerator $gen;

    protected function setUp(): void
    {
        $this->gen = new SwaggerGenerator();
    }

    public function testGenerateBasicSpec(): void
    {
        $spec = $this->gen->generate(
            controllers: [SwaggerTestController::class],
            excludeControllers: [],
            config: ['title' => 'Test API', 'version' => '1.0.0'],
        );

        $this->assertEquals('3.0.3', $spec['openapi']);
        $this->assertEquals('Test API', $spec['info']['title']);
        $this->assertArrayHasKey('paths', $spec);
    }

    public function testRoutesAreDocumented(): void
    {
        $spec = $this->gen->generate(
            controllers: [SwaggerTestController::class],
            excludeControllers: [],
            config: ['title' => 'Test', 'version' => '1.0'],
        );

        $this->assertArrayHasKey('/api/items', $spec['paths']);
        $this->assertArrayHasKey('get', $spec['paths']['/api/items']);
    }

    public function testPathParameters(): void
    {
        $spec = $this->gen->generate(
            controllers: [SwaggerTestController::class],
            excludeControllers: [],
            config: ['title' => 'Test', 'version' => '1.0'],
        );

        $this->assertArrayHasKey('/api/items/{id}', $spec['paths']);
        $params = $spec['paths']['/api/items/{id}']['get']['parameters'] ?? [];
        $names = array_column($params, 'name');
        $this->assertContains('id', $names);
    }

    public function testQueryParameters(): void
    {
        $spec = $this->gen->generate(
            controllers: [SwaggerTestController::class],
            excludeControllers: [],
            config: ['title' => 'Test', 'version' => '1.0'],
        );

        $params = $spec['paths']['/api/items']['get']['parameters'] ?? [];
        $names = array_column($params, 'name');
        $this->assertContains('page', $names);
    }

    public function testExcludeController(): void
    {
        $spec = $this->gen->generate(
            controllers: [SwaggerTestController::class],
            excludeControllers: [SwaggerTestController::class],
            config: ['title' => 'Test', 'version' => '1.0'],
        );

        $this->assertEmpty($spec['paths']);
    }

    public function testExcludeEndpoint(): void
    {
        $spec = $this->gen->generate(
            controllers: [SwaggerTestController::class],
            excludeControllers: [],
            config: ['title' => 'Test', 'version' => '1.0'],
        );

        // /api/items/internal has ApiDoc(exclude: true)
        $this->assertArrayNotHasKey('/api/items/internal', $spec['paths']);
    }

    public function testRequestBodyFromDto(): void
    {
        $spec = $this->gen->generate(
            controllers: [SwaggerTestController::class],
            excludeControllers: [],
            config: ['title' => 'Test', 'version' => '1.0'],
        );

        $post = $spec['paths']['/api/items']['post'] ?? [];
        $this->assertArrayHasKey('requestBody', $post);
    }

    public function testApiTag(): void
    {
        $spec = $this->gen->generate(
            controllers: [SwaggerTestController::class],
            excludeControllers: [],
            config: ['title' => 'Test', 'version' => '1.0'],
        );

        $tagNames = array_column($spec['tags'] ?? [], 'name');
        $this->assertContains('Items', $tagNames);
    }

    public function testAuthGuards(): void
    {
        $this->gen->setAuthGuards([SwaggerTestGuard::class]);

        $spec = $this->gen->generate(
            controllers: [SwaggerTestController::class],
            excludeControllers: [],
            config: ['title' => 'Test', 'version' => '1.0'],
        );

        // The guarded endpoint should have security
        $show = $spec['paths']['/api/items/{id}']['get'] ?? [];
        $this->assertArrayHasKey('security', $show);
    }
}

// ── Test fixtures ───────────────────────────────────────────────────────

#[Controller('/api/items')]
#[ApiTag(name: 'Items', description: 'Item management')]
class SwaggerTestController
{
    #[Route('GET', '/')]
    #[QueryParam('page', type: 'integer', description: 'Page number')]
    #[ApiDoc(summary: 'List items')]
    public function index(Request $r): Response
    {
        return Response::json([]);
    }

    #[Route('GET', '/:id')]
    #[UseGuards(SwaggerTestGuard::class)]
    public function show(Request $r): Response
    {
        return Response::json([]);
    }

    #[Route('POST', '/')]
    #[Body(SwaggerTestCreateDto::class)]
    public function create(Request $r): Response
    {
        return Response::json([], 201);
    }

    #[Route('GET', '/internal')]
    #[ApiDoc(exclude: true)]
    public function internal(Request $r): Response
    {
        return Response::json([]);
    }
}

class SwaggerTestGuard implements \MikroApi\GuardInterface
{
    public function canActivate(Request $r): bool { return true; }
    public function deny(): Response { return Response::error('Unauthorized', 401); }
}

class SwaggerTestCreateDto
{
    #[Required]
    #[IsString]
    public string $name;
}
