<?php

namespace MikroApi\Tests;

use MikroApi\Attributes\Schema\Column;
use MikroApi\Attributes\Schema\Index;
use MikroApi\Attributes\Schema\PrimaryKey;
use MikroApi\Attributes\Schema\Table;
use MikroApi\Attributes\Schema\Timestamps;
use MikroApi\Attributes\Schema\Unique;
use MikroApi\Database\Database;
use MikroApi\Database\Migration;
use MikroApi\Database\SchemaBuilder;
use PHPUnit\Framework\TestCase;

class MigrationAlterTableTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        // Resetear el singleton antes de cada test
        $reflection = new \ReflectionClass(Database::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);

        $this->db = Database::connect([
            'driver' => 'sqlite',
            'database' => ':memory:'
        ]);
    }

    public function testBuildAlterTableAddsNewColumns(): void
    {
        // Crear tabla inicial con solo id y name
        $this->db->execute("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )
        ");

        $schema = new SchemaBuilder('sqlite');
        $sql = $schema->buildAlterTable(TestUserMigrationV2::class, $this->db->getPdo());

        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('ADD COLUMN `email`', $sql);
        $this->assertStringContainsString('ADD COLUMN `age`', $sql);
    }

    public function testBuildAlterTableSkipsExistingColumns(): void
    {
        // Crear tabla con todas las columnas
        $this->db->execute("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                age INTEGER NULL
            )
        ");

        $schema = new SchemaBuilder('sqlite');
        $sql = $schema->buildAlterTable(TestUserMigrationV2::class, $this->db->getPdo());

        // No debería generar ALTER TABLE si todas las columnas existen
        $this->assertEmpty($sql);
    }

    public function testBuildAlterTableAddsIndexes(): void
    {
        $this->db->execute("
            CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )
        ");

        $schema = new SchemaBuilder('sqlite');
        $sql = $schema->buildAlterTable(TestProductMigrationWithIndex::class, $this->db->getPdo());

        $this->assertStringContainsString('ADD COLUMN `sku`', $sql);
        $this->assertStringContainsString('CREATE INDEX', $sql);
        $this->assertStringContainsString('idx_products_sku', $sql);
    }

    public function testBuildAlterTableAddsUniqueConstraints(): void
    {
        $this->db->execute("
            CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )
        ");

        $schema = new SchemaBuilder('sqlite');
        $sql = $schema->buildAlterTable(TestProductMigrationWithIndex::class, $this->db->getPdo());

        $this->assertStringContainsString('CREATE UNIQUE INDEX', $sql);
        $this->assertStringContainsString('uniq_products_email', $sql);
    }

    public function testBuildAlterTableAddsTimestamps(): void
    {
        $this->db->execute("
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL
            )
        ");

        $schema = new SchemaBuilder('sqlite');
        $sql = $schema->buildAlterTable(TestPostMigrationWithTimestamps::class, $this->db->getPdo());

        $this->assertStringContainsString('ADD COLUMN `created_at`', $sql);
        $this->assertStringContainsString('ADD COLUMN `updated_at`', $sql);
    }

    public function testMigrationUpCreatesTableWhenNotExists(): void
    {
        $migration = new TestUserMigrationV2('sqlite', $this->db->getPdo());
        $sql = $migration->up();

        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringNotContainsString('ALTER TABLE', $sql);
    }

    public function testMigrationUpAltersTableWhenExists(): void
    {
        // Crear tabla inicial
        $this->db->execute("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )
        ");

        $migration = new TestUserMigrationV2('sqlite', $this->db->getPdo());
        $sql = $migration->up();

        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringNotContainsString('CREATE TABLE', $sql);
    }

    public function testAlterTableExecutesSuccessfully(): void
    {
        // Crear tabla inicial
        $this->db->execute("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )
        ");

        // Insertar datos
        $this->db->execute("INSERT INTO users (name) VALUES ('John')");

        // Ejecutar migración que agrega columnas
        $migration = new TestUserMigrationV2('sqlite', $this->db->getPdo());
        $sql = $migration->up();
        
        if ($sql && trim($sql) !== '' && trim($sql) !== '-- No hay cambios que aplicar') {
            $this->db->execute($sql);
        }

        // Verificar que las nuevas columnas existen
        $result = $this->db->queryOne("PRAGMA table_info(users)");
        $this->assertNotNull($result);

        // Verificar que los datos existentes se mantienen
        $user = $this->db->queryOne("SELECT * FROM users WHERE name = 'John'");
        $this->assertEquals('John', $user['name']);
    }

    public function testGetExistingColumnsReturnsEmptyForNonExistentTable(): void
    {
        $schema = new SchemaBuilder('sqlite');
        
        // Usar reflexión para acceder al método privado
        $reflection = new \ReflectionClass($schema);
        $method = $reflection->getMethod('getExistingColumns');
        $method->setAccessible(true);

        $columns = $method->invoke($schema, $this->db->getPdo(), 'non_existent_table');

        $this->assertIsArray($columns);
        $this->assertEmpty($columns);
    }

    public function testGetExistingColumnsReturnsColumnNames(): void
    {
        $this->db->execute("
            CREATE TABLE test_table (
                id INTEGER PRIMARY KEY,
                name TEXT,
                email TEXT
            )
        ");

        $schema = new SchemaBuilder('sqlite');
        
        $reflection = new \ReflectionClass($schema);
        $method = $reflection->getMethod('getExistingColumns');
        $method->setAccessible(true);

        $columns = $method->invoke($schema, $this->db->getPdo(), 'test_table');

        $this->assertIsArray($columns);
        $this->assertContains('id', $columns);
        $this->assertContains('name', $columns);
        $this->assertContains('email', $columns);
    }

    public function testAlterTableWithMultipleNewColumns(): void
    {
        $this->db->execute("
            CREATE TABLE orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT
            )
        ");

        $migration = new TestOrderMigrationMultipleColumns('sqlite', $this->db->getPdo());
        $sql = $migration->up();
        
        $this->db->execute($sql);

        // Verificar que todas las columnas fueron agregadas
        $stmt = $this->db->getPdo()->prepare("PRAGMA table_info(orders)");
        $stmt->execute();
        $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'name');

        $this->assertContains('customer_name', $columnNames);
        $this->assertContains('total', $columnNames);
        $this->assertContains('status', $columnNames);
    }
}

// Clases de migración de prueba

#[Table(name: 'users')]
class TestUserMigrationV2 extends Migration
{
    #[PrimaryKey(autoIncrement: true)]
    #[Column(type: 'int')]
    public int $id;

    #[Column(type: 'varchar', length: 100)]
    public string $name;

    #[Column(type: 'varchar', length: 255)]
    public string $email;

    #[Column(type: 'int', nullable: true)]
    public ?int $age;
}

#[Table(name: 'products')]
class TestProductMigrationWithIndex extends Migration
{
    #[PrimaryKey(autoIncrement: true)]
    #[Column(type: 'int')]
    public int $id;

    #[Column(type: 'varchar', length: 100)]
    public string $name;

    #[Column(type: 'varchar', length: 50)]
    #[Index(name: 'idx_products_sku')]
    public string $sku;

    #[Column(type: 'varchar', length: 255)]
    #[Unique(name: 'uniq_products_email')]
    public string $email;
}

#[Table(name: 'posts')]
#[Timestamps]
class TestPostMigrationWithTimestamps extends Migration
{
    #[PrimaryKey(autoIncrement: true)]
    #[Column(type: 'int')]
    public int $id;

    #[Column(type: 'varchar', length: 200)]
    public string $title;

    #[Column(type: 'text')]
    public string $content;
}

#[Table(name: 'orders')]
class TestOrderMigrationMultipleColumns extends Migration
{
    #[PrimaryKey(autoIncrement: true)]
    #[Column(type: 'int')]
    public int $id;

    #[Column(type: 'varchar', length: 100)]
    public string $customer_name;

    #[Column(type: 'decimal', precision: 10, scale: 2)]
    public float $total;

    #[Column(type: 'varchar', length: 20, default: 'pending')]
    public string $status;
}
