<?php

namespace MikroApi\Tests;

use MikroApi\Database\Database;
use MikroApi\Repository\BaseRepository;
use MikroApi\Attributes\Relation\HasMany;
use MikroApi\Attributes\Relation\BelongsTo;
use PHPUnit\Framework\TestCase;

class BaseRepositoryTest extends TestCase
{
    private Database $db;
    private TestUserRepository $userRepo;
    private TestPostRepository $postRepo;

    protected function setUp(): void
    {
        // Resetear el singleton para cada test
        $reflection = new \ReflectionClass(Database::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);

        $this->db = Database::connect([
            'driver' => 'sqlite',
            'database' => ':memory:'
        ]);

        // Crear tablas
        $this->db->execute("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL
            )
        ");

        $this->db->execute("
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                content TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");

        $this->userRepo = new TestUserRepository($this->db);
        $this->postRepo = new TestPostRepository($this->db);
    }

    public function testCreate(): void
    {
        $user = $this->userRepo->create([
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ]);

        $this->assertIsArray($user);
        $this->assertEquals('John Doe', $user['name']);
        $this->assertArrayHasKey('id', $user);
    }

    public function testFindById(): void
    {
        $created = $this->userRepo->create([
            'name' => 'Jane Smith',
            'email' => 'jane@example.com'
        ]);

        $found = $this->userRepo->findById($created['id']);

        $this->assertNotNull($found);
        $this->assertEquals('Jane Smith', $found['name']);
    }

    public function testFindByIdReturnsNull(): void
    {
        $found = $this->userRepo->findById(999);

        $this->assertNull($found);
    }

    public function testFindAll(): void
    {
        $this->userRepo->create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $this->userRepo->create(['name' => 'User 2', 'email' => 'user2@example.com']);
        $this->userRepo->create(['name' => 'User 3', 'email' => 'user3@example.com']);

        $users = $this->userRepo->findAll();

        $this->assertCount(3, $users);
    }

    public function testFindBy(): void
    {
        $this->userRepo->create(['name' => 'John', 'email' => 'john@example.com']);
        $this->userRepo->create(['name' => 'Jane', 'email' => 'jane@example.com']);
        $this->userRepo->create(['name' => 'John', 'email' => 'john2@example.com']);

        $users = $this->userRepo->findBy('name', 'John');

        $this->assertCount(2, $users);
    }

    public function testFindOneBy(): void
    {
        $this->userRepo->create(['name' => 'John', 'email' => 'john@example.com']);

        $user = $this->userRepo->findOneBy('email', 'john@example.com');

        $this->assertNotNull($user);
        $this->assertEquals('John', $user['name']);
    }

    public function testUpdate(): void
    {
        $user = $this->userRepo->create([
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ]);

        $updated = $this->userRepo->update($user['id'], [
            'name' => 'John Updated'
        ]);

        $this->assertEquals('John Updated', $updated['name']);
        $this->assertEquals('john@example.com', $updated['email']); // Sin cambios
    }

    public function testDelete(): void
    {
        $user = $this->userRepo->create([
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ]);

        $deleted = $this->userRepo->delete($user['id']);

        $this->assertTrue($deleted);
        $this->assertNull($this->userRepo->findById($user['id']));
    }

    public function testCount(): void
    {
        $this->userRepo->create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $this->userRepo->create(['name' => 'User 2', 'email' => 'user2@example.com']);

        $count = $this->userRepo->count();

        $this->assertEquals(2, $count);
    }

    public function testCountWithConditions(): void
    {
        $this->userRepo->create(['name' => 'John', 'email' => 'john1@example.com']);
        $this->userRepo->create(['name' => 'Jane', 'email' => 'jane@example.com']);
        $this->userRepo->create(['name' => 'John', 'email' => 'john2@example.com']);

        $count = $this->userRepo->count(['name' => 'John']);

        $this->assertEquals(2, $count);
    }

    public function testExists(): void
    {
        $this->userRepo->create(['name' => 'John', 'email' => 'john@example.com']);

        $exists = $this->userRepo->exists('email', 'john@example.com');
        $this->assertTrue($exists);

        $notExists = $this->userRepo->exists('email', 'fake@example.com');
        $this->assertFalse($notExists);
    }

    public function testExistsWithExclude(): void
    {
        $user = $this->userRepo->create(['name' => 'John', 'email' => 'john@example.com']);

        // Verificar que el email existe pero excluyendo el propio usuario
        $exists = $this->userRepo->exists('email', 'john@example.com', $user['id']);
        $this->assertFalse($exists);
    }

    public function testPaginate(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->userRepo->create([
                'name' => "User {$i}",
                'email' => "user{$i}@example.com"
            ]);
        }

        $result = $this->userRepo->paginate(page: 1, perPage: 3);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertCount(3, $result['data']);
        $this->assertEquals(10, $result['total']);
        $this->assertEquals(4, $result['last_page']);
    }

