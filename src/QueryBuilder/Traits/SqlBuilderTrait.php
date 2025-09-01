<?php

namespace Rcalicdan\FiberAsync\QueryBuilder\Traits;

trait SqlBuilderTrait
{
    /**
     * Build the SELECT SQL query string.
     *
     * @return string The complete SELECT SQL query.
     */
    protected function buildSelectQuery(): string
    {
        $sql = 'SELECT '.implode(', ', $this->select);
        $sql .= ' FROM '.$this->table;

        foreach ($this->joins as $join) {
            if ($join['type'] === 'CROSS') {
                $sql .= " CROSS JOIN {$join['table']}";
            } else {
                $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['condition']}";
            }
        }

        $whereSql = $this->buildWhereClause();
        if ($whereSql !== '') {
            $sql .= ' WHERE '.$whereSql;
        }

        if ($this->groupBy !== []) {
            $sql .= ' GROUP BY '.implode(', ', $this->groupBy);
        }

        if ($this->having !== []) {
            $sql .= ' HAVING '.implode(' AND ', $this->having);
        }

        if ($this->orderBy !== []) {
            $sql .= ' ORDER BY '.implode(', ', $this->orderBy);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT '.$this->limit;
            if ($this->offset !== null) {
                $sql .= ' OFFSET '.$this->offset;
            }
        }

