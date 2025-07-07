<?php

namespace Rcalicdan\FiberAsync\Managers;

final class DatabaseManager
{
    /** @var array<int, array{link: \mysqli, onSuccess: callable, onFailure: callable}> */
    private array $pendingMysqlQueries = [];

    /** @var array<int, array{connection: \PgSql\Connection, onSuccess: callable, onFailure: callable}> */
    private array $pendingPgsqlQueries = [];

    /**
     * Add a pending asynchronous MySQL query to be polled.
     */
    public function addMysqlQuery(\mysqli $link, callable $onSuccess, callable $onFailure): void
    {
        $this->pendingMysqlQueries[spl_object_id($link)] = compact('link', 'onSuccess', 'onFailure');
    }

    /**
     * Add a pending asynchronous PostgreSQL query to be polled.
     */
    public function addPgsqlQuery(\PgSql\Connection $connection, callable $onSuccess, callable $onFailure): void
    {
        $this->pendingPgsqlQueries[spl_object_id($connection)] = compact('connection', 'onSuccess', 'onFailure');
    }

    /**
     * Check if there are any database queries pending.
     */
    public function hasPendingQueries(): bool
    {
        return !empty($this->pendingMysqlQueries) || !empty($this->pendingPgsqlQueries);
    }

    /**
     * Process all pending database queries for one event loop tick.
     */
    public function processPendingQueries(): bool
    {
        $workDone = false;
        
        if (!empty($this->pendingMysqlQueries)) {
            $workDone = $this->pollMysql() || $workDone;
        }

        if (!empty($this->pendingPgsqlQueries)) {
            $workDone = $this->pollPgsql() || $workDone;
        }

        return $workDone;
    }

    private function pollMysql(): bool
    {
        $links = $errors = $rejects = [];
        foreach ($this->pendingMysqlQueries as $query) {
            $links[] = $query['link'];
        }

        if (empty($links)) {
            return false;
        }

        // Poll with a 0-second timeout to be non-blocking
        $readyCount = mysqli_poll($links, $errors, $rejects, 0);
        if ($readyCount === 0) {
            return false;
        }

        foreach ($links as $link) {
            $this->handleMysqlResult($link, fn($link) => mysqli_reap_async_query($link), 'onSuccess');
        }
        
        foreach ($errors as $link) {
            $this->handleMysqlResult($link, fn($link) => new \RuntimeException('MySQL query error: ' . mysqli_error($link)), 'onFailure');
        }

        foreach ($rejects as $link) {
            $this->handleMysqlResult($link, fn($link) => new \RuntimeException('MySQL query rejected: ' . mysqli_error($link)), 'onFailure');
        }

        return true;
    }

    private function handleMysqlResult(\mysqli $link, callable $resultProvider, string $callbackType): void
    {
        $id = spl_object_id($link);
        if (isset($this->pendingMysqlQueries[$id])) {
            $query = $this->pendingMysqlQueries[$id];
            unset($this->pendingMysqlQueries[$id]);
            
            try {
                $result = $resultProvider($link);
                $query[$callbackType]($result);
            } catch (\Throwable $e) {
                $query['onFailure']($e);
            }
        }
    }

    private function pollPgsql(): bool
    {
        $workDone = false;
        foreach ($this->pendingPgsqlQueries as $id => $query) {
            $connection = $query['connection'];
            
            // pg_connection_busy is non-blocking
            if (pg_connection_busy($connection)) {
                continue;
            }

            // Consume the input from the socket to make pg_get_result non-blocking
            pg_consume_input($connection);
            
            $result = pg_get_result($connection);
            
            // `false` means the command is not yet complete.
            // We only process when we get a result resource.
            if ($result === false) {
                 // Check if it's really finished with no result, or an error.
                if (pg_result_status($result) === PGSQL_COMMAND_OK) {
                    // It's finished, e.g. an UPDATE/DELETE
                } else if (pg_last_error($connection)) {
                    // An error occurred
                    $error = new \RuntimeException('PostgreSQL query failed: ' . pg_last_error($connection));
                    $query['onFailure']($error);
                    unset($this->pendingPgsqlQueries[$id]);
                    $workDone = true;
                    continue;
                } else {
                    // Still busy, will check next tick.
                    continue;
                }
            }

            // Query is complete, process it.
            unset($this->pendingPgsqlQueries[$id]);
            $workDone = true;

            $status = pg_result_status($result);
            if ($status === PGSQL_COMMAND_OK || $status === PGSQL_TUPLES_OK) {
                $query['onSuccess']($result);
            } else {
                $error = new \RuntimeException('PostgreSQL query failed: ' . pg_result_error($result));
                $query['onFailure']($error);
            }
        }
        return $workDone;
    }
}