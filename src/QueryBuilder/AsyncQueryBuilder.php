<?php

namespace Rcalicdan\FiberAsync\QueryBuilder;

use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\Api\AsyncPDO;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * Async Query Builder for easy way to write asynchonous sql queries
 *
 * Usage:
 * AsyncDb::table('users')->select('id, name')->where('active', 1)->get()
 * AsyncDb::table('users')->find(1)
 * AsyncDb::table('users')->create(['name' => 'John', 'email' => 'john@example.com'])
 * AsyncDb::table('users')->where('id', 1)->update(['name' => 'Jane'])
 * AsyncDb::table('users')->where('id', 1)->delete()
 */
class AsyncQueryBuilder
{
    protected string $table = '';
    protected array $select = ['*'];
    protected array $joins = [];
    protected array $where = [];
    protected array $orWhere = [];
    protected array $whereIn = [];
    protected array $whereNotIn = [];
    protected array $whereBetween = [];
    protected array $whereNull = [];
    protected array $whereNotNull = [];
    protected array $groupBy = [];
    protected array $having = [];
    protected array $orderBy = [];
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected array $bindings = [];
    protected int $bindingIndex = 0;

    public function __construct(string $table = '')
    {
        if ($table) {
            $this->table = $table;
        }
    }

    /**
     * Set the table for the query
     */
    public function table(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    /**
     * Set the columns to select
     */
    public function select(string|array $columns = '*'): self
    {
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }
        $this->select = $columns;

        return $this;
    }

    /**
     * Add a join clause
     */
    public function join(string $table, string $condition, string $type = 'INNER'): self
    {
        $this->joins[] = [
            'type' => strtoupper($type),
            'table' => $table,
            'condition' => $condition,
        ];

        return $this;
    }

    /**
     * Add a left join clause
     */
    public function leftJoin(string $table, string $condition): self
    {
        return $this->join($table, $condition, 'LEFT');
    }

    /**
     * Add a right join clause
     */
    public function rightJoin(string $table, string $condition): self
    {
        return $this->join($table, $condition, 'RIGHT');
    }

    /**
     * Add a where clause
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $placeholder = $this->getPlaceholder();
        $this->where[] = "{$column} {$operator} {$placeholder}";
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add an or where clause
     */
    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $placeholder = $this->getPlaceholder();
        $this->orWhere[] = "{$column} {$operator} {$placeholder}";
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add a where in clause
     */
    public function whereIn(string $column, array $values): self
    {
        $placeholders = [];
        foreach ($values as $value) {
            $placeholders[] = $this->getPlaceholder();
            $this->bindings[] = $value;
        }
        $this->whereIn[] = "{$column} IN (".implode(', ', $placeholders).')';

        return $this;
    }

    /**
     * Add a where not in clause
     */
    public function whereNotIn(string $column, array $values): self
    {
        $placeholders = [];
        foreach ($values as $value) {
            $placeholders[] = $this->getPlaceholder();
            $this->bindings[] = $value;
        }
        $this->whereNotIn[] = "{$column} NOT IN (".implode(', ', $placeholders).')';

        return $this;
    }

    /**
     * Add a where between clause
     */
    public function whereBetween(string $column, array $values): self
    {
        if (count($values) !== 2) {
            throw new \InvalidArgumentException('whereBetween requires exactly 2 values');
        }
        $placeholder1 = $this->getPlaceholder();
        $placeholder2 = $this->getPlaceholder();
        $this->whereBetween[] = "{$column} BETWEEN {$placeholder1} AND {$placeholder2}";
        $this->bindings[] = $values[0];
        $this->bindings[] = $values[1];

        return $this;
    }

    /**
     * Add a where null clause
     */
    public function whereNull(string $column): self
    {
        $this->whereNull[] = "{$column} IS NULL";

        return $this;
    }

    /**
     * Add a where not null clause
     */
    public function whereNotNull(string $column): self
    {
        $this->whereNotNull[] = "{$column} IS NOT NULL";

        return $this;
    }

