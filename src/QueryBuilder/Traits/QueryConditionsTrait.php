<?php

namespace Rcalicdan\FiberAsync\QueryBuilder\Traits;

trait QueryConditionsTrait
{
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
     * @var array<string> Raw WHERE conditions.
     */
    protected array $whereRaw = [];

    /**
     * @var array<string> Raw OR WHERE conditions.
     */
    protected array $orWhereRaw = [];

    /**
     * @var array<string> The HAVING conditions for the query.
     */
    protected array $having = [];

    /**
     * Add a WHERE clause to the query.
     *
     * @param  string  $column  The column name.
     * @param  mixed  $operator  The comparison operator or value if only 2 arguments.
     * @param  mixed  $value  The value to compare against.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        if (! is_string($operator)) {
            $operator = '=';
        }

        $instance = clone $this;
        $placeholder = $instance->getPlaceholder();
        $instance->where[] = "{$column} {$operator} {$placeholder}";
        $instance->bindings['where'][] = $value;

        return $instance;
    }

    /**
     * Add an OR WHERE clause to the query.
     *
     * @param  string  $column  The column name.
     * @param  mixed  $operator  The comparison operator or value if only 2 arguments.
     * @param  mixed  $value  The value to compare against.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function orWhere(string $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        if (! is_string($operator)) {
            $operator = '=';
        }

        $instance = clone $this;
        $placeholder = $instance->getPlaceholder();
        $instance->orWhere[] = "{$column} {$operator} {$placeholder}";
        $instance->bindings['orWhere'][] = $value;

        return $instance;
    }

    /**
     * Add a WHERE IN clause to the query.
     *
     * @param  string  $column  The column name.
     * @param  array<mixed>  $values  The values to check against.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereIn(string $column, array $values): static
    {
        if ($values === []) {
            return $this->whereRaw('0=1');
        }

        $instance = clone $this;
        $placeholders = implode(', ', array_fill(0, count($values), $instance->getPlaceholder()));
        $instance->whereIn[] = "{$column} IN ({$placeholders})";
        $instance->bindings['whereIn'] = array_merge($instance->bindings['whereIn'], $values);

        return $instance;
    }

    /**
     * Add a WHERE NOT IN clause to the query.
     *
     * @param  string  $column  The column name.
     * @param  array<mixed>  $values  The values to check against.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereNotIn(string $column, array $values): static
    {
        if ($values === []) {
            return $this; // No change needed for an empty array
        }

        $instance = clone $this;
        $placeholders = implode(', ', array_fill(0, count($values), $instance->getPlaceholder()));
        $instance->whereNotIn[] = "{$column} NOT IN ({$placeholders})";
        $instance->bindings['whereNotIn'] = array_merge($instance->bindings['whereNotIn'], $values);

        return $instance;
    }

    /**
     * Add a WHERE BETWEEN clause to the query.
     *
     * @param  array<mixed>  $values  An array with exactly 2 values for the range.
     * @param  string  $column  The column name.
     * @return static Returns a new query builder instance for method chaining.
     *
     * @throws \InvalidArgumentException When values array doesn't contain exactly 2 elements.
     */
    public function whereBetween(string $column, array $values): static
    {
        if (count($values) !== 2) {
            throw new \InvalidArgumentException('whereBetween requires exactly 2 values');
        }

        $instance = clone $this;
        $placeholder1 = $instance->getPlaceholder();
        $placeholder2 = $instance->getPlaceholder();
        $instance->whereBetween[] = "{$column} BETWEEN {$placeholder1} AND {$placeholder2}";
        $instance->bindings['whereBetween'][] = $values[0];
        $instance->bindings['whereBetween'][] = $values[1];

        return $instance;
    }

