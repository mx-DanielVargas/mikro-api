<?php
/**
 * MikroAPI - CRUD API Example ("Mini Blog")
 *
 * A complete "authors have many posts" blog API that demonstrates the
 * Repository Pattern, Migrations (schema attributes), Relations
 * (HasMany / BelongsTo with eager loading), Soft Deletes, the fluent
 * QueryBuilder, pagination, DTO validation, and database transactions.
 *
 * It runs against a real, file-based SQLite database that is
 * auto-migrated and auto-seeded on first boot -- no CLI required.
 *
 * To run:
 *   cd examples/crud-api
 *   php -S localhost:8000 index.php
 *
 * See README.md in this directory for the full endpoint list and
 * curl examples.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MikroApi\App;
use MikroApi\Request;
use MikroApi\Response;
use MikroApi\RequestDto;
use MikroApi\Database\Database;
use MikroApi\Database\MigrationRunner;
use MikroApi\Repository\BaseRepository;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Route;
use MikroApi\Attributes\Body;
use MikroApi\Attributes\Relation\HasMany;
use MikroApi\Attributes\Relation\BelongsTo;
use MikroApi\Attributes\Validation\Required;
use MikroApi\Attributes\Validation\Optional;
use MikroApi\Attributes\Validation\IsString;
use MikroApi\Attributes\Validation\IsInt;
use MikroApi\Attributes\Validation\IsBool;
use MikroApi\Attributes\Validation\IsEmail;
use MikroApi\Attributes\Validation\MinLength;
use MikroApi\Attributes\Validation\MaxLength;

// ────────────────────────────────────────────────────────────────────────────
// Database connection (file-based SQLite so data survives across requests)
// ────────────────────────────────────────────────────────────────────────────

$db = Database::connect([
    'driver'   => 'sqlite',
    'database' => __DIR__ . '/database/blog.sqlite',
]);

// ────────────────────────────────────────────────────────────────────────────
// Auto-migration
//
// MigrationRunner tracks executed migrations in a `migrations` table, so
// calling migrate() on every request (as php -S does, since it boots this
// script fresh per request) is safe and cheap once the schema is up to
// date -- it just checks the tracking table and does nothing.
// ────────────────────────────────────────────────────────────────────────────

$runner = new MigrationRunner(
    db: $db,
    migrationsPath: __DIR__ . '/database/migrations',
    migrationsNamespace: 'Database\\Migrations',
);
$runner->migrate();

// ────────────────────────────────────────────────────────────────────────────
// DTOs
//
// Validation attributes are applied per-property. A field with #[Optional]
// is only validated/assigned when present in the request body; otherwise
// the DTO property is left uninitialized and safely reads as null via `??`.
// ────────────────────────────────────────────────────────────────────────────

class CreateAuthorDto extends RequestDto
{
    #[Required]
    #[IsString]
    #[MinLength(2)]
    #[MaxLength(100)]
    public string $name;

    #[Required]
    #[IsEmail]
    #[MaxLength(150)]
    public string $email;
}

class CreatePostDto extends RequestDto
{
    #[Required]
    #[IsInt]
    public int $author_id;

    #[Required]
    #[IsString]
    #[MinLength(3)]
    #[MaxLength(200)]
    public string $title;

    #[Required]
    #[IsString]
    #[MinLength(10)]
    public string $body;

    #[Optional]
    #[IsBool]
    public bool $published;
}

class UpdatePostDto extends RequestDto
{
    #[Optional]
    #[IsString]
    #[MinLength(3)]
    #[MaxLength(200)]
    public ?string $title;

    #[Optional]
    #[IsString]
    #[MinLength(10)]
    public ?string $body;

    #[Optional]
    #[IsBool]
    public ?bool $published;
}

// ────────────────────────────────────────────────────────────────────────────
// Repositories
// ────────────────────────────────────────────────────────────────────────────

/**
 * AuthorRepository demonstrates:
 * - The Repository Pattern (CRUD via BaseRepository).
 * - $fillable to whitelist mass-assignable columns.
 * - #[HasMany] so $authorRepo->with('posts')->findAll() eager-loads every
 *   author's posts in a single extra query (no N+1).
 */
class AuthorRepository extends BaseRepository
{
    protected string $table    = 'authors';
    protected array  $fillable = ['name', 'email'];

    #[HasMany(repository: PostRepository::class, foreignKey: 'author_id')]
    public array $posts;
}

