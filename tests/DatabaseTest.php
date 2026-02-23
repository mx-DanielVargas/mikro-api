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
}