        return $sql;
    }

    /**
     * Build the COUNT SQL query string.
     *
     * @param  string  $column  The column to count.
     * @return string The complete COUNT SQL query.
     */
    protected function buildCountQuery(string $column = '*'): string
    {
        $sql = "SELECT COUNT({$column}) FROM ".$this->table;

        foreach ($this->joins as $join) {
            if ($join['type'] === 'CROSS') {
                $sql .= " CROSS JOIN {$join['table']}";
            } else {
                $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['condition']}";
            }
        }

        $whereSql = $this->buildWhereClause();
        if ($whereSql !== '') {
            $sql .= ' WHERE '.$whereSql;
        }

        if ($this->groupBy !== []) {
            $sql .= ' GROUP BY '.implode(', ', $this->groupBy);
        }

        if ($this->having !== []) {
            $sql .= ' HAVING '.implode(' AND ', $this->having);
        }

        return $sql;
    }

    /**
     * Build the INSERT SQL query string.
     *
     * @param  array<string, mixed>  $data  The data to insert.
     * @return string The complete INSERT SQL query.
     */
    protected function buildInsertQuery(array $data): string
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        return "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
    }

    /**
     * Build the INSERT BATCH SQL query string.
     *
     * @param  array<array<string, mixed>>  $data  The data array for batch insert.
     * @return string The complete INSERT SQL query.
     *
     * @throws \InvalidArgumentException When data format is invalid.
     */
    protected function buildInsertBatchQuery(array $data): string
    {
        $firstRow = $data[0];
        if (! is_array($firstRow)) {
            throw new \InvalidArgumentException('Invalid data format for batch insert');
        }

        $columns = implode(', ', array_keys($firstRow));
        $placeholders = '('.implode(', ', array_fill(0, count($firstRow), '?')).')';
        $allPlaceholders = implode(', ', array_fill(0, count($data), $placeholders));

        return "INSERT INTO {$this->table} ({$columns}) VALUES {$allPlaceholders}";
    }

    /**
     * Build the UPDATE SQL query string.
     *
     * @param  array<string, mixed>  $data  The data to update.
     * @return string The complete UPDATE SQL query.
     */
    protected function buildUpdateQuery(array $data): string
    {
        $setClauses = [];
        foreach (array_keys($data) as $column) {
            $setClauses[] = "{$column} = ?";
        }
        $sql = "UPDATE {$this->table} SET ".implode(', ', $setClauses);
        $whereSql = $this->buildWhereClause();
        if ($whereSql !== '') {
            $sql .= ' WHERE '.$whereSql;
        }

        return $sql;
    }

    /**
     * Build the DELETE SQL query string.
     *
     * @return string The complete DELETE SQL query.
     */
    protected function buildDeleteQuery(): string
    {
        $sql = "DELETE FROM {$this->table}";
        $whereSql = $this->buildWhereClause();
        if ($whereSql !== '') {
            $sql .= ' WHERE '.$whereSql;
        }

        return $sql;
    }

    /**
     * Build the WHERE clause portion of the SQL query.
     *
     * @return string The WHERE clause string or empty string if no conditions.
     */
    protected function buildWhereClause(): string
    {
        $allParts = $this->collectAllConditionParts();

        if ($allParts === []) {
            return '';
        }

        return $this->combineConditionParts($allParts);
    }

    /**
     * Collect all condition parts from different sources.
     *
     * @return array<array{conditions: array<string>, operator: string, priority: int}> All condition parts.
     */
    protected function collectAllConditionParts(): array
    {
        $parts = [];

        $andConditions = array_merge(
            $this->where,
            $this->whereIn,
            $this->whereNotIn,
            $this->whereBetween,
            $this->whereNull,
            $this->whereNotNull,
            $this->whereRaw
        );

        $filteredAnd = array_filter($andConditions, fn ($condition) => trim($condition) !== '');
        if ($filteredAnd !== []) {
            $parts[] = ['conditions' => $filteredAnd, 'operator' => 'AND', 'priority' => 1];
        }

        $orConditions = array_merge($this->orWhere, $this->orWhereRaw);
        $filteredOr = array_filter($orConditions, fn ($condition) => trim($condition) !== '');
        if ($filteredOr !== []) {
            $parts[] = ['conditions' => $filteredOr, 'operator' => 'OR', 'priority' => 2];
        }

        return $parts;
    }

    /**
     * Build a group of conditions with the same logical operator.
     *
     * @param  array<string>  $conditions  Array of condition strings.
     * @param  string  $operator  The logical operator (AND/OR).
     * @return string The built condition group.
     */
    protected function buildConditionGroup(array $conditions, string $operator): string
    {
        $filteredConditions = array_filter($conditions, fn ($condition) => trim($condition) !== '');

        if ($filteredConditions === []) {
            return '';
        }

        if (count($filteredConditions) === 1) {
            return reset($filteredConditions);
        }

        return '('.implode(' '.strtoupper($operator).' ', $filteredConditions).')';
    }

    /**
     * Combine different condition parts with appropriate logic.
     *
     * @param  array<array{conditions: array<string>, operator: string, priority: int}>  $parts  Array of condition parts.
     * @return string The combined condition string.
     */
    protected function combineConditionParts(array $parts): string
    {
        if ($parts === []) {
            return '';
        }

        usort($parts, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        $andParts = [];
        $orParts = [];

        foreach ($parts as $part) {
            if ($part['conditions'] === []) {
                continue;
            }

            $conditionString = $this->buildConditionGroup($part['conditions'], $part['operator']);

            if ($conditionString === '') {
                continue;
            }

            if ($part['operator'] === 'AND') {
                $andParts[] = $conditionString;
            } else {
                $orParts[] = $conditionString;
            }
        }

        return $this->combineAndOrParts($andParts, $orParts);
    }

    /**
     * Combine AND and OR parts with proper precedence.
     *
     * @param  array<string>  $andParts  AND condition parts.
     * @param  array<string>  $orParts  OR condition parts.
     * @return string The combined condition string.
     */
    protected function combineAndOrParts(array $andParts, array $orParts): string
    {
        $finalParts = [];

        if ($andParts !== []) {
            if (count($andParts) === 1) {
                $finalParts[] = $andParts[0];
            } else {
                $finalParts[] = '('.implode(' AND ', $andParts).')';
            }
        }

        foreach ($orParts as $orPart) {
            $finalParts[] = $orPart;
        }

        return implode(' OR ', $finalParts);
    }

    /**
     * Get all conditions organized by their logical operators.
     *
     * @return array<string, array<string>> Array of conditions grouped by operator type.
     */
    protected function getAllConditions(): array
    {
        return [
            'AND' => array_merge(
                $this->where,
                $this->whereIn,
                $this->whereNotIn,
                $this->whereBetween,
                $this->whereNull,
                $this->whereNotNull,
                $this->whereRaw
            ),
            'OR' => array_merge($this->orWhere, $this->orWhereRaw),
        ];
    }
}
