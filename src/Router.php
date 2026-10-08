<?php

namespace MikroApi;

use MikroApi\Attributes\Body;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Route;
use MikroApi\Attributes\UseFilters;
use MikroApi\Attributes\UseGuards;
use MikroApi\Attributes\UseInterceptors;
use MikroApi\Exception\ExceptionHandler;
use MikroApi\Exception\MethodNotAllowedException;
use MikroApi\Exception\NotFoundException;
use MikroApi\Exception\ValidationException;
use MikroApi\Interceptor\InterceptorInterface;

/**
 * Registra rutas a partir de los atributos de los controladores y despacha
 * cada petición por el pipeline:
 *
 *   guards (globales → clase → método)
 *     → interceptors (globales → clase → método)
 *       → validación/inyección de argumentos (#[Body], #[Param], #[Query]...)
 *         → handler
 *
 * Cualquier excepción del pipeline pasa por los exception filters
 * (método → clase → globales) y, si ninguno la atiende, por el manejo por
 * defecto de ExceptionHandler. dispatch() siempre retorna una Response.
 */
class Router
{
    /**
     * @var array<int, array{method:string, pattern:string, regex:string, paramNames:string[], controller:string,
     *     action:string, guards:string[], dto:string|null, interceptors?:string[], filters?:string[], args?:array|null}>
     */
    private array $routes = [];

    private ?Container $container = null;

    /** @var array<string, Container> controlador → container que lo resuelve (módulos) */
    private array $controllerContainers = [];

    private bool $loadedFromCache = false;

    /** @var string[] */
    private array $globalGuards = [];

    /** @var string[] */
    private array $globalInterceptors = [];

    /** @var string[] */
    private array $globalFilters = [];

    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    /** @param string[] $guards */
    public function setGlobalGuards(array $guards): void
    {
        $this->globalGuards = $guards;
    }

    /** @param string[] $interceptors */
    public function setGlobalInterceptors(array $interceptors): void
    {
        $this->globalInterceptors = $interceptors;
    }

    /** @param string[] $filters */
    public function setGlobalFilters(array $filters): void
    {
        $this->globalFilters = $filters;
    }

    /* ------------------------------------------------------------------ */
    /*  Registro                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param Container|null $container Container con el que se resuelven el
     *        controlador y sus guards/interceptors/filtros (lo usa el sistema
     *        de módulos). null → el container global del router.
     */
    public function registerController(string $controllerClass, ?Container $container = null): void
    {
        if ($container !== null) {
            $this->controllerContainers[$controllerClass] = $container;
        }

        if ($this->loadedFromCache) {
            return; // las rutas ya se cargaron desde caché, evitar reflexión redundante
        }

        $refClass = new \ReflectionClass($controllerClass);
        $prefix   = '';

        $ctrlAttrs = $refClass->getAttributes(Controller::class);
        if (!empty($ctrlAttrs)) {
            /** @var Controller $ctrlAttr */
            $ctrlAttr = $ctrlAttrs[0]->newInstance();
            $prefix   = '/' . trim($ctrlAttr->prefix, '/');
            if ($prefix === '//') $prefix = '/';
        }

        $classGuards       = $this->collectClasses($refClass, UseGuards::class, 'guards');
        $classInterceptors = $this->collectClasses($refClass, UseInterceptors::class, 'interceptors');
        $classFilters      = $this->collectClasses($refClass, UseFilters::class, 'filters');

        // Iterar métodos públicos
        foreach ($refClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $routeAttrs = $method->getAttributes(Route::class);
            if (empty($routeAttrs)) continue;

            // DTO de validación del body a nivel de método (forma clásica)
            $dtoClass  = null;
            $bodyAttrs = $method->getAttributes(Body::class);
            if (!empty($bodyAttrs)) {
                /** @var Body $bodyAttr */
                $bodyAttr = $bodyAttrs[0]->newInstance();
                $dtoClass = $bodyAttr->dtoClass;
            }

            $guards       = array_merge($classGuards, $this->collectClasses($method, UseGuards::class, 'guards'));
            $interceptors = array_merge($classInterceptors, $this->collectClasses($method, UseInterceptors::class, 'interceptors'));
            // Filtros: del más específico al más general
            $filters      = array_merge($this->collectClasses($method, UseFilters::class, 'filters'), $classFilters);
            $args         = ArgumentResolver::describe($method);

            // Register a route entry for each #[Route] attribute
            foreach ($routeAttrs as $rAttr) {
                /** @var Route $routeAttr */
                $routeAttr = $rAttr->newInstance();

                $fullPath = rtrim($prefix, '/') . '/' . ltrim($routeAttr->path, '/');
                $fullPath = rtrim($fullPath, '/') ?: '/';

                [$regex, $paramNames] = $this->buildRegex($fullPath);

                $this->routes[] = [
                    'method'       => strtoupper($routeAttr->method),
                    'pattern'      => $fullPath,
                    'regex'        => $regex,
                    'paramNames'   => $paramNames,
                    'controller'   => $controllerClass,
                    'action'       => $method->getName(),
                    'guards'       => $guards,
                    'dto'          => $dtoClass,
                    'interceptors' => $interceptors,
                    'filters'      => $filters,
                    'args'         => $args,
                ];
            }
        }
    }

    /**
     * Rutas registradas (método, patrón, controlador, acción, guards...).
     * Lo usa `mikro route:list`.
     *
     * @return array<int, array>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /** @return string[] */
    private function collectClasses(\ReflectionClass|\ReflectionMethod $ref, string $attrClass, string $prop): array
    {
        $classes = [];
        foreach ($ref->getAttributes($attrClass) as $attr) {
            $classes = array_merge($classes, $attr->newInstance()->$prop);
        }
        return $classes;
    }

