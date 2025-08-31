<?php

namespace Rcalicdan\FiberAsync\QueryBuilder\Traits;

trait QueryAdvancedConditionsTrait
{
    /**
     * Add a custom condition group with specific logic.
     *
     * @param  callable(static): void  $callback  Callback function that receives a new query builder instance.
     * @param  string  $logicalOperator  How this group connects to others ('AND' or 'OR').
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereGroup(callable $callback, string $logicalOperator = 'AND'): static
    {
        $subBuilder = new static($this->table);
        $callback($subBuilder);

        $subSql = $subBuilder->buildWhereClause();

        if ($subSql === '') {
            return $this;
        }

        return $this->whereRaw("({$subSql})", $subBuilder->getCompiledBindings(), $logicalOperator);
    }

    /**
     * Add nested WHERE conditions with custom logic.
     *
     * @param  callable(static): void  $callback  Callback function for nested conditions.
     * @param  string  $operator  How to connect with existing conditions.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereNested(callable $callback, string $operator = 'AND'): static
    {
        return $this->whereGroup($callback, $operator);
    }

    /**
     * Add a nested OR WHERE condition with custom logic.
     *
     * @param  callable(static): void  $callback  Callback function for nested conditions.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function orWhereNested(callable $callback): static
    {
        return $this->whereGroup($callback, 'OR');
    }

    /**
     * Add conditions with EXISTS clause.
     *
     * @param  callable(static): void  $callback  Callback function for the EXISTS subquery.
     * @param  string  $operator  Logical operator ('AND' or 'OR').
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereExists(callable $callback, string $operator = 'AND'): static
    {
        $subBuilder = new static;
        $callback($subBuilder);

        $subSql = $subBuilder->buildSelectQuery();
        $condition = "EXISTS ({$subSql})";

        return $this->whereRaw($condition, $subBuilder->getCompiledBindings(), $operator);
    }

    /**
     * Add conditions with NOT EXISTS clause.
     *
     * @param  callable(static): void  $callback  Callback function for the NOT EXISTS subquery.
     * @param  string  $operator  Logical operator ('AND' or 'OR').
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereNotExists(callable $callback, string $operator = 'AND'): static
    {
        $subBuilder = new static;
        $callback($subBuilder);

        $subSql = $subBuilder->buildSelectQuery();
        $condition = "NOT EXISTS ({$subSql})";

        return $this->whereRaw($condition, $subBuilder->getCompiledBindings(), $operator);
    }

    /**
     * Add an OR WHERE EXISTS clause.
     *
     * @param  callable(static): void  $callback  Callback function for the EXISTS subquery.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function orWhereExists(callable $callback): static
    {
        return $this->whereExists($callback, 'OR');
    }

    /**
     * Add an OR WHERE NOT EXISTS clause.
     *
     * @param  callable(static): void  $callback  Callback function for the NOT EXISTS subquery.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function orWhereNotExists(callable $callback): static
    {
        return $this->whereNotExists($callback, 'OR');
    }

    /**
     * Add a WHERE clause with a subquery.
     *
     * @param  string  $column  The column name.
     * @param  string  $operator  The comparison operator.
     * @param  callable(static): void  $callback  Callback function for the subquery.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereSub(string $column, string $operator, callable $callback): static
    {
        $subBuilder = new static;
        $callback($subBuilder);

        $subSql = $subBuilder->buildSelectQuery();
        $condition = "{$column} {$operator} ({$subSql})";

        return $this->whereRaw($condition, $subBuilder->getCompiledBindings());
    }
}