<?php
// core/Repository/RelationLoader.php

namespace MikroApi\Repository;

use MikroApi\Attributes\Relation\BelongsTo;
use MikroApi\Attributes\Relation\BelongsToMany;
use MikroApi\Attributes\Relation\HasMany;
use MikroApi\Attributes\Relation\HasOne;
use MikroApi\Database\Database;

/**
 * Carga relaciones definidas con atributos en batch (evita el problema N+1).
 *
 * En lugar de hacer una query por cada registro, agrupa los IDs y hace
 * una sola query por relación:
 *
 *   // Sin RelationLoader: 1 + N queries
 *   foreach ($users as $user) {
 *       $user['posts'] = $postRepo->findBy('user_id', $user['id']); // N queries
 *   }
 *
 *   // Con RelationLoader: 1 + 1 queries
 *   $loader->load($users, 'posts'); // una sola query con WHERE user_id IN (1,2,3...)
 */
class RelationLoader
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Carga las relaciones solicitadas sobre un conjunto de registros.
     *
     * @param array  $records         Registros sobre los que cargar relaciones
     * @param array  $relations       Nombres de propiedades a cargar ['posts', 'roles', ...]
     * @param string $repositoryClass Clase del repositorio que define las relaciones
     */
    public function load(array $records, array $relations, string $repositoryClass): array
    {
        if (empty($records) || empty($relations)) {
            return $records;
        }

        $ref = new \ReflectionClass($repositoryClass);

        foreach ($relations as $relation) {
            [$immediate, $nested] = $this->parseNested($relation);

            $prop = $this->findProperty($ref, $immediate);
            if ($prop === null) continue;

            $records = $this->resolveRelation($records, $prop, $nested);
        }

        return $records;
    }

    /* ------------------------------------------------------------------ */
    /*  Resolución por tipo de relación                                     */
    /* ------------------------------------------------------------------ */

    private function resolveRelation(array $records, \ReflectionProperty $prop, ?string $nested): array
    {
        foreach ($prop->getAttributes(HasMany::class) as $attr) {
            return $this->loadHasMany($records, $attr->newInstance(), $prop->getName(), $nested);
        }
        foreach ($prop->getAttributes(HasOne::class) as $attr) {
            return $this->loadHasOne($records, $attr->newInstance(), $prop->getName(), $nested);
        }
        foreach ($prop->getAttributes(BelongsTo::class) as $attr) {
            return $this->loadBelongsTo($records, $attr->newInstance(), $prop->getName(), $nested);
        }
        foreach ($prop->getAttributes(BelongsToMany::class) as $attr) {
            return $this->loadBelongsToMany($records, $attr->newInstance(), $prop->getName(), $nested);
        }
        return $records;
    }

    // ── HasMany ────────────────────────────────────────────────────────────

    private function loadHasMany(array $records, HasMany $rel, string $propName, ?string $nested): array
    {
        $relRepo  = $this->makeRepo($rel->repository);
        $localKey = $rel->localKey ?? 'id';
        $key      = $rel->as ?? $propName;

        $localIds = $this->extractIds($records, $localKey);
        if (empty($localIds)) return $this->fillEmpty($records, $key, []);

        $related = $this->fetchWhereIn($relRepo->getTable(), $rel->foreignKey, $localIds);

        if ($nested !== null) {
            $related = $relRepo->loadWith($related, [$nested]);
        }

        $grouped = [];
        foreach ($related as $row) {
            $grouped[$row[$rel->foreignKey]][] = $row;
        }

        return \array_map(function ($record) use ($grouped, $localKey, $key) {
            $record[$key] = $grouped[$record[$localKey]] ?? [];
            return $record;
        }, $records);
    }

    // ── HasOne ─────────────────────────────────────────────────────────────

    private function loadHasOne(array $records, HasOne $rel, string $propName, ?string $nested): array
    {
        $relRepo  = $this->makeRepo($rel->repository);
        $localKey = $rel->localKey ?? 'id';
        $key      = $rel->as ?? $propName;

        $localIds = $this->extractIds($records, $localKey);
        if (empty($localIds)) return $this->fillEmpty($records, $key, null);

        $related = $this->fetchWhereIn($relRepo->getTable(), $rel->foreignKey, $localIds);

        if ($nested !== null) {
            $related = $relRepo->loadWith($related, [$nested]);
        }

        $indexed = [];
        foreach ($related as $row) {
            $indexed[$row[$rel->foreignKey]] ??= $row;
        }

        return \array_map(function ($record) use ($indexed, $localKey, $key) {
            $record[$key] = $indexed[$record[$localKey]] ?? null;
            return $record;
        }, $records);
    }

    // ── BelongsTo ──────────────────────────────────────────────────────────

    private function loadBelongsTo(array $records, BelongsTo $rel, string $propName, ?string $nested): array
    {
        $relRepo  = $this->makeRepo($rel->repository);
        $ownerKey = $rel->ownerKey ?? $relRepo->getPrimaryKey();
        $key      = $rel->as ?? $propName;

        $foreignIds = $this->extractIds($records, $rel->foreignKey);
        if (empty($foreignIds)) return $this->fillEmpty($records, $key, null);

        $related = $this->fetchWhereIn($relRepo->getTable(), $ownerKey, $foreignIds);

        if ($nested !== null) {
            $related = $relRepo->loadWith($related, [$nested]);
        }

        $indexed = [];
        foreach ($related as $row) {
            $indexed[$row[$ownerKey]] = $row;
        }

        return \array_map(function ($record) use ($indexed, $rel, $key) {
            $record[$key] = $indexed[$record[$rel->foreignKey]] ?? null;
            return $record;
        }, $records);
    }

    // ── BelongsToMany ──────────────────────────────────────────────────────

    private function loadBelongsToMany(array $records, BelongsToMany $rel, string $propName, ?string $nested): array
    {
        $relRepo   = $this->makeRepo($rel->repository);
        $localKey  = $rel->localKey  ?? 'id';
        $relatedPk = $rel->relatedPk ?? $relRepo->getPrimaryKey();
        $key       = $rel->as ?? $propName;

        $localIds = $this->extractIds($records, $localKey);
        if (empty($localIds)) return $this->fillEmpty($records, $key, []);

        $relatedTable = $relRepo->getTable();
        $pivotTable   = $rel->pivotTable;

        $extraCols = '';
        if (!empty($rel->pivotColumns)) {
            $cols      = \implode(', ', \array_map(
                fn($c) => "`{$pivotTable}`.`{$c}` as `pivot_{$c}`",
                $rel->pivotColumns
            ));
            $extraCols = ", {$cols}";
        }

        $holders = \implode(', ', \array_fill(0, \count($localIds), '?'));
        $sql = "
            SELECT `{$relatedTable}`.*{$extraCols},
                   `{$pivotTable}`.`{$rel->foreignKey}` as `_pivot_fk`
            FROM `{$relatedTable}`
            INNER JOIN `{$pivotTable}`
                ON `{$pivotTable}`.`{$rel->relatedKey}` = `{$relatedTable}`.`{$relatedPk}`
            WHERE `{$pivotTable}`.`{$rel->foreignKey}` IN ({$holders})
        ";

        $related = $this->db->query($sql, $localIds);

        if ($nested !== null) {
            $related = $relRepo->loadWith($related, [$nested]);
        }

        $grouped = [];
        foreach ($related as $row) {
            $fk = $row['_pivot_fk'];
            unset($row['_pivot_fk']);
            $grouped[$fk][] = $row;
        }

        return \array_map(function ($record) use ($grouped, $localKey, $key) {
            $record[$key] = $grouped[$record[$localKey]] ?? [];
            return $record;
        }, $records);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Instancia un repositorio pasándole la misma conexión DB.
     * Evita que cada repo abra su propia conexión.
     */
    private function makeRepo(string $repositoryClass): BaseRepository
    {
        return new $repositoryClass($this->db);
    }

    private function fetchWhereIn(string $table, string $column, array $ids): array
    {
        $holders = \implode(', ', \array_fill(0, \count($ids), '?'));
        return $this->db->query(
            "SELECT * FROM `{$table}` WHERE `{$column}` IN ({$holders})",
            $ids
        );
    }

    private function extractIds(array $records, string $key): array
    {
        return \array_values(\array_unique(\array_filter(\array_column($records, $key))));
    }

    private function fillEmpty(array $records, string $key, mixed $empty): array
    {
        return \array_map(fn($r) => \array_merge($r, [$key => $empty]), $records);
    }

    private function parseNested(string $relation): array
    {
        $parts = \explode('.', $relation, 2);
        return [$parts[0], $parts[1] ?? null];
    }

    private function findProperty(\ReflectionClass $ref, string $name): ?\ReflectionProperty
    {
        try {
            return $ref->getProperty($name);
        } catch (\ReflectionException) {
            return null;
        }
    }
}
