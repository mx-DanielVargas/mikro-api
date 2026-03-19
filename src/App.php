<?php

namespace MikroApi;

use MikroApi\Swagger\SwaggerGenerator;
use MikroApi\Swagger\SwaggerUI;

class App
{
    private Router $router;

    /** Controladores registrados via useController() */
    private array $controllers = [];

    /** SwaggerUI listo para despachar, o null si no está habilitado */
    private ?SwaggerUI $swaggerUI = null;

    public function __construct()
    {
        $this->router = new Router();
    }

    public function useViews(string $viewsPath, string $extension = '.php'): self
    {
        Response::setViewEngine(new View\Engine($viewsPath, $extension));
        return $this;
    }

    /* ------------------------------------------------------------------ */
    /*  Registro de controladores                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Registra uno o varios controladores.
     * Retorna $this para encadenamiento fluido.
     */
    public function useController(string ...$controllers): self
    {
        foreach ($controllers as $controller) {
            $this->controllers[] = $controller;
            $this->router->registerController($controller);
        }
        return $this;
    }

    /* ------------------------------------------------------------------ */
    /*  Swagger                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Habilita la generación automática de documentación Swagger UI.
     *
     * Toma por defecto los controladores registrados con useController().
     * Usa $excludeControllers para ignorar controladores específicos.
     *
     * @param array       $config              Metadatos del spec (title, version, description, servers)
     * @param string[]    $excludeControllers  Clases de controladores a excluir del spec
     * @param string[]    $controllers         Controladores a documentar (default: todos los registrados)
     * @param string      $path                Ruta de la UI  (default: /docs)
     * @param string      $jsonPath            Ruta del JSON  (default: /docs/json)
     * @param string[]    $authGuards          Guards que implican autenticación Bearer
     */
    public function enableSwagger(
        array   $config             = [],
        array   $excludeControllers = [],
        array   $controllers        = [],
        string  $path               = '/docs',
        string  $jsonPath           = '/docs/json',
        array   $authGuards         = [],
    ): self {
        // Si no se pasan controladores explícitos, usar los registrados
        $toDocument = empty($controllers) ? $this->controllers : $controllers;

        $generator = new SwaggerGenerator();

        if (!empty($authGuards)) {
            $generator->setAuthGuards($authGuards);
        }

        $spec = $generator->generate(
            controllers:         $toDocument,
            excludeControllers:  $excludeControllers,
            config:              $config,
        );

        $this->swaggerUI = new SwaggerUI(
            spec:     $spec,
            uiPath:   $path,
            jsonPath: $jsonPath,
        );

        return $this;
    }

    /* ------------------------------------------------------------------ */
    /*  Run                                                                 */
    /* ------------------------------------------------------------------ */

    public function run(): void
    {
        // Manejar preflight CORS (descomenta si necesitas)
        // if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        //     header('Access-Control-Allow-Origin: *');
        //     header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
        //     header('Access-Control-Allow-Headers: Content-Type, Authorization');
        //     http_response_code(204);
        //     exit;
        // }

        try {
            $request = Request::capture();

            // Interceptar rutas de documentación antes del router normal
            if ($this->swaggerUI !== null && $this->swaggerUI->matches($request->path)) {
                $this->swaggerUI->handle($request->path);
                return; // handle() llama exit internamente
            }

            $response = $this->router->dispatch($request);
            $response->send();

        } catch (\Core\Service\ServiceException $e) {
            Response::error($e->getMessage(), $e->getStatusCode())->send();
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500)->send();
        }
    }
}