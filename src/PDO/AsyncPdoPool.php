<?php

namespace Rcalicdan\FiberAsync\PDO;

use PDO;
use PDOException;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise as AsyncPromise;
use SplQueue;

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
     * @param  array  $dbConfig  The database configuration array, compatible with PDOManager.
     * @param  int  $maxSize  The maximum number of concurrent connections allowed.
     */
    public function __construct(array $dbConfig, int $maxSize = 10)
    {
        $this->dbConfig = $dbConfig;
        $this->maxSize = $maxSize;
        $this->pool = new SplQueue;
        $this->waiters = new SplQueue;
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
        if (! $this->pool->isEmpty()) {
            return Promise::resolve($this->pool->dequeue());
        }

        // If we haven't reached our max connection limit, create a new one.
        if ($this->activeConnections < $this->maxSize) {
            $this->activeConnections++;

            try {
                $connection = $this->createConnection();

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
     * Releases a PDO connection back to the pool.
     *
     * If other fibers are waiting for a connection, the connection is passed
     * directly to the next waiting fiber. Otherwise, it's returned to the
     * idle pool.
     *
     * @param  PDO  $connection  The PDO connection to release.
     */
    public function release(PDO $connection): void
    {
        // If a fiber is waiting, give this connection to it directly.
        if (! $this->waiters->isEmpty()) {
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
        $this->pool = new SplQueue;
        $this->waiters = new SplQueue;
        $this->activeConnections = 0;
        // Existing PDO objects will be closed by PHP's garbage collector when references are lost.
    }

    /**
     * Creates a new PDO connection based on the provided configuration.
     */
    private function createConnection(): PDO
    {
        $config = $this->dbConfig;
        $dsn = $this->buildDSN($config);

        return new PDO(
            $dsn,
            $config['username'] ?? null,
            $config['password'] ?? null,
            $config['options'] ?? []
        );
    }

    /**
     * Builds a DSN string for PDO based on the provided configuration.
     *
     * @param array $config The database configuration array
     * @return string The DSN string
     * @throws PDOException If the driver is not set or not supported
     */
    private function buildDSN(array $config): string
    {
        if (!isset($config['driver']) || empty($config['driver'])) {
            throw new PDOException("Database driver is not set in configuration");
        }

        return match ($config['driver']) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'] ?? 'localhost',
                $config['port'] ?? 3306,
                $config['database'] ?? '',
                $config['charset'] ?? 'utf8mb4'
            ),

            'pgsql', 'postgresql' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $config['host'] ?? 'localhost',
                $config['port'] ?? 5432,
                $config['database'] ?? ''
            ),

            'sqlite' => 'sqlite:' . ($config['database'] ?? ':memory:'),

            'sqlsrv', 'mssql' => $this->buildSqlSrvDSN($config),

            'oci', 'oracle' => $this->buildOciDSN($config),

            'ibm', 'db2' => $this->buildIbmDSN($config),

            'odbc' => 'odbc:' . ($config['dsn'] ?? $config['database'] ?? ''),

            'firebird' => 'firebird:dbname=' . ($config['database'] ?? ''),

            'informix' => $this->buildInformixDSN($config),

            default => throw new PDOException("Unsupported database driver for pool: {$config['driver']}")
        };
    }

    /**
     * Builds a SQL Server DSN string.
     */
    private function buildSqlSrvDSN(array $config): string
    {
        $dsn = 'sqlsrv:server=' . ($config['host'] ?? 'localhost');

        if (isset($config['port']) && $config['port'] != 1433) {
            $dsn .= ',' . $config['port'];
        }

        if (isset($config['database'])) {
            $dsn .= ';Database=' . $config['database'];
        }

        return $dsn;
    }

    /**
     * Builds an Oracle DSN string.
     */
    private function buildOciDSN(array $config): string
    {
        $dsn = 'oci:dbname=';

        if (isset($config['host'])) {
            $dsn .= '//' . $config['host'];

            if (isset($config['port'])) {
                $dsn .= ':' . $config['port'];
            }

            $dsn .= '/';
        }

        $dsn .= $config['database'] ?? '';

        if (isset($config['charset'])) {
            $dsn .= ';charset=' . $config['charset'];
        }

        return $dsn;
    }

    /**
     * Builds an IBM DB2 DSN string.
     */
    private function buildIbmDSN(array $config): string
    {
        $dsn = 'ibm:';

        if (isset($config['database'])) {
            $dsn .= $config['database'];
        } elseif (isset($config['dsn'])) {
            $dsn .= $config['dsn'];
        }

        return $dsn;
    }

    /**
     * Builds an Informix DSN string.
     */
    private function buildInformixDSN(array $config): string
    {
        $dsn = 'informix:';

        if (isset($config['host'])) {
            $dsn .= 'host=' . $config['host'] . ';';
        }

        if (isset($config['database'])) {
            $dsn .= 'database=' . $config['database'] . ';';
        }

        if (isset($config['server'])) {
            $dsn .= 'server=' . $config['server'] . ';';
        }

        if (isset($config['protocol'])) {
            $dsn .= 'protocol=' . $config['protocol'] . ';';
        }

        if (isset($config['service'])) {
            $dsn .= 'service=' . $config['service'] . ';';
        }

        return $dsn;
    }
}
