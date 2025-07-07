<?php

namespace Rcalicdan\FiberAsync\Config;

final readonly class DatabaseConfig
{
    public string $driver;
    public string $host;
    public int $port;  
    public string $database;
    public string $username;
    public string $password;
    public string $charset;
    public int $timeout;

    public function __construct()
    {
        $this->driver = getenv('DB_DRIVER') ?: 'mysql';
        $this->host = getenv('DB_HOST') ?: 'localhost';
        $this->port = (int) (getenv('DB_PORT') ?: ($this->driver === 'pgsql' ? '5432' : '3306'));
        $this->database = getenv('DB_DATABASE') ?: '';
        $this->username = getenv('DB_USERNAME') ?: '';
        $this->password = getenv('DB_PASSWORD') ?: '';
        $this->charset = getenv('DB_CHARSET') ?: 'utf8mb4';
        $this->timeout = (int) (getenv('DB_TIMEOUT') ?: 30);
    }
}