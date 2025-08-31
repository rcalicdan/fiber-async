<?php

namespace Rcalicdan\FiberAsync\QueryBuilder\Traits;

trait QueryDebugTrait
{
    /**
     * Get the built SQL query for debugging purposes.
     *
     * @return string The complete SQL query.
     */
    public function toSql(): string
    {
        return $this->buildSelectQuery();
    }

    /**
     * Get the parameter bindings for debugging purposes.
     *
     * @return array<mixed> The parameter bindings.
     */
    public function getBindings(): array
    {
        return $this->getCompiledBindings();
    }

    /**
     * Get the SQL query with bindings interpolated (for debugging only).
     * WARNING: This should NEVER be used for actual query execution due to SQL injection risks.
     *
     * @return string The SQL query with values interpolated.
     */
    public function toRawSql(): string
    {
        $sql = $this->toSql();
        $bindings = $this->getBindings();

        if (empty($bindings)) {
            return $sql;
        }

        foreach ($bindings as $binding) {
            $value = $this->formatValueForDisplay($binding);
            $sql = preg_replace('/\?/', $value, $sql, 1);
        }

        return $sql;
    }

    /**
     * Dump the query and continue execution (like Laravel's dump()).
     *
     * @return static Returns the same query builder instance for method chaining.
     */
    public function dump(): static
    {
        $this->displayQuery(false);
        return $this;
    }

    /**
     * Dump the query and die (like Laravel's dd()).
     *
     * @return never
     */
    public function dd(): never
    {
        $this->displayQuery(true);
        exit(1);
    }

    /**
     * Display query information in a clean, Laravel-like format.
     *
     * @param  bool  $die  Whether to stop execution after displaying.
     * @return void
     */
    protected function displayQuery(bool $die): void
    {
        $sql = $this->toSql();
        $bindings = $this->getBindings();
        $rawSql = $this->toRawSql();

        // Check if we're in CLI or web environment
        $isCli = php_sapi_name() === 'cli';

        if ($isCli) {
            $this->displayCliFormat($sql, $bindings, $rawSql, $die);
        } else {
            $this->displayWebFormat($sql, $bindings, $rawSql, $die);
        }
    }

    /**
     * Display query in CLI format.
     *
     * @param  string  $sql
     * @param  array<mixed>  $bindings
     * @param  string  $rawSql
     * @param  bool  $die
     * @return void
     */
    protected function displayCliFormat(string $sql, array $bindings, string $rawSql, bool $die): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo $die ? "Query Builder DD (Execution Stopped)\n" : "Query Builder Dump\n";
        echo str_repeat("=", 80) . "\n\n";

        echo "\033[1;36mSQL:\033[0m\n";
        echo $this->highlightSqlCli($sql) . "\n\n";

        echo "\033[1;33mBindings:\033[0m\n";
        if (empty($bindings)) {
            echo "  (no bindings)\n\n";
        } else {
            foreach ($bindings as $i => $binding) {
                $type = gettype($binding);
                $value = $this->formatValueForDisplay($binding);
                echo "  \033[0;32m[$i]\033[0m \033[0;35m($type)\033[0m $value\n";
            }
            echo "\n";
        }

        echo "\033[1;32mRaw SQL:\033[0m\n";
        echo $this->highlightSqlCli($rawSql) . "\n\n";

        // Show basic stats
        $this->displayBasicStats();

