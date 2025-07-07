<?php

namespace Rcalicdan\FiberAsync\Config;

final readonly class DatabaseConfig
{
    public string $driver;
    public string $host;
    public string $port;
    public string $database;
    public string $username;
    public string $password;
    public string $charset;
    public int $timeout;

    public function __construct()
    {
        $this->driver = $_ENV['DB_DRIVER'] ?? 'mysql';
        $this->host = $_ENV['DB_HOST'] ?? 'localhost';
        $this->port = $_ENV['DB_PORT'] ?? ($this->driver === 'pgsql' ? '5432' : '3306');
        $this->database = $_ENV['DB_DATABASE'] ?? '';
        $this->username = $_ENV['DB_USERNAME'] ?? '';
        $this->password = $_ENV['DB_PASSWORD'] ?? '';
        $this->charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';
        $this->timeout = (int) ($_ENV['DB_TIMEOUT'] ?? 30);
    }
}