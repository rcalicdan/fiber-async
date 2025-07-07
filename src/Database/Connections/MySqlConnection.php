<?php
// src/Database/Connections/MySQLConnection.php

namespace Rcalicdan\FiberAsync\Database\Connections;

use Rcalicdan\FiberAsync\Config\DatabaseConfig;
use Rcalicdan\FiberAsync\Contracts\DatabaseConnectionInterface;

final class MySQLConnection implements DatabaseConnectionInterface
{
    private $connection = null;
    private bool $connected = false;

    public function __construct(private readonly DatabaseConfig $config) {}

    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        $this->connection = mysqli_connect(
            $this->config->host,
            $this->config->username,
            $this->config->password,
            $this->config->database,
            (int) $this->config->port
        );

        if (!$this->connection) {
            throw new \RuntimeException('Failed to connect to MySQL: ' . mysqli_connect_error());
        }

        mysqli_set_charset($this->connection, $this->config->charset);
        $this->connected = true;

        return true;
    }

    public function disconnect(): void
    {
        if ($this->connection && $this->connected) {
            mysqli_close($this->connection);
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
            return mysqli_query($this->connection, $sql);
        }

        $stmt = $this->prepare($sql);
        return $this->execute($stmt, $bindings);
    }

    public function prepare(string $sql): mixed
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $stmt = mysqli_prepare($this->connection, $sql);
        
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare statement: ' . mysqli_error($this->connection));
        }

        return $stmt;
    }

    public function execute(mixed $statement, array $bindings = []): mixed
    {
        if (!empty($bindings)) {
            $types = '';
            foreach ($bindings as $binding) {
                $types .= match (gettype($binding)) {
                    'integer' => 'i',
                    'double' => 'd',
                    'string' => 's',
                    default => 's'
                };
            }

            mysqli_stmt_bind_param($statement, $types, ...$bindings);
        }

        if (!mysqli_stmt_execute($statement)) {
            throw new \RuntimeException('Failed to execute statement: ' . mysqli_stmt_error($statement));
        }

        return mysqli_stmt_get_result($statement);
    }

    public function fetchAll(mixed $result): array
    {
        if (!$result) {
            return [];
        }

        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function fetchOne(mixed $result): ?array
    {
        if (!$result) {
            return null;
        }

        return mysqli_fetch_assoc($result) ?: null;
    }

    public function getLastInsertId(): int
    {
        return mysqli_insert_id($this->connection);
    }

    public function getAffectedRows(): int
    {
        return mysqli_affected_rows($this->connection);
    }

    public function beginTransaction(): bool
    {
        return mysqli_begin_transaction($this->connection);
    }

    public function commit(): bool
    {
        return mysqli_commit($this->connection);
    }

    public function rollback(): bool
    {
        return mysqli_rollback($this->connection);
    }
}