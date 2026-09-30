# CRUD API Example ("Mini Blog")

A complete "authors have many posts" blog API demonstrating the full
Repository + Migrations + Relations stack of MikroAPI, running against a
real, file-based SQLite database.

## Features Demonstrated

- ✅ Repository Pattern (`BaseRepository` CRUD: `create`, `update`, `delete`, `findById`, `findAll`...)
- ✅ Fluent Query Builder (`->query()->withTrashed()->whereNotNull(...)->orderBy(...)->get()`)
- ✅ Migrations with schema attributes (`#[Table]`, `#[Column]`, `#[PrimaryKey]`, `#[ForeignKey]`, `#[Unique]`, `#[Timestamps]`, `#[SoftDeletes]`)
- ✅ Relations with eager loading (`#[HasMany]` / `#[BelongsTo]` via `with()`, avoiding N+1 queries)
- ✅ Soft Deletes (`delete()` / `restore()` / trashed listing)
- ✅ Pagination (`paginate($page, $perPage)`)
- ✅ Database transactions (`Database::getInstance()->transaction(fn () => ...)`)
- ✅ DTO validation (`#[Required]`, `#[Optional]`, `#[IsString]`, `#[IsInt]`, `#[IsBool]`, `#[IsEmail]`, `#[MinLength]`, `#[MaxLength]`)
- ✅ Auto-migration on boot (no `mikro-migrate` CLI required)

## Running the Example

```bash
cd examples/crud-api
php -S localhost:8000 index.php
```

On the first request, MikroAPI will:

1. Create `database/blog.sqlite` (a real file, so data persists across requests).
2. Run the migrations in `database/migrations/` to create the `authors` and `posts` tables.
3. Seed 3 authors and 5 posts (one unpublished, one author with 3 posts) if the tables are empty.

Every subsequent request re-checks the migrations table (a cheap no-op once
up to date) and skips seeding once data exists, so it's safe to keep using
`php -S`, which restarts the script fresh on every request.

## API Endpoints

| Method | Path                     | Description                                              |
|--------|--------------------------|------------------------------------------------------------|
| GET    | `/api/authors`           | List all authors, eager-loading their posts (`with('posts')`) |
| GET    | `/api/authors/:id`       | Get one author with their posts, 404 if missing            |
| POST   | `/api/authors`           | Create an author (uses `create(..., reload: false)`)       |
| GET    | `/api/posts`             | Paginated posts, eager-loading their author (`with('author')`) |
| GET    | `/api/posts/trashed`     | List soft-deleted posts (`withTrashed()` + `whereNotNull`) |
| GET    | `/api/posts/:id`         | Get one post with its author, 404 if missing                |
| POST   | `/api/posts`             | Create a post inside a `transaction()`                     |
| PUT    | `/api/posts/:id`         | Partially update a post                                     |
| DELETE | `/api/posts/:id`         | Soft-delete a post                                           |
| POST   | `/api/posts/:id/restore` | Restore a soft-deleted post                                  |
| POST   | `/api/posts/:id/publish` | Convenience endpoint: sets `published = true`                |

## curl Examples

**List authors with their posts (eager loading):**

```bash
curl http://localhost:8000/api/authors
```

**Create an author:**

```bash
curl -X POST http://localhost:8000/api/authors \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Diego Fernández",
    "email": "diego@example.com"
  }'
```

**List posts, paginated:**

```bash
curl "http://localhost:8000/api/posts?page=1&per_page=5"
```

**Create a post:**

```bash
curl -X POST http://localhost:8000/api/posts \
  -H "Content-Type: application/json" \
  -d '{
    "author_id": 1,
    "title": "A Brand New Post",
    "body": "This post was created through the CRUD API example.",
    "published": true
  }'
```

**Update a post:**

```bash
curl -X PUT http://localhost:8000/api/posts/1 \
  -H "Content-Type: application/json" \
  -d '{
    "title": "An Updated Title"
  }'
```

**Soft-delete a post:**

```bash
curl -X DELETE http://localhost:8000/api/posts/1
```

**Restore a soft-deleted post:**

```bash
curl -X POST http://localhost:8000/api/posts/1/restore
```

**View trashed (soft-deleted) posts:**

```bash
curl http://localhost:8000/api/posts/trashed
```

## Database File

`database/blog.sqlite` is created automatically on first boot and is
**not** committed to the repository (see `database/.gitignore`). Delete it
at any time to reset the example back to its initial, freshly-seeded state
on the next request.