    /**
     * Add a WHERE NULL clause to the query.
     *
     * @param  string  $column  The column name.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereNull(string $column): static
    {
        $instance = clone $this;
        $instance->whereNull[] = "{$column} IS NULL";

        return $instance;
    }

    /**
     * Add a WHERE NOT NULL clause to the query.
     *
     * @param  string  $column  The column name.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereNotNull(string $column): static
    {
        $instance = clone $this;
        $instance->whereNotNull[] = "{$column} IS NOT NULL";

        return $instance;
    }

    /**
     * Add a LIKE clause to the query.
     *
     * @param  string  $column  The column name.
     * @param  string  $value  The value to search for.
     * @param  string  $side  The side to add wildcards ('before', 'after', 'both').
     * @return static Returns a new query builder instance for method chaining.
     */
    public function like(string $column, string $value, string $side = 'both'): static
    {
        $instance = clone $this;
        $placeholder = $instance->getPlaceholder();
        $instance->where[] = "{$column} LIKE {$placeholder}";

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
     * Add a HAVING clause to the query.
     *
     * @param  string  $column  The column name.
     * @param  mixed  $operator  The comparison operator or value if only 2 arguments.
     * @param  mixed  $value  The value to compare against.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function having(string $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        if (! is_string($operator)) {
            $operator = '=';
        }

        $instance = clone $this;
        $placeholder = $instance->getPlaceholder();
        $instance->having[] = "{$column} {$operator} {$placeholder}";
        $instance->bindings['having'][] = $value;

        return $instance;
    }

    /**
     * Add a raw HAVING condition.
     *
     * @param  string  $condition  The raw SQL condition.
     * @param  array<mixed>  $bindings  Parameter bindings for the condition.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function havingRaw(string $condition, array $bindings = []): static
    {
        $instance = clone $this;
        $instance->having[] = $condition;
        $instance->bindings['having'] = array_merge($instance->bindings['having'], $bindings);

        return $instance;
    }

    /**
     * Add a raw WHERE condition.
     *
     * @param  string  $condition  The raw SQL condition.
     * @param  array<mixed>  $bindings  Parameter bindings for the condition.
     * @param  string  $operator  Logical operator ('AND' or 'OR').
     * @return static Returns a new query builder instance for method chaining.
     */
    public function whereRaw(string $condition, array $bindings = [], string $operator = 'AND'): static
    {
        $instance = clone $this;

        if (strtoupper($operator) === 'OR') {
            $instance->orWhereRaw[] = $condition;
            $instance->bindings['orWhereRaw'] = array_merge($instance->bindings['orWhereRaw'], $bindings);
        } else {
            $instance->whereRaw[] = $condition;
            $instance->bindings['whereRaw'] = array_merge($instance->bindings['whereRaw'], $bindings);
        }

        return $instance;
    }

    /**
     * Add a raw OR WHERE condition.
     *
     * @param  string  $condition  The raw SQL condition.
     * @param  array<mixed>  $bindings  Parameter bindings for the condition.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function orWhereRaw(string $condition, array $bindings = []): static
    {
        return $this->whereRaw($condition, $bindings, 'OR');
    }

    /**
     * Reset all WHERE conditions and bindings.
     *
     * @return static Returns a new query builder instance for method chaining.
     */
    public function resetWhere(): static
    {
        $instance = clone $this;
        $instance->where = [];
        $instance->orWhere = [];
        $instance->whereIn = [];
        $instance->whereNotIn = [];
        $instance->whereBetween = [];
        $instance->whereNull = [];
        $instance->whereNotNull = [];
        $instance->whereRaw = [];
        $instance->orWhereRaw = [];
        $instance->bindings['where'] = [];
        $instance->bindings['whereIn'] = [];
        $instance->bindings['whereNotIn'] = [];
        $instance->bindings['whereBetween'] = [];
        $instance->bindings['whereRaw'] = [];
        $instance->bindings['orWhere'] = [];
        $instance->bindings['orWhereRaw'] = [];
        $instance->bindings['having'] = [];
        $instance->bindingIndex = 0;

        return $instance;
    }
}
