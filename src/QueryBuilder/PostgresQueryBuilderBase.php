<?php

namespace Rcalicdan\FiberAsync\QueryBuilder;

use Rcalicdan\FiberAsync\QueryBuilder\Traits\QueryBuilderCoreTrait;
use Rcalicdan\FiberAsync\QueryBuilder\Traits\QueryConditionsTrait;
use Rcalicdan\FiberAsync\QueryBuilder\Traits\QueryJoinTrait;
use Rcalicdan\FiberAsync\QueryBuilder\Traits\QueryGroupingTrait;
use Rcalicdan\FiberAsync\QueryBuilder\Traits\QueryAdvancedConditionsTrait;
use Rcalicdan\FiberAsync\QueryBuilder\Traits\QueryDebugTrait;
use Rcalicdan\FiberAsync\QueryBuilder\Traits\SqlBuilderTrait;

/**
 * Abstract PostgreSQL query builder base with optimizations and PostgreSQL-specific features.
 */
abstract class PostgresQueryBuilderBase
{
    use QueryBuilderCoreTrait,
        QueryConditionsTrait,
        QueryJoinTrait,
        QueryGroupingTrait,
        QueryAdvancedConditionsTrait,
        SqlBuilderTrait,
        QueryDebugTrait;

    /**
     * @var int Parameter counter for PostgreSQL numbered parameters
     */
    protected int $parameterCount = 0;

    /**
     * Generate PostgreSQL parameter placeholder ($1, $2, $3, etc.)
     *
     * @return string The placeholder string.
     */
    protected function getPlaceholder(): string
    {
        return '$' . (++$this->parameterCount);
    }

    /**
     * Reset parameter counter for new query
     *
     * @return void
     */
    protected function resetParameterCount(): void
    {
        $this->parameterCount = 0;
    }

    /**
     * PostgreSQL ILIKE for case-insensitive matching.
     *
     * @param  string  $column  The column name.
     * @param  string  $value  The value to search for.
     * @param  string  $side  The side to add wildcards ('before', 'after', 'both').
     * @return static Returns a new query builder instance for method chaining.
     */
    public function ilike(string $column, string $value, string $side = 'both'): static
    {
        $instance = clone $this;
        $placeholder = $instance->getPlaceholder();
        $instance->where[] = "{$column} ILIKE {$placeholder}";

        $likeValue = match ($side) {
            'before' => "%{$value}",
            'after' => "{$value}%",
            'both' => "%{$value}%",
            default => $value
        };

        $instance->bindings['where'][] = $likeValue;
        return $instance;
    }

