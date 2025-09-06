<?php

namespace Rcalicdan\FiberAsync\MySQL;

use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise;
use SplQueue;

/**
 * An asynchronous MySQL connection pool for fiber-based operations.
 */
class MySQLPool
{
    private SplQueue $pool;
    private SplQueue $waiters;
    private int $maxSize;
    private int $activeConnections = 0;
    private array $connectionParams;

    public function __construct(array $connectionParams, int $maxSize = 10)
    {
        $this->connectionParams = $connectionParams;
        $this->maxSize = $maxSize;
        $this->pool = new SplQueue;
        $this->waiters = new SplQueue;
    }

    /**
     * Gets a MySQL client connection from the pool.
     */
    public function get(): PromiseInterface
    {
        if (! $this->pool->isEmpty()) {
            return Promise::resolved($this->pool->dequeue());
        }

        // If we haven't reached max connections, create a new one
        if ($this->activeConnections < $this->maxSize) {
            $this->activeConnections++;

            return Async::async(function () {
                try {
                    $client = new MySQLClient($this->connectionParams);
                    await($client->connect());

                    return $client;
                } catch (\Throwable $e) {
                    $this->activeConnections--;

                    throw $e;
                }
            })();
        }

        // Pool is full, wait for a connection to be released
        $promise = new Promise;
        $this->waiters->enqueue($promise);

        return $promise;
    }

    /**
     * Releases a MySQL client connection back to the pool.
     */
    public function release(MySQLClient $client): void
    {
        // If a fiber is waiting, give this connection directly
        if (! $this->waiters->isEmpty()) {
            $promise = $this->waiters->dequeue();
            $promise->resolve($client);
        } else {
            // Add to idle pool
            $this->pool->enqueue($client);
        }
    }

    /**
     * Closes all connections and clears the pool.
     */
    public function close(): void
    {
        // Close all idle connections
        while (! $this->pool->isEmpty()) {
            $client = $this->pool->dequeue();

            try {
                await($client->close());
            } catch (\Throwable $e) {
                // Ignore errors during shutdown
            }
        }

        // Reject all waiting promises
        while (! $this->waiters->isEmpty()) {
            $promise = $this->waiters->dequeue();
            $promise->reject(new \RuntimeException('Pool is closing'));
        }

        $this->activeConnections = 0;
    }
}
