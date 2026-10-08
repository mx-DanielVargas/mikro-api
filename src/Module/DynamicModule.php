<?php

namespace MikroApi\Module;

/**
 * Módulo configurable en tiempo de ejecución (equivalente a DynamicModule de
 * NestJS). Se construye desde un método estático del módulo y se registra con
 * App::useModule(); su metadata se suma a la del atributo #[Module] (si lo tiene).
 *
 *   #[Module]
 *   class MailModule
 *   {
 *       public static function forRoot(array $options): DynamicModule
 *       {
 *           return new DynamicModule(
 *               module:    self::class,
 *               providers: [['provide' => 'mail.options', 'useValue' => $options], MailService::class],
 *               exports:   [MailService::class],
 *               global:    true,
 *           );
 *       }
 *   }
 *
 *   $app->useModule(MailModule::forRoot(['from' => 'no-reply@app.com']), AppModule::class);
 *
 * Los módulos que importen MailModule::class reciben esta versión configurada.
 * A diferencia del atributo, aquí 'useFactory' acepta cualquier callable (closures).
 */
final class DynamicModule
{
    public function __construct(
        public string $module,
        public array $imports = [],
        public array $controllers = [],
        public array $providers = [],
        public array $exports = [],
        public ?bool $global = null,
    ) {}
}
