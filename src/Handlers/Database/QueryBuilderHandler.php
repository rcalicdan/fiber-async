<?php
// src/Handlers/Database/QueryBuilderHandler.php

namespace Rcalicdan\FiberAsync\Handlers\Database;

use Rcalicdan\FiberAsync\Contracts\DatabaseConnectionInterface;

final class QueryBuilderHandler
{
    private array $select = ['*'];
    private string $table = '';
    private array $joins = [];
    private array $where = [];
    private array $orWhere = [];
    private array $orderBy = [];
    private array $groupBy = [];
    private array $having = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private array $bindings = [];

    public function __construct(private readonly DatabaseConnectionInterface $connection) {}

    public function reset(): void
    {
        $this->select = ['*'];
        $this->table = '';
        $this->joins = [];
        $this->where = [];
        $this->orWhere = [];
        $this->orderBy = [];
        $this->groupBy = [];
        $this->having = [];
        $this->limit = null;
        $this->offset = null;
        $this->bindings = [];
    }

    public function setTable(string $table): void
    {
        $this->table = $table;
    }

    public function addSelect(array $columns): void
    {
        $this->select = $columns;
    }

    public function addWhere(string $column, string $operator, mixed $value): void
    {
        $this->where[] = compact('column', 'operator', 'value');
        $this->bindings[] = $value;
    }

    public function addOrWhere(string $column, string $operator, mixed $value): void
    {
        $this->orWhere[] = compact('column', 'operator', 'value');
        $this->bindings[] = $value;
    }

    public function addJoin(string $table, string $first, string $operator, string $second, string $type = 'INNER'): void
    {
        $this->joins[] = compact('table', 'first', 'operator', 'second', 'type');
    }

    public function addOrderBy(string $column, string $direction = 'ASC'): void
    {
        $this->orderBy[] = compact('column', 'direction');
    }

    public function addGroupBy(string $column): void
    {
        $this->groupBy[] = $column;
    }

    public function setLimit(int $limit): void
    {
        $this->limit = $limit;
    }

    public function setOffset(int $offset): void
    {
        $this->offset = $offset;
    }

    public function buildSelectQuery(): string
    {
        $sql = 'SELECT ' . implode(', ', $this->select);
        $sql .= ' FROM ' . $this->table;

        if (!empty($this->joins)) {
            foreach ($this->joins as $join) {
                $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['first']} {$join['operator']} {$join['second']}";
            }
        }

        if (!empty($this->where)) {
            $sql .= ' WHERE ';
            $conditions = [];
            foreach ($this->where as $condition) {
                $conditions[] = "{$condition['column']} {$condition['operator']} ?";
            }
            $sql .= implode(' AND ', $conditions);

            if (!empty($this->orWhere)) {
                foreach ($this->orWhere as $condition) {
                    $sql .= " OR {$condition['column']} {$condition['operator']} ?";
                }
            }
        }

        if (!empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        if (!empty($this->orderBy)) {
            $sql .= ' ORDER BY ';
            $orders = [];
            foreach ($this->orderBy as $order) {
                $orders[] = "{$order['column']} {$order['direction']}";
            }
            $sql .= implode(', ', $orders);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    public function buildInsertQuery(array $data): string
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $this->bindings = array_values($data);

        return "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
    }

    public function buildUpdateQuery(array $data): string
    {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = "{$column} = ?";
        }
        $this->bindings = array_merge(array_values($data), $this->bindings);

        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets);

        if (!empty($this->where)) {
            $sql .= ' WHERE ';
            $conditions = [];
            foreach ($this->where as $condition) {
                $conditions[] = "{$condition['column']} {$condition['operator']} ?";
            }
            $sql .= implode(' AND ', $conditions);
        }

        return $sql;
    }

    public function buildDeleteQuery(): string
    {
        $sql = "DELETE FROM {$this->table}";

        if (!empty($this->where)) {
            $sql .= ' WHERE ';
            $conditions = [];
            foreach ($this->where as $condition) {
                $conditions[] = "{$condition['column']} {$condition['operator']} ?";
            }
            $sql .= implode(' AND ', $conditions);
        }

        return $sql;
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }
}