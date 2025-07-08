<?php

namespace Rcalicdan\FiberAsync\Database;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\Contracts\DatabaseClientInterface;

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
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = ['column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => 'and'];
        $this->bindings[] = $value;
        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = ['column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => 'or'];
        $this->bindings[] = $value;
        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        $this->where[] = ['column' => $column, 'operator' => 'in', 'value' => $values, 'boolean' => 'and'];
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = [
            'type' => 'inner',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
        ];
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = [
            'type' => 'left',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
        ];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orderBy[] = ['column' => $column, 'direction' => $direction];
        return $this;
    }

    public function groupBy(string $column): self
    {
        $this->groupBy[] = $column;
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
        $sql = $this->buildSelectQuery();
        return $this->client->query($sql, $this->bindings);
    }

    public function first(): PromiseInterface
    {
        $this->limit(1);
        return $this->get()->then(function ($result) {
            return $result['rows'][0] ?? null;
        });
    }

    public function find(mixed $id): PromiseInterface
    {
        return $this->where('id', $id)->first();
    }

    public function insert(array $data): PromiseInterface
    {
        $sql = $this->buildInsertQuery($data);
        return $this->client->query($sql, array_values($data));
    }

    public function update(array $data): PromiseInterface
    {
        $sql = $this->buildUpdateQuery($data);
        $bindings = array_merge(array_values($data), $this->bindings);
        return $this->client->query($sql, $bindings);
    }

    public function delete(): PromiseInterface
    {
        $sql = $this->buildDeleteQuery();
        return $this->client->query($sql, $this->bindings);
    }

    private function buildSelectQuery(): string
    {
        $sql = 'SELECT ' . implode(', ', $this->select) . ' FROM ' . $this->table;
        
        if (!empty($this->joins)) {
            foreach ($this->joins as $join) {
                $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['first']} {$join['operator']} {$join['second']}";
            }
        }
        
        if (!empty($this->where)) {
            $sql .= ' WHERE ' . $this->buildWhereClause();
        }
        
        if (!empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }
        
        if (!empty($this->orderBy)) {
            $orderClauses = [];
            foreach ($this->orderBy as $order) {
                $orderClauses[] = "{$order['column']} {$order['direction']}";
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderClauses);
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
        $columns = implode(', ', array_keys($data));
        $placeholders = str_repeat('?, ', count($data) - 1) . '?';
        
        return "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
    }

    private function buildUpdateQuery(array $data): string
    {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = "{$column} = ?";
        }
        
        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets);
        
        if (!empty($this->where)) {
            $sql .= ' WHERE ' . $this->buildWhereClause();
        }
        
        return $sql;
    }

    private function buildDeleteQuery(): string
    {
        $sql = "DELETE FROM {$this->table}";
        
        if (!empty($this->where)) {
            $sql .= ' WHERE ' . $this->buildWhereClause();
        }
        
        return $sql;
    }

    private function buildWhereClause(): string
    {
        $clauses = [];
        
        foreach ($this->where as $i => $condition) {
            $boolean = $i === 0 ? '' : " {$condition['boolean']} ";
            
            if ($condition['operator'] === 'in') {
                $placeholders = str_repeat('?, ', count($condition['value']) - 1) . '?';
                $clauses[] = "{$boolean}{$condition['column']} IN ({$placeholders})";
            } else {
                $clauses[] = "{$boolean}{$condition['column']} {$condition['operator']} ?";
            }
        }
        
        return implode('', $clauses);
    }
}