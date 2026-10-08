<?php

namespace MikroApi;

use MikroApi\Middleware\MiddlewareInterface;
use MikroApi\Config\ConfigService;
use MikroApi\Exception\ExceptionHandler;
use MikroApi\Module\DynamicModule;
use MikroApi\Module\ModuleLoader;
use MikroApi\Module\ModuleRef;
use MikroApi\Module\OnApplicationShutdown;
use MikroApi\Module\OnModuleInit;
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

    /** @var string[] */
    private array $globalGuards = [];

    /** @var string[] */
    private array $globalInterceptors = [];

    /** @var string[] */
    private array $globalFilters = [];

    /** SwaggerUI listo para despachar, o null si no está habilitado */
    private ?SwaggerUI $swaggerUI = null;

    private ?ModuleLoader $moduleLoader = null;

    private bool $closed = false;

    /** Ruta de archivo de caché de rutas pendiente de escribir en run() */
    private ?string $pendingRouteCacheFile = null;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? new Container();
        $this->router    = new Router();
        $this->router->setContainer($this->container);
    }

    /**
     * Crea la app a partir de un módulo raíz (estilo NestFactory.create).
     *
     *   App::create(AppModule::class)->useGlobalFilters(...)->run();
     */
    public static function create(string|DynamicModule ...$modules): self
    {
        return (new self())->useModule(...$modules);
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    /* ------------------------------------------------------------------ */
    /*  Módulos                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Carga uno o más módulos (y sus imports), registra sus controladores y
     * ejecuta los hooks OnModuleInit. Los módulos dinámicos (forRoot) deben
     * pasarse antes que los módulos que los importan.
     *
     *   $app->useModule(ConfigModule::forRoot([...]), AppModule::class);
     */
    public function useModule(string|DynamicModule ...$modules): self
    {
        $this->moduleLoader ??= new ModuleLoader($this->container);

        $loaded = [];
        foreach ($modules as $module) {
            \array_push($loaded, ...$this->moduleLoader->load($module));
        }

        foreach ($loaded as $ref) {
            foreach ($ref->controllers as $controller) {
                $this->controllers[] = $controller;
                $this->router->registerController($controller, $ref->container);
            }
        }

        foreach ($loaded as $ref) {
            $this->initModule($ref);
        }

        return $this;
    }

    /**
     * Container de un módulo cargado (útil en tests o scripts para obtener
     * sus providers).
     */
    public function getModuleContainer(string $moduleClass): Container
    {
        foreach ($this->moduleLoader?->modules() ?? [] as $ref) {
            if ($ref->class === $moduleClass) {
                return $ref->container;
            }
        }
        throw new \RuntimeException("El módulo {$moduleClass} no está cargado.");
    }

    /**
     * Ejecuta los hooks OnApplicationShutdown de los providers ya
     * instanciados (orden inverso al de carga). run() lo llama al terminar;
     * es idempotente.
     */
    public function close(): void
    {
        if ($this->closed || $this->moduleLoader === null) {
            return;
        }
        $this->closed = true;

        foreach (\array_reverse($this->moduleLoader->modules()) as $ref) {
            foreach (\array_reverse($ref->providerIds) as $id) {
                if (!$ref->container->isResolved($id)) continue;
                $instance = $ref->container->get($id);
                if ($instance instanceof OnApplicationShutdown) {
                    $instance->onApplicationShutdown();
                }
            }
            if (\is_subclass_of($ref->class, OnApplicationShutdown::class) && $ref->container->isResolved($ref->class)) {
                $ref->container->get($ref->class)->onApplicationShutdown();
            }
        }
    }

    private function initModule(ModuleRef $ref): void
    {
        foreach ($ref->providerClasses as $id => $class) {
            if (\is_subclass_of($class, OnModuleInit::class)) {
                $ref->container->get($id)->onModuleInit();
            }
        }
        if (\is_subclass_of($ref->class, OnModuleInit::class)) {
            $ref->container->get($ref->class)->onModuleInit();
        }
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

    /* ------------------------------------------------------------------ */
    /*  Guards, interceptors y filtros globales                             */
    /* ------------------------------------------------------------------ */

    /**
     * Guards que se ejecutan en todas las rutas, antes que los de clase y método.
     * Combínalo con #[PublicRoute] para excluir rutas de un JwtGuard global.
     */
    public function useGlobalGuards(string ...$guards): self
    {
        \array_push($this->globalGuards, ...$guards);
        $this->router->setGlobalGuards($this->globalGuards);
        return $this;
    }

    /** Interceptors que envuelven todas las rutas (los más externos). */
    public function useGlobalInterceptors(string ...$interceptors): self
    {
        \array_push($this->globalInterceptors, ...$interceptors);
        $this->router->setGlobalInterceptors($this->globalInterceptors);
        return $this;
    }

    /**
     * Filtros de excepciones globales. Se prueban después de los de método
     * y clase, y también atienden errores de middlewares y 404/405.
     */
    public function useGlobalFilters(string ...$filters): self
    {
        \array_push($this->globalFilters, ...$filters);
        $this->router->setGlobalFilters($this->globalFilters);
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

            // Se leen al generar el spec (perezoso), así que no importa si
            // useGlobalGuards() se llamó antes o después de enableSwagger().
            $generator->setGlobalGuards($this->globalGuards);

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
    /*  Caché de rutas                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Habilita caché de rutas compiladas. Si el archivo indicado existe y es
     * válido, las rutas se cargan desde ahí (evitando reflexión). Si no existe,
     * está corrupto, o tiene una estructura inesperada, se generará
     * automáticamente al llamar a run() (después de que todos los
     * useController() ya se hayan registrado).
     *
     * IMPORTANTE: debe llamarse ANTES de cualquier useController(), o se
     * lanzará una excepción — llamarlo después podría descartar silenciosamente
     * rutas ya registradas si el archivo de caché ya existe pero está
     * desactualizado (Ronda 2, hallazgo crítico).
     *
     * Uso:
     *   $app->cacheRoutes(__DIR__ . '/cache/routes.php')
     *       ->useController(UserController::class, PostController::class)
     *       ->run();
     *
     * Para invalidar manualmente el caché (ej. tras agregar/quitar rutas),
     * borra el archivo o usa App::clearRouteCache().
     */
    public function cacheRoutes(string $cacheFile): self
    {
        if (!empty($this->controllers)) {
            throw new \LogicException(
                'App::cacheRoutes() debe llamarse antes de useController(); '
                . 'llamarlo después puede descartar silenciosamente rutas ya '
                . 'registradas si el archivo de caché ya existe.'
            );
        }

        if (!$this->router->loadFromCache($cacheFile)) {
            $this->pendingRouteCacheFile = $cacheFile;
        }
        return $this;
    }

    /**
     * Elimina el archivo de caché de rutas si existe. Útil para forzar la
     * regeneración tras agregar/quitar controladores o rutas, ya que
     * loadFromCache() no invalida automáticamente por staleness.
     */
    public function clearRouteCache(string $cacheFile): self
    {
        if (\is_file($cacheFile)) {
            \unlink($cacheFile);
        }
        return $this;
    }

    /* ------------------------------------------------------------------ */
    /*  Run                                                                 */
    /* ------------------------------------------------------------------ */

    public function run(): void
    {
        $this->handle(Request::capture())->send();
        $this->close();
    }

    /**
     * Procesa una petición por el pipeline completo (middlewares → docs |
     * router) y retorna la respuesta sin enviarla. Útil para tests.
     */
    public function handle(Request $request): Response
    {
        if ($this->pendingRouteCacheFile !== null) {
            $this->router->cacheTo($this->pendingRouteCacheFile);
            $this->pendingRouteCacheFile = null;
        }

        try {
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

            return $pipeline($request);

        } catch (\Throwable $e) {
            // Excepciones fuera de una ruta (middlewares) → filtros globales
            try {
                return ExceptionHandler::handle($e, $request, null, $this->globalFilters, $this->container);
            } catch (\Throwable $filterError) {
                return ExceptionHandler::defaultResponse($filterError);
            }
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
        return ExceptionHandler::isProduction();
    }

}
