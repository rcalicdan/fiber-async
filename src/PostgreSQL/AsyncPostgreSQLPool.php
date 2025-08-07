<?php

namespace Rcalicdan\FiberAsync\PostgreSQL;

use PgSql\Connection;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise as AsyncPromise;
use SplQueue;

class AsyncPostgreSQLPool
{
    private SplQueue $pool;
    private SplQueue $waiters;
    private int $maxSize;
    private int $activeConnections = 0;
    private array $dbConfig;
    private ?Connection $lastConnection = null;
    private bool $configValidated = false;

    public function __construct(array $dbConfig, int $maxSize = 10)
    {
        $this->validateDbConfig($dbConfig);
        $this->configValidated = true;
        $this->dbConfig = $dbConfig;
        $this->maxSize = $maxSize;
        $this->pool = new SplQueue;
        $this->waiters = new SplQueue;
    }

    public function get(): PromiseInterface
    {
        if (!$this->pool->isEmpty()) {
            $connection = $this->pool->dequeue();
            $this->lastConnection = $connection;
            return Promise::resolve($connection);
        }

        if ($this->activeConnections < $this->maxSize) {
            $this->activeConnections++;

            try {
                $connection = $this->createConnection();
                $this->lastConnection = $connection;

                return Promise::resolve($connection);
            } catch (\Throwable $e) {
                $this->activeConnections--;
                return Promise::reject($e);
            }
        }

        $promise = new AsyncPromise;
        $this->waiters->enqueue($promise);

        return $promise;
    }

    public function release(Connection $connection): void
    {
        if (!$this->isConnectionAlive($connection)) {
            $this->activeConnections--;
            
            if (!$this->waiters->isEmpty() && $this->activeConnections < $this->maxSize) {
                $this->activeConnections++;
                $promise = $this->waiters->dequeue();
                
                try {
                    $newConnection = $this->createConnection();
                    $this->lastConnection = $newConnection;
                    $promise->resolve($newConnection);
                } catch (\Throwable $e) {
                    $this->activeConnections--;
                    $promise->reject($e);
                }
            }
            return;
        }

        $this->resetConnectionState($connection);

        if (!$this->waiters->isEmpty()) {
            $promise = $this->waiters->dequeue();
            $this->lastConnection = $connection;
            $promise->resolve($connection);
        } else {
            $this->pool->enqueue($connection);
        }
    }

    public function getLastConnection(): ?Connection
    {
        return $this->lastConnection;
    }

    public function getStats(): array
    {
        return [
            'active_connections' => $this->activeConnections,
            'pooled_connections' => $this->pool->count(),
            'waiting_requests' => $this->waiters->count(),
            'max_size' => $this->maxSize,
            'config_validated' => $this->configValidated,
        ];
    }

    public function close(): void
    {
        while (!$this->pool->isEmpty()) {
            $connection = $this->pool->dequeue();
            if ($this->isConnectionAlive($connection)) {
                pg_close($connection);
            }
        }

        while (!$this->waiters->isEmpty()) {
            $promise = $this->waiters->dequeue();
            $promise->reject(new \RuntimeException('Pool is being closed'));
        }

        $this->pool = new SplQueue;
        $this->waiters = new SplQueue;
        $this->activeConnections = 0;
        $this->lastConnection = null;
        $this->configValidated = false;
    }

    private function validateDbConfig(array $dbConfig): void
    {
        if (empty($dbConfig)) {
            throw new \InvalidArgumentException('Database configuration cannot be empty');
        }

        $requiredFields = ['host', 'username', 'database'];

        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $dbConfig)) {
                throw new \InvalidArgumentException("Missing required database configuration field: '{$field}'");
            }

            if ($field !== 'username' && $field !== 'password' && empty($dbConfig[$field])) {
                throw new \InvalidArgumentException("Database configuration field '{$field}' cannot be empty");
            }
        }

        if (isset($dbConfig['port']) && (!is_int($dbConfig['port']) || $dbConfig['port'] <= 0)) {
            throw new \InvalidArgumentException('Database port must be a positive integer');
        }

        if (isset($dbConfig['host']) && !is_string($dbConfig['host'])) {
            throw new \InvalidArgumentException('Database host must be a string');
        }

        if (isset($dbConfig['sslmode']) && !in_array($dbConfig['sslmode'], ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'])) {
            throw new \InvalidArgumentException('Invalid sslmode value');
        }
    }

    private function createConnection(): Connection
    {
        $config = $this->dbConfig;
        
        $connectionString = $this->buildConnectionString($config);
        
        $connection = pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);
        
        if ($connection === false) {
            throw new \RuntimeException('PostgreSQL Connection failed');
        }

        $status = pg_connection_status($connection);
        if ($status !== PGSQL_CONNECTION_OK) {
            $error = pg_last_error($connection);
            pg_close($connection);
            throw new \RuntimeException('PostgreSQL Connection failed: ' . ($error ?: 'Unknown error'));
        }

        return $connection;
    }

    private function buildConnectionString(array $config): string
    {
        $parts = [];
        
        $parts[] = 'host=' . $config['host'];
        $parts[] = 'user=' . $config['username'];
        $parts[] = 'dbname=' . $config['database'];
        
        if (isset($config['password'])) {
            $parts[] = 'password=' . $config['password'];
        }
        
        if (isset($config['port'])) {
            $parts[] = 'port=' . $config['port'];
        }
        
        if (isset($config['sslmode'])) {
            $parts[] = 'sslmode=' . $config['sslmode'];
        }
        
        if (isset($config['connect_timeout'])) {
            $parts[] = 'connect_timeout=' . $config['connect_timeout'];
        }

        return implode(' ', $parts);
    }

    private function isConnectionAlive(Connection $connection): bool
    {
        try {
            $status = pg_connection_status($connection);
            return $status === PGSQL_CONNECTION_OK;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function resetConnectionState(Connection $connection): void
    {
        try {
            if (pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
                pg_query($connection, 'ROLLBACK');
            }
        } catch (\Throwable $e) {
            // If reset fails, connection will be considered dead
        }
    }
}