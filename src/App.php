<?php

namespace MikroApi;

use MikroApi\Middleware\MiddlewareInterface;
use MikroApi\Config\ConfigService;
use MikroApi\Swagger\SwaggerGenerator;
use MikroApi\Swagger\SwaggerUI;

class App
{
    private Router $router;
    private Container $container;

    /** Controladores registrados via useController() */
    private array $controllers = [];

    /** @var MiddlewareInterface[] */
    private array $middlewares = [];

    /** SwaggerUI listo para despachar, o null si no está habilitado */
    private ?SwaggerUI $swaggerUI = null;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? new Container();
        $this->router    = new Router();
        $this->router->setContainer($this->container);
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    /* ------------------------------------------------------------------ */
    /*  Middleware                                                           */
    /* ------------------------------------------------------------------ */

    public function useMiddleware(MiddlewareInterface ...$middlewares): self
    {
        foreach ($middlewares as $mw) {
            $this->middlewares[] = $mw;
        }
        return $this;
    }

    public function useViews(string $viewsPath, string $extension = '.php'): self
    {
        Response::setViewEngine(new View\Engine($viewsPath, $extension));
        return $this;
    }

    /* ------------------------------------------------------------------ */
    /*  Config                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Load .env configuration and register ConfigService in the container.
     *
     * @param string $basePath  Directory containing .env file
     * @param string $envFile   Filename (default: .env)
     */
    public function useConfig(string $basePath, string $envFile = '.env'): self
    {
        $config = new ConfigService($basePath, $envFile);
        $this->container->instance(ConfigService::class, $config);
        return $this;
    }

    /* ------------------------------------------------------------------ */
    /*  Registro de controladores                                           */
    /* ------------------------------------------------------------------ */

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

    public function enableSwagger(
        array   $config             = [],
        array   $excludeControllers = [],
        array   $controllers        = [],
        string  $path               = '/docs',
        string  $jsonPath           = '/docs/json',
        array   $authGuards         = [],
    ): self {
        $toDocument = empty($controllers) ? $this->controllers : $controllers;

        $specFactory = function () use ($toDocument, $excludeControllers, $config, $authGuards): array {
            $generator = new SwaggerGenerator();

            if (!empty($authGuards)) {
                $generator->setAuthGuards($authGuards);
            }

            return $generator->generate(
                controllers:         $toDocument,
                excludeControllers:  $excludeControllers,
                config:              $config,
            );
        };

        $this->swaggerUI = new SwaggerUI(
            specFactory: $specFactory,
            uiPath:      $path,
            jsonPath:    $jsonPath,
        );

        return $this;
    }

    /* ------------------------------------------------------------------ */
    /*  Run                                                                 */
    /* ------------------------------------------------------------------ */

    public function run(): void
    {
        try {
            $request = Request::capture();

            // Construir pipeline: middlewares → (docs | router dispatch)
            // El chequeo de rutas de documentación vive dentro del pipeline
            // para que CORS, rate limiting, etc. también se apliquen a /docs
            // y /docs/json (AUD-005).
            $core = function (Request $req): Response {
                if ($this->swaggerUI !== null && $this->swaggerUI->matches($req->path)) {
                    return $this->swaggerUI->handle($req->path);
                }
                return $this->router->dispatch($req);
            };

            $pipeline = array_reduce(
                array_reverse($this->middlewares),
                fn(callable $next, MiddlewareInterface $mw) =>
                    fn(Request $req): Response => $mw->handle($req, $next),
                $core,
            );

            $response = $pipeline($request);
            $response->send();

        } catch (\MikroApi\Service\ServiceException $e) {
            Response::error($e->getMessage(), $e->getStatusCode())->send();
        } catch (\Throwable $e) {
            $message = $this->isProduction()
                ? 'Internal Server Error'
                : $e->getMessage();
            Response::error($message, 500)->send();
        }
    }

    /**
     * Determina si el entorno actual es de producción.
     *
     * ConfigService::loadEnvFile() escribe APP_ENV en $_ENV y putenv(),
     * nunca en $_SERVER, así que hay que revisar las tres fuentes para
     * no filtrar mensajes de error internos cuando APP_ENV se define
     * únicamente vía .env (AUD-001).
     */
    private function isProduction(): bool
    {
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? null;

        if ($env === null) {
            $fromGetenv = getenv('APP_ENV');
            $env = $fromGetenv !== false ? $fromGetenv : null;
        }

        return $env === 'production';
    }
}