/**
 * PostRepository demonstrates:
 * - The Repository Pattern (CRUD via BaseRepository).
 * - $useSoftDeletes = true, so delete()/restore() manage `deleted_at`
 *   instead of physically removing rows.
 * - #[BelongsTo] so $postRepo->with('author')->findAll() eager-loads the
 *   owning author for every post in a single extra query.
 */
class PostRepository extends BaseRepository
{
    protected string $table          = 'posts';
    protected bool   $useSoftDeletes = true;
    protected array  $fillable       = ['author_id', 'title', 'body', 'published'];

    #[BelongsTo(repository: AuthorRepository::class, foreignKey: 'author_id')]
    public array $author;
}

// ────────────────────────────────────────────────────────────────────────────
// Seed data (only runs once -- guarded by a count() check)
// ────────────────────────────────────────────────────────────────────────────

$authorRepo = new AuthorRepository($db);
$postRepo   = new PostRepository($db);

if ($authorRepo->count() === 0) {
    $alice = $authorRepo->create(['name' => 'Alice Nakamura', 'email' => 'alice@example.com']);
    $bob   = $authorRepo->create(['name' => 'Bob García',     'email' => 'bob@example.com']);
    $carol = $authorRepo->create(['name' => 'Carol Studio',   'email' => 'carol@example.com']);

    // Alice gets three posts to showcase the HasMany relation with more
    // than one related record per author.
    $postRepo->create([
        'author_id' => $alice['id'],
        'title'     => 'Getting Started with MikroAPI',
        'body'      => 'A quick tour of routing, controllers, and DTOs.',
        'published' => 1,
    ]);
    $postRepo->create([
        'author_id' => $alice['id'],
        'title'     => 'Deep Dive into the Repository Pattern',
        'body'      => 'How BaseRepository, QueryBuilder, and relations fit together.',
        'published' => 1,
    ]);
    $postRepo->create([
        'author_id' => $alice['id'],
        'title'     => 'Draft: Upcoming Features',
        'body'      => 'Unpublished notes about what might ship next.',
        // Cast to int: PDO_SQLITE binds a literal PHP `false` as an empty
        // string rather than 0, which corrupts an INTEGER column. Casting
        // booleans to 0/1 before writing sidesteps that driver quirk.
        'published' => (int) false,
    ]);

    $postRepo->create([
        'author_id' => $bob['id'],
        'title'     => 'Migrations Without Downtime',
        'body'      => 'Strategies for evolving schemas safely in production.',
        'published' => 1,
    ]);

    $postRepo->create([
        'author_id' => $carol['id'],
        'title'     => 'Designing Clean APIs',
        'body'      => 'Principles for predictable, well-documented endpoints.',
        'published' => 1,
    ]);
}

// ────────────────────────────────────────────────────────────────────────────
// Controllers
// ────────────────────────────────────────────────────────────────────────────

#[Controller('/api/authors')]
class AuthorController
{
    private AuthorRepository $authors;

    public function __construct()
    {
        $this->authors = new AuthorRepository();
    }

    #[Route('GET', '/')]
    public function index(Request $req): Response
    {
        // Eager loading via with('posts') avoids the N+1 problem: this
        // issues one query for authors and one extra query for all their
        // posts (grouped in PHP), instead of one query per author.
        $authors = $this->authors->with('posts')->findAll();

        return Response::json([
            'data'  => $authors,
            'total' => count($authors),
        ]);
    }

    #[Route('GET', '/:id')]
    public function show(Request $req): Response
    {
        $author = $this->authors->with('posts')->findById($req->params['id']);

        if ($author === null) {
            return Response::error('Author not found', 404);
        }

        return Response::json(['data' => $author]);
    }

    #[Route('POST', '/')]
    #[Body(CreateAuthorDto::class)]
    public function create(Request $req): Response
    {
        $dto = $req->dto;

        // reload: false skips the extra SELECT that create() normally runs
        // after the INSERT to reflect DB-generated defaults/triggers. It's
        // a worthwhile trade-off on high-volume write paths where the
        // caller doesn't need created_at/updated_at back immediately --
        // notice the response below won't include them, unlike a
        // reload: true (the default) call would. Compare with
        // PostController::create(), which uses the default reload: true.
        $author = $this->authors->create([
            'name'  => $dto->name,
            'email' => $dto->email,
        ], reload: false);

        return Response::json(['data' => $author], 201);
    }
}

#[Controller('/api/posts')]
class PostController
{
    private PostRepository $posts;

    public function __construct()
    {
        $this->posts = new PostRepository();
    }

