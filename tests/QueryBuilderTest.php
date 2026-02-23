<?php

namespace MikroApi\Tests;

use MikroApi\Database\Database;
use MikroApi\Repository\QueryBuilder;
use PHPUnit\Framework\TestCase;

class QueryBuilderTest extends TestCase
{
    private Database $db;
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        // Resetear el singleton para cada test
        $reflection = new \ReflectionClass(Database::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);

        // Usar SQLite en memoria para tests
        $this->db = Database::connect([
            'driver' => 'sqlite',
            'database' => ':memory:'
        ]);

        // Crear tabla de prueba
        $this->db->execute("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                age INTEGER,
                active INTEGER DEFAULT 1,
                deleted_at DATETIME NULL
            )
        ");

        // Insertar datos de prueba
        $this->db->execute("
            INSERT INTO users (name, email, age, active) VALUES
            ('John Doe', 'john@example.com', 30, 1),
            ('Jane Smith', 'jane@example.com', 25, 1),
            ('Bob Wilson', 'bob@example.com', 35, 0),
            ('Alice Brown', 'alice@example.com', 28, 1)
        ");

        $this->qb = new QueryBuilder(
            db: $this->db,
            table: 'users',
            primaryKey: 'id',
            useSoftDeletes: false,
            softDeleteColumn: 'deleted_at'
        );
    }

    public function testSimpleGet(): void
    {
        $results = $this->qb->get();

        $this->assertCount(4, $results);
        $this->assertEquals('John Doe', $results[0]['name']);
    }

    public function testWhereCondition(): void
    {
        $results = $this->qb->where('name', 'John Doe')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('john@example.com', $results[0]['email']);
    }

    public function testMultipleWhereConditions(): void
    {
        $results = $this->qb
            ->where('active', 1)
            ->where('age', 30, '>=')
            ->get();

        $this->assertCount(1, $results); // Solo John (30, active=1)
        $this->assertEquals('John Doe', $results[0]['name']);
    }

    public function testOrWhereCondition(): void
    {
        $results = $this->qb
            ->where('name', 'John Doe')
            ->orWhere('name', 'Jane Smith')
            ->get();

        $this->assertCount(2, $results);
    }

    public function testWhereInCondition(): void
    {
        $results = $this->qb
            ->whereIn('name', ['John Doe', 'Jane Smith'])
            ->get();

        $this->assertCount(2, $results);
    }

    public function testWhereBetweenCondition(): void
    {
        $results = $this->qb
            ->whereBetween('age', 25, 30)
            ->get();

        $this->assertCount(3, $results); // Jane (25), Alice (28), John (30)
    }

    public function testWhereLikeCondition(): void
    {
        $results = $this->qb
            ->whereLike('email', '%example.com')
            ->get();

        $this->assertCount(4, $results);
    }

    public function testWhereNullCondition(): void
    {
        $results = $this->qb
            ->whereNull('deleted_at')
            ->get();

        $this->assertCount(4, $results);
    }

    public function testOrderBy(): void
    {
        $results = $this->qb
            ->orderBy('age', 'DESC')
            ->get();

        $this->assertEquals('Bob Wilson', $results[0]['name']); // 35 años
        $this->assertEquals('Jane Smith', $results[3]['name']); // 25 años
    }

    public function testLimitAndOffset(): void
    {
        $results = $this->qb
            ->orderBy('name')
            ->limit(2)
            ->offset(1)
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals('Bob Wilson', $results[0]['name']);
    }

    public function testFirst(): void
    {
        $result = $this->qb
            ->where('email', 'john@example.com')
            ->first();

        $this->assertNotNull($result);
        $this->assertEquals('John Doe', $result['name']);
    }

    public function testFirstReturnsNull(): void
    {
        $result = $this->qb
            ->where('email', 'nonexistent@example.com')
            ->first();

        $this->assertNull($result);
    }

    public function testCount(): void
    {
        $count = $this->qb->where('active', 1)->count();

        $this->assertEquals(3, $count);
    }

    public function testExists(): void
    {
        $exists = $this->qb->where('email', 'john@example.com')->exists();
        $this->assertTrue($exists);

        $notExists = $this->qb->where('email', 'fake@example.com')->exists();
        $this->assertFalse($notExists);
    }

    public function testPaginate(): void
    {
        $result = $this->qb
            ->orderBy('name')
            ->paginate(page: 1, perPage: 2);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('current_page', $result);
        $this->assertArrayHasKey('last_page', $result);

        $this->assertCount(2, $result['data']);
        $this->assertEquals(4, $result['total']);
        $this->assertEquals(1, $result['current_page']);
        $this->assertEquals(2, $result['last_page']);
    }

    public function testPaginateSecondPage(): void
    {
        $result = $this->qb
            ->orderBy('name')
            ->paginate(page: 2, perPage: 2);

        $this->assertCount(2, $result['data']);
        $this->assertEquals(2, $result['current_page']);
    }

    public function testSelectSpecificColumns(): void
    {
        $results = $this->qb
            ->select('name', 'email')
            ->get();

        $this->assertArrayHasKey('name', $results[0]);
        $this->assertArrayHasKey('email', $results[0]);
        $this->assertArrayNotHasKey('age', $results[0]);
    }

    public function testSoftDeletesExcluded(): void
    {
        // Marcar un usuario como eliminado
        $this->db->execute("UPDATE users SET deleted_at = CURRENT_TIMESTAMP WHERE id = 1");

        $qb = new QueryBuilder(
            db: $this->db,
            table: 'users',
            primaryKey: 'id',
            useSoftDeletes: true,
            softDeleteColumn: 'deleted_at'
        );

        $results = $qb->get();
        $this->assertCount(3, $results); // Solo 3, excluyendo el eliminado
    }

    public function testSoftDeletesWithTrashed(): void
    {
        $this->db->execute("UPDATE users SET deleted_at = CURRENT_TIMESTAMP WHERE id = 1");

        $qb = new QueryBuilder(
            db: $this->db,
            table: 'users',
            primaryKey: 'id',
            useSoftDeletes: true,
            softDeleteColumn: 'deleted_at'
        );

        $results = $qb->withTrashed()->get();
        $this->assertCount(4, $results); // Incluye el eliminado
    }

    public function testComplexQuery(): void
    {
        $results = $this->qb
            ->select('name', 'email', 'age')
            ->where('active', 1)
            ->where('age', 25, '>=')
            ->orderBy('age', 'ASC')
            ->limit(2)
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals('Jane Smith', $results[0]['name']); // 25
        $this->assertEquals('Alice Brown', $results[1]['name']); // 28
    }
}
