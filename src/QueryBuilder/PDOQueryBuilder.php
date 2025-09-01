<?php

namespace Rcalicdan\FiberAsync\QueryBuilder;

use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\Api\AsyncPDO;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\QueryBuilder\Traits\QueryAdvancedConditionsTrait;
use Rcalicdan\FiberAsync\QueryBuilder\Traits\QueryBuilderCoreTrait;
use Rcalicdan\FiberAsync\QueryBuilder\Traits\QueryConditionsTrait;
use Rcalicdan\FiberAsync\QueryBuilder\Traits\QueryDebugTrait;
use Rcalicdan\FiberAsync\QueryBuilder\Traits\QueryGroupingTrait;
use Rcalicdan\FiberAsync\QueryBuilder\Traits\QueryJoinTrait;
use Rcalicdan\FiberAsync\QueryBuilder\Traits\SqlBuilderTrait;

/**
 * Async Query Builder for an easy way to write asynchronous SQL queries.
 *
 * This query builder is fully immutable. Each method that modifies the query
 * returns a new instance instead of modifying the current one, ensuring a
 * predictable and safe state management.
 */
class PDOQueryBuilder
{
    use QueryAdvancedConditionsTrait;
    use QueryBuilderCoreTrait;
    use QueryConditionsTrait;
    use QueryDebugTrait;
    use QueryGroupingTrait;
    use QueryJoinTrait;
    use SqlBuilderTrait;

    /**
     * Create a new AsyncQueryBuilder instance.
     *
     * @param  string  $table  The table name to query.
     */
    final public function __construct(string $table = '')
    {
        if ($table !== '') {
            $this->table = $table;
        }
    }

    /**
     * Execute the query and return all results.
     *
     * @return PromiseInterface<array<int, array<string, mixed>>> A promise that resolves to the query results.
     */
    public function get(): PromiseInterface
    {
        $sql = $this->buildSelectQuery();

        return AsyncPDO::query($sql, $this->getCompiledBindings());
    }

    /**
     * Get the first result from the query.
     *
     * @return PromiseInterface<array<string, mixed>|false> A promise that resolves to the first result or false.
     */
    public function first(): PromiseInterface
    {
        // A new instance with a limit is created for this specific query execution
        $instanceWithLimit = $this->limit(1);
        $sql = $instanceWithLimit->buildSelectQuery();

        return AsyncPDO::fetchOne($sql, $instanceWithLimit->getCompiledBindings());
    }

    /**
     * Find a record by ID.
     *
     * @param  mixed  $id  The ID value to search for.
     * @param  string  $column  The column name to search in.
     * @return PromiseInterface<array<string, mixed>|false> A promise that resolves to the found record or false.
     */
    public function find(mixed $id, string $column = 'id'): PromiseInterface
    {
        return $this->where($column, $id)->first();
    }

    /**
     * Find a record by ID or throw an exception if not found.
     *
     * @param  mixed  $id  The ID value to search for.
     * @param  string  $column  The column name to search in.
     * @return PromiseInterface<array<string, mixed>> A promise that resolves to the found record.
     *
     * @throws \RuntimeException When no record is found.
     */
    public function findOrFail(mixed $id, string $column = 'id'): PromiseInterface
    {
        // @phpstan-ignore-next-line
        return Async::async(function () use ($id, $column): array {
            $result = await($this->find($id, $column));
            if ($result === null || $result === false) {
                $idString = is_scalar($id) ? (string) $id : 'complex_type';

                throw new \RuntimeException("Record not found with {$column} = {$idString}");
            }

            return $result;
        })();
    }

    /**
     * Get a single value from the first result.
     *
     * @param  string  $column  The column name to retrieve.
     * @return PromiseInterface<mixed> A promise that resolves to the column value or null.
     */
    public function value(string $column): PromiseInterface
    {
        // @phpstan-ignore-next-line
        return Async::async(function () use ($column): mixed {
            $result = await($this->select($column)->first());

            return ($result !== false && isset($result[$column])) ? $result[$column] : null;
        })();
    }

    /**
     * Count the number of records.
     *
     * @param  string  $column  The column to count.
     * @return PromiseInterface<int> A promise that resolves to the record count.
     */
    public function count(string $column = '*'): PromiseInterface
    {
        $sql = $this->buildCountQuery($column);
        /** @var PromiseInterface<int> */
        $promise = AsyncPDO::fetchValue($sql, $this->getCompiledBindings());

        return $promise;
    }

    /**
     * Check if any records exist.
     *
     * @return PromiseInterface<bool> A promise that resolves to true if records exist, false otherwise.
     */
    public function exists(): PromiseInterface
    {
        // @phpstan-ignore-next-line
        return Async::async(function (): bool {
            $count = await($this->count());

            return $count > 0;
        })();
    }

    /**
     * Insert a single record.
     *
     * @param  array<string, mixed>  $data  The data to insert as column => value pairs.
     * @return PromiseInterface<int> A promise that resolves to the number of affected rows.
     */
    public function insert(array $data): PromiseInterface
    {
        if ($data === []) {
            return Promise::resolve(0);
        }
        $sql = $this->buildInsertQuery($data);

        return AsyncPDO::execute($sql, array_values($data));
    }

    /**
     * Insert multiple records in a batch operation.
     *
     * @param  array<array<string, mixed>>  $data  An array of records to insert.
     * @return PromiseInterface<int> A promise that resolves to the number of affected rows.
     */
    public function insertBatch(array $data): PromiseInterface
    {
        if ($data === []) {
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
     * @param  array<string, mixed>  $data  The data to insert as column => value pairs.
     * @return PromiseInterface<int> A promise that resolves to the number of affected rows.
     */
    public function create(array $data): PromiseInterface
    {
        return $this->insert($data);
    }

    /**
     * Update records matching the query conditions.
     *
     * @param  array<string, mixed>  $data  The data to update as column => value pairs.
     * @return PromiseInterface<int> A promise that resolves to the number of affected rows.
     */
    public function update(array $data): PromiseInterface
    {
        if ($data === []) {
            return Promise::resolve(0);
        }
        $sql = $this->buildUpdateQuery($data);
        $whereBindings = $this->getCompiledBindings();
        $bindings = array_merge(array_values($data), $whereBindings);

        return AsyncPDO::execute($sql, $bindings);
    }

    /**
     * Delete records matching the query conditions.
     *
     * @return PromiseInterface<int> A promise that resolves to the number of affected rows.
     */
    public function delete(): PromiseInterface
    {
        $sql = $this->buildDeleteQuery();

        return AsyncPDO::execute($sql, $this->getCompiledBindings());
    }
}
