<?php

namespace Rcalicdan\FiberAsync\MySQLi;

use mysqli;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise as AsyncPromise;
use SplQueue;

/**
 * An asynchronous, fiber-aware MySQLi connection pool with true async support.
 */
class AsyncMySQLiPool
{
    private SplQueue $pool;
    private SplQueue $waiters;
    private int $maxSize;
    private int $activeConnections = 0;
    private array $dbConfig;
    private ?mysqli $lastConnection = null;
    private bool $configValidated = false;

    /**
     * Creates a new MySQLi connection pool.
     *
     * @param array $dbConfig Database configuration array
     * @param int $maxSize Maximum number of concurrent connections
     */
    public function __construct(array $dbConfig, int $maxSize = 10)
    {
        $this->validateDbConfig($dbConfig);
        $this->configValidated = true; // Mark as validated
        $this->dbConfig = $dbConfig;
        $this->maxSize = $maxSize;
        $this->pool = new SplQueue;
        $this->waiters = new SplQueue;
    }

    /**
     * Asynchronously acquires a MySQLi connection from the pool.
     *
     * @return PromiseInterface<mysqli>
     */
    public function get(): PromiseInterface
    {
        // If an idle connection is waiting in the pool, use it.
        if (!$this->pool->isEmpty()) {
            $connection = $this->pool->dequeue();
            $this->lastConnection = $connection;
            return Promise::resolve($connection);
        }

        // If we haven't reached our max connection limit, create a new one.
        if ($this->activeConnections < $this->maxSize) {
            $this->activeConnections++;

            try {
                $connection = $this->createConnection();
                $this->lastConnection = $connection;

                return Promise::resolve($connection);
            } catch (\Throwable $e) {
                // If connection fails, decrement count and reject the promise.
                $this->activeConnections--;

                return Promise::reject($e);
            }
        }

        // If the pool is full, wait for a connection to be released.
        $promise = new AsyncPromise;
        $this->waiters->enqueue($promise);

        return $promise;
    }

    /**
     * Releases a MySQLi connection back to the pool.
     *
     * @param mysqli $connection The MySQLi connection to release
     */
    public function release(mysqli $connection): void
    {
        // Check if connection is still alive before reusing
        if (!$this->isConnectionAlive($connection)) {
            $this->activeConnections--;
            
            // If there are waiters, try to create a new connection for them
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

        // Reset connection state
        $this->resetConnectionState($connection);

        // If a fiber is waiting, give this connection to it directly.
        if (!$this->waiters->isEmpty()) {
            $promise = $this->waiters->dequeue();
            $this->lastConnection = $connection;
            $promise->resolve($connection);
        } else {
            // Otherwise, add the connection to the pool of available connections.
            $this->pool->enqueue($connection);
        }
    }

    /**
     * Gets the last used connection (for affected_rows in execute operations).
     */
    public function getLastConnection(): ?mysqli
    {
        return $this->lastConnection;
    }

    /**
     * Gets pool statistics for monitoring.
     */
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

    /**
     * Closes all connections and clears the pool.
     */
    public function close(): void
    {
        while (!$this->pool->isEmpty()) {
            $connection = $this->pool->dequeue();
            if ($this->isConnectionAlive($connection)) {
                $connection->close();
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

    /**
     * Validates database configuration - called only once during construction.
     */
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

        if (isset($dbConfig['charset']) && !is_string($dbConfig['charset'])) {
            throw new \InvalidArgumentException('Database charset must be a string');
        }

        if (isset($dbConfig['socket']) && !is_string($dbConfig['socket'])) {
            throw new \InvalidArgumentException('Database socket must be a string');
        }
    }

    /**
     * Creates a new MySQLi connection using validated config.
     */
    private function createConnection(): mysqli
    {
        $config = $this->dbConfig;

        $mysqli = new mysqli(
            $config['host'],           
            $config['username'],         
            $config['password'] ?? '', 
            $config['database'],       
            $config['port'] ?? 3306,
            $config['socket'] ?? null
        );

        if ($mysqli->connect_error) {
            throw new \RuntimeException('MySQLi Connection failed: ' . $mysqli->connect_error);
        }

        if (isset($config['charset'])) {
            if (!$mysqli->set_charset($config['charset'])) {
                throw new \RuntimeException('Failed to set charset: ' . $mysqli->error);
            }
        }

        return $mysqli;
    }

    /**
     * Checks if a MySQLi connection is still alive using a lightweight query.
     * This replaces the deprecated ping() method.
     */
    private function isConnectionAlive(mysqli $connection): bool
    {
        try {
            $result = $connection->query('SELECT 1', MYSQLI_USE_RESULT);
            
            if ($result === false) {
                return false;
            }
            
            if ($result instanceof \mysqli_result) {
                $result->free();
            }
            
            return true;
            
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resets connection state to clean it for reuse.
     */
    private function resetConnectionState(mysqli $connection): void
    {
        try {
            // Clear any remaining result sets
            if ($connection->more_results()) {
                while ($connection->next_result()) {
                    if ($result = $connection->store_result()) {
                        $result->free();
                    }
                }
            }

            // Reset connection state
            $connection->autocommit(true);
            
        } catch (\Throwable $e) {
            // If reset fails, connection will be considered dead
            // and will be caught by isConnectionAlive check
        }
    }
}