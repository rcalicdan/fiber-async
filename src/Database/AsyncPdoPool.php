<?php

namespace Rcalicdan\FiberAsync\Database;

use PDO;
use SplQueue;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\AsyncPromise;

/**
 * An asynchronous, fiber-aware PDO connection pool.
 *
 * This pool manages a limited number of PDO connections, allowing multiple
 * concurrent fibers to share them efficiently without blocking the event loop.
 */
class AsyncPdoPool
{
    private SplQueue $pool;
    private SplQueue $waiters;
    private int $maxSize;
    private int $activeConnections = 0;
    private array $dbConfig;

    /**
     * Creates a new connection pool.
     *
     * @param array $dbConfig The database configuration array, compatible with PDOManager.
     * @param int $maxSize The maximum number of concurrent connections allowed.
     */
    public function __construct(array $dbConfig, int $maxSize = 10)
    {
        $this->dbConfig = $dbConfig;
        $this->maxSize = $maxSize;
        $this->pool = new SplQueue();
        $this->waiters = new SplQueue();
    }

    /**
     * Asynchronously acquires a PDO connection from the pool.
     *
     * If a connection is available, it returns an instantly resolved promise.
     * If the pool is full, it returns a promise that will resolve when a
     * connection is released by another fiber.
     *
     * @return PromiseInterface<PDO>
     */
    public function get(): PromiseInterface
    {
        // If an idle connection is waiting in the pool, use it.
        if (!$this->pool->isEmpty()) {
            return Async::resolve($this->pool->dequeue());
        }

        // If we haven't reached our max connection limit, create a new one.
        if ($this->activeConnections < $this->maxSize) {
            $this->activeConnections++;
            try {
                $connection = $this->createConnection();
                return Async::resolve($connection);
            } catch (\Throwable $e) {
                // If connection fails, decrement count and reject the promise.
                $this->activeConnections--;
                return Async::reject($e);
            }
        }

        // If the pool is full, wait for a connection to be released.
        $promise = new AsyncPromise();
        $this->waiters->enqueue($promise);
        return $promise;
    }

    /**
     * Releases a PDO connection back to the pool.
     *
     * If other fibers are waiting for a connection, the connection is passed
     * directly to the next waiting fiber. Otherwise, it's returned to the
     * idle pool.
     *
     * @param PDO $connection The PDO connection to release.
     */
    public function release(PDO $connection): void
    {
        // If a fiber is waiting, give this connection to it directly.
        if (!$this->waiters->isEmpty()) {
            $promise = $this->waiters->dequeue();
            $promise->resolve($connection);
        } else {
            // Otherwise, add the connection to the pool of available connections.
            $this->pool->enqueue($connection);
        }
    }

    /**
     * Closes all connections and clears the pool.
     * Useful for graceful application shutdown.
     */
    public function close(): void
    {
        $this->pool = new SplQueue();
        $this->waiters = new SplQueue();
        $this->activeConnections = 0;
        // Existing PDO objects will be closed by PHP's garbage collector when references are lost.
    }

    /**
     * Creates a new PDO connection based on the provided configuration.
     *
     * @return PDO
     */
    private function createConnection(): PDO
    {
        // This logic is borrowed from your PDOManager to ensure consistency.
        $config = $this->dbConfig;
        $dsn = $this->buildDSN($config);

        return new PDO(
            $dsn,
            $config['username'] ?? null,
            $config['password'] ?? null,
            $config['options'] ?? []
        );
    }

    private function buildDSN(array $config): string
    {
        switch ($config['driver']) {
            case 'mysql':
                return sprintf(
                    "mysql:host=%s;port=%d;dbname=%s;charset=%s",
                    $config['host'] ?? 'localhost',
                    $config['port'] ?? 3306,
                    $config['database'] ?? '',
                    $config['charset'] ?? 'utf8mb4'
                );
            case 'sqlite':
                return "sqlite:" . ($config['database'] ?? ':memory:');
                // Add other drivers as needed
            default:
                throw new \InvalidArgumentException("Unsupported database driver for pool: {$config['driver']}");
        }
    }
}
