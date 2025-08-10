<?php

namespace Rcalicdan\FiberAsync\PostgreSQL;

use InvalidArgumentException;
use PgSql\Connection;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise;
use RuntimeException;
use SplQueue;
use Throwable;

/**
 * Manages a pool of asynchronous PostgreSQL connections.
 *
 * This class provides a robust mechanism for managing a limited number of database
 * connections, preventing resource exhaustion and reducing the latency associated
 * with establishing new connections for every query. It uses promises to handle
 * asynchronous acquisition of connections.
 */
class AsyncPostgreSQLPool
{
    /**
     * @var SplQueue<Connection> A queue of available, idle connections.
     */
    private SplQueue $pool;

    /**
     * @var SplQueue<Promise<Connection>> A queue of pending requests (waiters) for a connection.
     */
    private SplQueue $waiters;

    /**
     * @var int The maximum number of concurrent connections allowed.
     */
    private int $maxSize;

    /**
     * @var int The current number of active connections (both in pool and in use).
     */
    private int $activeConnections = 0;

    /**
     * @var array<string, mixed> The database connection configuration.
     */
    private array $dbConfig;

    /**
     * @var Connection|null The most recently used or created connection.
     */
    private ?Connection $lastConnection = null;

    /**
     * @var bool Flag indicating if the initial configuration was validated.
     */
    private bool $configValidated = false;

    /**
     * Creates a new PostgreSQL connection pool.
     *
     * @param  array<string, mixed>  $dbConfig  The database connection parameters (host, user, dbname, etc.).
     * @param  int  $maxSize  The maximum number of connections this pool can manage.
     *
     * @throws InvalidArgumentException If the database configuration is invalid.
     */
    public function __construct(array $dbConfig, int $maxSize = 10)
    {
        $this->validateDbConfig($dbConfig);
        $this->configValidated = true;
        $this->dbConfig = $dbConfig;
        $this->maxSize = $maxSize;
        $this->pool = new SplQueue;
        $this->waiters = new SplQueue;
    }

    /**
     * Asynchronously acquires a connection from the pool.
     *
     * This method returns a promise that will be fulfilled with a `Connection` object.
     * The logic is as follows:
     * 1. If an idle connection is available in the pool, it is returned immediately.
     * 2. If no connection is available but the pool is not at max capacity, a new one is created.
     * 3. If the pool is at max capacity, the request is enqueued and will be fulfilled later
     *    when another connection is released.
     *
     * @return PromiseInterface<Connection> A promise that resolves with a database connection.
     */
    public function get(): PromiseInterface
    {
        if (! $this->pool->isEmpty()) {
            $connection = $this->pool->dequeue();
            $this->lastConnection = $connection;

            /** @var PromiseInterface<Connection> $promise */
            $promise = Promise::resolved($connection);

            return $promise;
        }

        if ($this->activeConnections < $this->maxSize) {
            $this->activeConnections++;

            try {
                $connection = $this->createConnection();
                $this->lastConnection = $connection;

                /** @var PromiseInterface<Connection> $promise */
                $promise = Promise::resolved($connection);

                return $promise;
            } catch (Throwable $e) {
                $this->activeConnections--;
                /** @var PromiseInterface<Connection> $promise */
                $promise = Promise::rejected($e);

                return $promise;
            }
        }

        /** @var Promise<Connection> $promise */
        $promise = new Promise;
        $this->waiters->enqueue($promise);

        return $promise;
    }

    /**
     * Releases a connection back to the pool.
     *
     * After use, a connection must be released to make it available for other requests.
     * This method will first check if there are any waiting requests and fulfill one if so.
     * Otherwise, it returns the connection to the idle pool.
     *
     * @param  Connection  $connection  The connection to release.
     */
    public function release(Connection $connection): void
    {
        if (! $this->isConnectionAlive($connection)) {
            $this->activeConnections--;
            // If a waiter exists and we have capacity, create a new connection for them.
            if (! $this->waiters->isEmpty() && $this->activeConnections < $this->maxSize) {
                $this->activeConnections++;
                /** @var Promise<Connection> $promise */
                $promise = $this->waiters->dequeue();

                try {
                    $newConnection = $this->createConnection();
                    $this->lastConnection = $newConnection;
                    $promise->resolve($newConnection);
                } catch (Throwable $e) {
                    $this->activeConnections--;
                    $promise->reject($e);
                }
            }

            return;
        }

        $this->resetConnectionState($connection);

        // Prioritize giving the connection to a waiting request.
        if (! $this->waiters->isEmpty()) {
            /** @var Promise<Connection> $promise */
            $promise = $this->waiters->dequeue();
            $this->lastConnection = $connection;
            $promise->resolve($connection);
        } else {
            $this->pool->enqueue($connection);
        }
    }

    /**
     * Gets the most recently active connection handled by the pool.
     *
     * @return Connection|null The last connection object or null if none have been handled.
     */
    public function getLastConnection(): ?Connection
    {
        return $this->lastConnection;
    }