    public function testFillableFiltering(): void
    {
        $user = $this->userRepo->create([
            'name' => 'John',
            'email' => 'john@example.com',
            'id' => 999, // Debería ser ignorado
            'created_at' => '2020-01-01', // Debería ser ignorado
        ]);

        // El ID no debería ser 999 porque fillable lo filtra
        $this->assertNotEquals(999, $user['id']);
    }

    public function testQueryBuilder(): void
    {
        $this->userRepo->create(['name' => 'John', 'email' => 'john@example.com']);
        $this->userRepo->create(['name' => 'Jane', 'email' => 'jane@example.com']);

        $users = $this->userRepo->query()
            ->where('name', 'John')
            ->orderBy('email')
            ->get();

        $this->assertCount(1, $users);
        $this->assertEquals('John', $users[0]['name']);
    }

    public function testEagerLoadingHasMany(): void
    {
        $user = $this->userRepo->create(['name' => 'John', 'email' => 'john@example.com']);
        
        $this->postRepo->create(['user_id' => $user['id'], 'title' => 'Post 1', 'content' => 'Content 1']);
        $this->postRepo->create(['user_id' => $user['id'], 'title' => 'Post 2', 'content' => 'Content 2']);

        $users = $this->userRepo->with('posts')->findAll();

        $this->assertArrayHasKey('posts', $users[0]);
        $this->assertCount(2, $users[0]['posts']);
    }

    public function testEagerLoadingBelongsTo(): void
    {
        $user = $this->userRepo->create(['name' => 'John', 'email' => 'john@example.com']);
        $post = $this->postRepo->create(['user_id' => $user['id'], 'title' => 'Post 1', 'content' => 'Content']);

        $posts = $this->postRepo->with('user')->findAll();

        $this->assertArrayHasKey('user', $posts[0]);
        $this->assertEquals('John', $posts[0]['user']['name']);
    }

    public function testSoftDeletes(): void
    {
        $softDeleteRepo = new TestSoftDeleteRepository($this->db);
        
        $user = $softDeleteRepo->create(['name' => 'John', 'email' => 'john@example.com']);
        $softDeleteRepo->delete($user['id']);

        // No debería aparecer en findAll
        $users = $softDeleteRepo->findAll();
        $this->assertCount(0, $users);

        // Debería poder restaurarse
        $restored = $softDeleteRepo->restore($user['id']);
        $this->assertNotNull($restored);
        $this->assertNull($restored['deleted_at']);
    }
}

// ── Test Repositories ───────────────────────────────────────────────────

class TestUserRepository extends BaseRepository
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email'];

    #[HasMany(repository: TestPostRepository::class, foreignKey: 'user_id')]
    public array $posts;
}

class TestPostRepository extends BaseRepository
{
    protected string $table = 'posts';
    protected array $fillable = ['user_id', 'title', 'content'];

    #[BelongsTo(repository: TestUserRepository::class, foreignKey: 'user_id')]
    public array $user;
}

class TestSoftDeleteRepository extends BaseRepository
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email'];
    protected bool $useSoftDeletes = true;
}
