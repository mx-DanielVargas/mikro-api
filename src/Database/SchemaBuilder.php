<?php
// core/Database/SchemaBuilder.php
namespace MikroApi\Database;

use MikroApi\Attributes\Schema\Column;
use MikroApi\Attributes\Schema\ForeignKey;
use MikroApi\Attributes\Schema\Index;
use MikroApi\Attributes\Schema\PrimaryKey;
use MikroApi\Attributes\Schema\SoftDeletes;
use MikroApi\Attributes\Schema\Table;
use MikroApi\Attributes\Schema\Timestamps;
use MikroApi\Attributes\Schema\Unique;

class SchemaBuilder
{
    private string $driver;

    public function __construct(string $driver = 'sqlite')
    {
        $this->driver = $driver;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    /* ------------------------------------------------------------------ */
    /*  API pública                                                         */
    /* ------------------------------------------------------------------ */

    public function buildAlterTable(string $migrationClass, \PDO $pdo): string
    {
        $ref = new \ReflectionClass($migrationClass);

        $tableAttrs = $ref->getAttributes(Table::class);
        if (empty($tableAttrs)) {
            throw new \RuntimeException("La clase {$migrationClass} no tiene el atributo #[Table]");
        }

        /** @var Table $table */
        $table          = $tableAttrs[0]->newInstance();
        $tableName      = $table->name;
        $hasTimestamps  = !empty($ref->getAttributes(Timestamps::class));
        $hasSoftDeletes = !empty($ref->getAttributes(SoftDeletes::class));

        // Obtener columnas existentes en la tabla
        $existingColumns = $this->getExistingColumns($pdo, $tableName);

        $alterStatements = [];
        $postStatements  = [];

        foreach ($ref->getProperties() as $prop) {
            $colAttrs = $prop->getAttributes(Column::class);
            if (empty($colAttrs)) continue;

            /** @var Column $col */
            $col     = $colAttrs[0]->newInstance();
            $colName = $col->name ?? $this->toSnakeCase($prop->getName());

            // Si la columna ya existe, saltarla
            if (\in_array($colName, $existingColumns)) {
                continue;
            }

            $pkAttrs = $prop->getAttributes(PrimaryKey::class);
            $isPk    = !empty($pkAttrs);
            $pk      = $isPk ? $pkAttrs[0]->newInstance() : null;

            // Para ALTER TABLE en SQLite, las columnas NOT NULL deben tener un valor por defecto
            // o ser nullable si la tabla ya tiene datos
            $columnDef = $this->buildColumnDef($colName, $col, $pk);
            
            // Si es SQLite y la columna es NOT NULL sin default, hacerla nullable para ALTER TABLE
            if ($this->driver === 'sqlite' && !$col->nullable && $col->default === '__NONE__' && !$isPk) {
                // Crear una copia modificada de la columna para hacerla nullable
                $modifiedCol = clone $col;
                $modifiedCol->nullable = true;
                $columnDef = $this->buildColumnDef($colName, $modifiedCol, $pk);
            }

            if ($this->driver === 'mysql') {
                $alterStatements[] = "ADD COLUMN {$columnDef}";
            } else {
                // SQLite
                $alterStatements[] = "ADD COLUMN {$columnDef}";
            }

            // Unique
            foreach ($prop->getAttributes(Unique::class) as $uAttr) {
                /** @var Unique $u */
                $u       = $uAttr->newInstance();
                $idxName = $u->name ?? "uniq_{$tableName}_{$colName}";
                if ($this->driver === 'mysql') {
                    $alterStatements[] = "ADD UNIQUE KEY `{$idxName}` (`{$colName}`)";
                } else {
                    $postStatements[] = "CREATE UNIQUE INDEX IF NOT EXISTS `{$idxName}` ON `{$tableName}` (`{$colName}`);";
                }
            }

            // Index
            foreach ($prop->getAttributes(Index::class) as $iAttr) {
                /** @var Index $i */
                $i       = $iAttr->newInstance();
                $idxName = $i->name ?? "idx_{$tableName}_{$colName}";
                if ($this->driver === 'mysql') {
                    $alterStatements[] = "ADD KEY `{$idxName}` (`{$colName}`)";
                } else {
                    $postStatements[] = "CREATE INDEX IF NOT EXISTS `{$idxName}` ON `{$tableName}` (`{$colName}`);";
                }
            }

            // ForeignKey
            foreach ($prop->getAttributes(ForeignKey::class) as $fkAttr) {
                /** @var ForeignKey $fk */
                $fk     = $fkAttr->newInstance();
                $fkName = $fk->name ?? "fk_{$tableName}_{$colName}";
                if ($this->driver === 'mysql') {
                    $alterStatements[] = "ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`{$colName}`) "
                        . "REFERENCES `{$fk->references}` (`{$fk->on}`) "
                        . "ON DELETE {$fk->onDelete} ON UPDATE {$fk->onUpdate}";
                } else {
                    // SQLite no soporta agregar FK después de crear la tabla
                    // Se requeriría recrear la tabla completa
                }
            }
        }

        // Timestamps
        if ($hasTimestamps) {
            if (!\in_array('created_at', $existingColumns)) {
                if ($this->driver === 'mysql') {
                    $alterStatements[] = "ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP";
                } else {
                    $alterStatements[] = "ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP";
                }
            }
            if (!\in_array('updated_at', $existingColumns)) {
                if ($this->driver === 'mysql') {
                    $alterStatements[] = "ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
                } else {
                    $alterStatements[] = "ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP";
                }
            }
        }

        // SoftDeletes
        if ($hasSoftDeletes && !\in_array('deleted_at', $existingColumns)) {
            $colDef = "`deleted_at` " . ($this->driver === 'mysql' ? 'TIMESTAMP' : 'DATETIME') . " NULL DEFAULT NULL";
            $alterStatements[] = "ADD COLUMN {$colDef}";
            
            $idxName = "idx_{$tableName}_deleted_at";
            if ($this->driver === 'mysql') {
                $alterStatements[] = "ADD KEY `{$idxName}` (`deleted_at`)";
            } else {
                $postStatements[] = "CREATE INDEX IF NOT EXISTS `{$idxName}` ON `{$tableName}` (`deleted_at`);";
            }
        }

        if (empty($alterStatements)) {
            return ''; // No hay nada que alterar
        }

        $sql = '';
        
        if ($this->driver === 'mysql') {
            // MySQL soporta múltiples ADD COLUMN en una sola sentencia
            $sql = "ALTER TABLE `{$tableName}`\n  " . \implode(",\n  ", $alterStatements) . ";";
        } else {
            // SQLite requiere una sentencia ALTER TABLE por cada ADD COLUMN
            foreach ($alterStatements as $statement) {
                $sql .= "ALTER TABLE `{$tableName}` {$statement};\n";
            }
        }
        
        if (!empty($postStatements)) {
            $sql .= "\n" . \implode("\n", $postStatements);
        }

        return $sql;
    }

    public function buildCreateTable(string $migrationClass): string
    {
        $ref = new \ReflectionClass($migrationClass);

        $tableAttrs = $ref->getAttributes(Table::class);
        if (empty($tableAttrs)) {
            throw new \RuntimeException("La clase {$migrationClass} no tiene el atributo #[Table]");
        }

        /** @var Table $table */
        $table          = $tableAttrs[0]->newInstance();
        $tableName      = $table->name;
        $hasTimestamps  = !empty($ref->getAttributes(Timestamps::class));
        $hasSoftDeletes = !empty($ref->getAttributes(SoftDeletes::class));

        $columns     = [];
        $indexes     = [];   // para MySQL; SQLite los maneja por separado
        $foreignKeys = [];
        $postStatements = []; // CREATE INDEX para SQLite

        foreach ($ref->getProperties() as $prop) {
            $colAttrs = $prop->getAttributes(Column::class);
            if (empty($colAttrs)) continue;

            /** @var Column $col */
            $col     = $colAttrs[0]->newInstance();
            $colName = $col->name ?? $this->toSnakeCase($prop->getName());

            $pkAttrs = $prop->getAttributes(PrimaryKey::class);
            $isPk    = !empty($pkAttrs);
            $pk      = $isPk ? $pkAttrs[0]->newInstance() : null;

            $columns[] = $this->buildColumnDef($colName, $col, $pk);

            if ($isPk) {
                if ($this->driver === 'mysql') {
                    $indexes[] = "PRIMARY KEY (`{$colName}`)";
                }
                // SQLite: PRIMARY KEY va inline en la columna
            }

            // Unique
            foreach ($prop->getAttributes(Unique::class) as $uAttr) {
                /** @var Unique $u */
                $u       = $uAttr->newInstance();
                $idxName = $u->name ?? "uniq_{$tableName}_{$colName}";
                if ($this->driver === 'mysql') {
                    $indexes[] = "UNIQUE KEY `{$idxName}` (`{$colName}`)";
                } else {
                    $postStatements[] = "CREATE UNIQUE INDEX IF NOT EXISTS `{$idxName}` ON `{$tableName}` (`{$colName}`);";
                }
            }

            // Index
            foreach ($prop->getAttributes(Index::class) as $iAttr) {
                /** @var Index $i */
                $i       = $iAttr->newInstance();
                $idxName = $i->name ?? "idx_{$tableName}_{$colName}";
                if ($this->driver === 'mysql') {
                    $indexes[] = "KEY `{$idxName}` (`{$colName}`)";
                } else {
                    $postStatements[] = "CREATE INDEX IF NOT EXISTS `{$idxName}` ON `{$tableName}` (`{$colName}`);";
                }
            }

            // ForeignKey
            foreach ($prop->getAttributes(ForeignKey::class) as $fkAttr) {
                /** @var ForeignKey $fk */
                $fk     = $fkAttr->newInstance();
                $fkName = $fk->name ?? "fk_{$tableName}_{$colName}";
                if ($this->driver === 'mysql') {
                    $foreignKeys[] = "CONSTRAINT `{$fkName}` FOREIGN KEY (`{$colName}`) "
                        . "REFERENCES `{$fk->references}` (`{$fk->on}`) "
                        . "ON DELETE {$fk->onDelete} ON UPDATE {$fk->onUpdate}";
                } else {
                    // SQLite: FK inline en el CREATE TABLE
                    $foreignKeys[] = "FOREIGN KEY (`{$colName}`) REFERENCES `{$fk->references}` (`{$fk->on}`) "
                        . "ON DELETE {$fk->onDelete} ON UPDATE {$fk->onUpdate}";
                }
            }
        }

        // Timestamps
        if ($hasTimestamps) {
            if ($this->driver === 'mysql') {
                $columns[] = "`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP";
                $columns[] = "`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
            } else {
                $columns[] = "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP";
                // SQLite no tiene ON UPDATE, se maneja desde la app o un trigger
                $columns[] = "`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP";
            }
        }

        // SoftDeletes
        if ($hasSoftDeletes) {
            $columns[] = "`deleted_at` " . ($this->driver === 'mysql' ? 'TIMESTAMP' : 'DATETIME') . " NULL DEFAULT NULL";
            $idxName   = "idx_{$tableName}_deleted_at";
            if ($this->driver === 'mysql') {
                $indexes[] = "KEY `{$idxName}` (`deleted_at`)";
            } else {
                $postStatements[] = "CREATE INDEX IF NOT EXISTS `{$idxName}` ON `{$tableName}` (`deleted_at`);";
            }
        }

        $all  = \array_merge($columns, $indexes, $foreignKeys);
        $body = \implode(",\n  ", $all);

        if ($this->driver === 'mysql') {
            $comment = $table->comment ? " COMMENT='{$table->comment}'" : '';
            $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` (\n  {$body}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci{$comment};";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` (\n  {$body}\n);";
            if (!empty($postStatements)) {
                $sql .= "\n" . \implode("\n", $postStatements);
            }
        }

        return $sql;
    }

    public function buildDropTable(string $migrationClass): string
    {
        $ref       = new \ReflectionClass($migrationClass);
        $tableAttr = $ref->getAttributes(Table::class);
        if (empty($tableAttr)) {
            throw new \RuntimeException("La clase {$migrationClass} no tiene el atributo #[Table]");
        }
        $table = $tableAttr[0]->newInstance();
        return "DROP TABLE IF EXISTS `{$table->name}`;";
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function buildColumnDef(string $colName, Column $col, ?PrimaryKey $pk): string
    {
        $type = $this->resolveType($col);
        $def  = "`{$colName}` {$type}";

        if ($pk !== null) {
            if ($this->driver === 'sqlite') {
                // SQLite: INTEGER PRIMARY KEY es alias de rowid (autoincrement implícito)
                $def = "`{$colName}` INTEGER PRIMARY KEY" . ($pk->autoIncrement ? ' AUTOINCREMENT' : '');
            } else {
                $def .= $pk->autoIncrement ? ' NOT NULL AUTO_INCREMENT' : ' NOT NULL';
            }
        } else {
            $def .= $col->nullable ? ' NULL' : ' NOT NULL';
            if ($col->default !== '__NONE__') {
                $def .= ' DEFAULT ' . $this->formatDefault($col->default);
            }
        }

        if ($col->comment && $this->driver === 'mysql') {
            $def .= " COMMENT '{$col->comment}'";
        }

        return $def;
    }

    private function resolveType(Column $col): string
    {
        if ($this->driver === 'sqlite') {
            return match ($col->type) {
                'int', 'bigint', 'tinyint', 'smallint', 'boolean' => 'INTEGER',
                'float', 'double', 'decimal'                       => 'REAL',
                'text', 'mediumtext', 'longtext', 'json'           => 'TEXT',
                'varchar', 'char', 'uuid'                          => 'TEXT',
                'date', 'datetime', 'timestamp'                    => 'DATETIME',
                default => 'TEXT',
            };
        }

        return match ($col->type) {
            'int'        => 'INT',
            'bigint'     => 'BIGINT',
            'tinyint'    => 'TINYINT',
            'smallint'   => 'SMALLINT',
            'varchar'    => 'VARCHAR(' . ($col->length ?? 255) . ')',
            'char'       => 'CHAR(' . ($col->length ?? 1) . ')',
            'text'       => 'TEXT',
            'mediumtext' => 'MEDIUMTEXT',
            'longtext'   => 'LONGTEXT',
            'decimal'    => 'DECIMAL(' . ($col->precision ?? 10) . ',' . ($col->scale ?? 2) . ')',
            'float'      => 'FLOAT',
            'double'     => 'DOUBLE',
            'boolean'    => 'TINYINT(1)',
            'date'       => 'DATE',
            'datetime'   => 'DATETIME',
            'timestamp'  => 'TIMESTAMP',
            'json'       => 'JSON',
            'uuid'       => 'VARCHAR(36)',
            default      => \strtoupper($col->type),
        };
    }

    private function formatDefault(mixed $value): string
    {
        if ($value === null)           return 'NULL';
        if (\is_bool($value))          return $value ? '1' : '0';
        if (\is_int($value) || \is_float($value)) return (string) $value;
        if (\strtoupper((string) $value) === 'CURRENT_TIMESTAMP') return 'CURRENT_TIMESTAMP';
        return "'" . \addslashes((string) $value) . "'";
    }

    private function toSnakeCase(string $name): string
    {
        return \strtolower(\preg_replace('/[A-Z]/', '_$0', \lcfirst($name)));
    }

    private function getExistingColumns(\PDO $pdo, string $tableName): array
    {
        try {
            if ($this->driver === 'mysql') {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$tableName}`");
                $stmt->execute();
                $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                return \array_column($columns, 'Field');
            } else {
                // SQLite
                $stmt = $pdo->prepare("PRAGMA table_info(`{$tableName}`)");
                $stmt->execute();
                $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                return \array_column($columns, 'name');
            }
        } catch (\PDOException $e) {
            // La tabla no existe
            return [];
        }
    }
}
