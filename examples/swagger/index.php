<?php
/**
 * MikroAPI - Swagger Documentation Example
 * 
 * This example demonstrates how to enable and customize Swagger documentation
 * for your API endpoints.
 * 
 * To run:
 * php -S localhost:8000 examples/swagger/index.php
 * 
 * Then visit:
 * - Swagger UI: http://localhost:8000/docs
 * - OpenAPI JSON: http://localhost:8000/docs/json
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MikroApi\App;
use MikroApi\Database\Database;
use MikroApi\Request;
use MikroApi\Response;
use MikroApi\RequestDto;
use MikroApi\GuardInterface;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Route;
use MikroApi\Attributes\Body;
use MikroApi\Attributes\UseGuards;
use MikroApi\Attributes\ApiTag;
use MikroApi\Attributes\ApiDoc;
use MikroApi\Attributes\Validation\Required;
use MikroApi\Attributes\Validation\Optional;
use MikroApi\Attributes\Validation\IsEmail;
use MikroApi\Attributes\Validation\MinLength;
use MikroApi\Attributes\Validation\MaxLength;
use MikroApi\Attributes\Validation\Min;
use MikroApi\Attributes\Validation\IsIn;
use MikroApi\Attributes\Validation\IsArray;
use MikroApi\Attributes\Validation\ArrayOf;

// ────────────────────────────────────────────────────────────────────────────
// DTOs
// ────────────────────────────────────────────────────────────────────────────

class CreateProductDto extends RequestDto
{
    #[Required]
    #[MinLength(3)]
    #[MaxLength(100)]
    public string $name;

    #[Required]
    #[Min(0)]
    public float $price;

    #[Optional]
    #[MaxLength(500)]
    public ?string $description;

    #[Required]
    #[IsIn(['active', 'inactive', 'draft'])]
    public string $status;

    #[Optional]
    #[IsArray]
    #[ArrayOf('string')]
    public array $tags;
}

class UpdateProductDto extends RequestDto
{
    #[Optional]
    #[MinLength(3)]
    #[MaxLength(100)]
    public ?string $name;

    #[Optional]
    #[Min(0)]
    public ?float $price;

    #[Optional]
    #[MaxLength(500)]
    public ?string $description;

    #[Optional]
    #[IsIn(['active', 'inactive', 'draft'])]
    public ?string $status;
}

class CreateUserDto extends RequestDto
{
    #[Required]
    #[MinLength(3)]
    #[MaxLength(50)]
    public string $name;

    #[Required]
    #[IsEmail]
    public string $email;

    #[Required]
    #[MinLength(8)]
    public string $password;

    #[Optional]
    #[IsIn(['admin', 'user', 'guest'])]
    public string $role;
}

class LoginDto extends RequestDto
{
    #[Required]
    #[IsEmail]
    public string $email;

    #[Required]
    public string $password;
}

// ────────────────────────────────────────────────────────────────────────────
// Guards
// ────────────────────────────────────────────────────────────────────────────

class JwtGuard implements GuardInterface
{
    public function canActivate(Request $request): bool
    {
        $auth = $request->header('Authorization');
        
        if (!$auth || !str_starts_with($auth, 'Bearer ')) {
            return false;
        }

        $token = substr($auth, 7);
        
        // Simple validation for demo (in production, validate JWT properly)
        if ($token === 'demo-token-12345') {
            $request->params['_auth'] = [
                'id' => 1,
                'email' => 'demo@example.com',
                'role' => 'admin'
            ];
            return true;
        }

        return false;
    }

    public function deny(): Response
    {
        return Response::json([
            'error' => 'Unauthorized',
            'message' => 'Valid Bearer token required'
        ], 401);
    }
}

// ────────────────────────────────────────────────────────────────────────────
// Controllers
// ────────────────────────────────────────────────────────────────────────────

#[Controller('/api/products')]
#[ApiTag(
    name: 'Products',
    description: 'Product catalog management'
)]
class ProductController
{
    #[Route('GET', '/')]
    #[ApiDoc(
        summary: 'List all products',
        description: 'Returns a list of all products in the catalog. Supports filtering by status.',
        responses: [
            200 => 'List of products returned successfully',
        ]
    )]
    public function index(Request $req): Response
    {
        $products = [
            [
                'id' => 1,
                'name' => 'Laptop Pro',
                'price' => 1299.99,
                'description' => 'High-performance laptop',
                'status' => 'active',
                'tags' => ['electronics', 'computers']
            ],
            [
                'id' => 2,
                'name' => 'Wireless Mouse',
                'price' => 29.99,
                'description' => 'Ergonomic wireless mouse',
                'status' => 'active',
                'tags' => ['electronics', 'accessories']
            ],
        ];

        return Response::json([
            'products' => $products,
            'total' => count($products)
        ]);
    }

    #[Route('GET', '/:id')]
    #[ApiDoc(
        summary: 'Get product by ID',
        description: 'Returns detailed information about a specific product',
        responses: [
            200 => 'Product found',
            404 => 'Product not found',
        ]
    )]
    public function show(Request $req): Response
    {
        $id = $req->params['id'];

        return Response::json([
            'id' => (int) $id,
            'name' => 'Sample Product',
            'price' => 99.99,
            'description' => 'Product description',
            'status' => 'active',
            'tags' => ['sample']
        ]);
    }

    #[Route('POST', '/')]
    #[Body(CreateProductDto::class)]
    #[UseGuards(JwtGuard::class)]
    #[ApiDoc(
        summary: 'Create a new product',
        description: 'Creates a new product in the catalog. Requires authentication.',
        responses: [
            201 => 'Product created successfully',
            401 => 'Unauthorized - Bearer token required',
            422 => 'Validation error',
        ]
    )]
    public function create(Request $req): Response
    {
        $dto = $req->dto;

        return Response::json([
            'id' => rand(100, 999),
            'name' => $dto->name,
            'price' => $dto->price,
            'description' => $dto->description ?? null,
            'status' => $dto->status,
            'tags' => $dto->tags ?? [],
            'created_at' => date('Y-m-d H:i:s')
        ], 201);
    }

    #[Route('PUT', '/:id')]
    #[Body(UpdateProductDto::class)]
    #[UseGuards(JwtGuard::class)]
    #[ApiDoc(
        summary: 'Update product',
        description: 'Updates an existing product. Only provided fields will be updated.',
        responses: [
            200 => 'Product updated successfully',
            401 => 'Unauthorized',
            404 => 'Product not found',
            422 => 'Validation error',
        ]
    )]
    public function update(Request $req): Response
    {
        $id = $req->params['id'];
        $dto = $req->dto;

        return Response::json([
            'id' => (int) $id,
            'name' => $dto->name ?? 'Sample Product',
            'price' => $dto->price ?? 99.99,
            'description' => $dto->description ?? 'Updated description',
            'status' => $dto->status ?? 'active',
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    #[Route('DELETE', '/:id')]
    #[UseGuards(JwtGuard::class)]
    #[ApiDoc(
        summary: 'Delete product',
        description: 'Soft deletes a product from the catalog',
        responses: [
            200 => 'Product deleted successfully',
            401 => 'Unauthorized',
            404 => 'Product not found',
        ]
    )]
    public function delete(Request $req): Response
    {
        $id = $req->params['id'];

        return Response::json([
            'message' => 'Product deleted successfully',
            'id' => (int) $id
        ]);
    }

    #[Route('GET', '/search')]
    #[ApiDoc(
        summary: 'Search products',
        description: 'Search products by name, description, or tags'
    )]
    public function search(Request $req): Response
    {
        $query = $req->query['q'] ?? '';

        return Response::json([
            'query' => $query,
            'results' => [],
            'total' => 0
        ]);
    }
}

#[Controller('/api/users')]
#[ApiTag(
    name: 'Users',
    description: 'User management and authentication'
)]
class UserController
{
    #[Route('POST', '/register')]
    #[Body(CreateUserDto::class)]
    #[ApiDoc(
        summary: 'Register new user',
        description: 'Creates a new user account',
        responses: [
            201 => 'User registered successfully',
            422 => 'Validation error',
        ]
    )]
    public function register(Request $req): Response
    {
        $dto = $req->dto;

        return Response::json([
            'id' => rand(1, 999),
            'name' => $dto->name,
            'email' => $dto->email,
            'role' => $dto->role ?? 'user',
            'created_at' => date('Y-m-d H:i:s')
        ], 201);
    }

    #[Route('POST', '/login')]
    #[Body(LoginDto::class)]
    #[ApiDoc(
        summary: 'User login',
        description: 'Authenticates a user and returns a JWT token',
        responses: [
            200 => 'Login successful',
            401 => 'Invalid credentials',
            422 => 'Validation error',
        ]
    )]
    public function login(Request $req): Response
    {
        $dto = $req->dto;

        // Demo: always return success with demo token
        return Response::json([
            'token' => 'demo-token-12345',
            'type' => 'Bearer',
            'expires_in' => 3600,
            'user' => [
                'id' => 1,
                'email' => $dto->email,
                'role' => 'admin'
            ]
        ]);
    }

    #[Route('GET', '/me')]
    #[UseGuards(JwtGuard::class)]
    #[ApiDoc(
        summary: 'Get current user',
        description: 'Returns the authenticated user profile',
        responses: [
            200 => 'User profile',
            401 => 'Unauthorized',
        ]
    )]
    public function me(Request $req): Response
    {
        $auth = $req->params['_auth'];

        return Response::json([
            'user' => $auth
        ]);
    }

    #[Route('GET', '/')]
    #[UseGuards(JwtGuard::class)]
    #[ApiDoc(
        summary: 'List all users',
        description: 'Returns a list of all users (admin only)',
        responses: [
            200 => 'List of users',
            401 => 'Unauthorized',
        ]
    )]
    public function index(Request $req): Response
    {
        return Response::json([
            'users' => [
                ['id' => 1, 'name' => 'Admin User', 'email' => 'admin@example.com', 'role' => 'admin'],
                ['id' => 2, 'name' => 'John Doe', 'email' => 'john@example.com', 'role' => 'user'],
            ],
            'total' => 2
        ]);
    }
}

#[Controller('/api/health')]
#[ApiTag(
    name: 'Health',
    description: 'API health check endpoints'
)]
class HealthController
{
    #[Route('GET', '/')]
    #[ApiDoc(
        summary: 'Health check',
        description: 'Returns API health status',
        responses: [
            200 => 'API is healthy',
        ]
    )]
    public function check(Request $req): Response
    {
        return Response::json([
            'status' => 'ok',
            'timestamp' => date('c'),
            'version' => '1.0.0'
        ]);
    }

    #[Route('GET', '/internal/debug')]
    #[ApiDoc(exclude: true)] // This endpoint won't appear in Swagger
    public function debug(Request $req): Response
    {
        return Response::json([
            'debug' => 'Internal debug info',
            'memory' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ]);
    }
}

// ────────────────────────────────────────────────────────────────────────────
// Application Setup
// ────────────────────────────────────────────────────────────────────────────

$app = new App();

// Register controllers
$app->useController(
    ProductController::class,
    UserController::class,
    HealthController::class
);

// Enable Swagger Documentation
$app->enableSwagger(
    config: [
        'title'       => 'MikroAPI Demo',
        'version'     => '1.0.0',
        'description' => 'Complete API documentation example with authentication, validation, and CRUD operations.',
        'servers'     => [
            [
                'url' => 'http://localhost:8000',
                'description' => 'Local development server'
            ],
            [
                'url' => 'https://api.example.com',
                'description' => 'Production server'
            ],
        ],
    ],
    path: '/docs',
    jsonPath: '/docs/json',
    authGuards: [JwtGuard::class] // Guards that require Bearer authentication
);

// Run application
$app->run();
