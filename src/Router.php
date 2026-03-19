<?php
// core/Router.php
namespace MikroApi;

use MikroApi\Attributes\Body;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Route;
use MikroApi\Attributes\UseGuards;

class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, paramNames:string[], controller:string, action:string, guards:string[], dto:string|null}> */
    private array $routes = [];

    /* ------------------------------------------------------------------ */
    /*  Registro                                                            */
    /* ------------------------------------------------------------------ */

    public function registerController(string $controllerClass): void
    {
        $refClass    = new \ReflectionClass($controllerClass);
        $prefix      = '';
        $classGuards = [];

        $ctrlAttrs = $refClass->getAttributes(Controller::class);
        if (!empty($ctrlAttrs)) {
            /** @var Controller $ctrlAttr */
            $ctrlAttr = $ctrlAttrs[0]->newInstance();
            $prefix   = '/' . trim($ctrlAttr->prefix, '/');
            if ($prefix === '//') $prefix = '/';
        }

        // Guards a nivel de clase
        foreach ($refClass->getAttributes(UseGuards::class) as $guardAttr) {
            /** @var UseGuards $guardInst */
            $guardInst   = $guardAttr->newInstance();
            $classGuards = array_merge($classGuards, $guardInst->guards);
        }

        // Iterar métodos públicos
        foreach ($refClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $routeAttrs = $method->getAttributes(Route::class);
            if (empty($routeAttrs)) continue;

            /** @var Route $routeAttr */
            $routeAttr = $routeAttrs[0]->newInstance();

            // Guards del método
            $methodGuards = [];
            foreach ($method->getAttributes(UseGuards::class) as $guardAttr) {
                /** @var UseGuards $guardInst */
                $guardInst    = $guardAttr->newInstance();
                $methodGuards = array_merge($methodGuards, $guardInst->guards);
            }

            // DTO de validación del body (si existe)
            $dtoClass  = null;
            $bodyAttrs = $method->getAttributes(Body::class);
            if (!empty($bodyAttrs)) {
                /** @var Body $bodyAttr */
                $bodyAttr = $bodyAttrs[0]->newInstance();
                $dtoClass = $bodyAttr->dtoClass;
            }

            $fullPath = rtrim($prefix, '/') . '/' . ltrim($routeAttr->path, '/');
            $fullPath = rtrim($fullPath, '/') ?: '/';

            [$regex, $paramNames] = $this->buildRegex($fullPath);

            $this->routes[] = [
                'method'     => strtoupper($routeAttr->method),
                'pattern'    => $fullPath,
                'regex'      => $regex,
                'paramNames' => $paramNames,
                'controller' => $controllerClass,
                'action'     => $method->getName(),
                'guards'     => array_merge($classGuards, $methodGuards),
                'dto'        => $dtoClass,
            ];
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Dispatch                                                            */
    /* ------------------------------------------------------------------ */

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) continue;
            if (!preg_match($route['regex'], $request->path, $matches)) continue;

            // Extraer parámetros de ruta
            foreach ($route['paramNames'] as $name) {
                $request->params[$name] = $matches[$name] ?? null;
            }

            // Ejecutar guards
            foreach ($route['guards'] as $guardClass) {
                /** @var GuardInterface $guard */
                $guard = new $guardClass();
                if (!$guard->canActivate($request)) {
                    return $guard->deny();
                }
            }

            // Validar body con DTO (si aplica)
            if ($route['dto'] !== null) {
                $validator = new Validator();
                $dto       = $validator->validate($route['dto'], $request->body);

                if ($validator->hasErrors()) {
                    return Response::json([
                        'error'  => 'Validation failed',
                        'errors' => $validator->getErrors(),
                    ], 422);
                }

                // El DTO validado y casteado queda disponible en $request->dto
                $request->dto = $dto;
            }

            // Ejecutar método del controlador
            $controller = new $route['controller']();
            $action     = $route['action'];
            $response   = $controller->$action($request);

            if (!$response instanceof Response) {
                return Response::json($response);
            }

            return $response;
        }

        return Response::error('Not Found', 404);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                             */
    /* ------------------------------------------------------------------ */

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
