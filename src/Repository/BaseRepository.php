<?php
// core/Repository/BaseRepository.php

namespace MikroApi\Repository;

use MikroApi\Database\Database;

/**
 * Repositorio base con CRUD genérico, QueryBuilder fluent y soporte de relaciones.
 *
 * Para definir relaciones, agrega propiedades con atributos en la subclase:
 *
 *   use MikroApi\Attributes\Relation\HasMany;
 *   use MikroApi\Attributes\Relation\BelongsTo;
 *
 *   class UserRepository extends BaseRepository
 *   {
 *       protected string $table    = 'users';
 *       protected array  $fillable = ['name', 'email', 'password', 'role'];
 *
 *       #[HasMany(repository: PostRepository::class, foreignKey: 'user_id')]
 *       public array $posts;
 *
 *       #[HasOne(repository: ProfileRepository::class, foreignKey: 'user_id')]
 *       public array $profile;
 *   }
 *
 * Luego en el servicio:
 *   $repo->with('posts')->findAll();
 *   $repo->with('posts', 'profile')->findById(1);
 *   $repo->with('posts.comments')->findAll();  // relaciones anidadas
 */
abstract class BaseRepository implements RepositoryInterface
{
    protected Database $db;
    protected string   $table;
    protected string   $primaryKey       = 'id';
    protected string   $softDeleteColumn = 'deleted_at';
    protected bool     $useSoftDeletes   = false;
    protected bool     $useTimestamps    = true;
    protected array    $fillable         = [];

    /** Relaciones a cargar en la próxima query */
    private array $eagerLoad = [];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /* ------------------------------------------------------------------ */
    /*  Eager loading                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Indica qué relaciones cargar en la próxima operación de lectura.
     * Soporta dot notation para relaciones anidadas: 'posts.comments'
     *
     * @return static Retorna una COPIA para no mutar el repositorio original
     */
    public function with(string ...$relations): static
    {
        $clone             = clone $this;
        $clone->eagerLoad  = $relations;
        return $clone;
    }

    /**
     * Carga relaciones sobre un conjunto de registros ya obtenidos.
     * Útil para cuando tienes los datos y quieres hidratar relaciones a posteriori.
     */
    public function loadWith(array $records, array $relations): array
    {
        if (empty($records) || empty($relations)) return $records;

        $loader = new RelationLoader($this->db);
        return $loader->load($records, $relations, static::class);
    }

    /* ------------------------------------------------------------------ */
    /*  QueryBuilder fluent                                                 */
    /* ------------------------------------------------------------------ */