    /**
     * Add a like clause
     */
    public function like(string $column, string $value, string $side = 'both'): self
    {
        $placeholder = $this->getPlaceholder();
        $this->where[] = "{$column} LIKE {$placeholder}";

        $likeValue = match ($side) {
            'before' => "%{$value}",
            'after' => "{$value}%",
            'both' => "%{$value}%",
            default => $value
        };

        $this->bindings[] = $likeValue;

        return $this;
    }

    /**
     * Add a group by clause
     */
    public function groupBy(string|array $columns): self
    {
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }
        $this->groupBy = array_merge($this->groupBy, $columns);

        return $this;
    }

    /**
     * Add a having clause
     */
    public function having(string $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $placeholder = $this->getPlaceholder();
        $this->having[] = "{$column} {$operator} {$placeholder}";
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add an order by clause
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = "{$column} ".strtoupper($direction);

        return $this;
    }

    /**
     * Set the limit
     */
    public function limit(int $limit, ?int $offset = null): self
    {
        $this->limit = $limit;
        if ($offset !== null) {
            $this->offset = $offset;
        }

        return $this;
    }

    /**
     * Set the offset
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;

        return $this;
    }

    /**
     * Execute the query and return all results
     */
    public function get(): PromiseInterface
    {
        $sql = $this->buildSelectQuery();

        return AsyncPDO::query($sql, $this->bindings);
    }

    /**
     * Get the first result
     */
    public function first(): PromiseInterface
    {
        $originalLimit = $this->limit;
        $this->limit = 1;
        $sql = $this->buildSelectQuery();
        $this->limit = $originalLimit;

        return AsyncPDO::fetchOne($sql, $this->bindings);
    }

    /**
     * Find a record by ID
     */
    public function find(mixed $id, string $column = 'id'): PromiseInterface
    {
        return $this->where($column, $id)->first();
    }

    /**
     * Find a record by ID or fail
     */
    public function findOrFail(mixed $id, string $column = 'id'): PromiseInterface
    {
        return Async::async(function () use ($id, $column) {
            $result = await($this->find($id, $column));
            if (! $result) {
                throw new \RuntimeException("Record not found with {$column} = {$id}");
            }

            return $result;
        })();
    }

    /**
     * Get a single value
     */
    public function value(string $column): PromiseInterface
    {
        return Async::async(function () use ($column) {
            $result = await($this->select($column)->first());

            return $result ? $result[$column] : null;
        })();
    }

    /**
     * Count records
     */
    public function count(string $column = '*'): PromiseInterface
    {
        $sql = $this->buildCountQuery($column);

        return AsyncPDO::fetchValue($sql, $this->bindings);
    }

    /**
     * Check if records exist
     */
    public function exists(): PromiseInterface
    {
        return Async::async(function () {
            $count = await($this->count());

            return $count > 0;
        })();
    }

    /**
     * Insert a single record
     */
    public function insert(array $data): PromiseInterface
    {
        $sql = $this->buildInsertQuery($data);

        return AsyncPDO::execute($sql, array_values($data));
    }

    /**
     * Insert multiple records
     */
    public function insertBatch(array $data): PromiseInterface
    {
        if (empty($data)) {
            return Promise::resolve(0);
        }

        $sql = $this->buildInsertBatchQuery($data);
        $bindings = [];
        foreach ($data as $row) {
            $bindings = array_merge($bindings, array_values($row));
        }

        return AsyncPDO::execute($sql, $bindings);
    }

    /**
     * Create a new record (alias for insert)
     */
    public function create(array $data): PromiseInterface
    {
        return $this->insert($data);
    }

    /**
     * Update records
     */
    public function update(array $data): PromiseInterface
    {
        $sql = $this->buildUpdateQuery($data);
        $bindings = array_merge(array_values($data), $this->bindings);

        return AsyncPDO::execute($sql, $bindings);
    }

    /**
     * Delete records
     */
    public function delete(): PromiseInterface
    {
        $sql = $this->buildDeleteQuery();

        return AsyncPDO::execute($sql, $this->bindings);
    }

    /**
     * Execute a raw query
     */
    public function raw(string $sql, array $bindings = []): PromiseInterface
    {
        return AsyncPDO::query($sql, $bindings);
    }

    /**
     * Execute a raw query and return first result
     */
    public function rawFirst(string $sql, array $bindings = []): PromiseInterface
    {
        return AsyncPDO::fetchOne($sql, $bindings);
    }

    /**
     * Execute a raw query and return single value
     */
    public function rawValue(string $sql, array $bindings = []): PromiseInterface
    {
        return AsyncPDO::fetchValue($sql, $bindings);
    }

    /**
     * Build the SELECT query
     */
    protected function buildSelectQuery(): string
    {
        $sql = 'SELECT '.implode(', ', $this->select);
        $sql .= ' FROM '.$this->table;

        // Add joins
        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['condition']}";
        }

        // Add where clauses
        $sql .= $this->buildWhereClause();

        // Add group by
        if (! empty($this->groupBy)) {
            $sql .= ' GROUP BY '.implode(', ', $this->groupBy);
        }

        // Add having
        if (! empty($this->having)) {
            $sql .= ' HAVING '.implode(' AND ', $this->having);
        }

        // Add order by
        if (! empty($this->orderBy)) {
            $sql .= ' ORDER BY '.implode(', ', $this->orderBy);
        }

        // Add limit and offset
        if ($this->limit !== null) {
            $sql .= ' LIMIT '.$this->limit;
            if ($this->offset !== null) {
                $sql .= ' OFFSET '.$this->offset;
            }
        }

        return $sql;
    }

    /**
     * Build the COUNT query
     */
    protected function buildCountQuery(string $column = '*'): string
    {
        $sql = "SELECT COUNT({$column}) FROM ".$this->table;

        // Add joins
        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['condition']}";
        }

        // Add where clauses
        $sql .= $this->buildWhereClause();

        // Add group by
        if (! empty($this->groupBy)) {
            $sql .= ' GROUP BY '.implode(', ', $this->groupBy);
        }

        // Add having
        if (! empty($this->having)) {
            $sql .= ' HAVING '.implode(' AND ', $this->having);
        }

        return $sql;
    }

    /**
     * Build the INSERT query
     */
    protected function buildInsertQuery(array $data): string
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        return "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
    }

    /**
     * Build the INSERT BATCH query
     */
    protected function buildInsertBatchQuery(array $data): string
    {
        $firstRow = $data[0];
        $columns = implode(', ', array_keys($firstRow));
        $placeholders = '('.implode(', ', array_fill(0, count($firstRow), '?')).')';
        $allPlaceholders = implode(', ', array_fill(0, count($data), $placeholders));

        return "INSERT INTO {$this->table} ({$columns}) VALUES {$allPlaceholders}";
    }

    /**
     * Build the UPDATE query
     */
    protected function buildUpdateQuery(array $data): string
    {
        $setClauses = [];
        foreach (array_keys($data) as $column) {
            $setClauses[] = "{$column} = ?";
        }
        $sql = "UPDATE {$this->table} SET ".implode(', ', $setClauses);
        $sql .= $this->buildWhereClause();

        return $sql;
    }

    /**
     * Build the DELETE query
     */
    protected function buildDeleteQuery(): string
    {
        $sql = "DELETE FROM {$this->table}";
        $sql .= $this->buildWhereClause();

        return $sql;
    }

    /**
     * Build the WHERE clause
     */
    protected function buildWhereClause(): string
    {
        $conditions = array_merge(
            $this->where,
            $this->whereIn,
            $this->whereNotIn,
            $this->whereBetween,
            $this->whereNull,
            $this->whereNotNull
        );

        if (empty($conditions) && empty($this->orWhere)) {
            return '';
        }

        $sql = ' WHERE ';

        if (! empty($conditions)) {
            $sql .= implode(' AND ', $conditions);
        }

        if (! empty($this->orWhere)) {
            if (! empty($conditions)) {
                $sql .= ' OR ';
            }
            $sql .= implode(' OR ', $this->orWhere);
        }

        return $sql;
    }

    /**
     * Generate a unique placeholder
     */
    protected function getPlaceholder(): string
    {
        return '?';
    }
}