    /**
     * Retrieves statistics about the current state of the connection pool.
     *
     * @return array<string, mixed> An associative array with pool metrics.
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
     * Closes all connections and shuts down the pool.
     *
     * This method closes all idle connections and rejects any pending connection requests.
     * The pool is reset to an empty state and cannot be used until re-initialized.
     */
    public function close(): void
    {
        while (! $this->pool->isEmpty()) {
            $connection = $this->pool->dequeue();
            if ($this->isConnectionAlive($connection)) {
                pg_close($connection);
            }
        }
        while (! $this->waiters->isEmpty()) {
            /** @var Promise<Connection> $promise */
            $promise = $this->waiters->dequeue();
            $promise->reject(new RuntimeException('Pool is being closed'));
        }
        $this->pool = new SplQueue;
        $this->waiters = new SplQueue;
        $this->activeConnections = 0;
        $this->lastConnection = null;
    }

    /**
     * Validates the provided database configuration array.
     *
     * @param  array<string, mixed>  $dbConfig
     *
     * @throws InvalidArgumentException If any required fields are missing or invalid.
     */
    private function validateDbConfig(array $dbConfig): void
    {
        if (count($dbConfig) === 0) {
            throw new InvalidArgumentException('Database configuration cannot be empty');
        }
        $requiredFields = ['host', 'username', 'database'];
        foreach ($requiredFields as $field) {
            if (! array_key_exists($field, $dbConfig)) {
                throw new InvalidArgumentException("Missing required database configuration field: '{$field}'");
            }
            if (in_array($field, ['host', 'database'], true) && ($dbConfig[$field] === '' || $dbConfig[$field] === null)) {
                throw new InvalidArgumentException("Database configuration field '{$field}' cannot be empty");
            }
        }
        if (isset($dbConfig['port']) && (! is_int($dbConfig['port']) || $dbConfig['port'] <= 0)) {
            throw new InvalidArgumentException('Database port must be a positive integer');
        }
        if (isset($dbConfig['host']) && ! is_string($dbConfig['host'])) {
            throw new InvalidArgumentException('Database host must be a string');
        }
        if (isset($dbConfig['sslmode']) && ! in_array($dbConfig['sslmode'], ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            throw new InvalidArgumentException('Invalid sslmode value');
        }
    }

    /**
     * Establishes a new PostgreSQL connection.
     *
     * @return Connection The newly created connection resource.
     *
     * @throws RuntimeException If the connection fails.
     */
    private function createConnection(): Connection
    {
        $connectionString = $this->buildConnectionString($this->dbConfig);
        $connection = pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);

        if ($connection === false) {
            // PHPStan has proven pg_last_error() is guaranteed to return a string here.
            throw new RuntimeException('PostgreSQL Connection failed: '.pg_last_error());
        }

        if (pg_connection_status($connection) !== PGSQL_CONNECTION_OK) {
            // PHPStan has proven pg_last_error() is guaranteed to return a string here.
            $error = pg_last_error($connection);
            pg_close($connection);

            throw new RuntimeException('PostgreSQL Connection failed: '.$error);
        }

        return $connection;
    }

    /**
     * Constructs a PostgreSQL connection string from a configuration array.
     *
     * @param  array<string, mixed>  $config  The database configuration.
     * @return string The formatted connection string.
     *
     * @throws InvalidArgumentException On invalid configuration types.
     */
    private function buildConnectionString(array $config): string
    {
        if (! isset($config['host']) || ! is_string($config['host']) ||
            ! isset($config['username']) || ! is_string($config['username']) ||
            ! isset($config['database']) || ! is_string($config['database'])) {
            throw new InvalidArgumentException('Host, username, and database must be non-empty strings.');
        }

        $parts = [
            'host='.$config['host'],
            'user='.$config['username'],
            'dbname='.$config['database'],
        ];

        if (isset($config['password']) && is_string($config['password'])) {
            $parts[] = 'password='.$config['password'];
        }
        if (isset($config['port']) && is_int($config['port'])) {
            $parts[] = 'port='.$config['port'];
        }
        if (isset($config['sslmode']) && is_string($config['sslmode'])) {
            $parts[] = 'sslmode='.$config['sslmode'];
        }
        if (isset($config['connect_timeout']) && is_int($config['connect_timeout'])) {
            $parts[] = 'connect_timeout='.$config['connect_timeout'];
        }

        return implode(' ', $parts);
    }

    /**
     * Checks if a connection is still active and usable.
     *
     * @param  Connection  $connection  The connection to check.
     * @return bool True if the connection is alive.
     */
    private function isConnectionAlive(Connection $connection): bool
    {
        try {
            return pg_connection_status($connection) === PGSQL_CONNECTION_OK;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Resets the state of a connection before returning it to the pool.
     *
     * This method ensures that any open transactions are rolled back, so the
     * connection is clean for its next use.
     *
     * @param  Connection  $connection  The connection to reset.
     */
    private function resetConnectionState(Connection $connection): void
    {
        try {
            if (pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
                pg_query($connection, 'ROLLBACK');
            }
        } catch (Throwable $e) {
            // If reset fails, isConnectionAlive() will catch it on the next cycle.
        }
    }
}
