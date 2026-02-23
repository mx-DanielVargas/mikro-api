<?php
// core/Repository/QueryBuilder.php

namespace MikroApi\Repository;

use MikroApi\Database\Database;

/**
 * Constructor de queries SQL fluent.
 * Se obtiene llamando a $repository->query() desde un repositorio.
 *
 * Ejemplo:
 *   $repo->query()
 *        ->select('id', 'name', 'email')
 *        ->where('active', 1)
 *        ->where('role', 'admin')
 *        ->orWhere('role', 'moderator')
 *        ->orderBy('name')
 *        ->limit(10)
 *        ->offset(20)
 *        ->get();
 */
class QueryBuilder
{
    private Database $db;
    private string   $table;
    private string   $primaryKey;
    private bool     $useSoftDeletes;
    private string   $softDeleteColumn;

    private array  $selects  = [];
    private array  $joins    = [];
    private array  $wheres   = [];   // [['AND'|'OR', 'col', 'op', 'val']]
    private array  $params   = [];
    private array  $orders   = [];
    private ?int   $limitVal  = null;
    private ?int   $offsetVal = null;
    private bool   $withTrashed = false;

    public function __construct(
        Database $db,
        string $table,
        string $primaryKey,
        bool $useSoftDeletes,
        string $softDeleteColumn,
    ) {
        $this->db               = $db;
        $this->table            = $table;
        $this->primaryKey       = $primaryKey;
        $this->useSoftDeletes   = $useSoftDeletes;
        $this->softDeleteColumn = $softDeleteColumn;
    }

    /* ------------------------------------------------------------------ */
    /*  SELECT                                                              */
    /* ------------------------------------------------------------------ */

    public function select(string ...$columns): static
    {
        $this->selects = $columns;
        return $this;
    }

    /* ------------------------------------------------------------------ */
    /*  JOINs                                                               */
    /* ------------------------------------------------------------------ */

    public function join(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = "INNER JOIN `{$table}` ON `{$first}` {$operator} `{$second}`";
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = "LEFT JOIN `{$table}` ON `{$first}` {$operator} `{$second}`";
        return $this;
    }

    /* ------------------------------------------------------------------ */
    /*  WHERE                                                               */
    /* ------------------------------------------------------------------ */

    public function where(string $column, mixed $value, string $operator = '='): static
    {
        return $this->addWhere('AND', $column, $operator, $value);
    }

    public function orWhere(string $column, mixed $value, string $operator = '='): static
    {
        return $this->addWhere('OR', $column, $operator, $value);
    }

    public function whereNull(string $column): static
    {
        $this->wheres[] = ['AND', $column, 'IS NULL', null];
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->wheres[] = ['AND', $column, 'IS NOT NULL', null];
        return $this;
    }

    public function whereIn(string $column, array $values): static
    {
        if (empty($values)) return $this;
        $holders = \implode(', ', \array_fill(0, \count($values), '?'));
        $this->wheres[] = ['AND', $column, "IN ({$holders})", $values];
        return $this;
    }

    public function whereBetween(string $column, mixed $min, mixed $max): static
    {
        $this->wheres[]  = ['AND', $column, 'BETWEEN', [$min, $max]];
        return $this;
    }

    public function whereLike(string $column, string $pattern): static
    {
        return $this->addWhere('AND', $column, 'LIKE', $pattern);
    }

    /* ------------------------------------------------------------------ */
    /*  ORDER / LIMIT / OFFSET                                              */
    /* ------------------------------------------------------------------ */

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $direction      = \strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = "`{$column}` {$direction}";
        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limitVal = $limit;
        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offsetVal = $offset;
        return $this;
    }

    public function page(int $page, int $perPage = 15): static
    {
        return $this->limit($perPage)->offset(($page - 1) * $perPage);
    }

    public function withTrashed(): static
    {
        $this->withTrashed = true;
        return $this;
    }

    /* ------------------------------------------------------------------ */
    /*  Ejecución                                                           */
    /* ------------------------------------------------------------------ */

    public function get(): array
    {
        [$sql, $params] = $this->buildSelect();
        return $this->db->query($sql, $params);
    }

    public function first(): ?array
    {
        $this->limit(1);
        [$sql, $params] = $this->buildSelect();
        return $this->db->queryOne($sql, $params);
    }

    public function count(): int
    {
        [$sql, $params] = $this->buildSelect(count: true);
        $row = $this->db->queryOne($sql, $params);
        return (int) ($row['total'] ?? 0);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function paginate(int $page, int $perPage = 15): array
    {
        $total = $this->count();
        $items = $this->page($page, $perPage)->get();

        return [
            'data'         => $items,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int) \ceil($total / $perPage),
            'from'         => (($page - 1) * $perPage) + 1,
            'to'           => \min($page * $perPage, $total),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Build                                                               */
    /* ------------------------------------------------------------------ */

    private function buildSelect(bool $count = false): array
    {
        $params = [];

        // SELECT
        if ($count) {
            $select = "SELECT COUNT(*) as total";
        } elseif (!empty($this->selects)) {
            $cols   = \implode(', ', \array_map(
                fn($c) => \str_contains($c, '.') || \str_contains($c, '(') ? $c : "`{$c}`",
                $this->selects
            ));
            $select = "SELECT {$cols}";
        } else {
            $select = "SELECT `{$this->table}`.*";
        }

        $sql = "{$select} FROM `{$this->table}`";

        // JOINs
        if (!empty($this->joins)) {
            $sql .= ' ' . \implode(' ', $this->joins);
        }

        // WHERE
        $conditions = $this->wheres;

        if ($this->useSoftDeletes && !$this->withTrashed) {
            $conditions[] = ['AND', $this->softDeleteColumn, 'IS NULL', null];
        }

        if (!empty($conditions)) {
            $parts = [];
            foreach ($conditions as $i => [$bool, $col, $op, $val]) {
                $prefix = $i === 0 ? '' : "{$bool} ";
                if (\in_array($op, ['IS NULL', 'IS NOT NULL'])) {
                    $parts[] = "{$prefix}`{$col}` {$op}";
                } elseif (\str_starts_with($op, 'IN')) {
                    $parts[] = "{$prefix}`{$col}` {$op}";
                    $params  = \array_merge($params, $val);
                } elseif ($op === 'BETWEEN') {
                    $parts[]  = "{$prefix}`{$col}` BETWEEN ? AND ?";
                    $params[] = $val[0];
                    $params[] = $val[1];
                } else {
                    $parts[]  = "{$prefix}`{$col}` {$op} ?";
                    $params[] = $val;
                }
            }
            $sql .= ' WHERE ' . \implode(' ', $parts);
        }

        // ORDER BY
        if (!empty($this->orders) && !$count) {
            $sql .= ' ORDER BY ' . \implode(', ', $this->orders);
        }

        // LIMIT / OFFSET
        if ($this->limitVal !== null && !$count) {
            $sql .= " LIMIT {$this->limitVal}";
        }
        if ($this->offsetVal !== null && !$count) {
            $sql .= " OFFSET {$this->offsetVal}";
        }

        return [$sql, $params];
    }

    private function addWhere(string $bool, string $column, string $operator, mixed $value): static
    {
        $this->wheres[] = [$bool, $column, $operator, $value];
        return $this;
    }
}
