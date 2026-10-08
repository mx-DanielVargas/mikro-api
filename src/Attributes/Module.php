<?php

namespace MikroApi\Attributes;

/**
 * Declara un módulo (equivalente a @Module de NestJS): agrupa controladores
 * y providers, y define qué expone a otros módulos.
 *
 *   #[Module(
 *       imports:     [DatabaseModule::class],
 *       controllers: [UserController::class],
 *       providers:   [
 *           UserService::class,                                          // clase (singleton)
 *           ['provide' => UserRepositoryInterface::class, 'useClass' => SqlUserRepository::class],
 *           ['provide' => 'users.pageSize', 'useValue' => 20],
 *           ['provide' => Mailer::class, 'useFactory' => [MailerFactory::class, 'create'], 'inject' => [ConfigService::class]],
 *           ['provide' => 'mailer', 'useExisting' => Mailer::class],
 *       ],
 *       exports:     [UserService::class],
 *   )]
 *   class UsersModule {}
 *
 * Encapsulamiento: un módulo solo puede inyectar sus propios providers, los
 * exportados por los módulos que importa, los de módulos globales y lo
 * registrado directamente en el container de App. Las clases que no son
 * provider de ningún módulo se siguen resolviendo por autowiring.
 *
 * global: true expone sus exports a todos los módulos sin importarlo.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Module
{
    /**
     * @param array<int, string>       $imports     Clases de módulos a importar
     * @param array<int, string>       $controllers Controladores del módulo
     * @param array<int, string|array> $providers   Providers (ver arriba)
     * @param array<int, string>       $exports     IDs de providers o módulos importados a re-exportar
     */
    public function __construct(
        public array $imports = [],
        public array $controllers = [],
        public array $providers = [],
        public array $exports = [],
        public bool $global = false,
    ) {}
}
