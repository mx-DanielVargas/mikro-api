<?php
// core/Database/Migration.php
namespace MikroApi\Database;

abstract class Migration
{
    protected SchemaBuilder $schema;

    public function __construct(string $driver = 'sqlite')
    {
        $this->schema = new SchemaBuilder($driver);
    }

    public function up(): string
    {
        return $this->schema->buildCreateTable(static::class);
    }

    public function down(): string
    {
        return $this->schema->buildDropTable(static::class);
    }
}