    #[Route('GET', '/')]
    public function index(Request $req): Response
    {
        // Pagination via the repository's paginate(), which wraps the
        // fluent QueryBuilder (count + LIMIT/OFFSET) and returns a
        // Laravel-style pagination envelope. with('author') eager-loads
        // the owning author for every post in the page (BelongsTo).
        $page    = max(1, (int) ($req->query['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($req->query['per_page'] ?? 10)));

        $result = $this->posts->with('author')->paginate($page, $perPage);

        return Response::json($result);
    }

    /**
     * IMPORTANT: this route MUST be declared before show() below.
     * Routes are matched in declaration order and `/:id` would otherwise
     * greedily match the literal path `/trashed` (treating "trashed" as
     * an id), returning a 404 instead of the trashed list.
     */
    #[Route('GET', '/trashed')]
    public function trashed(Request $req): Response
    {
        // withTrashed() disables the repository's default "hide
        // soft-deleted rows" filter, and whereNotNull('deleted_at') then
        // narrows the result down to ONLY the soft-deleted posts.
        $records = $this->posts->query()
            ->withTrashed()
            ->whereNotNull('deleted_at')
            ->orderBy('deleted_at', 'DESC')
            ->get();

        return Response::json([
            'data'  => $records,
            'total' => count($records),
        ]);
    }

    #[Route('GET', '/:id')]
    public function show(Request $req): Response
    {
        $post = $this->posts->with('author')->findById($req->params['id']);

        if ($post === null) {
            return Response::error('Post not found', 404);
        }

        return Response::json(['data' => $post]);
    }

    #[Route('POST', '/')]
    #[Body(CreatePostDto::class)]
    public function create(Request $req): Response
    {
        $dto = $req->dto;

        // A single INSERT doesn't strictly need a transaction -- it's
        // already atomic on its own. This is wrapped in
        // Database::transaction() purely to DEMONSTRATE the API. In a
        // real app, a transaction earns its keep when MULTIPLE related
        // writes must all succeed or all fail together (e.g. creating the
        // post AND incrementing a denormalized "post_count" on the author
        // in the same unit of work). If the callback throws, the
        // transaction rolls back automatically and the exception
        // propagates.
        $post = Database::getInstance()->transaction(function () use ($dto) {
            return $this->posts->create([
                'author_id' => $dto->author_id,
                'title'     => $dto->title,
                'body'      => $dto->body,
                // Cast to int: see the seed data comment above about the
                // PDO_SQLITE `false` -> empty string binding quirk.
                'published' => (int) ($dto->published ?? false),
            ]);
        });

        return Response::json(['data' => $post], 201);
    }

    #[Route('PUT', '/:id')]
    #[Body(UpdatePostDto::class)]
    public function update(Request $req): Response
    {
        $id = $req->params['id'];

        if ($this->posts->findById($id) === null) {
            return Response::error('Post not found', 404);
        }

        $dto  = $req->dto;
        $data = [];
        if (isset($dto->title))     $data['title']     = $dto->title;
        if (isset($dto->body))      $data['body']      = $dto->body;
        if (isset($dto->published)) $data['published'] = (int) $dto->published;

        $post = $this->posts->update($id, $data);

        return Response::json(['data' => $post]);
    }

    #[Route('DELETE', '/:id')]
    public function delete(Request $req): Response
    {
        $id = $req->params['id'];

        if ($this->posts->findById($id) === null) {
            return Response::error('Post not found', 404);
        }

        // Because PostRepository sets $useSoftDeletes = true, delete()
        // sets `deleted_at` instead of physically removing the row.
        $this->posts->delete($id);

        return Response::json([
            'message' => 'Post soft-deleted successfully',
            'id'      => (int) $id,
        ]);
    }

    #[Route('POST', '/:id/restore')]
    public function restore(Request $req): Response
    {
        $post = $this->posts->restore($req->params['id']);

        if ($post === null) {
            return Response::error('Post not found', 404);
        }

        return Response::json(['data' => $post]);
    }

    #[Route('POST', '/:id/publish')]
    public function publish(Request $req): Response
    {
        $id = $req->params['id'];

        if ($this->posts->findById($id) === null) {
            return Response::error('Post not found', 404);
        }

        $post = $this->posts->update($id, ['published' => 1]);

        return Response::json(['data' => $post]);
    }
}

// ────────────────────────────────────────────────────────────────────────────
// Application Setup
// ────────────────────────────────────────────────────────────────────────────

$app = new App();

$app->useController(
    AuthorController::class,
    PostController::class,
);

$app->run();
