<?php

namespace MikroApi\Tests;

use MikroApi\Database\Database;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        // Resetear el singleton antes de cada test
        $reflection = new \ReflectionClass(Database::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    public function testSqliteConnection(): void
    {
        $db = Database::connect([
            'driver' => 'sqlite',
            'database' => ':memory:'
        ]);

        $this->assertInstanceOf(Database::class, $db);
        $this->assertEquals('sqlite', $db->getDriver());
    }

    public function testSingletonPattern(): void
    {
        $db1 = Database::connect(['driver' => 'sqlite', 'database' => ':memory:']);
        $db2 = Database::getInstance();

        $this->assertSame($db1, $db2);
    }

    public function testExecute(): void
    {
        $db = Database::connect(['driver' => 'sqlite', 'database' => ':memory:']);

        $db->execute("
            CREATE TABLE test (
                id INTEGER PRIMARY KEY,
                name TEXT
            )
        ");

        $db->execute("INSERT INTO test (name) VALUES ('John')");

        $result = $db->query("SELECT * FROM test");
        $this->assertCount(1, $result);
        $this->assertEquals('John', $result[0]['name']);
    }

    public function testQuery(): void
    {
        $db = Database::connect(['driver' => 'sqlite', 'database' => ':memory:']);

        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER)");
        $db->execute("INSERT INTO users (name, age) VALUES ('John', 30), ('Jane', 25)");

        $results = $db->query("SELECT * FROM users WHERE age > ?", [20]);

        $this->assertCount(2, $results);
        $this->assertIsArray($results[0]);
    }

    public function testQueryOne(): void
    {
        $db = Database::connect(['driver' => 'sqlite', 'database' => ':memory:']);

        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $db->execute("INSERT INTO users (name) VALUES ('John')");

        $result = $db->queryOne("SELECT * FROM users WHERE name = ?", ['John']);

        $this->assertIsArray($result);
        $this->assertEquals('John', $result['name']);
    }

    public function testQueryOneReturnsNull(): void
    {
        $db = Database::connect(['driver' => 'sqlite', 'database' => ':memory:']);

        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");

        $result = $db->queryOne("SELECT * FROM users WHERE name = ?", ['NonExistent']);

        $this->assertNull($result);
    }

    public function testLastInsertId(): void
    {
        $db = Database::connect(['driver' => 'sqlite', 'database' => ':memory:']);

        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
        $db->execute("INSERT INTO users (name) VALUES ('John')");

        $lastId = $db->lastInsertId();

        $this->assertEquals('1', $lastId);
    }

    public function testPreparedStatements(): void
    {
        $db = Database::connect(['driver' => 'sqlite', 'database' => ':memory:']);

        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");

        $results = $db->query(
            "INSERT INTO users (name, email) VALUES (?, ?)",
            ['John', 'john@example.com']
        );

        $user = $db->queryOne("SELECT * FROM users WHERE email = ?", ['john@example.com']);

        $this->assertEquals('John', $user['name']);
    }

    public function testSqlitePragmas(): void
    {
        $db = Database::connect(['driver' => 'sqlite', 'database' => ':memory:']);

        // Verificar que foreign keys están activadas
        $result = $db->queryOne("PRAGMA foreign_keys");
        $this->assertEquals(1, $result['foreign_keys']);
    }

    public function testTransactionSupport(): void
    {
        $db = Database::connect(['driver' => 'sqlite', 'database' => ':memory:']);

        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");

        $pdo = $db->getPdo();
        $pdo->beginTransaction();

        $db->execute("INSERT INTO users (name) VALUES ('John')");
        $db->execute("INSERT INTO users (name) VALUES ('Jane')");

        $pdo->commit();

        $count = $db->queryOne("SELECT COUNT(*) as total FROM users");
        $this->assertEquals(2, $count['total']);
    }

    public function testTransactionRollback(): void
    {
        $db = Database::connect(['driver' => 'sqlite', 'database' => ':memory:']);

        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");

        $pdo = $db->getPdo();
        $pdo->beginTransaction();

        $db->execute("INSERT INTO users (name) VALUES ('John')");

        $pdo->rollBack();

        $count = $db->queryOne("SELECT COUNT(*) as total FROM users");
        $this->assertEquals(0, $count['total']);
    }

    public function testTursoDriverThrowsClearErrorWhenPackageMissing(): void
    {
        if (\class_exists(\Libsql\PDO::class)) {
            $this->markTestSkipped('turso/libsql está instalado en este entorno; este test valida el guard que se activa cuando NO lo está.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer require turso/libsql');

        Database::connect(['driver' => 'turso', 'database' => ':memory:']);
    }

    public function testGetDriverTranslatesTursoToSqlite(): void
    {
        // libSQL habla el dialecto SQL de SQLite; getDriver() debe traducir
        // 'turso' -> 'sqlite' para que MigrationRunner/SchemaBuilder tomen
        // las mismas decisiones de dialecto. No podemos conectar realmente
        // vía 'turso' en este entorno (requiere el paquete turso/libsql +
        // FFI), así que forzamos el campo privado $driver por Reflection
        // sobre una conexión sqlite real ya establecida.
        $db = Database::connect(['driver' => 'sqlite', 'database' => ':memory:']);

        $reflection = new \ReflectionClass(Database::class);
        $driverProp = $reflection->getProperty('driver');
        $driverProp->setAccessible(true);
        $driverProp->setValue($db, 'turso');

        $this->assertEquals('sqlite', $db->getDriver());
    }

    public function testQueryAndQueryOneReturnOnlyStringKeys(): void
    {
        // Guarda de regresión para el fetch mode explícito: antes de este
        // fix, query()/queryOne() dependían únicamente del atributo de
        // conexión PDO::ATTR_DEFAULT_FETCH_MODE, que Libsql\PDOStatement no
        // respeta (su default es FETCH_BOTH). Pasar \PDO::FETCH_ASSOC
        // explícitamente es un no-op para sqlite/mysql (ya era su default
        // efectivo) pero vuelve el comportamiento correcto también con
        // Turso. Este test fija la forma del array para cualquier driver.
        $db = Database::connect(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $db->execute("INSERT INTO users (name) VALUES ('John')");

        $rows = $db->query("SELECT * FROM users");
        foreach (array_keys($rows[0]) as $key) {
            $this->assertIsString($key, 'query() debe retornar únicamente claves string');
        }

        $row = $db->queryOne("SELECT * FROM users WHERE name = ?", ['John']);
        foreach (array_keys($row) as $key) {
            $this->assertIsString($key, 'queryOne() debe retornar únicamente claves string');
        }
    }
}
