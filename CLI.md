# MikroAPI CLI — `vendor/bin/mikro`

One command for everything the framework can generate or run: project scaffolding, code generators (modules, controllers, services, repositories, DTOs, guards, interceptors, filters, middleware, metadata attributes, migrations, views, tests, full CRUD resources), migrations, route listing, OpenAPI export, JWT secrets and a dev server.

```bash
vendor/bin/mikro list        # every command
vendor/bin/mikro --version
```

> Replaces the old `vendor/bin/mikro-migrate`. Its command names still work as aliases: `init` → `new`, `make <name>` → `make:migration`, `rollback`/`reset`/`status` → `migrate:*`.

## Quick start

```bash
composer require mikro-api/mikro-api
vendor/bin/mikro new                    # scaffold in the current directory (or: mikro new my-api)
composer dump-autoload                  # only if composer.json already existed
vendor/bin/mikro make:resource products # full CRUD module
vendor/bin/mikro migrate
vendor/bin/mikro serve                  # http://127.0.0.1:8000 — Swagger UI at /docs
```

## Commands

### Project

| Command | Description |
|---|---|
| `new [path]` (alias `init`) | Scaffold a modular project. Safe to re-run: never overwrites files. Merges `autoload.psr-4` / `require` into an existing `composer.json`. |
| `serve [--host=127.0.0.1] [--port=8000]` | PHP built-in server for `public/index.php`. |
| `key:generate [--force] [--show]` | Writes a random 64-hex `JWT_SECRET` to `.env`. Won't replace a real secret without `--force` (existing tokens stop working); a `change-me` placeholder is replaced. `--show` only prints a key. |

`new` creates:

```
├── public/index.php        App::create(ConfigModule::forRoot(...), AppModule::class), lazy Database
│                            binding, CORS + JSON body middlewares, Swagger at /docs
├── src/AppModule.php        Root module (generators add feature modules to its imports)
├── src/AppController.php    GET /
├── config/database.php      SQLite by default; MySQL and PostgreSQL examples
├── database/migrations/
├── views/home.php
├── tests/
├── .env / .env.example      APP_NAME, APP_ENV, JWT_SECRET (random in .env), JWT_TTL, DB_*
├── .gitignore, composer.json, README.md
```

### Generators

`make:<type> <name>` — or the short form `g <type> <name>` (also `generate`).

| Type | Output (without `--module`) | Notes |
|---|---|---|
| `resource` | `src/<Plural>/` | `<Plural>Module`, `<Plural>Controller` (CRUD with `#[Param]`/`#[Query]`/`#[Body]`), `<Plural>Service` (404 via `notFound()`), `<Plural>Repository`, `Dto/Create<Singular>Dto`, `Dto/Update<Singular>Dto`, migration `create_<plural>_table`. Registers the module in `src/AppModule.php`. |
| `module` | `src/<Name>/<Name>Module.php` | Registered in `AppModule` imports. |
| `controller` | `src/Controllers/<Name>Controller.php` | Route prefix `/<plural-kebab>`. |
| `service` | `src/Services/<Name>Service.php` | Extends `BaseService`. |
| `repository` | `src/Repositories/<Name>Repository.php` | Table `<plural_snake>`. |
| `dto` | `src/DTOs/<Name>Dto.php` | Names starting with `Update` get `#[Optional]` fields. |
| `guard` | `src/Guards/<Name>Guard.php` | With `Reflector` injected. |
| `interceptor` | `src/Interceptors/<Name>Interceptor.php` | |
| `filter` | `src/Filters/<Name>Filter.php` | `#[Catches(HttpException::class)]`. |
| `middleware` | `src/Middleware/<Name>Middleware.php` | |
| `attribute` | `src/Attributes/<Name>.php` | `SetMetadata` subclass with a `KEY` constant, read with `Reflector`. |
| `migration` | `database/migrations/<timestamp>_<name>.php` | `create_<x>_table` → table `<x>`. |
| `view` | `views/<name>.php` | Dots become folders: `emails.welcome` → `views/emails/welcome.php`. |
| `test` | `tests/<Name>Test.php` | PHPUnit test using `App::create(AppModule::class)->handle(Request::create(...))`. `--route=/path` sets the tested route. |

