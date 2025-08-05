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

    /**
     * Creates a new MySQLi connection pool.
     *
     * @param array $dbConfig Database configuration array
     * @param int $maxSize Maximum number of concurrent connections
     */
    public function __construct(array $dbConfig, int $maxSize = 10)
    {
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
        // Reset connection state
        if ($connection->more_results()) {
            while ($connection->next_result()) {
                if ($result = $connection->store_result()) {
                    $result->free();
                }
            }
        }

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
     * Closes all connections and clears the pool.
     */
    public function close(): void
    {
        // Close all pooled connections
        while (!$this->pool->isEmpty()) {
            $connection = $this->pool->dequeue();
            $connection->close();
        }

        // Reject all waiting promises
        while (!$this->waiters->isEmpty()) {
            $promise = $this->waiters->dequeue();
            $promise->reject(new \RuntimeException('Pool is being closed'));
        }

        $this->pool = new SplQueue;
        $this->waiters = new SplQueue;
        $this->activeConnections = 0;
        $this->lastConnection = null;
    }

    /**
     * Creates a new MySQLi connection.
     */
    private function createConnection(): mysqli
    {
        $config = $this->dbConfig;
        
        $mysqli = new mysqli(
            $config['host'] ?? 'localhost',
            $config['username'] ?? '',
            $config['password'] ?? '',
            $config['database'] ?? '',
            $config['port'] ?? 3306,
            $config['socket'] ?? null
        );

        if ($mysqli->connect_error) {
            throw new \RuntimeException('MySQLi Connection failed: ' . $mysqli->connect_error);
        }

        // Set charset if specified
        if (isset($config['charset'])) {
            $mysqli->set_charset($config['charset']);
        }

        return $mysqli;
    }
}