<?php
// examples/crud-api/database/migrations/2024_01_01_000002_create_posts_table.php

namespace Database\Migrations;

use MikroApi\Database\Migration;
use MikroApi\Attributes\Schema\Table;
use MikroApi\Attributes\Schema\Column;
use MikroApi\Attributes\Schema\PrimaryKey;
use MikroApi\Attributes\Schema\ForeignKey;
use MikroApi\Attributes\Schema\Timestamps;
use MikroApi\Attributes\Schema\SoftDeletes;

/**
 * Creates the `posts` table.
 *
 * Schema attributes demonstrated:
 * - #[Table]       names the table.
 * - #[Timestamps]  auto-adds created_at / updated_at columns.
 * - #[SoftDeletes] auto-adds a nullable deleted_at column (+ index),
 *                   which PostRepository uses via $useSoftDeletes = true.
 * - #[ForeignKey]  links `author_id` back to authors.id (CASCADE by default).
 *
 * The `author_id` column name is derived automatically from the property
 * name `authorId` via the framework's camelCase -> snake_case conversion.
 */
#[Table('posts')]
#[Timestamps]
#[SoftDeletes]
class CreatePostsTable extends Migration
{
    #[PrimaryKey]
    #[Column(type: 'int')]
    public int $id;

    #[ForeignKey(references: 'authors', on: 'id')]
    #[Column(type: 'int')]
    public int $authorId;

    #[Column(type: 'varchar', length: 200)]
    public string $title;

    #[Column(type: 'text')]
    public string $body;

    #[Column(type: 'boolean', default: false)]
    public bool $published;
}
