<?php
// examples/crud-api/database/migrations/2024_01_01_000001_create_authors_table.php

namespace Database\Migrations;

use MikroApi\Database\Migration;
use MikroApi\Attributes\Schema\Table;
use MikroApi\Attributes\Schema\Column;
use MikroApi\Attributes\Schema\PrimaryKey;
use MikroApi\Attributes\Schema\Unique;
use MikroApi\Attributes\Schema\Timestamps;

/**
 * Creates the `authors` table.
 *
 * Schema attributes demonstrated:
 * - #[Table]      names the table.
 * - #[Timestamps] auto-adds created_at / updated_at columns.
 * - #[PrimaryKey] marks the auto-incrementing primary key.
 * - #[Unique]     adds a unique index on `email`.
 *
 * MigrationRunner resolves this class from the filename
 * (create_authors_table.php -> CreateAuthorsTable), so the class name
 * MUST match the file name convention.
 */
#[Table('authors')]
#[Timestamps]
class CreateAuthorsTable extends Migration
{
    #[PrimaryKey]
    #[Column(type: 'int')]
    public int $id;

    #[Column(type: 'varchar', length: 100)]
    public string $name;

    #[Unique]
    #[Column(type: 'varchar', length: 150)]
    public string $email;
}
