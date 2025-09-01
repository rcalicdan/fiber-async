<?php

namespace Rcalicdan\FiberAsync\QueryBuilder\Traits;

trait QueryBuilderCoreTrait
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
     * @var array<string, array<mixed>> The parameter bindings for the query, grouped by type.
     */
    protected array $bindings = [
        'where' => [],
        'whereIn' => [],
        'whereNotIn' => [],
        'whereBetween' => [],
        'whereRaw' => [],
        'orWhere' => [],
        'orWhereRaw' => [],
        'having' => [],
    ];

    /**
     * @var int The current binding index counter.
     */
    protected int $bindingIndex = 0;

    /**
     * Set the table for the query.
     *
     * @param  string  $table  The table name.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function table(string $table): static
    {
        $instance = clone $this;
        $instance->table = $table;

        return $instance;
    }

    /**
     * Set the columns to select.
     *
     * @param  string|array<string>  $columns  The columns to select.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function select(string|array $columns = '*'): static
    {
        $instance = clone $this;
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }
        $instance->select = $columns;

        return $instance;
    }

    /**
     * Add columns to the existing select.
     *
     * @param  string|array<string>  $columns  The columns to add.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function addSelect(string|array $columns): static
    {
        $instance = clone $this;
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }
        $instance->select = array_merge($instance->select, $columns);

        return $instance;
    }

    /**
     * Select distinct records.
     *
     * @param  string|array<string>  $columns  The columns to select.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function selectDistinct(string|array $columns = '*'): static
    {
        $instance = $this->select($columns);

        // Add DISTINCT to the first column
        if ($instance->select !== [] && $instance->select[0] !== '*') {
            $instance->select[0] = 'DISTINCT '.$instance->select[0];
        } elseif ($instance->select[0] === '*') {
            $instance->select[0] = 'DISTINCT *';
        }

        return $instance;
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

    /**
     * Compiles the final bindings array in the correct order for execution.
     *
     * @return array<mixed>
     */
    protected function getCompiledBindings(): array
    {
        // This merge order MUST match the order in `collectAllConditionParts()`
        $whereBindings = array_merge(
            $this->bindings['where'],
            $this->bindings['whereIn'],
            $this->bindings['whereNotIn'],
            $this->bindings['whereBetween'],
            $this->bindings['whereRaw'],
            $this->bindings['orWhere'],
            $this->bindings['orWhereRaw']
        );

        return array_merge($whereBindings, $this->bindings['having']);
    }
}
