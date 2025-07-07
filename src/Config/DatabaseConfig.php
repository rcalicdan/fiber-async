<?php
// src/Config/DatabaseConfig.php

namespace Rcalicdan\FiberAsync\Config;

use Dotenv\Dotenv;

final readonly class DatabaseConfig
{
    public string $driver;
    public string $host;
    public int $port;
    public string $database;
    public string $username;
    public string $password;
    public string $charset;

    public function __construct()
    {
        $this->loadEnv();

        $this->driver = $_ENV['DB_DRIVER'] ?? 'mysql';
        $this->host = $_ENV['DB_HOST'] ?? 'localhost';
        $this->port = (int) ($_ENV['DB_PORT'] ?? $this->getDefaultPort());
        $this->database = $_ENV['DB_DATABASE'] ?? '';
        $this->username = $_ENV['DB_USERNAME'] ?? '';
        $this->password = $_ENV['DB_PASSWORD'] ?? '';
        $this->charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

        $this->validate();
    }

    private function loadEnv(): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $rootPath = $this->findProjectRoot();

        if (file_exists($rootPath . '/.env')) {
            $dotenv = Dotenv::createImmutable($rootPath);
            $dotenv->load();
        }

        $loaded = true;
    }

    private function findProjectRoot(): string
    {
        $currentDir = __DIR__;

        while ($currentDir !== '/') {
            if (file_exists($currentDir . '/composer.json') || file_exists($currentDir . '/.env')) {
                return $currentDir;
            }
            $currentDir = dirname($currentDir);
        }

        return getcwd();
    }

    private function getDefaultPort(): int
    {
        return match ($this->driver) {
            'mysql' => 3306,
            'pgsql' => 5432,
            default => 3306
        };
    }

    private function validate(): void
    {
        if (empty($this->driver)) {
            throw new \InvalidArgumentException('Database driver is required');
        }

        if (empty($this->host)) {
            throw new \InvalidArgumentException('Database host is required');
        }

        if (empty($this->database)) {
            throw new \InvalidArgumentException('Database name is required');
        }

        if (empty($this->username)) {
            throw new \InvalidArgumentException('Database username is required');
        }

        if (!in_array($this->driver, ['mysql', 'pgsql'])) {
            throw new \InvalidArgumentException("Unsupported database driver: {$this->driver}");
        }
    }
}
