# Swagger Documentation Example

This example demonstrates how to enable and customize Swagger/OpenAPI documentation in MikroAPI.

## Features Demonstrated

- ✅ Automatic OpenAPI 3.0 spec generation
- ✅ Swagger UI integration
- ✅ Custom API documentation with `#[ApiDoc]`
- ✅ API tags and grouping with `#[ApiTag]`
- ✅ DTO schema generation
- ✅ Authentication documentation (Bearer JWT)
- ✅ Request/response examples
- ✅ Excluding endpoints from documentation

## Running the Example

```bash
# From the project root
php -S localhost:8000 examples/swagger/index.php
```

## Access Documentation

Once running, visit:

- **Swagger UI**: http://localhost:8000/docs
- **OpenAPI JSON**: http://localhost:8000/docs/json

## API Endpoints

### Products (Public & Protected)

- `GET /api/products` - List all products (public)
- `GET /api/products/:id` - Get product by ID (public)
- `GET /api/products/search?q=query` - Search products (public)
- `POST /api/products` - Create product (🔒 requires auth)
- `PUT /api/products/:id` - Update product (🔒 requires auth)
- `DELETE /api/products/:id` - Delete product (🔒 requires auth)

### Users & Authentication

- `POST /api/users/register` - Register new user
- `POST /api/users/login` - Login and get JWT token
- `GET /api/users/me` - Get current user (🔒 requires auth)
- `GET /api/users` - List all users (🔒 requires auth)

### Health Check

- `GET /api/health` - API health status
- `GET /api/health/internal/debug` - Debug info (excluded from docs)

## Testing Authentication

1. **Login to get a token:**

```bash
curl -X POST http://localhost:8000/api/users/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "demo@example.com",
    "password": "password123"
  }'
```

Response:
```json
{
  "token": "demo-token-12345",
  "type": "Bearer",
  "expires_in": 3600,
  "user": {
    "id": 1,
    "email": "demo@example.com",
    "role": "admin"
  }
}
```

2. **Use the token for protected endpoints:**

```bash
curl -X POST http://localhost:8000/api/products \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer demo-token-12345" \
  -d '{
    "name": "New Product",
    "price": 49.99,
    "description": "A great product",
    "status": "active",
    "tags": ["electronics", "new"]
  }'
```

## Using Swagger UI

1. Open http://localhost:8000/docs in your browser
2. Click on "Authorize" button (top right)
3. Enter: `demo-token-12345`
4. Click "Authorize"
5. Now you can test protected endpoints directly from Swagger UI

## Key Features

### Automatic Schema Generation

DTOs are automatically converted to OpenAPI schemas with validation constraints:

```php
class CreateProductDto extends RequestDto
{
    #[Required]
    #[MinLength(3)]
    #[MaxLength(100)]
    public string $name;

    #[Required]
    #[Min(0)]
    public float $price;
}
```

Generates:
```json
{
  "type": "object",
  "required": ["name", "price"],
  "properties": {
    "name": {
      "type": "string",
      "minLength": 3,
      "maxLength": 100
    },
    "price": {
      "type": "number",
      "minimum": 0
    }
  }
}
```

### Custom Documentation

Use `#[ApiDoc]` to enrich your endpoints:

```php
#[Route('POST', '/')]
#[ApiDoc(
    summary: 'Create a new product',
    description: 'Creates a new product in the catalog',
    responses: [
        201 => 'Product created successfully',
        401 => 'Unauthorized',
        422 => 'Validation error',
    ]
)]
public function create(Request $req): Response
{
    // ...
}
```

### Grouping with Tags

Use `#[ApiTag]` to organize endpoints:

```php
#[Controller('/api/products')]
#[ApiTag(
    name: 'Products',
    description: 'Product catalog management'
)]
class ProductController
{
    // All endpoints will be grouped under "Products"
}
```

### Excluding Endpoints

Hide internal endpoints from documentation:

```php
#[Route('GET', '/internal/debug')]
#[ApiDoc(exclude: true)]
public function debug(Request $req): Response
{
    // This won't appear in Swagger
}
```

## Customization Options

### Configure Swagger

```php
$app->enableSwagger(
    config: [
        'title'       => 'My API',
        'version'     => '1.0.0',
        'description' => 'API description',
        'servers'     => [
            ['url' => 'http://localhost:8000', 'description' => 'Local'],
            ['url' => 'https://api.example.com', 'description' => 'Production'],
        ],
    ],
    excludeControllers: [InternalController::class], // Exclude entire controllers
    path: '/docs',                                    // Swagger UI path
    jsonPath: '/docs/json',                          // OpenAPI JSON path
    authGuards: [JwtGuard::class]                    // Guards requiring Bearer auth
);
```

## What Gets Documented Automatically

MikroAPI automatically detects and documents:

- ✅ HTTP methods (GET, POST, PUT, DELETE, etc.)
- ✅ Route paths with parameters (`:id` → `{id}`)
- ✅ Request body schemas from DTOs
- ✅ Validation rules as schema constraints
- ✅ Required vs optional fields
- ✅ Authentication requirements from guards
- ✅ Response codes (200, 201, 401, 422, etc.)
- ✅ Controller prefixes
- ✅ Enum values from `IsIn` validation

## Tips

1. **Use descriptive DTO names**: They appear as schema names in Swagger
2. **Add ApiDoc attributes**: Provide better context for API consumers
3. **Group related endpoints**: Use ApiTag for better organization
4. **Document responses**: Specify all possible response codes
5. **Test in Swagger UI**: Use the "Try it out" feature to test endpoints
6. **Export OpenAPI spec**: Download JSON for use with other tools

## Next Steps

- Explore the generated OpenAPI JSON at `/docs/json`
- Import the spec into Postman or Insomnia
- Use the spec for client code generation
- Add more detailed descriptions and examples
- Configure CORS for production use