    /**
     * PostgreSQL JSON field access using -> operator.
     *
     * @param  string  $column  The JSON column name.
     * @param  string  $path  The JSON path to access.
     * @param  mixed  $value  The value to compare against.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereJson(string $column, string $path, mixed $value): static
    {
        $instance = clone $this;
        $placeholder = $instance->getPlaceholder();
        $instance->where[] = "{$column}->'{$path}' = {$placeholder}";
        $instance->bindings['where'][] = $value;
        return $instance;
    }

    /**
     * PostgreSQL JSON field text extraction using ->> operator.
     *
     * @param  string  $column  The JSON column name.
     * @param  string  $path  The JSON path to access.
     * @param  mixed  $value  The value to compare against.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereJsonEquals(string $column, string $path, mixed $value): static
    {
        $instance = clone $this;
        $placeholder1 = $instance->getPlaceholder();
        $placeholder2 = $instance->getPlaceholder();
        $instance->where[] = "{$column}->>{$placeholder1} = {$placeholder2}";
        $instance->bindings['where'][] = $path;
        $instance->bindings['where'][] = $value;
        return $instance;
    }

    /**
     * PostgreSQL JSON contains operator (@>).
     *
     * @param  string  $column  The JSON column name.
     * @param  array<string, mixed>  $value  The JSON value to check containment.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereJsonContains(string $column, array $value): static
    {
        $instance = clone $this;
        $placeholder = $instance->getPlaceholder();
        $instance->where[] = "{$column} @> {$placeholder}::jsonb";
        $instance->bindings['where'][] = json_encode($value);
        return $instance;
    }

    /**
     * PostgreSQL JSON key exists operator (?).
     *
     * @param  string  $column  The JSON column name.
     * @param  string  $key  The key to check for existence.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereJsonHasKey(string $column, string $key): static
    {
        $instance = clone $this;
        $placeholder = $instance->getPlaceholder();
        $instance->where[] = "{$column} ? {$placeholder}";
        $instance->bindings['where'][] = $key;
        return $instance;
    }

    /**
     * PostgreSQL array contains using ANY operator.
     *
     * @param  string  $column  The array column name.
     * @param  mixed  $value  The value to search for in the array.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereArrayContains(string $column, mixed $value): static
    {
        $instance = clone $this;
        $placeholder = $instance->getPlaceholder();
        $instance->where[] = "{$placeholder} = ANY({$column})";
        $instance->bindings['where'][] = $value;
        return $instance;
    }

    /**
     * PostgreSQL array overlap operator (&&).
     *
     * @param  string  $column  The array column name.
     * @param  array<mixed>  $values  The values to check for overlap.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereArrayOverlap(string $column, array $values): static
    {
        $instance = clone $this;
        $placeholder = $instance->getPlaceholder();
        $instance->where[] = "{$column} && {$placeholder}::text[]";
        $instance->bindings['where'][] = '{' . implode(',', array_map(fn($v) => '"' . addslashes($v) . '"', $values)) . '}';
        return $instance;
    }

    /**
     * PostgreSQL full-text search using tsvector and tsquery.
     *
     * @param  string  $column  The column to search in.
     * @param  string  $query  The search query.
     * @param  string  $config  The text search configuration (default: 'english').
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereFullText(string $column, string $query, string $config = 'english'): static
    {
        $instance = clone $this;
        $placeholder1 = $instance->getPlaceholder();
        $placeholder2 = $instance->getPlaceholder();
        
        $instance->where[] = "to_tsvector({$placeholder1}, {$column}) @@ plainto_tsquery({$placeholder2})";
        $instance->bindings['where'][] = $config;
        $instance->bindings['where'][] = $query;
        return $instance;
    }

    /**
     * Override buildSelectQuery to reset parameter count and optimize for PostgreSQL
     *
     * @return string The complete SELECT SQL query.
     */
    protected function buildSelectQuery(): string
    {
        $this->resetParameterCount();
        
        $sql = 'SELECT ' . implode(', ', $this->select);
        $sql .= ' FROM ' . $this->table;

        foreach ($this->joins as $join) {
            if ($join['type'] === 'CROSS') {
                $sql .= " CROSS JOIN {$join['table']}";
            } else {
                $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['condition']}";
            }
        }

        $whereSql = $this->buildWhereClause();
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        if ($this->groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        if ($this->having !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $this->having);
        }

        if ($this->orderBy !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
            if ($this->offset !== null) {
                $sql .= ' OFFSET ' . $this->offset;
            }
        }

