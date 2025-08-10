<?php

namespace Rcalicdan\FiberAsync\QueryBuilder;

use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\Api\AsyncPDO;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * Async Query Builder for easy way to write asynchronous SQL queries.
 *
 * Usage:
 * - AsyncDb::table('users')->select('id, name')->where('active', 1)->get()
 * - AsyncDb::table('users')->find(1)
 * - AsyncDb::table('users')->create(['name' => 'John', 'email' => 'john@example.com'])
 * - AsyncDb::table('users')->where('id', 1)->update(['name' => 'Jane'])
 * - AsyncDb::table('users')->where('id', 1)->delete()
 */
class AsyncQueryBuilder
{
    /**
     * @var string The table name for the query.
     */
    protected string $table = '';

    /**
     * @var array<string> The columns to select in the query.
     */
    protected array $select = ['*'];

    /**
     * @var array<array{type: string, table: string, condition: string}> The join clauses for the query.
     */
    protected array $joins = [];

    /**
     * @var array<string> The WHERE conditions for the query.
     */
    protected array $where = [];

    /**
     * @var array<string> The OR WHERE conditions for the query.
     */
    protected array $orWhere = [];

    /**
     * @var array<string> The WHERE IN conditions for the query.
     */
    protected array $whereIn = [];

    /**
     * @var array<string> The WHERE NOT IN conditions for the query.
     */
    protected array $whereNotIn = [];

    /**
     * @var array<string> The WHERE BETWEEN conditions for the query.
     */
    protected array $whereBetween = [];

    /**
     * @var array<string> The WHERE NULL conditions for the query.
     */
    protected array $whereNull = [];

    /**
     * @var array<string> The WHERE NOT NULL conditions for the query.
     */
    protected array $whereNotNull = [];

    /**
     * @var array<string> The GROUP BY clauses for the query.
     */
    protected array $groupBy = [];

    /**
     * @var array<string> The HAVING conditions for the query.
     */
    protected array $having = [];

    /**
     * @var array<string> The ORDER BY clauses for the query.
     */
    protected array $orderBy = [];

    /**
     * @var int|null The LIMIT clause for the query.
     */
    protected ?int $limit = null;

    /**
     * @var int|null The OFFSET clause for the query.
     */
    protected ?int $offset = null;

    /**
     * @var array<mixed> The parameter bindings for the query.
     */
    protected array $bindings = [];

    /**
     * @var int The current binding index counter.
     */
    protected int $bindingIndex = 0;

    /**
     * Create a new AsyncQueryBuilder instance.
     *
     * @param string $table The table name to query.
     */
    public function __construct(string $table = '')
    {
        if ($table !== '') {
            $this->table = $table;
        }
    }

