<?php
// src/Database/AsyncQueryBuilder.php

namespace Rcalicdan\FiberAsync\Database;

use Rcalicdan\FiberAsync\AsyncEventLoop;
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

    public function get(): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) {
            AsyncEventLoop::getInstance()->nextTick(function () use ($resolve, $reject) {
                try {
                    $sql = $this->handler->buildSelectQuery();
                    $bindings = $this->handler->getBindings();
                    
                    $result = $this->connection->query($sql, $bindings);
                    $data = $this->connection->fetchAll($result);
                    
                    $resolve($data);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        });
    }

    public function first(): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) {
            AsyncEventLoop::getInstance()->nextTick(function () use ($resolve, $reject) {
                try {
                    $this->handler->setLimit(1);
                    $sql = $this->handler->buildSelectQuery();
                    $bindings = $this->handler->getBindings();
                    
                    $result = $this->connection->query($sql, $bindings);
                    $data = $this->connection->fetchOne($result);
                    
                    $resolve($data);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        });
    }

    public function create(array $data): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($data) {
            AsyncEventLoop::getInstance()->nextTick(function () use ($data, $resolve, $reject) {
                try {
                    $sql = $this->handler->buildInsertQuery($data);
                    $bindings = $this->handler->getBindings();
                    
                    $this->connection->query($sql, $bindings);
                    $insertId = $this->connection->getLastInsertId();
                    
                    $resolve($insertId);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        });
    }

    public function update(array $data): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($data) {
            AsyncEventLoop::getInstance()->nextTick(function () use ($data, $resolve, $reject) {
                try {
                    $sql = $this->handler->buildUpdateQuery($data);
                    $bindings = $this->handler->getBindings();
                    
                    $this->connection->query($sql, $bindings);
                    $affectedRows = $this->connection->getAffectedRows();
                    
                    $resolve($affectedRows);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        });
    }

    public function delete(): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) {
            AsyncEventLoop::getInstance()->nextTick(function () use ($resolve, $reject) {
                try {
                    $sql = $this->handler->buildDeleteQuery();
                    $bindings = $this->handler->getBindings();
                    
                    $this->connection->query($sql, $bindings);
                    $affectedRows = $this->connection->getAffectedRows();
                    
                    $resolve($affectedRows);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        });
    }

    public function count(): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) {
            AsyncEventLoop::getInstance()->nextTick(function () use ($resolve, $reject) {
                try {
                    $this->handler->addSelect(['COUNT(*) as count']);
                    $sql = $this->handler->buildSelectQuery();
                    $bindings = $this->handler->getBindings();
                    
                    $result = $this->connection->query($sql, $bindings);
                    $data = $this->connection->fetchOne($result);
                    
                    $resolve((int) $data['count']);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        });
    }

    public function exists(): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) {
            AsyncEventLoop::getInstance()->nextTick(function () use ($resolve, $reject) {
                try {
                    $this->handler->addSelect(['1']);
                    $this->handler->setLimit(1);
                    $sql = $this->handler->buildSelectQuery();
                    $bindings = $this->handler->getBindings();
                    
                    $result = $this->connection->query($sql, $bindings);
                    $data = $this->connection->fetchOne($result);
                    
                    $resolve($data !== null);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        });
    }
}