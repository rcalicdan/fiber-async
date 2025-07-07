<?php

namespace Rcalicdan\FiberAsync\Database\Connections;

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
        
        $this->connection = pg_connect($dsn);

        if (!$this->connection) {
            throw new \RuntimeException('Failed to connect to PostgreSQL');
        }

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

    public function query(string $sql, array $bindings = []): mixed
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        if (empty($bindings)) {
            return pg_query($this->connection, $sql);
        }

        return pg_query_params($this->connection, $sql, $bindings);
    }

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
        $result = pg_query($this->connection, 'SELECT LASTVAL()');
        $row = pg_fetch_row($result);
        return (int) $row[0];
    }

    public function getAffectedRows(): int
    {
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