    /**
     * Set the table for the query.
     *
     * @param string $table The table name.
     * @return self Returns the query builder instance for method chaining.
     */
    public function table(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    /**
     * Set the columns to select.
     *
     * @param string|array<string> $columns The columns to select.
     * @return self Returns the query builder instance for method chaining.
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
     * Add a join clause to the query.
     *
     * @param string $table The table to join.
     * @param string $condition The join condition.
     * @param string $type The type of join (INNER, LEFT, RIGHT).
     * @return self Returns the query builder instance for method chaining.
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
     * Add a left join clause to the query.
     *
     * @param string $table The table to join.
     * @param string $condition The join condition.
     * @return self Returns the query builder instance for method chaining.
     */
    public function leftJoin(string $table, string $condition): self
    {
        return $this->join($table, $condition, 'LEFT');
    }

    /**
     * Add a right join clause to the query.
     *
     * @param string $table The table to join.
     * @param string $condition The join condition.
     * @return self Returns the query builder instance for method chaining.
     */
    public function rightJoin(string $table, string $condition): self
    {
        return $this->join($table, $condition, 'RIGHT');
    }

    /**
     * Add a WHERE clause to the query.
     *
     * @param string $column The column name.
     * @param mixed $operator The comparison operator or value if only 2 arguments.
     * @param mixed $value The value to compare against.
     * @return self Returns the query builder instance for method chaining.
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        // Ensure operator is a string, default to '=' if null
        if (!is_string($operator)) {
            $operator = '=';
        }

        $placeholder = $this->getPlaceholder();
        $this->where[] = "{$column} {$operator} {$placeholder}";
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add an OR WHERE clause to the query.
     *
     * @param string $column The column name.
     * @param mixed $operator The comparison operator or value if only 2 arguments.
     * @param mixed $value The value to compare against.
     * @return self Returns the query builder instance for method chaining.
     */
    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        // Ensure operator is a string, default to '=' if null
        if (!is_string($operator)) {
            $operator = '=';
        }

        $placeholder = $this->getPlaceholder();
        $this->orWhere[] = "{$column} {$operator} {$placeholder}";
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add a WHERE IN clause to the query.
     *
     * @param string $column The column name.
     * @param array<mixed> $values The values to check against.
     * @return self Returns the query builder instance for method chaining.
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
     * Add a WHERE NOT IN clause to the query.
     *
     * @param string $column The column name.
     * @param array<mixed> $values The values to check against.
     * @return self Returns the query builder instance for method chaining.
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
     * Add a WHERE BETWEEN clause to the query.
     *
     * @param string $column The column name.
     * @param array<mixed> $values An array with exactly 2 values for the range.
     * @return self Returns the query builder instance for method chaining.
     * @throws \InvalidArgumentException When values array doesn't contain exactly 2 elements.
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
     * Add a WHERE NULL clause to the query.
     *
     * @param string $column The column name.
     * @return self Returns the query builder instance for method chaining.
     */
    public function whereNull(string $column): self
    {
        $this->whereNull[] = "{$column} IS NULL";

        return $this;
    }

    /**
     * Add a WHERE NOT NULL clause to the query.
     *
     * @param string $column The column name.
     * @return self Returns the query builder instance for method chaining.
     */
    public function whereNotNull(string $column): self
    {
        $this->whereNotNull[] = "{$column} IS NOT NULL";

        return $this;
    }

    /**
     * Add a LIKE clause to the query.
     *
     * @param string $column The column name.
     * @param string $value The value to search for.
     * @param string $side The side to add wildcards ('before', 'after', 'both').
     * @return self Returns the query builder instance for method chaining.
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
     * Add a GROUP BY clause to the query.
     *
     * @param string|array<string> $columns The columns to group by.
     * @return self Returns the query builder instance for method chaining.
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
     * Add a HAVING clause to the query.
     *
     * @param string $column The column name.
     * @param mixed $operator The comparison operator or value if only 2 arguments.
     * @param mixed $value The value to compare against.
     * @return self Returns the query builder instance for method chaining.
     */
    public function having(string $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        // Ensure operator is a string, default to '=' if null
        if (!is_string($operator)) {
            $operator = '=';
        }

        $placeholder = $this->getPlaceholder();
        $this->having[] = "{$column} {$operator} {$placeholder}";
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add an ORDER BY clause to the query.
     *
     * @param string $column The column name.
     * @param string $direction The sort direction (ASC or DESC).
     * @return self Returns the query builder instance for method chaining.
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = "{$column} ".strtoupper($direction);

        return $this;
    }

    /**
     * Set the LIMIT and optionally OFFSET for the query.
     *
     * @param int $limit The maximum number of records to return.
     * @param int|null $offset The number of records to skip.
     * @return self Returns the query builder instance for method chaining.
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
     * Set the OFFSET for the query.
     *
     * @param int $offset The number of records to skip.
     * @return self Returns the query builder instance for method chaining.
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;

        return $this;
    }

    /**
     * Execute the query and return all results.
     *
     * @return PromiseInterface<mixed> A promise that resolves to the query results.
     */
    public function get(): PromiseInterface
    {
        $sql = $this->buildSelectQuery();

        return AsyncPDO::query($sql, $this->bindings);
    }

    /**
     * Get the first result from the query.
     *
     * @return PromiseInterface<mixed> A promise that resolves to the first result or null.
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
     * Find a record by ID.
     *
     * @param mixed $id The ID value to search for.
     * @param string $column The column name to search in.
     * @return PromiseInterface<mixed> A promise that resolves to the found record or null.
     */
    public function find(mixed $id, string $column = 'id'): PromiseInterface
    {
        return $this->where($column, $id)->first();
    }

    /**
     * Find a record by ID or throw an exception if not found.
     *
     * @param mixed $id The ID value to search for.
     * @param string $column The column name to search in.
     * @return PromiseInterface<mixed> A promise that resolves to the found record.
     * @throws \RuntimeException When no record is found.
     */
    public function findOrFail(mixed $id, string $column = 'id'): PromiseInterface
    {
        /** @var PromiseInterface<mixed> */
        return Async::async(function () use ($id, $column): mixed {
            $result = await($this->find($id, $column));
            if ($result === null || $result === false) {
                // Convert $id to string safely
                $idString = $id === null ? 'null' : (is_scalar($id) ? (string)$id : 'complex_type');
                throw new \RuntimeException("Record not found with {$column} = {$idString}");
            }

            return $result;
        })();
    }

    /**
     * Get a single value from the first result.
     *
     * @param string $column The column name to retrieve.
     * @return PromiseInterface<mixed> A promise that resolves to the column value or null.
     */
    public function value(string $column): PromiseInterface
    {
        /** @var PromiseInterface<mixed> */
        return Async::async(function () use ($column): mixed {
            $result = await($this->select($column)->first());

            return ($result !== null && is_array($result) && isset($result[$column])) ? $result[$column] : null;
        })();
    }

    /**
     * Count the number of records.
     *
     * @param string $column The column to count.
     * @return PromiseInterface<mixed> A promise that resolves to the record count.
     */
    public function count(string $column = '*'): PromiseInterface
    {
        $sql = $this->buildCountQuery($column);

        return AsyncPDO::fetchValue($sql, $this->bindings);
    }

    /**
     * Check if any records exist.
     *
     * @return PromiseInterface<mixed> A promise that resolves to true if records exist, false otherwise.
     */
    public function exists(): PromiseInterface
    {
        /** @var PromiseInterface<mixed> */
        return Async::async(function (): mixed {
            $count = await($this->count());

            return $count > 0;
        })();
    }

    /**
     * Insert a single record.
     *
     * @param array<string, mixed> $data The data to insert as column => value pairs.
     * @return PromiseInterface<mixed> A promise that resolves to the result of the insert operation.
     */
    public function insert(array $data): PromiseInterface
    {
        $sql = $this->buildInsertQuery($data);

        return AsyncPDO::execute($sql, array_values($data));
    }

    /**
     * Insert multiple records in a batch operation.
     *
     * @param array<array<string, mixed>> $data An array of records to insert.
     * @return PromiseInterface<mixed> A promise that resolves to the result of the batch insert operation.
     */
    public function insertBatch(array $data): PromiseInterface
    {
        if (count($data) === 0) {
            return Promise::resolve(0);
        }

        $sql = $this->buildInsertBatchQuery($data);
        $bindings = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $bindings = array_merge($bindings, array_values($row));
            }
        }

