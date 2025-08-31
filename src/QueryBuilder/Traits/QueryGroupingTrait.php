<?php

namespace Rcalicdan\FiberAsync\QueryBuilder\Traits;

trait QueryGroupingTrait
{
    /**
     * @var array<string> The GROUP BY clauses for the query.
     */
    protected array $groupBy = [];

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
     * Add a GROUP BY clause to the query.
     *
     * @param  string|array<string>  $columns  The columns to group by.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function groupBy(string|array $columns): static
    {
        $instance = clone $this;
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }
        $instance->groupBy = array_merge($instance->groupBy, $columns);

        return $instance;
    }

    /**
     * Add an ORDER BY clause to the query.
     *
     * @param  string  $column  The column name.
     * @param  string  $direction  The sort direction (ASC or DESC).
     * @return static Returns a new query builder instance for method chaining.
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $instance = clone $this;
        $instance->orderBy[] = "{$column} ".strtoupper($direction);

        return $instance;
    }

    /**
     * Add an ORDER BY ASC clause to the query.
     *
     * @param  string  $column  The column name.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function orderByAsc(string $column): static
    {
        return $this->orderBy($column, 'ASC');
    }

    /**
     * Add an ORDER BY DESC clause to the query.
     *
     * @param  string  $column  The column name.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Set the LIMIT and optionally OFFSET for the query.
     *
     * @param  int  $limit  The maximum number of records to return.
     * @param  int|null  $offset  The number of records to skip.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function limit(int $limit, ?int $offset = null): static
    {
        $instance = clone $this;
        $instance->limit = $limit;
        if ($offset !== null) {
            $instance->offset = $offset;
        }

        return $instance;
    }

    /**
     * Set the OFFSET for the query.
     *
     * @param  int  $offset  The number of records to skip.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function offset(int $offset): static
    {
        $instance = clone $this;
        $instance->offset = $offset;

        return $instance;
    }

    /**
     * Set pagination for the query.
     *
     * @param  int  $page  The page number (1-based).
     * @param  int  $perPage  The number of records per page.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function paginate(int $page, int $perPage = 15): static
    {
        $offset = ($page - 1) * $perPage;
        return $this->limit($perPage, $offset);
    }
}