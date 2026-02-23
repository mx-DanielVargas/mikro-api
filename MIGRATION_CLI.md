# MikroAPI Migration CLI

## Overview

MikroAPI includes a powerful CLI tool for managing database migrations. The tool is automatically available after installing the package via Composer.

## Installation

When you install MikroAPI:

```bash
composer require mikro-api/mikro-api
```

The migration CLI is automatically available at:

```bash
vendor/bin/mikro-migrate
```

## Commands

### Create Migration

```bash
vendor/bin/mikro-migrate make <migration_name>
```

Example:
```bash
vendor/bin/mikro-migrate make create_users_table
```

This creates a new migration file in `database/migrations/` with a timestamp prefix.

### Run Migrations

```bash
vendor/bin/mikro-migrate migrate
```

Executes all pending migrations.

### Rollback Migration

```bash
vendor/bin/mikro-migrate rollback
```

Rolls back the last executed migration.

### Reset Migrations

```bash
vendor/bin/mikro-migrate reset
```

Rolls back all migrations.

### Check Status

```bash
vendor/bin/mikro-migrate status
```

Shows the status of all migrations (executed or pending).

## Composer Scripts

For convenience, you can also use composer scripts:

```bash
composer exec mikro-migrate migrate
composer exec mikro-migrate rollback
composer exec mikro-migrate status
composer exec mikro-migrate reset
composer exec mikro-migrate make create_users_table
```

## Configuration

The CLI tool looks for database configuration in:

```
<project-root>/config/database.php
```

Example configuration:

```php
<?php
return [
    'driver'   => 'mysql',
    'host'     => getenv('DB_HOST') ?: 'localhost',
    'port'     => 3306,
    'database' => getenv('DB_DATABASE') ?: 'myapp',
    'username' => getenv('DB_USERNAME') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'charset'  => 'utf8mb4',
];
```

## Migration Files

Migration files are stored in:

```
<project-root>/database/migrations/
```

Example migration:

```php
<?php

namespace Database\Migrations;

use MikroApi\Database\Migration;
use MikroApi\Attributes\Schema\Table;
use MikroApi\Attributes\Schema\Column;
use MikroApi\Attributes\Schema\PrimaryKey;
use MikroApi\Attributes\Schema\Unique;
use MikroApi\Attributes\Schema\Timestamps;

#[Table('users')]
#[Timestamps]
class CreateUsersTable extends Migration
{
    #[PrimaryKey]
    #[Column(type: 'int')]
    public int $id;

    #[Column(type: 'varchar', length: 100)]
    public string $name;

    #[Column(type: 'varchar', length: 150)]
    #[Unique]
    public string $email;

    #[Column(type: 'varchar', length: 255)]
    public string $password;
}
```

## How It Works

1. **Auto-detection**: The CLI automatically detects whether it's running in a project that installed MikroAPI as a dependency or in the MikroAPI development environment.

2. **Project Root**: It uses `getcwd()` to determine the project root where `config/database.php` and `database/migrations/` should be located.

3. **Namespace**: All migrations use the `Database\Migrations` namespace by default.

4. **Autoloading**: The CLI uses Composer's autoloader to load migration classes.

## Workflow Example

```bash
# 1. Create a new migration
vendor/bin/mikro-migrate make create_products_table

# 2. Edit the migration file
# database/migrations/2024_01_15_120000_create_products_table.php

# 3. Run the migration
vendor/bin/mikro-migrate migrate

# 4. Check status
vendor/bin/mikro-migrate status

# 5. If needed, rollback
vendor/bin/mikro-migrate rollback
```

## Troubleshooting

### Command not found

If `vendor/bin/mikro-migrate` is not found:

1. Make sure you ran `composer install`
2. Check that `vendor/bin/` exists
3. Try using the full path: `./vendor/bin/mikro-migrate`

### Database configuration not found

Make sure you have created `config/database.php` in your project root with valid database credentials.

### Autoload errors

Run `composer dump-autoload` to regenerate the autoloader.

## Advanced Usage

### Custom Migration Path

By default, migrations are stored in `database/migrations/`. This is currently not configurable but may be added in future versions.

### Custom Namespace

By default, migrations use the `Database\Migrations` namespace. This is currently not configurable but may be added in future versions.

## Integration with CI/CD

You can use the migration CLI in your CI/CD pipelines:

```yaml
# Example GitHub Actions
- name: Run migrations
  run: vendor/bin/mikro-migrate migrate
```

```yaml
# Example GitLab CI
migrate:
  script:
    - vendor/bin/mikro-migrate migrate
```

## Best Practices

1. **Version Control**: Always commit migration files to version control
2. **Never Edit**: Never edit a migration that has been run in production
3. **Rollback Safety**: Always test rollback before deploying
4. **Naming**: Use descriptive names for migrations (e.g., `create_users_table`, `add_email_to_users`)
5. **Order**: Migrations run in chronological order based on timestamp
