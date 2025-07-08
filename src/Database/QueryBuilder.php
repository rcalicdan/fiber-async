<?php

namespace Rcalicdan\FiberAsync\Database;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Contracts\Database\DatabaseClientInterface;

class QueryBuilder
{
    private DatabaseClientInterface $client;
    private string $table = '';
    private array $select = ['*'];
    private array $where = [];
    private array $joins = [];
    private array $orderBy = [];
    private array $groupBy = [];
    private array $having = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private array $bindings = [];

    public function __construct(DatabaseClientInterface $client)
    {
        $this->client = $client;
    }

    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    public function select(array $columns = ['*']): self
    {
        $this->select = $columns;
        return $this;
    }

    public function where(string $column, string $operator, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->where[] = ['type' => 'basic', 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => 'and'];
        $this->bindings[] = $value;
        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->where[] = ['type' => 'basic', 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => 'or'];
        $this->bindings[] = $value;
        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        $this->where[] = ['type' => 'in', 'column' => $column, 'values' => $values, 'boolean' => 'and'];
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = ['type' => 'inner', 'table' => $table, 'first' => $first, 'operator' => $operator, 'second' => $second];
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = ['type' => 'left', 'table' => $table, 'first' => $first, 'operator' => $operator, 'second' => $second];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orderBy[] = ['column' => $column, 'direction' => strtolower($direction)];
        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        $this->groupBy = array_merge($this->groupBy, $columns);
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function get(): PromiseInterface
    {
        $sql = $this->toSql();
        return $this->client->query($sql, $this->bindings);
    }

    public function first(): PromiseInterface
    {
        $this->limit(1);
        return $this->get()->then(function ($result) {
            // SAFER CHECK: Ensure 'rows' key exists and is not empty.
            if (isset($result['rows']) && !empty($result['rows'])) {
                return $result['rows'][0];
            }
            return null;
        });
    }

    public function find(mixed $id): PromiseInterface
    {
        return $this->where('id', '=', $id)->first();
    }

    public function insert(array $data): PromiseInterface
    {
        if (empty($data)) {
            return reject(new \InvalidArgumentException('Insert data cannot be empty.'));
        }
        $sql = $this->buildInsertQuery($data);
        return $this->client->query($sql, array_values($data));
    }

    public function update(array $data): PromiseInterface
    {
        if (empty($data)) {
            return reject(new \InvalidArgumentException('Update data cannot be empty.'));
        }
        $sql = $this->buildUpdateQuery($data);
        $bindings = array_merge(array_values($data), $this->bindings);
        return $this->client->query($sql, $bindings);
    }

    public function delete(): PromiseInterface
    {
        $sql = $this->buildDeleteQuery();
        return $this->client->query($sql, $this->bindings);
    }

    public function toSql(): string
    {
        return $this->buildSelectQuery();
    }

    private function buildSelectQuery(): string
    {
        $sql = 'SELECT ' . implode(', ', $this->select) . ' FROM ' . $this->table;

        if (!empty($this->joins)) {
            $sql .= ' ' . $this->buildJoins();
        }

        if (!empty($this->where)) {
            $sql .= ' WHERE ' . $this->buildWhereClause();
        }

        if (!empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        if (!empty($this->orderBy)) {
            $sql .= ' ORDER BY ' . $this->buildOrderBy();
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    private function buildInsertQuery(array $data): string
    {
        $columns = implode('`, `', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        return "INSERT INTO `{$this->table}` (`{$columns}`) VALUES ({$placeholders})";
    }

    private function buildUpdateQuery(array $data): string
    {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = "`{$column}` = ?";
        }

        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $sets);

        if (!empty($this->where)) {
            $sql .= ' WHERE ' . $this->buildWhereClause();
        }

        return $sql;
    }

    private function buildDeleteQuery(): string
    {
        $sql = "DELETE FROM `{$this->table}`";

        if (!empty($this->where)) {
            $sql .= ' WHERE ' . $this->buildWhereClause();
        }

        return $sql;
    }

    /**
     * MAJOR FIX: Rewritten to be more robust and readable.
     */
    private function buildWhereClause(): string
    {
        $sqlParts = [];

        foreach ($this->where as $index => $condition) {
            $connector = ($index > 0) ? "{$condition['boolean']} " : '';

            if ($condition['type'] === 'basic') {
                $sqlParts[] = "{$connector}`{$condition['column']}` {$condition['operator']} ?";
            } elseif ($condition['type'] === 'in') {
                $placeholders = implode(', ', array_fill(0, count($condition['values']), '?'));
                $sqlParts[] = "{$connector}`{$condition['column']}` IN ({$placeholders})";
            }
        }

        return implode(' ', $sqlParts);
    }

    private function buildJoins(): string
    {
        return implode(' ', array_map(function ($join) {
            return "{$join['type']} JOIN `{$join['table']}` ON `{$join['first']}` {$join['operator']} `{$join['second']}`";
        }, $this->joins));
    }

    private function buildOrderBy(): string
    {
        return implode(', ', array_map(function ($order) {
            return "`{$order['column']}` {$order['direction']}";
        }, $this->orderBy));
    }
}
