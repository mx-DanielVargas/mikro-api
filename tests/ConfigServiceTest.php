<?php

namespace MikroApi\Tests;

use MikroApi\Config\ConfigService;
use PHPUnit\Framework\TestCase;

class ConfigServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mikro_config_test_' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->tmpDir) as $f) {
            if ($f !== '.' && $f !== '..') unlink($this->tmpDir . '/' . $f);
        }
        rmdir($this->tmpDir);
    }

    private function writeEnv(string $content, string $filename = '.env'): void
    {
        file_put_contents($this->tmpDir . '/' . $filename, $content);
    }

    /* ------------------------------------------------------------------ */
    /*  .env loading                                                        */
    /* ------------------------------------------------------------------ */

    public function testLoadsEnvFile(): void
    {
        $this->writeEnv("APP_NAME=MikroTest\nDB_HOST=localhost");
        $config = new ConfigService($this->tmpDir);

        $this->assertSame('MikroTest', $config->get('APP_NAME'));
        $this->assertSame('localhost', $config->get('DB_HOST'));
    }

    public function testIgnoresCommentsAndBlankLines(): void
    {
        $this->writeEnv("# comment\n\nKEY=value\n  # another comment");
        $config = new ConfigService($this->tmpDir);

        $this->assertSame('value', $config->get('KEY'));
    }

    public function testStripsQuotes(): void
    {
        $this->writeEnv("A=\"double\"\nB='single'");
        $config = new ConfigService($this->tmpDir);

        $this->assertSame('double', $config->get('A'));
        $this->assertSame('single', $config->get('B'));
    }

    public function testVariableInterpolation(): void
    {
        $this->writeEnv("HOST=localhost\nURL=http://\${HOST}:8000");
        $config = new ConfigService($this->tmpDir);

        $this->assertSame('http://localhost:8000', $config->get('URL'));
    }

    public function testEnvironmentSpecificOverride(): void
    {
        $this->writeEnv("MODE=base\nDB=original");
        $this->writeEnv("MODE=production", '.env.production');

        // Set APP_ENV so the override file is loaded
        $prev = getenv('APP_ENV');
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';

        $config = new ConfigService($this->tmpDir);

        $this->assertSame('production', $config->get('MODE'));
        $this->assertSame('original', $config->get('DB'));

        // Restore
        if ($prev === false) { putenv('APP_ENV'); unset($_ENV['APP_ENV']); }
        else { putenv("APP_ENV={$prev}"); $_ENV['APP_ENV'] = $prev; }
    }

    public function testMissingEnvFileDoesNotThrow(): void
    {
        $config = new ConfigService($this->tmpDir . '/nonexistent');
        $this->assertNull($config->get('ANYTHING'));
    }

    /* ------------------------------------------------------------------ */
    /*  Typed accessors                                                     */
    /* ------------------------------------------------------------------ */

    public function testGetReturnsDefault(): void
    {
        $config = new ConfigService();
        $this->assertSame('fallback', $config->get('MISSING', 'fallback'));
        $this->assertNull($config->get('MISSING'));
    }

    public function testGetOrThrow(): void
    {
        $config = new ConfigService();
        $config->set('EXISTS', 'yes');

        $this->assertSame('yes', $config->getOrThrow('EXISTS'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required config: NOPE');
        $config->getOrThrow('NOPE');
    }

    public function testGetInt(): void
    {
        $this->writeEnv("PORT=3306");
        $config = new ConfigService($this->tmpDir);

        $this->assertSame(3306, $config->getInt('PORT'));
        $this->assertSame(5432, $config->getInt('MISSING', 5432));
        $this->assertNull($config->getInt('MISSING'));
    }

    public function testGetBool(): void
    {
        $this->writeEnv("A=true\nB=false\nC=1\nD=0\nE=yes\nF=no");
        $config = new ConfigService($this->tmpDir);

        $this->assertTrue($config->getBool('A'));
        $this->assertFalse($config->getBool('B'));
        $this->assertTrue($config->getBool('C'));
        $this->assertFalse($config->getBool('D'));
        $this->assertTrue($config->getBool('E'));
        $this->assertFalse($config->getBool('F'));
        $this->assertNull($config->getBool('MISSING'));
        $this->assertTrue($config->getBool('MISSING', true));
    }

    public function testGetFloat(): void
    {
        $this->writeEnv("RATE=0.75");
        $config = new ConfigService($this->tmpDir);

        $this->assertSame(0.75, $config->getFloat('RATE'));
        $this->assertSame(1.5, $config->getFloat('MISSING', 1.5));
        $this->assertNull($config->getFloat('MISSING'));
    }

    /* ------------------------------------------------------------------ */
    /*  Runtime set                                                         */
    /* ------------------------------------------------------------------ */

    public function testSetOverridesValue(): void
    {
        $this->writeEnv("KEY=original");
        $config = new ConfigService($this->tmpDir);

        $config->set('KEY', 'overridden');
        $this->assertSame('overridden', $config->get('KEY'));
    }

    /* ------------------------------------------------------------------ */
    /*  Namespaces                                                          */
    /* ------------------------------------------------------------------ */

    public function testRegisterAndDotNotation(): void
    {
        $config = new ConfigService();
        $config->register('database', [
            'host' => '127.0.0.1',
            'port' => 3306,
            'credentials' => ['user' => 'root', 'pass' => 'secret'],
        ]);

        $this->assertSame('127.0.0.1', $config->get('database.host'));
        $this->assertSame(3306, $config->get('database.port'));
        $this->assertSame('root', $config->get('database.credentials.user'));
        $this->assertSame('default', $config->get('database.missing', 'default'));
    }

    /* ------------------------------------------------------------------ */
    /*  Validation                                                          */
    /* ------------------------------------------------------------------ */

    public function testValidatePassesWhenAllPresent(): void
    {
        $this->writeEnv("A=1\nB=2");
        $config = new ConfigService($this->tmpDir);

        $config->validate(['A', 'B']);
        $this->assertTrue(true); // no exception
    }

    public function testValidateThrowsWithMissingKeys(): void
    {
        $config = new ConfigService();
        $config->set('A', '1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('B, C');
        $config->validate(['A', 'B', 'C']);
    }
}
