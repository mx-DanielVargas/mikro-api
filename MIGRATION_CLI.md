# MikroAPI CLI

## Overview

MikroAPI includes a powerful CLI tool for managing database migrations, scaffolding new projects, and generating boilerplate code (controllers, repositories, DTOs, middleware, guards, services). The tool is automatically available after installing the package via Composer.

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

### Scaffold a New Project

```bash
vendor/bin/mikro-migrate init [path]
```

Generates a complete, ready-to-run project structure (default: current directory):

```
<path>/
├── public/index.php          Front controller, already wired (useConfig, useViews, middlewares, HomeController)
├── src/
│   ├── Controllers/            HomeController.php included as a starting point
│   ├── Repositories/           empty, ready for make:repository
│   ├── DTOs/                   empty, ready for make:dto
│   ├── Middleware/              empty, ready for make:middleware
│   ├── Guards/                  empty, ready for make:guard
│   └── Services/                empty, ready for make:service
├── database/migrations/         a starter create_users_table migration
├── config/database.php          SQLite by default, MySQL alternative commented out
├── views/home.php                sample view using {{ }} interpolation
├── .env / .env.example            APP_*/DB_* variables
├── .gitignore
├── composer.json                  only created if one doesn't already exist
└── README.md                      documents the structure and CLI commands
```

`init` is safe to re-run: any file that already exists is skipped (never
overwritten), so you can use it to fill in missing pieces of a partially
set up project without losing existing work.

### Generate Code

```bash
vendor/bin/mikro-migrate make:controller <Name>   # src/Controllers/<Name>Controller.php
vendor/bin/mikro-migrate make:repository <Name>   # src/Repositories/<Name>Repository.php
vendor/bin/mikro-migrate make:dto <Name>          # src/DTOs/<Name>Dto.php
vendor/bin/mikro-migrate make:middleware <Name>   # src/Middleware/<Name>Middleware.php
vendor/bin/mikro-migrate make:guard <Name>        # src/Guards/<Name>Guard.php
vendor/bin/mikro-migrate make:service <Name>      # src/Services/<Name>Service.php
```

Each generator accepts names in `PascalCase`, `snake_case`, or `kebab-case`
(e.g. `make:controller product`, `make:controller Product`, and
`make:controller create-product` all resolve sensibly), and automatically
appends the matching suffix (`Controller`, `Repository`, ...) if you didn't
include it. Generated stubs use the `App\` namespace, matching the
`composer.json` created by `init` — adjust the namespace if your project
uses a different PSR-4 mapping.

Unlike `init`, these generators **refuse to overwrite** an existing file:
they print an error and exit with a non-zero status instead, so they're
safe to use in scripts.

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
composer exec mikro-migrate init
composer exec mikro-migrate make:controller Product
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
# 0. New project? Scaffold the whole structure first.
vendor/bin/mikro-migrate init
composer install

# 1. Create a new migration
vendor/bin/mikro-migrate make create_products_table

# 2. Edit the migration file
# database/migrations/2024_01_15_120000_create_products_table.php

# 3. Run the migration
vendor/bin/mikro-migrate migrate

# 4. Generate the matching repository, controller, and DTO
vendor/bin/mikro-migrate make:repository Product
vendor/bin/mikro-migrate make:controller Product
vendor/bin/mikro-migrate make:dto CreateProduct

# 5. Check migration status
vendor/bin/mikro-migrate status

# 6. If needed, rollback
vendor/bin/mikro-migrate rollback
```

## Troubleshooting

### Command not found

If `vendor/bin/mikro-migrate` is not found:

1. Make sure you ran `composer install`
2. Check that `vendor/bin/` exists
3. Try using the full path: `./vendor/bin/mikro-migrate`

### `Class "App\Controllers\..." does not exist` (ReflectionException)

This means Composer's autoloader doesn't know how to map the `App\` namespace to `src/`. It happens when `composer.json` already existed **before** running `init` (e.g. you ran `composer require mikro-api/mikro-api` first) — `init` merges the missing `autoload.psr-4`/`require` entries into it automatically, but Composer's autoloader files under `vendor/composer/` are only regenerated when you run `composer install`/`composer update`/`composer dump-autoload`, not just by editing `composer.json`. Fix:

```bash
composer dump-autoload
```

Then confirm `composer.json` has:
```json
"autoload": {
    "psr-4": {
        "App\\": "src/"
    }
}
```
and that the class file exists at the expected path (`src/Controllers/HomeController.php` for `App\Controllers\HomeController`) with a matching `namespace`/class name.

### Generated file already exists

`make:controller`, `make:repository`, `make:dto`, `make:middleware`,
`make:guard`, and `make:service` never overwrite an existing file — they
print `✗ Already exists, skipped: ...` and exit with a non-zero status.
Rename/move the existing file first, or edit it directly instead of
regenerating it.

### `init` didn't create something I expected

`init` also never overwrites existing files — if a file already exists at
the target path (even an empty one), it's skipped with a `- skipped
(already exists): ...` message. Delete or rename the conflicting file and
re-run `init` to have it generated.

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