    public function query(): QueryBuilder
    {
        return new QueryBuilder(
            db:               $this->db,
            table:            $this->table,
            primaryKey:       $this->primaryKey,
            useSoftDeletes:   $this->useSoftDeletes,
            softDeleteColumn: $this->softDeleteColumn,
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Lectura                                                             */
    /* ------------------------------------------------------------------ */

    public function findAll(): array
    {
        $records = $this->query()->get();
        return $this->hydrate($records);
    }

    public function findById(mixed $id): ?array
    {
        $record = $this->query()->where($this->primaryKey, $id)->first();
        if (!$record) return null;

        $results = $this->hydrate([$record]);
        return $results[0] ?? null;
    }

    public function findBy(string $column, mixed $value): array
    {
        $records = $this->query()->where($column, $value)->get();
        return $this->hydrate($records);
    }

    public function findOneBy(string $column, mixed $value): ?array
    {
        $record = $this->query()->where($column, $value)->first();
        if (!$record) return null;

        $results = $this->hydrate([$record]);
        return $results[0] ?? null;
    }

    public function findWhere(array $conditions): array
    {
        $qb = $this->query();
        foreach ($conditions as $column => $value) {
            $qb->where($column, $value);
        }
        $records = $qb->get();
        return $this->hydrate($records);
    }

    public function count(array $conditions = []): int
    {
        $qb = $this->query();
        foreach ($conditions as $column => $value) {
            $qb->where($column, $value);
        }
        return $qb->count();
    }

    public function exists(string $column, mixed $value, mixed $excludeId = null): bool
    {
        $qb = $this->query()->where($column, $value);
        if ($excludeId !== null) {
            $qb->where($this->primaryKey, $excludeId, '!=');
        }
        return $qb->exists();
    }

    public function paginate(int $page, int $perPage = 15, array $conditions = []): array
    {
        $qb = $this->query();
        foreach ($conditions as $column => $value) {
            $qb->where($column, $value);
        }
        $result          = $qb->paginate($page, $perPage);
        $result['data']  = $this->hydrate($result['data']);
        return $result;
    }

    /* ------------------------------------------------------------------ */
    /*  Escritura                                                           */
    /* ------------------------------------------------------------------ */

    public function create(array $data): array
    {
        $data    = $this->filterColumns($data);
        $columns = \implode('`, `', \array_keys($data));
        $holders = \implode(', ', \array_fill(0, \count($data), '?'));

        $stmt = $this->db->getPdo()->prepare(
            "INSERT INTO `{$this->table}` (`{$columns}`) VALUES ({$holders})"
        );
        $stmt->execute(\array_values($data));

        $id = $this->db->lastInsertId();
        return $this->findById($id) ?? \array_merge([$this->primaryKey => $id], $data);
    }

    public function update(mixed $id, array $data): ?array
    {
        $data = $this->filterColumns($data);
        if (empty($data)) return $this->findById($id);
        if ($this->useTimestamps) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        $set    = \implode(', ', \array_map(fn($c) => "`{$c}` = ?", \array_keys($data)));
        $params = \array_merge(\array_values($data), [$id]);

        $stmt = $this->db->getPdo()->prepare(
            "UPDATE `{$this->table}` SET {$set} WHERE `{$this->primaryKey}` = ?"
        );
        $stmt->execute($params);

        return $this->findById($id);
    }

    public function delete(mixed $id): bool
    {
        if ($this->useSoftDeletes) {
            return $this->softDelete($id);
        }
        $stmt = $this->db->getPdo()->prepare(
            "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = ?"
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function softDelete(mixed $id): bool
    {
        $stmt = $this->db->getPdo()->prepare(
            "UPDATE `{$this->table}` SET `{$this->softDeleteColumn}` = CURRENT_TIMESTAMP WHERE `{$this->primaryKey}` = ?"
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function restore(mixed $id): ?array
    {
        $stmt = $this->db->getPdo()->prepare(
            "UPDATE `{$this->table}` SET `{$this->softDeleteColumn}` = NULL WHERE `{$this->primaryKey}` = ?"
        );
        $stmt->execute([$id]);
        return $this->findById($id);
    }

    /* ------------------------------------------------------------------ */
    /*  Queries raw                                                         */
    /* ------------------------------------------------------------------ */

    protected function raw(string $sql, array $params = []): array
    {
        return $this->db->query($sql, $params);
    }

    protected function rawOne(string $sql, array $params = []): ?array
    {
        return $this->db->queryOne($sql, $params);
    }

    /* ------------------------------------------------------------------ */
    /*  Acceso interno (usado por RelationLoader)                          */
    /* ------------------------------------------------------------------ */

    public function getTable(): string
    {
        return $this->table;
    }

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    public function usesSoftDeletes(): bool
    {
        return $this->useSoftDeletes;
    }

    public function getSoftDeleteColumn(): string
    {
        return $this->softDeleteColumn;
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers privados                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Aplica eager loading si hay relaciones pendientes y limpia la lista.
     */
    private function hydrate(array $records): array
    {
        if (empty($this->eagerLoad) || empty($records)) {
            return $records;
        }

        $relations       = $this->eagerLoad;
        $this->eagerLoad = []; // limpiar para no reutilizar en la próxima llamada

        $loader = new RelationLoader($this->db);
        return $loader->load($records, $relations, static::class);
    }

    protected function filterColumns(array $data): array
    {
        if (!empty($this->fillable)) {
            return \array_intersect_key($data, \array_flip($this->fillable));
        }
        unset($data[$this->primaryKey], $data['created_at'], $data['updated_at'], $data['deleted_at']);
        return $data;
    }
}
