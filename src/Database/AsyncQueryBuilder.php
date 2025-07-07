<?php
// src/Database/AsyncQueryBuilder.php

namespace Rcalicdan\FiberAsync\Database;

use Rcalicdan\FiberAsync\Contracts\DatabaseConnectionInterface;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Handlers\Database\QueryBuilderHandler;

final class AsyncQueryBuilder
{
    private QueryBuilderHandler $handler;

    public function __construct(
        private readonly DatabaseConnectionInterface $connection,
        private readonly string $table
    ) {
        $this->handler = new QueryBuilderHandler($connection);
        $this->handler->setTable($table);
    }
    
    // --- Builder methods (unchanged) ---

    public function select(array $columns = ['*']): self
    {
        $this->handler->addSelect($columns);
        return $this;
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        $this->handler->addWhere($column, $operator, $value);
        return $this;
    }
    
    // ... other builder methods like orWhere, join, orderBy, etc. are unchanged ...
    
    public function orWhere(string $column, string $operator, mixed $value): self
    {
        $this->handler->addOrWhere($column, $operator, $value);
        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second): self
    {
        $this->handler->addJoin($table, $first, $operator, $second, 'INNER');
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->handler->addJoin($table, $first, $operator, $second, 'LEFT');
        return $this;
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->handler->addJoin($table, $first, $operator, $second, 'RIGHT');
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->handler->addOrderBy($column, $direction);
        return $this;
    }

    public function groupBy(string $column): self
    {
        $this->handler->addGroupBy($column);
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->handler->setLimit($limit);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->handler->setOffset($offset);
        return $this;
    }

    // --- Action methods (refactored) ---

    public function get(): PromiseInterface
    {
        return $this->createPromiseForQuery(function (callable $resolve, callable $reject) {
            $this->connection->asyncQuery(
                $this->handler->buildSelectQuery(),
                $this->handler->getBindings(),
                onSuccess: fn ($result) => $resolve($this->connection->fetchAll($result)),
                onFailure: $reject
            );
        });
    }

    public function first(): PromiseInterface
    {
        return $this->createPromiseForQuery(function (callable $resolve, callable $reject) {
            $this->handler->setLimit(1);
            $this->connection->asyncQuery(
                $this->handler->buildSelectQuery(),
                $this->handler->getBindings(),
                onSuccess: fn ($result) => $resolve($this->connection->fetchOne($result)),
                onFailure: $reject
            );
        });
    }

    public function create(array $data): PromiseInterface
    {
        return $this->createPromiseForQuery(function (callable $resolve, callable $reject) use ($data) {
            $this->connection->asyncQuery(
                $this->handler->buildInsertQuery($data),
                $this->handler->getBindings(),
                onSuccess: fn () => $resolve($this->connection->getLastInsertId()),
                onFailure: $reject
            );
        });
    }

    public function update(array $data): PromiseInterface
    {
        return $this->createPromiseForQuery(function (callable $resolve, callable $reject) use ($data) {
            $this->connection->asyncQuery(
                $this->handler->buildUpdateQuery($data),
                $this->handler->getBindings(),
                onSuccess: fn () => $resolve($this->connection->getAffectedRows()),
                onFailure: $reject
            );
        });
    }

    public function delete(): PromiseInterface
    {
        return $this->createPromiseForQuery(function (callable $resolve, callable $reject) {
            $this->connection->asyncQuery(
                $this->handler->buildDeleteQuery(),
                $this->handler->getBindings(),
                onSuccess: fn () => $resolve($this->connection->getAffectedRows()),
                onFailure: $reject
            );
        });
    }

    public function count(): PromiseInterface
    {
        return $this->createPromiseForQuery(function (callable $resolve, callable $reject) {
            $this->handler->addSelect(['COUNT(*) as count']);
            $this->connection->asyncQuery(
                $this->handler->buildSelectQuery(),
                $this->handler->getBindings(),
                onSuccess: function ($result) use ($resolve) {
                    $data = $this->connection->fetchOne($result);
                    $resolve((int)($data['count'] ?? 0));
                },
                onFailure: $reject
            );
        });
    }

    public function exists(): PromiseInterface
    {
        return $this->createPromiseForQuery(function (callable $resolve, callable $reject) {
            $this->handler->addSelect(['1']);
            $this->handler->setLimit(1);
            $this->connection->asyncQuery(
                $this->handler->buildSelectQuery(),
                $this->handler->getBindings(),
                onSuccess: function ($result) use ($resolve) {
                    $data = $this->connection->fetchOne($result);
                    $resolve($data !== null);
                },
                onFailure: $reject
            );
        });
    }

    /**
     * Private helper to encapsulate promise creation and error handling.
     * This eliminates boilerplate from all public action methods.
     */
    private function createPromiseForQuery(callable $queryExecutor): PromiseInterface
    {
        try {
            // We still need the AsyncPromise constructor for its resolve/reject callbacks,
            // but the outer try/catch is now handled here.
            return new AsyncPromise(function ($resolve, $reject) use ($queryExecutor) {
                // The onSuccess callbacks can also throw, so we wrap the executor.
                $wrappedResolve = function (...$args) use ($resolve, $reject) {
                    try {
                        $resolve(...$args);
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                };
                
                $queryExecutor($wrappedResolve, $reject);
            });
        } catch (\Throwable $e) {
            // If building the query fails, immediately return a rejected promise
            // using the global helper function.
            return reject($e);
        }
    }
}