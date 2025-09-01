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
 * Abstract PostgreSQL query builder base with optimizations.
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
     */
    protected function getPlaceholder(): string
    {
        return '$' . (++$this->parameterCount);
    }

    /**
     * Reset parameter counter for new query
     */
    protected function resetParameterCount(): void
    {
        $this->parameterCount = 0;
    }

    /**
     * PostgreSQL ILIKE for case-insensitive matching
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
     * PostgreSQL JSON field access
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
     * Override buildSelectQuery to reset parameter count and optimize for PostgreSQL
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
     */
    protected function buildInsertReturningQuery(array $data, string $returning = 'id'): string
    {
        return $this->buildInsertQuery($data) . " RETURNING {$returning}";
    }
}