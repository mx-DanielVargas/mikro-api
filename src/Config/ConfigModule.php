<?php

namespace MikroApi\Config;

use MikroApi\Attributes\Module;
use MikroApi\Module\DynamicModule;

/**
 * Módulo de configuración (equivalente a ConfigModule de @nestjs/config).
 * Expone ConfigService a los demás módulos.
 *
 *   App::create(
 *       ConfigModule::forRoot(
 *           envFilePath: __DIR__,
 *           load: [
 *               'jwt' => fn(ConfigService $c) => [
 *                   'secret' => $c->getOrThrow('JWT_SECRET'),
 *                   'ttl'    => $c->getInt('JWT_TTL', 3600),
 *               ],
 *           ],
 *           validate: ['JWT_SECRET'],
 *       ),
 *       AppModule::class,
 *   )->run();
 *
 * Luego cualquier provider recibe ConfigService por constructor, o por
 * 'inject' en una factory:
 *
 *   ['provide' => JwtService::class, 'useFactory' => [AuthModule::class, 'createJwt'], 'inject' => [ConfigService::class]]
 *
 * Importarlo sin forRoot() expone un ConfigService que solo lee las
 * variables de entorno del proceso (sin archivo .env).
 */
#[Module(providers: [ConfigService::class], exports: [ConfigService::class])]
final class ConfigModule
{
    /**
     * Carga el .env de inmediato (falla en el arranque si falta una clave de
     * $validate, no en la primera petición que la use).
     *
     * @param string|null                                $envFilePath Directorio del .env (null = solo variables del proceso)
     * @param string                                     $envFile     Nombre del archivo; también carga "$envFile.$APP_ENV" si existe
     * @param bool                                       $isGlobal    Visible en todos los módulos sin importarlo
     * @param array<string, callable(ConfigService):array> $load      Configuración por namespace (registerAs), accesible con 'ns.key'
     * @param string[]                                   $validate    Claves obligatorias
     */
    public static function forRoot(
        ?string $envFilePath = null,
        string $envFile = '.env',
        bool $isGlobal = true,
        array $load = [],
        array $validate = [],
    ): DynamicModule {
        $config = new ConfigService($envFilePath, $envFile);

        if (!empty($validate)) {
            $config->validate($validate);
        }

        foreach ($load as $namespace => $factory) {
            $config->register($namespace, $factory($config));
        }

        return new DynamicModule(
            module:    self::class,
            providers: [['provide' => ConfigService::class, 'useValue' => $config]],
            global:    $isGlobal,
        );
    }
}