    /* ------------------------------------------------------------------ */
    /*  Caché de rutas                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Intenta cargar las rutas desde un archivo de caché generado
     * previamente con cacheTo(). Si el archivo no existe o no contiene
     * un array válido, retorna false y no modifica el estado del router.
     */
    public function loadFromCache(string $path): bool
    {
        if (!\is_file($path)) {
            return false;
        }

        try {
            $cached = require $path;
        } catch (\Throwable $e) {
            return false; // archivo de caché corrupto: fallback silencioso a reflexión
        }

        if (!\is_array($cached)) {
            return false;
        }

        foreach ($cached as $route) {
            if (!\is_array($route) || !isset(
                $route['method'], $route['regex'], $route['paramNames'],
                $route['controller'], $route['action'], $route['guards']
            )) {
                return false; // estructura inesperada: no confiar en este caché
            }
        }

        $this->routes          = $cached;
        $this->loadedFromCache = true;
        return true;
    }

    /**
     * Serializa las rutas actualmente registradas a un archivo PHP que
     * puede cargarse luego con loadFromCache(), evitando la reflexión
     * de todos los controladores en requests subsecuentes (AUD-006).
     */
    public function cacheTo(string $path): void
    {
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new \RuntimeException("No se pudo crear el directorio de caché: {$dir}");
        }

        $export   = \var_export($this->routes, true);
        $contents = "<?php\n\n// Generado automáticamente por App::cacheRoutes(). No editar a mano.\nreturn {$export};\n";

        // Escritura atómica: se escribe a un archivo temporal en el mismo
        // directorio y se usa rename() (atómico en POSIX) para que ningún
        // lector vea nunca un archivo parcialmente escrito bajo múltiples
        // workers concurrentes (Ronda 2, hallazgo crítico).
        $tmp = $path . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
        \file_put_contents($tmp, $contents);
        \rename($tmp, $path);
    }

    /* ------------------------------------------------------------------ */
    /*  Dispatch                                                            */
    /* ------------------------------------------------------------------ */

    public function dispatch(Request $request): Response
    {
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) continue;

            if ($route['method'] !== $request->method) {
                $allowedMethods[] = $route['method'];
                continue;
            }

            // Extraer parámetros de ruta
            foreach ($route['paramNames'] as $name) {
                $request->params[$name] = $matches[$name] ?? null;
            }

            $container = $this->containerFor($route['controller']);
            $context   = new ExecutionContext($request, $route['controller'], $route['action']);
            $request->context = $context;

            try {
                return $this->runRoute($route, $request, $context, $container);
            } catch (\Throwable $e) {
                return ExceptionHandler::handle(
                    $e,
                    $request,
                    $context,
                    array_merge($route['filters'] ?? [], $this->globalFilters),
                    $container,
                );
            }
        }

        $exception = empty($allowedMethods)
            ? new NotFoundException()
            : new MethodNotAllowedException(array_values(array_unique($allowedMethods)));

        return ExceptionHandler::handle($exception, $request, null, $this->globalFilters, $this->container);
    }

    private function runRoute(array $route, Request $request, ExecutionContext $context, ?Container $container): Response
    {
        // Guards: globales → clase → método
        foreach (array_merge($this->globalGuards, $route['guards']) as $guardClass) {
            /** @var GuardInterface $guard */
            $guard = $this->resolve($guardClass, $container);
            if (!$guard->canActivate($request)) {
                return $guard->deny();
            }
        }

        $controller = $this->resolve($route['controller'], $container);
        $action     = $route['action'];

        $handler = function () use ($route, $request, $context, $controller, $action): mixed {
            // Validar body con DTO declarado a nivel de método (forma clásica)
            if ($route['dto'] !== null) {
                $validator = new Validator();
                $dto       = $validator->validate($route['dto'], $request->body);

                if ($validator->hasErrors()) {
                    throw new ValidationException($validator->getErrors());
                }

                $request->dto = $dto;
            }

            // Cachés generados antes de la inyección de argumentos no traen
            // 'args': se conserva la firma clásica handler(Request $req).
            $args = isset($route['args'])
                ? ArgumentResolver::resolve($route['args'], $request, $context)
                : [$request];

            return $controller->$action(...$args);
        };

        // Interceptors: el primero de la lista es el más externo
        $interceptors = array_merge($this->globalInterceptors, $route['interceptors'] ?? []);
        foreach (array_reverse($interceptors) as $interceptorClass) {
            /** @var InterceptorInterface $interceptor */
            $interceptor = $this->resolve($interceptorClass, $container);
            $next        = $handler;
            $handler     = fn(): mixed => $interceptor->intercept($context, $next);
        }

        $result = $handler();

        return $result instanceof Response ? $result : Response::json($result);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function containerFor(string $controllerClass): ?Container
    {
        return $this->controllerContainers[$controllerClass] ?? $this->container;
    }

    private function resolve(string $class, ?Container $container = null): object
    {
        $container ??= $this->container;
        if ($container !== null) {
            return $container->get($class);
        }
        return new $class();
    }

    /**
     * Convierte /users/:id/posts/:postId
     * en regex: /users/(?P<id>[^/]+)/posts/(?P<postId>[^/]+)
     *
     * @return array{0: string, 1: string[]}
     */
    private function buildRegex(string $pattern): array
    {
        $paramNames = [];

        $regex = preg_replace_callback('/:([a-zA-Z_][a-zA-Z0-9_]*)/', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $pattern);

        $regex = '#^' . $regex . '$#';

        return [$regex, $paramNames];
    }
}
