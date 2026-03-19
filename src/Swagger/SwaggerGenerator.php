<?php

namespace MikroApi\Swagger;

use MikroApi\Attributes\ApiDoc;
use MikroApi\Attributes\ApiTag;
use MikroApi\Attributes\Body;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\QueryParam;
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

            $dtoClass = null;
            $bodyAttrs = $method->getAttributes(Body::class);
            if (!empty($bodyAttrs)) {
                $dtoClass = $bodyAttrs[0]->newInstance()->dtoClass;
            }

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
        $pathParams = $this->extractPathParams($fullPath);
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
        $operation['responses'] = $this->buildResponses($apiDoc, $dtoClass, $guards);

        return $operation;
    }

    /* ------------------------------------------------------------------ */
    /*  Responses                                                           */
    /* ------------------------------------------------------------------ */

    private function buildResponses(?ApiDoc $apiDoc, ?string $dtoClass, array $guards): array
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

    private function extractPathParams(string $path): array
    {
        \preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $path, $matches);
        return \array_map(fn($name) => [
            'name'     => $name,
            'in'       => 'path',
            'required' => true,
            'schema'   => ['type' => 'string'],
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
        return $params;
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