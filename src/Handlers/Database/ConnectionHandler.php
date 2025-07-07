<?php
// src/Handlers/Database/ConnectionHandler.php

namespace Rcalicdan\FiberAsync\Handlers\Database;

use Rcalicdan\FiberAsync\Config\DatabaseConfig;
use Rcalicdan\FiberAsync\Contracts\DatabaseConnectionInterface;
use Rcalicdan\FiberAsync\Database\Connections\MySQLConnection;
use Rcalicdan\FiberAsync\Database\Connections\PostgreSQLConnection;

final readonly class ConnectionHandler
{
    public function __construct(private DatabaseConfig $config) {}

    public function createConnection(): DatabaseConnectionInterface
    {
        return match ($this->config->driver) {
            'mysql' => new MySQLConnection($this->config),
            'pgsql' => new PostgreSQLConnection($this->config),
            default => throw new \InvalidArgumentException("Unsupported database driver: {$this->config->driver}")
        };
    }

    public function isValidDriver(string $driver): bool
    {
        return in_array($driver, ['mysql', 'pgsql']);
    }
}