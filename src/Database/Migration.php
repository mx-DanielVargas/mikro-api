<?php
// core/Database/Migration.php
namespace MikroApi\Database;

abstract class Migration
{
    protected SchemaBuilder $schema;
    protected ?\PDO $pdo = null;

    public function __construct(string $driver = 'sqlite', ?\PDO $pdo = null)
    {
        $this->schema = new SchemaBuilder($driver);
        $this->pdo = $pdo;
    }

    public function up(): string
    {
        // Si tenemos acceso a PDO, verificar si la tabla existe
        if ($this->pdo !== null) {
            $ref = new \ReflectionClass(static::class);
            $tableAttrs = $ref->getAttributes(\MikroApi\Attributes\Schema\Table::class);
            
            if (!empty($tableAttrs)) {
                $table = $tableAttrs[0]->newInstance();
                $tableName = $table->name;
                
                if ($this->tableExists($tableName)) {
                    // La tabla existe, generar ALTER TABLE
                    $alterSql = $this->schema->buildAlterTable(static::class, $this->pdo);
                    return $alterSql ?: '-- No hay cambios que aplicar';
                }
            }
        }
        
        // La tabla no existe, generar CREATE TABLE
        return $this->schema->buildCreateTable(static::class);
    }

    public function down(): string
    {
        return $this->schema->buildDropTable(static::class);
    }

    private function tableExists(string $tableName): bool
    {
        try {
            $driver = $this->schema->getDriver();
            
            if ($driver === 'mysql') {
                $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
                $stmt->execute([$tableName]);
                return $stmt->fetch() !== false;
            } else {
                // SQLite
                $stmt = $this->pdo->prepare(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name=?"
                );
                $stmt->execute([$tableName]);
                return $stmt->fetch() !== false;
            }
        } catch (\PDOException $e) {
            return false;
        }
    }
}
