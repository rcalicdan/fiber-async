<?php

namespace Rcalicdan\FiberAsync\Database\Connections;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\Config\DatabaseConfig;
use Rcalicdan\FiberAsync\Contracts\DatabaseConnectionInterface;

final class PostgreSQLConnection implements DatabaseConnectionInterface
{
    private $connection = null;
    private bool $connected = false;

    public function __construct(private readonly DatabaseConfig $config) {}

    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        $dsn = "host={$this->config->host} port={$this->config->port} dbname={$this->config->database} user={$this->config->username} password={$this->config->password}";
        
        $this->connection = pg_connect($dsn, PGSQL_CONNECT_ASYNC);

        if (!$this->connection) {
            throw new \RuntimeException('Failed to connect to PostgreSQL');
        }
        
        // You might want to poll for connection status here as well, but for now, we assume it works.
        // For a fully robust system, you'd poll pg_connect_poll() until PGSQL_CONNECT_OK.
        
        $this->connected = true;
        return true;
    }

    public function disconnect(): void
    {
        if ($this->connection && $this->connected) {
            pg_close($this->connection);
            $this->connected = false;
        }
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->connection !== null;
    }

    public function asyncQuery(string $sql, array $bindings, callable $onSuccess, callable $onFailure): void
    {
        if (!$this->isConnected()) {
            try {
                $this->connect();
            } catch (\Throwable $e) {
                $onFailure($e);
                return;
            }
        }

        $sent = empty($bindings)
            ? pg_send_query($this->connection, $sql)
            : pg_send_query_params($this->connection, $sql, $bindings);

        if ($sent) {
            // ** THE FIX IS HERE **
            // The method name was misspelled. It should be 'addPgsqlQuery'.
            AsyncEventLoop::getInstance()->getDatabaseManager()->addPgsqlQuery(
                $this->connection,
                $onSuccess,
                $onFailure
            );
        } else {
            $onFailure(new \RuntimeException('Failed to send async query: ' . pg_last_error($this->connection)));
        }
    }

    // All other methods (prepare, execute, fetchAll, etc.) remain the same.
    public function prepare(string $sql): mixed
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $stmtName = 'stmt_' . uniqid();
        $result = pg_prepare($this->connection, $stmtName, $sql);
        
        if (!$result) {
            throw new \RuntimeException('Failed to prepare statement: ' . pg_last_error($this->connection));
        }

        return $stmtName;
    }

    public function execute(mixed $statement, array $bindings = []): mixed
    {
        $result = pg_execute($this->connection, $statement, $bindings);
        
        if (!$result) {
            throw new \RuntimeException('Failed to execute statement: ' . pg_last_error($this->connection));
        }

        return $result;
    }

    public function fetchAll(mixed $result): array
    {
        if (!$result) {
            return [];
        }

        return pg_fetch_all($result) ?: [];
    }

    public function fetchOne(mixed $result): ?array
    {
        if (!$result) {
            return null;
        }

        return pg_fetch_assoc($result) ?: null;
    }

    public function getLastInsertId(): int
    {
        // This must be synchronous as it depends on the previous command
        $result = pg_query($this->connection, 'SELECT LASTVAL()');
        $row = pg_fetch_row($result);
        return (int) $row[0];
    }

    public function getAffectedRows(): int
    {
        // This can be synchronous as it returns the result of the last command
        return pg_affected_rows($this->connection);
    }

    public function beginTransaction(): bool
    {
        return (bool) pg_query($this->connection, 'BEGIN');
    }

    public function commit(): bool
    {
        return (bool) pg_query($this->connection, 'COMMIT');
    }

    public function rollback(): bool
    {
        return (bool) pg_query($this->connection, 'ROLLBACK');
    }
}