        echo str_repeat("=", 80) . "\n\n";
    }

    /**
     * Display query in web format.
     *
     * @param  string  $sql
     * @param  array<mixed>  $bindings
     * @param  string  $rawSql
     * @param  bool  $die
     * @return void
     */
    protected function displayWebFormat(string $sql, array $bindings, string $rawSql, bool $die): void
    {
        $title = $die ? "Query Builder DD (Execution Stopped)" : "Query Builder Dump";

        echo "<div style='background: #1e1e1e; color: #f8f8f2; font-family: \"Fira Code\", \"Consolas\", monospace; font-size: 14px; padding: 20px; margin: 10px 0; border-radius: 8px; border-left: 4px solid #50fa7b;'>";
        echo "<h3 style='margin: 0 0 20px 0; color: #50fa7b; font-size: 16px;'>🔍 $title</h3>";

        echo "<div style='margin-bottom: 15px;'>";
        echo "<strong style='color: #8be9fd;'>SQL:</strong><br>";
        echo "<pre style='background: #282a36; padding: 10px; border-radius: 4px; margin: 5px 0; overflow-x: auto; white-space: pre-wrap;'>";
        echo $this->highlightSqlWeb($sql);
        echo "</pre>";
        echo "</div>";

        echo "<div style='margin-bottom: 15px;'>";
        echo "<strong style='color: #f1fa8c;'>Bindings:</strong><br>";
        if (empty($bindings)) {
            echo "<span style='color: #6272a4; font-style: italic;'>(no bindings)</span>";
        } else {
            echo "<ul style='margin: 5px 0; padding-left: 20px;'>";
            foreach ($bindings as $i => $binding) {
                $type = gettype($binding);
                $value = htmlspecialchars($this->formatValueForDisplay($binding));
                echo "<li><span style='color: #50fa7b;'>[$i]</span> ";
                echo "<span style='color: #bd93f9;'>($type)</span> ";
                echo "<span style='color: #f8f8f2;'>$value</span></li>";
            }
            echo "</ul>";
        }
        echo "</div>";

        echo "<div style='margin-bottom: 15px;'>";
        echo "<strong style='color: #50fa7b;'>Raw SQL:</strong><br>";
        echo "<pre style='background: #282a36; padding: 10px; border-radius: 4px; margin: 5px 0; overflow-x: auto; white-space: pre-wrap;'>";
        echo $this->highlightSqlWeb($rawSql);
        echo "</pre>";
        echo "</div>";

        $this->displayBasicStatsWeb();

        echo "</div>";
    }

    /**
     * Highlight SQL for CLI output.
     *
     * @param  string  $sql
     * @return string
     */
    protected function highlightSqlCli(string $sql): string
    {
        $keywords = [
            'SELECT',
            'FROM',
            'WHERE',
            'JOIN',
            'INNER',
            'LEFT',
            'RIGHT',
            'OUTER',
            'GROUP BY',
            'ORDER BY',
            'HAVING',
            'LIMIT',
            'OFFSET',
            'AND',
            'OR',
            'IN',
            'NOT',
            'IS',
            'NULL',
            'LIKE',
            'BETWEEN',
            'EXISTS',
            'DISTINCT',
            'COUNT',
            'SUM',
            'AVG',
            'MIN',
            'MAX',
            'INSERT',
            'UPDATE',
            'DELETE'
        ];

        $highlighted = $sql;
        foreach ($keywords as $keyword) {
            $highlighted = preg_replace(
                '/\b' . preg_quote($keyword, '/') . '\b/i',
                "\033[1;94m$keyword\033[0m",
                $highlighted
            );
        }

        return $highlighted;
    }

    /**
     * Highlight SQL for web output.
     *
     * @param  string  $sql
     * @return string
     */
    protected function highlightSqlWeb(string $sql): string
    {
        $keywords = [
            'SELECT',
            'FROM',
            'WHERE',
            'JOIN',
            'INNER',
            'LEFT',
            'RIGHT',
            'OUTER',
            'GROUP BY',
            'ORDER BY',
            'HAVING',
            'LIMIT',
            'OFFSET',
            'AND',
            'OR',
            'IN',
            'NOT',
            'IS',
            'NULL',
            'LIKE',
            'BETWEEN',
            'EXISTS',
            'DISTINCT',
            'COUNT',
            'SUM',
            'AVG',
            'MIN',
            'MAX',
            'INSERT',
            'UPDATE',
            'DELETE'
        ];

        $highlighted = htmlspecialchars($sql);
        foreach ($keywords as $keyword) {
            $highlighted = preg_replace(
                '/\b' . preg_quote($keyword, '/') . '\b/i',
                '<span style="color: #ff79c6; font-weight: bold;">' . $keyword . '</span>',
                $highlighted
            );
        }

        return $highlighted;
    }

    /**
     * Display basic query statistics.
     *
     * @return void
     */
    protected function displayBasicStats(): void
    {
        $bindingCount = count($this->getBindings());
        $joinCount = count($this->joins ?? []);
        $conditionCount = $this->countConditions();

        echo "\033[1;37mStats:\033[0m\n";
        echo "  Table: \033[0;36m{$this->table}\033[0m\n";
        echo "  Bindings: \033[0;33m$bindingCount\033[0m\n";
        echo "  Joins: \033[0;33m$joinCount\033[0m\n";
        echo "  Conditions: \033[0;33m$conditionCount\033[0m\n";

        if ($this->limit !== null) {
            echo "  Limit: \033[0;33m{$this->limit}\033[0m\n";
        }

        if ($this->offset !== null) {
            echo "  Offset: \033[0;33m{$this->offset}\033[0m\n";
        }

        echo "\n";
    }

    /**
     * Display basic query statistics for web.
     *
     * @return void
     */
    protected function displayBasicStatsWeb(): void
    {
        $bindingCount = count($this->getBindings());
        $joinCount = count($this->joins ?? []);
        $conditionCount = $this->countConditions();

        echo "<div>";
        echo "<strong style='color: #f8f8f2;'>Stats:</strong><br>";
        echo "<span style='color: #6272a4;'>Table:</span> <span style='color: #8be9fd;'>{$this->table}</span> | ";
        echo "<span style='color: #6272a4;'>Bindings:</span> <span style='color: #f1fa8c;'>$bindingCount</span> | ";
        echo "<span style='color: #6272a4;'>Joins:</span> <span style='color: #f1fa8c;'>$joinCount</span> | ";
        echo "<span style='color: #6272a4;'>Conditions:</span> <span style='color: #f1fa8c;'>$conditionCount</span>";

        if ($this->limit !== null || $this->offset !== null) {
            echo "<br>";
            if ($this->limit !== null) {
                echo "<span style='color: #6272a4;'>Limit:</span> <span style='color: #f1fa8c;'>{$this->limit}</span> ";
            }
            if ($this->offset !== null) {
                echo "<span style='color: #6272a4;'>Offset:</span> <span style='color: #f1fa8c;'>{$this->offset}</span>";
            }
        }

        echo "</div>";
    }

    /**
     * Format a value for display in debug output.
     *
     * @param  mixed  $value
     * @return string
     */
    protected function formatValueForDisplay(mixed $value): string
    {
        if (is_null($value)) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            // Truncate very long strings
            if (strlen($value) > 100) {
                return "'" . substr($value, 0, 97) . "...'";
            }
            return "'" . $value . "'";
        }

        if (is_array($value) || is_object($value)) {
            $json = json_encode($value);
            if (strlen($json) > 100) {
                return substr($json, 0, 97) . "...";
            }
            return $json;
        }

        return (string) $value;
    }

    /**
     * Count total conditions in the query.
     *
     * @return int
     */
    protected function countConditions(): int
    {
        return count($this->where ?? []) +
            count($this->orWhere ?? []) +
            count($this->whereIn ?? []) +
            count($this->whereNotIn ?? []) +
            count($this->whereBetween ?? []) +
            count($this->whereNull ?? []) +
            count($this->whereNotNull ?? []) +
            count($this->whereRaw ?? []) +
            count($this->orWhereRaw ?? []);
    }
}
