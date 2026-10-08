<?php

namespace MikroApi\Tests\ConfigModule;

use MikroApi\App;
use MikroApi\Attributes\Module;
use MikroApi\Config\ConfigModule;
use MikroApi\Config\ConfigService;
use PHPUnit\Framework\TestCase;

class ConfigModuleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/mikro-cfgmod-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
        \file_put_contents($this->dir . '/.env', "CFGMOD_SECRET=s3cr3t\nCFGMOD_TTL=120\n");
    }

    protected function tearDown(): void
    {
        @\unlink($this->dir . '/.env');
        @\rmdir($this->dir);
        foreach (['CFGMOD_SECRET', 'CFGMOD_TTL', 'CFGMOD_MISSING'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            \putenv($key);
        }
    }

    public function testForRootLoadsEnvAndNamespacesIntoGlobalModule(): void
    {
        $app = App::create(
            ConfigModule::forRoot(
                envFilePath: $this->dir,
                load: ['jwt' => fn(ConfigService $c) => [
                    'secret' => $c->getOrThrow('CFGMOD_SECRET'),
                    'ttl'    => $c->getInt('CFGMOD_TTL'),
                ]],
            ),
            TokenModule::class,
        );

        // TokenModule no importa ConfigModule: lo ve por ser global
        $token = $app->getModuleContainer(TokenModule::class)->get(TokenSettings::class);

        $this->assertSame('s3cr3t', $token->secret);
        $this->assertSame(120, $token->ttl);
    }

    public function testValidateFailsAtStartup(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CFGMOD_MISSING');

        ConfigModule::forRoot(envFilePath: $this->dir, validate: ['CFGMOD_SECRET', 'CFGMOD_MISSING']);
    }

    public function testNonGlobalConfigModuleMustBeImported(): void
    {
        $app = App::create(ConfigModule::forRoot(envFilePath: $this->dir, isGlobal: false), TokenModule::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('es provider de ' . ConfigModule::class);
        $app->getModuleContainer(TokenModule::class)->get(TokenSettings::class);
    }

    public function testImportWithoutForRootReadsProcessEnv(): void
    {
        \putenv('CFGMOD_SECRET=from-process');

        $app = App::create(ImportsPlainConfigModule::class);

        $this->assertSame(
            'from-process',
            $app->getModuleContainer(ImportsPlainConfigModule::class)->get(ConfigService::class)->get('CFGMOD_SECRET'),
        );
    }
}

class TokenSettings
{
    public function __construct(public string $secret, public int $ttl) {}

    public static function fromConfig(ConfigService $config): self
    {
        return new self($config->get('jwt.secret'), $config->get('jwt.ttl'));
    }
}

#[Module(
    providers: [['provide' => TokenSettings::class, 'useFactory' => [TokenSettings::class, 'fromConfig'], 'inject' => [ConfigService::class]]],
)]
class TokenModule {}

#[Module(imports: [ConfigModule::class])]
class ImportsPlainConfigModule {}
