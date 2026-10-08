<?php

namespace MikroApi\Swagger;

use MikroApi\Attributes\ApiDoc;
use MikroApi\Attributes\ApiTag;
use MikroApi\Attributes\Body;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Param;
use MikroApi\Attributes\PublicRoute;
use MikroApi\Attributes\Query;
use MikroApi\Attributes\QueryParam;
use MikroApi\Attributes\Roles;
use MikroApi\Attributes\Route;
use MikroApi\Attributes\UseGuards;

/**
 * Genera una especificación OpenAPI 3.0 leyendo los atributos
 * de los controladores registrados en la app.
 *
 * No requiere ninguna anotación adicional — funciona con los
 * atributos que ya existen (#[Route], #[Body], #[UseGuards], DTOs).
 * Los atributos #[ApiTag] y #[ApiDoc] son opcionales para enriquecer.
 */
class SwaggerGenerator
{
    private DtoSchemaBuilder $schemaBuilder;

    /** Clases de guards que implican autenticación Bearer */
    private array $authGuards = [];

    public function __construct()
    {
        $this->schemaBuilder = new DtoSchemaBuilder();
    }

    /**
     * Registra qué clases de guard implican autenticación.
     * Por defecto detecta cualquier guard cuyo nombre contenga 'Jwt' o 'Auth'.
     */
    public function setAuthGuards(array $guardClasses): self
    {
        $this->authGuards = $guardClasses;
        return $this;
    }

    /**
     * Genera el spec OpenAPI 3.0 completo como array.
     *
     * @param string[] $controllers      Clases de controladores a documentar
     * @param string[] $excludeControllers Clases a ignorar
     * @param array    $config           Metadatos: title, version, description, servers
     */
    public function generate(array $controllers, array $excludeControllers, array $config): array
    {
        $filtered = \array_values(\array_filter(
            $controllers,
            fn($c) => !\in_array($c, $excludeControllers)
        ));

        $paths      = [];
        $tags       = [];
        $schemas    = [];
        $tagNames   = [];

        foreach ($filtered as $controllerClass) {
            [$controllerPaths, $controllerTags, $controllerSchemas] =
                $this->processController($controllerClass);

            foreach ($controllerPaths as $path => $methods) {
                $paths[$path] = \array_merge($paths[$path] ?? [], $methods);
            }

            foreach ($controllerTags as $tag) {
                if (!\in_array($tag['name'], $tagNames)) {
                    $tags[]     = $tag;
                    $tagNames[] = $tag['name'];
                }
            }

            $schemas = \array_merge($schemas, $controllerSchemas);
        }

        // Ordenar paths alfabéticamente
        \ksort($paths);

        return $this->buildSpec($paths, $tags, $schemas, $config);
    }

    /* ------------------------------------------------------------------ */
    /*  Procesamiento por controlador                                       */
    /* ------------------------------------------------------------------ */

    private function processController(string $controllerClass): array
    {
        $ref    = new \ReflectionClass($controllerClass);
        $paths  = [];
        $tags   = [];
        $schemas = [];

        // ── Prefix y tag ──────────────────────────────────────────────
        $prefix  = '';
        $ctrlAttrs = $ref->getAttributes(Controller::class);
        if (!empty($ctrlAttrs)) {
            $prefix = '/' . \trim($ctrlAttrs[0]->newInstance()->prefix, '/');
            if ($prefix === '//') $prefix = '/';
        }

        $tagName = $this->resolveTagName($ref);
        $tags[]  = $this->resolveTagMeta($ref, $tagName);

        // ── Guards a nivel de clase ───────────────────────────────────
        $classGuards = $this->extractGuards($ref->getAttributes(UseGuards::class));

        // ── Iterar métodos ────────────────────────────────────────────
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $routeAttrs = $method->getAttributes(Route::class);
            if (empty($routeAttrs)) continue;

            // Verificar si este endpoint está excluido
            $apiDocAttrs = $method->getAttributes(ApiDoc::class);
            if (!empty($apiDocAttrs) && $apiDocAttrs[0]->newInstance()->exclude) {
                continue;
            }

            /** @var Route $route */
            $route      = $routeAttrs[0]->newInstance();
            $httpMethod = \strtolower($route->method);
            $fullPath   = $this->normalizePath($prefix . '/' . \ltrim($route->path, '/'));
            $swaggerPath = $this->toSwaggerPath($fullPath);

            $methodGuards = $this->extractGuards($method->getAttributes(UseGuards::class));
            $allGuards    = \array_merge($classGuards, $methodGuards);

            // Rutas #[PublicRoute] no requieren autenticación aunque haya guards
            if ($this->isPublic($ref, $method)) {
                $allGuards = [];
            }

            $dtoClass = $this->extractBodyDto($method);

            // Registrar schema del DTO si existe
            if ($dtoClass !== null) {
                $shortName         = $this->dtoShortName($dtoClass);
                $schemas[$shortName] = $this->schemaBuilder->build($dtoClass);
            }

            $operation = $this->buildOperation(
                method:    $method,
                tagName:   $tagName,
                fullPath:  $fullPath,
                guards:    $allGuards,
                dtoClass:  $dtoClass,
                schemas:   $schemas,
            );

            $paths[$swaggerPath][$httpMethod] = $operation;
        }