Options:

- `--module=<Name>`: write into `src/<Name>/` (namespace `App\<Name>`, DTOs in `Dto/`) and register the class in `src/<Name>/<Name>Module.php` — controllers in `controllers`, services and repositories in `providers`. The module must exist (`make:module` first); nothing is written otherwise.
- `--force` (`-f`): overwrite existing files. Without it, generators never overwrite and exit with code 1.

The root namespace is read from `composer.json` (the `psr-4` entry that maps to `src/`), `App\` by default. Names are normalized: `blog_post`, `blog-post` and `BlogPost` are equivalent; suffixes (`Controller`, `Service`...) are added if missing.

```bash
vendor/bin/mikro make:resource products
vendor/bin/mikro make:module Billing
vendor/bin/mikro g controller Invoice --module=Billing     # src/Billing/InvoiceController.php + registered
vendor/bin/mikro g repository Invoice --module=Billing     # providers
vendor/bin/mikro make:guard Admin
vendor/bin/mikro make:attribute Permissions                # #[Permissions('users:write')]
vendor/bin/mikro make:migration add_price_to_products
vendor/bin/mikro make:test Products --route=/products
```

### Database

| Command | Description |
|---|---|
| `migrate` | Run pending migrations. |
| `migrate:status` (alias `status`) | Executed / pending migrations. |
| `migrate:rollback` (alias `rollback`) | Roll back the last migration. |
| `migrate:reset` (alias `reset`) | Roll back every migration. |
| `migrate:fresh` | Reset + migrate. |

Reads `config/database.php` (after loading `.env`, so it can use `$_ENV`). Migrations live in `database/migrations/` with the `Database\Migrations` namespace and run in filename (timestamp) order. Works with SQLite, MySQL, PostgreSQL and Turso.

**Turso/libSQL**: `turso/libsql` needs PHP >= 8.3 and FFI with `ffi.enable=true` set explicitly (the default `"preload"` doesn't cover CLI). Otherwise you get `FFI API is restricted by "ffi.enable" configuration directive`; run `php -d ffi.enable=true vendor/bin/mikro migrate` or set it in your CLI `php.ini`.

### Routes & docs

| Command | Description |
|---|---|
| `route:list` | Table of every route (method, path, handler, guards/interceptors) declared by `#[Controller]` classes under `src/`. Global guards/interceptors registered in bootstrap aren't shown. |
| `route:clear [file]` | Deletes the compiled route cache (`App::cacheRoutes()`), default `cache/routes.php`. |
| `docs:export [file] [--title=] [--api-version=]` | Writes the OpenAPI 3 spec of the controllers under `src/` (default `openapi.json`; `-` prints to stdout). Title defaults to `APP_NAME`. |

## Troubleshooting

**`Class "App\..." does not exist`** — Composer doesn't map `App\` to `src/`. If `composer.json` existed before `new`, the CLI merged the mapping, but Composer only regenerates its autoloader on `composer dump-autoload` / `install` / `update`. Run `composer dump-autoload`.

**`Could not update src/...Module.php`** — the generator couldn't find the `#[Module(...)]` attribute or the list to edit (e.g. it was built dynamically). Add the printed class to the module manually.

**`config/database.php not found`** — create it (or run `mikro new`).

## CI/CD

```yaml
# GitHub Actions
- name: Run migrations
  run: vendor/bin/mikro migrate
```

## Best practices

1. Commit migration files; never edit a migration that already ran in production — create a new one.
2. Test `migrate:rollback` before deploying.
3. Use descriptive names: `create_users_table`, `add_email_to_users`.
4. Run `key:generate --force` per environment instead of sharing secrets.