        return $sql;
    }

    /**
     * Override buildCountQuery for PostgreSQL
     *
     * @param  string  $column  The column to count.
     * @return string The complete COUNT SQL query.
     */
    protected function buildCountQuery(string $column = '*'): string
    {
        $this->resetParameterCount();
        
        $sql = "SELECT COUNT({$column}) FROM " . $this->table;

        foreach ($this->joins as $join) {
            if ($join['type'] === 'CROSS') {
                $sql .= " CROSS JOIN {$join['table']}";
            } else {
                $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['condition']}";
            }
        }

        $whereSql = $this->buildWhereClause();
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        if ($this->groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        if ($this->having !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $this->having);
        }

        return $sql;
    }

    /**
     * Override buildInsertQuery for PostgreSQL
     *
     * @param  array<string, mixed>  $data  The data to insert.
     * @return string The complete INSERT SQL query.
     */
    protected function buildInsertQuery(array $data): string
    {
        $this->resetParameterCount();
        
        $columns = implode(', ', array_keys($data));
        $placeholders = [];
        
        foreach ($data as $value) {
            $placeholders[] = $this->getPlaceholder();
        }
        
        $placeholderString = implode(', ', $placeholders);
        return "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholderString})";
    }

    /**
     * Override buildInsertBatchQuery for PostgreSQL
     *
     * @param  array<array<string, mixed>>  $data  The data array for batch insert.
     * @return string The complete INSERT SQL query.
     *
     * @throws \InvalidArgumentException When data format is invalid.
     */
    protected function buildInsertBatchQuery(array $data): string
    {
        $this->resetParameterCount();
        
        if (empty($data) || !is_array($data[0])) {
            throw new \InvalidArgumentException('Invalid data format for batch insert');
        }

        $firstRow = $data[0];
        $columns = implode(', ', array_keys($firstRow));
        
        $valueGroups = [];
        foreach ($data as $row) {
            $rowPlaceholders = [];
            foreach ($row as $value) {
                $rowPlaceholders[] = $this->getPlaceholder();
            }
            $valueGroups[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }
        
        $allPlaceholders = implode(', ', $valueGroups);
        return "INSERT INTO {$this->table} ({$columns}) VALUES {$allPlaceholders}";
    }

    /**
     * Override buildUpdateQuery for PostgreSQL
     *
     * @param  array<string, mixed>  $data  The data to update.
     * @return string The complete UPDATE SQL query.
     */
    protected function buildUpdateQuery(array $data): string
    {
        $this->resetParameterCount();
        
        $setClauses = [];
        foreach (array_keys($data) as $column) {
            $setClauses[] = "{$column} = " . $this->getPlaceholder();
        }
        
        $sql = "UPDATE {$this->table} SET " . implode(', ', $setClauses);
        
        $whereSql = $this->buildWhereClause();
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        return $sql;
    }

    /**
     * Override buildDeleteQuery for PostgreSQL
     *
     * @return string The complete DELETE SQL query.
     */
    protected function buildDeleteQuery(): string
    {
        $this->resetParameterCount();
        
        $sql = "DELETE FROM {$this->table}";
        
        $whereSql = $this->buildWhereClause();
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        return $sql;
    }

    /**
     * Build INSERT with RETURNING clause for PostgreSQL
     *
     * @param  array<string, mixed>  $data  The data to insert.
     * @param  string  $returning  The column to return.
     * @return string The complete INSERT SQL query with RETURNING clause.
     */
    protected function buildInsertReturningQuery(array $data, string $returning = 'id'): string
    {
        return $this->buildInsertQuery($data) . " RETURNING {$returning}";
    }

    /**
     * Build PostgreSQL UPSERT query (INSERT ... ON CONFLICT).
     *
     * @param  array<string, mixed>  $data  The data to insert.
     * @param  array<string>  $conflictColumns  Columns that define the conflict.
     * @param  array<string, mixed>  $updateData  Data to update on conflict (optional).
     * @return string The complete UPSERT SQL query.
     */
    protected function buildUpsertQuery(array $data, array $conflictColumns, array $updateData = []): string
    {
        $this->resetParameterCount();
        
        $columns = implode(', ', array_keys($data));
        $placeholders = [];
        
        foreach ($data as $value) {
            $placeholders[] = $this->getPlaceholder();
        }
        
        $placeholderString = implode(', ', $placeholders);
        $conflictCols = implode(', ', $conflictColumns);
        
        $sql = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholderString})";
        $sql .= " ON CONFLICT ({$conflictCols})";
        
        if (empty($updateData)) {
            $sql .= " DO NOTHING";
        } else {
            $updateClauses = [];
            foreach (array_keys($updateData) as $column) {
                if (isset($updateData[$column]) && $updateData[$column] === 'EXCLUDED') {
                    $updateClauses[] = "{$column} = EXCLUDED.{$column}";
                } else {
                    $updateClauses[] = "{$column} = " . $this->getPlaceholder();
                }
            }
            $sql .= " DO UPDATE SET " . implode(', ', $updateClauses);
        }
        
        return $sql;
    }
}