        return [$paths, $tags, $schemas];
    }

    /* ------------------------------------------------------------------ */
    /*  Construcción de operación                                           */
    /* ------------------------------------------------------------------ */

    private function buildOperation(
        \ReflectionMethod $method,
        string $tagName,
        string $fullPath,
        array $guards,
        ?string $dtoClass,
        array &$schemas,
    ): array {
        $apiDoc  = $this->extractApiDoc($method);
        $docblock = $this->extractDocblock($method);

        $operation = [
            'tags'    => [$tagName],
            'summary' => $apiDoc?->summary ?? $docblock ?? $this->inferSummary($method->getName()),
        ];

        if ($apiDoc?->description) {
            $operation['description'] = $apiDoc->description;
        }

        if ($apiDoc?->deprecated) {
            $operation['deprecated'] = true;
        }

        // operationId único
        $operation['operationId'] = $this->buildOperationId($tagName, $method->getName());

        // Path parameters (:id → {id})
        $pathParams = $this->extractPathParams($fullPath, $method);
        $queryParams = $this->extractQueryParams($method);
        $allParams = array_merge($pathParams, $queryParams);
        if (!empty($allParams)) {
            $operation['parameters'] = $allParams;
        }

        // Security si hay guards de auth
        if ($this->hasAuthGuard($guards)) {
            $operation['security'] = [['bearerAuth' => []]];
        }

        // Request body
        if ($dtoClass !== null) {
            $shortName = $this->dtoShortName($dtoClass);
            $operation['requestBody'] = [
                'required' => true,
                'content'  => [
                    'application/json' => [
                        'schema' => ['$ref' => "#/components/schemas/{$shortName}"],
                    ],
                ],
            ];
        }

        // Responses
        $operation['responses'] = $this->buildResponses($apiDoc, $dtoClass, $guards, $this->hasTypedParams($method));
        if (!empty($guards) && $this->hasRoles($method)) {
            $operation['responses']['403'] ??= ['description' => 'Acceso denegado'];
        }

        return $operation;
    }

    /* ------------------------------------------------------------------ */
    /*  Responses                                                           */
    /* ------------------------------------------------------------------ */

    private function buildResponses(?ApiDoc $apiDoc, ?string $dtoClass, array $guards, bool $typedParams = false): array
    {
        // Si el usuario definió responses explícitas, usarlas
        if ($apiDoc !== null && !empty($apiDoc->responses)) {
            $responses = [];
            foreach ($apiDoc->responses as $code => $description) {
                $responses[(string) $code] = ['description' => $description];
            }
            return $responses;
        }

        // Inferir responses automáticamente
        $responses = [
            '200' => ['description' => 'OK'],
        ];

        if ($dtoClass !== null) {
            $responses['422'] = ['description' => 'Error de validación'];
            // 201 para POST con body
            $responses['201'] = $responses['200'];
            unset($responses['200']);
        }

        if ($typedParams) {
            $responses['400'] = ['description' => 'Parámetros inválidos'];
        }

        if ($this->hasAuthGuard($guards)) {
            $responses['401'] = ['description' => 'No autorizado'];
        }

        return $responses;
    }

    /* ------------------------------------------------------------------ */
    /*  Spec completo                                                       */
    /* ------------------------------------------------------------------ */

    private function buildSpec(array $paths, array $tags, array $schemas, array $config): array
    {
        $spec = [
            'openapi' => '3.0.3',
            'info'    => [
                'title'       => $config['title']       ?? 'API',
                'version'     => $config['version']     ?? '1.0.0',
                'description' => $config['description'] ?? '',
            ],
            'tags'  => $tags,
            'paths' => $paths,
        ];

        // Servers
        $spec['servers'] = !empty($config['servers'])
            ? $config['servers']
            : [['url' => '/', 'description' => 'Local']];

        // Components
        $components = [];

        if (!empty($schemas)) {
            $components['schemas'] = $schemas;
        }

        // Security scheme Bearer si hay algún endpoint autenticado
        if ($this->specHasAuth($paths)) {
            $components['securitySchemes'] = [
                'bearerAuth' => [
                    'type'   => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                ],
            ];
        }

        if (!empty($components)) {
            $spec['components'] = $components;
        }

        return $spec;
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function resolveTagName(\ReflectionClass $ref): string
    {
        $tagAttrs = $ref->getAttributes(ApiTag::class);
        if (!empty($tagAttrs)) {
            return $tagAttrs[0]->newInstance()->name;
        }
        // Inferir desde el nombre de la clase: UserController → User
        return \str_replace('Controller', '', $ref->getShortName());
    }

    private function resolveTagMeta(\ReflectionClass $ref, string $tagName): array
    {
        $tag     = ['name' => $tagName];
        $tagAttrs = $ref->getAttributes(ApiTag::class);
        if (!empty($tagAttrs)) {
            $apiTag = $tagAttrs[0]->newInstance();
            if ($apiTag->description) {
                $tag['description'] = $apiTag->description;
            }
        }
        return $tag;
    }

    private function extractGuards(array $guardAttrs): array
    {
        $guards = [];
        foreach ($guardAttrs as $attr) {
            $guards = \array_merge($guards, $attr->newInstance()->guards);
        }
        return $guards;
    }

    private function extractApiDoc(\ReflectionMethod $method): ?ApiDoc
    {
        $attrs = $method->getAttributes(ApiDoc::class);
        return !empty($attrs) ? $attrs[0]->newInstance() : null;
    }

    private function extractDocblock(\ReflectionMethod $method): ?string
    {
        $doc = $method->getDocComment();
        if (!$doc) return null;

        // Extraer primera línea significativa del docblock
        \preg_match('/\*\s+([^@\*][^\n]+)/', $doc, $matches);
        return isset($matches[1]) ? \trim($matches[1]) : null;
    }

    private function extractPathParams(string $path, ?\ReflectionMethod $method = null): array
    {
        \preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $path, $matches);

        // Tipos declarados con #[Param('x')] int $x
        $types = [];
        foreach ($method?->getParameters() ?? [] as $param) {
            $attrs = $param->getAttributes(Param::class);
            if (!empty($attrs) && ($name = $attrs[0]->newInstance()->name) !== null) {
                $types[$name] = $this->openApiType($param->getType());
            }
        }

        return \array_map(fn($name) => [
            'name'     => $name,
            'in'       => 'path',
            'required' => true,
            'schema'   => $types[$name] ?? ['type' => 'string'],
        ], $matches[1]);
    }

    private function extractQueryParams(\ReflectionMethod $method): array
    {
        $params = [];
        foreach ($method->getAttributes(QueryParam::class) as $attr) {
            /** @var QueryParam $qp */
            $qp = $attr->newInstance();
            $param = [
                'name'     => $qp->name,
                'in'       => 'query',
                'required' => $qp->required,
                'schema'   => ['type' => $qp->type],
            ];
            if ($qp->description) {
                $param['description'] = $qp->description;
            }
            if ($qp->example !== null) {
                $param['example'] = $qp->example;
            }
            $params[] = $param;
        }

        // #[Query('x')] tipado o #[Query] DtoClass en los parámetros del método
        $documented = \array_column($params, 'name');
        foreach ($method->getParameters() as $refParam) {
            $attrs = $refParam->getAttributes(Query::class);
            if (empty($attrs)) continue;

            $name = $attrs[0]->newInstance()->name;
            $type = $refParam->getType();

            if ($name === null) {
                $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
                if ($typeName === null || $type->isBuiltin() || !\class_exists($typeName)) continue;

                $schema = $this->schemaBuilder->build($typeName);
                foreach ($schema['properties'] ?? [] as $prop => $propSchema) {
                    if (\in_array($prop, $documented, true)) continue;
                    $params[] = [
                        'name'     => $prop,
                        'in'       => 'query',
                        'required' => \in_array($prop, $schema['required'] ?? [], true),
                        'schema'   => $propSchema,
                    ];
                }
                continue;
            }

            if (\in_array($name, $documented, true)) continue; // #[QueryParam] manda
            $params[] = [
                'name'     => $name,
                'in'       => 'query',
                'required' => !$refParam->isDefaultValueAvailable() && !($type?->allowsNull() ?? true),
                'schema'   => $this->openApiType($type),
            ];
        }

        return $params;
    }

    /** DTO del body: #[Body(Dto::class)] en el método o #[Body] Dto $dto en un parámetro. */
    private function extractBodyDto(\ReflectionMethod $method): ?string
    {
        $bodyAttrs = $method->getAttributes(Body::class);
        if (!empty($bodyAttrs)) {
            return $bodyAttrs[0]->newInstance()->dtoClass;
        }

        foreach ($method->getParameters() as $param) {
            $attrs = $param->getAttributes(Body::class);
            if (empty($attrs)) continue;

            $explicit = $attrs[0]->newInstance()->dtoClass;
            if ($explicit !== null && \class_exists($explicit)) {
                return $explicit;
            }

            $type = $param->getType();
            if ($explicit === null && $type instanceof \ReflectionNamedType && !$type->isBuiltin()
                && \class_exists($type->getName())) {
                return $type->getName();
            }
        }

        return null;
    }

    /** ¿Algún #[Param]/#[Query] tipado que pueda responder 400 por conversión? */
    private function hasTypedParams(\ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $param) {
            if (empty($param->getAttributes(Param::class)) && empty($param->getAttributes(Query::class))) continue;
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && \in_array($type->getName(), ['int', 'float', 'bool'], true)) {
                return true;
            }
            if ($type instanceof \ReflectionNamedType && \enum_exists($type->getName())) {
                return true;
            }
        }
        return false;
    }

    private function isPublic(\ReflectionClass $class, \ReflectionMethod $method): bool
    {
        return !empty($method->getAttributes(PublicRoute::class))
            || !empty($class->getAttributes(PublicRoute::class));
    }

    private function hasRoles(\ReflectionMethod $method): bool
    {
        return !empty($method->getAttributes(Roles::class))
            || !empty($method->getDeclaringClass()->getAttributes(Roles::class));
    }

    /** Tipo PHP → schema OpenAPI */
    private function openApiType(?\ReflectionType $type): array
    {
        if (!$type instanceof \ReflectionNamedType) {
            return ['type' => 'string'];
        }

        $name = $type->getName();
        if (\enum_exists($name) && \is_subclass_of($name, \BackedEnum::class)) {
            $values = \array_map(fn($c) => $c->value, $name::cases());
            return ['type' => \is_int($values[0] ?? null) ? 'integer' : 'string', 'enum' => $values];
        }

        return match ($name) {
            'int'   => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool'  => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => ['type' => 'string']],
            default => ['type' => 'string'],
        };
    }

    private function hasAuthGuard(array $guards): bool
    {
        foreach ($guards as $guard) {
            if (!empty($this->authGuards) && \in_array($guard, $this->authGuards)) {
                return true;
            }
            // Detección automática por nombre si no se configuró authGuards
            $shortName = \class_exists($guard)
                ? (new \ReflectionClass($guard))->getShortName()
                : $guard;
            if (\stripos($shortName, 'jwt') !== false || \stripos($shortName, 'auth') !== false) {
                return true;
            }
        }
        return false;
    }

    private function specHasAuth(array $paths): bool
    {
        foreach ($paths as $methods) {
            foreach ($methods as $operation) {
                if (!empty($operation['security'])) return true;
            }
        }
        return false;
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . \trim($path, '/');
        return $path === '/' ? '/' : \rtrim($path, '/');
    }

    private function toSwaggerPath(string $path): string
    {
        // /users/:id → /users/{id}
        return \preg_replace('/:([a-zA-Z_][a-zA-Z0-9_]*)/', '{$1}', $path);
    }

    private function dtoShortName(string $dtoClass): string
    {
        return (new \ReflectionClass($dtoClass))->getShortName();
    }

    private function buildOperationId(string $tag, string $method): string
    {
        return \lcfirst($tag) . \ucfirst($method);
    }

    private function inferSummary(string $methodName): string
    {
        // createUser → Create user
        $spaced = \preg_replace('/([A-Z])/', ' $1', $methodName);
        return \ucfirst(\strtolower(\trim($spaced)));
    }
}