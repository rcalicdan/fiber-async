<?php
// src/Database/Config/DatabaseConfig.php

namespace Rcalicdan\FiberAsync\Database\Config;

use Dotenv\Dotenv;

class DatabaseConfig
{
    public readonly string $host;
    public readonly int $port;
    public readonly string $database;
    public readonly string $username;
    public readonly string $password;
    public readonly string $charset;
    public readonly int $timeout;
    public readonly int $maxConnections;
    public readonly int $minConnections;
    public readonly array $options;

    private static bool $envLoaded = false;

    public function __construct(array $config = [])
    {
        $this->loadEnv();
        
        $this->host = $config['host'] ?? $this->getEnv('DB_HOST', 'localhost');
        $this->port = (int) ($config['port'] ?? $this->getEnv('DB_PORT', 3306));
        $this->database = $config['database'] ?? $this->getEnv('DB_DATABASE', '');
        $this->username = $config['username'] ?? $this->getEnv('DB_USERNAME', '');
        $this->password = $config['password'] ?? $this->getEnv('DB_PASSWORD', '');
        $this->charset = $config['charset'] ?? $this->getEnv('DB_CHARSET', 'utf8mb4');
        $this->timeout = (int) ($config['timeout'] ?? $this->getEnv('DB_TIMEOUT', 30));
        $this->maxConnections = (int) ($config['max_connections'] ?? $this->getEnv('DB_MAX_CONNECTIONS', 10));
        $this->minConnections = (int) ($config['min_connections'] ?? $this->getEnv('DB_MIN_CONNECTIONS', 1));
        $this->options = $config['options'] ?? [];
    }

    private function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }

        $envPath = $this->findEnvFile();
        
        if ($envPath && file_exists($envPath . '/.env')) {
            $dotenv = Dotenv::createImmutable($envPath);
            $dotenv->safeLoad(); 
        }
        
        self::$envLoaded = true;
    }

    private function findEnvFile(): ?string
    {
        $paths = [
            getcwd(),                      
            dirname(__DIR__, 3),            
            dirname(__DIR__, 4),          
            $_SERVER['DOCUMENT_ROOT'] ?? '',    
        ];

        foreach ($paths as $path) {
            if ($path && file_exists($path . '/.env')) {
                return $path;
            }
        }

        return null;
    }

    public function getEnv(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }

    public static function fromEnv(): self
    {
        return new self();
    }

    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'charset' => $this->charset,
            'timeout' => $this->timeout,
            'max_connections' => $this->maxConnections,
            'min_connections' => $this->minConnections,
            'options' => $this->options,
        ];
    }
}