        return AsyncPDO::execute($sql, $bindings);
    }

    /**
     * Create a new record (alias for insert).
     *
     * @param array<string, mixed> $data The data to insert as column => value pairs.
     * @return PromiseInterface<mixed> A promise that resolves to the result of the insert operation.
     */
    public function create(array $data): PromiseInterface
    {
        return $this->insert($data);
    }

    /**
     * Update records matching the query conditions.
     *
     * @param array<string, mixed> $data The data to update as column => value pairs.
     * @return PromiseInterface<mixed> A promise that resolves to the result of the update operation.
     */
    public function update(array $data): PromiseInterface
    {
        $sql = $this->buildUpdateQuery($data);
        $bindings = array_merge(array_values($data), $this->bindings);

        return AsyncPDO::execute($sql, $bindings);
    }

    /**
     * Delete records matching the query conditions.
     *
     * @return PromiseInterface<mixed> A promise that resolves to the result of the delete operation.
     */
    public function delete(): PromiseInterface
    {
        $sql = $this->buildDeleteQuery();

        return AsyncPDO::execute($sql, $this->bindings);
    }

    /**
     * Execute a raw SQL query.
     *
     * @param string $sql The raw SQL query.
     * @param array<mixed> $bindings The parameter bindings for the query.
     * @return PromiseInterface<mixed> A promise that resolves to the query results.
     */
    public function raw(string $sql, array $bindings = []): PromiseInterface
    {
        return AsyncPDO::query($sql, $bindings);
    }

    /**
     * Execute a raw SQL query and return the first result.
     *
     * @param string $sql The raw SQL query.
     * @param array<mixed> $bindings The parameter bindings for the query.
     * @return PromiseInterface<mixed> A promise that resolves to the first result or null.
     */
    public function rawFirst(string $sql, array $bindings = []): PromiseInterface
    {
        return AsyncPDO::fetchOne($sql, $bindings);
    }

    /**
     * Execute a raw SQL query and return a single value.
     *
     * @param string $sql The raw SQL query.
     * @param array<mixed> $bindings The parameter bindings for the query.
     * @return PromiseInterface<mixed> A promise that resolves to a single value.
     */
    public function rawValue(string $sql, array $bindings = []): PromiseInterface
    {
        return AsyncPDO::fetchValue($sql, $bindings);
    }

    /**
     * Build the SELECT SQL query string.
     *
     * @return string The complete SELECT SQL query.
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
        if (count($this->groupBy) > 0) {
            $sql .= ' GROUP BY '.implode(', ', $this->groupBy);
        }

        // Add having
        if (count($this->having) > 0) {
            $sql .= ' HAVING '.implode(' AND ', $this->having);
        }

        // Add order by
        if (count($this->orderBy) > 0) {
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
     * Build the COUNT SQL query string.
     *
     * @param string $column The column to count.
     * @return string The complete COUNT SQL query.
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
        if (count($this->groupBy) > 0) {
            $sql .= ' GROUP BY '.implode(', ', $this->groupBy);
        }

        // Add having
        if (count($this->having) > 0) {
            $sql .= ' HAVING '.implode(' AND ', $this->having);
        }

        return $sql;
    }

    /**
     * Build the INSERT SQL query string.
     *
     * @param array<string, mixed> $data The data to insert.
     * @return string The complete INSERT SQL query.
     */
    protected function buildInsertQuery(array $data): string
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        return "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
    }

    /**
     * Build the INSERT BATCH SQL query string.
     *
     * @param array<array<string, mixed>> $data The data array for batch insert.
     * @return string The complete INSERT SQL query.
     * @throws \InvalidArgumentException When data format is invalid.
     */
    protected function buildInsertBatchQuery(array $data): string
    {
        $firstRow = $data[0];
        if (!is_array($firstRow)) {
            throw new \InvalidArgumentException('Invalid data format for batch insert');
        }
        
        $columns = implode(', ', array_keys($firstRow));
        $placeholders = '('.implode(', ', array_fill(0, count($firstRow), '?')).')';
        $allPlaceholders = implode(', ', array_fill(0, count($data), $placeholders));

        return "INSERT INTO {$this->table} ({$columns}) VALUES {$allPlaceholders}";
    }

    /**
     * Build the UPDATE SQL query string.
     *
     * @param array<string, mixed> $data The data to update.
     * @return string The complete UPDATE SQL query.
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
     * Build the DELETE SQL query string.
     *
     * @return string The complete DELETE SQL query.
     */
    protected function buildDeleteQuery(): string
    {
        $sql = "DELETE FROM {$this->table}";
        $sql .= $this->buildWhereClause();

        return $sql;
    }

    /**
     * Build the WHERE clause portion of the SQL query.
     *
     * @return string The WHERE clause string or empty string if no conditions.
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

        if (count($conditions) === 0 && count($this->orWhere) === 0) {
            return '';
        }

        $sql = ' WHERE ';

        if (count($conditions) > 0) {
            $sql .= implode(' AND ', $conditions);
        }

        if (count($this->orWhere) > 0) {
            if (count($conditions) > 0) {
                $sql .= ' OR ';
            }
            $sql .= implode(' OR ', $this->orWhere);
        }

        return $sql;
    }

    /**
     * Generate a parameter placeholder for prepared statements.
     *
     * @return string The placeholder string.
     */
    protected function getPlaceholder(): string
    {
        return '?';
    }
}