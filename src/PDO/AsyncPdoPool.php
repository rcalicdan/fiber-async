<?php

namespace Rcalicdan\FiberAsync\PDO;

use PDO;
use PDOException;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise;
use SplQueue;
use Throwable;
use InvalidArgumentException;
use RuntimeException;

/**
 * An asynchronous, fiber-aware PDO connection pool.
 *
 * This pool manages a limited number of PDO connections, allowing multiple
 * concurrent fibers to share them efficiently without blocking the event loop.
 * It handles connection limits, waiting queues, and safe connection reuse.
 */
class AsyncPdoPool
{
    /** 
     * @var SplQueue<PDO> A queue of available, idle connections.
     */
    private SplQueue $pool;

    /**
     * @var SplQueue<Promise<PDO>> A queue of pending requests (waiters) for a connection.
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
     * @var bool Flag indicating if the initial configuration was validated.
     */
    private bool $configValidated = false;

    /**
     * @var PDO|null The most recently used or created connection.
     */
    private ?PDO $lastConnection = null;

    /**
     * Creates a new connection pool.
     *
     * @param array<string, mixed> $dbConfig The database configuration array, compatible with PDO.
     * @param int $maxSize The maximum number of concurrent connections allowed.
     * @throws InvalidArgumentException When the database configuration is invalid.
     */
    public function __construct(array $dbConfig, int $maxSize = 10)
    {
        $this->validateDbConfig($dbConfig);
        $this->configValidated = true;
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
     * @return PromiseInterface<PDO> A promise that resolves with a PDO connection object.
     */
    public function get(): PromiseInterface
    {
        if (!$this->pool->isEmpty()) {
            /** @var PDO $connection */
            $connection = $this->pool->dequeue();
            $this->lastConnection = $connection;

            /** @var PromiseInterface<PDO> $promise */
            $promise = Promise::resolved($connection);
            return $promise;
        }

        if ($this->activeConnections < $this->maxSize) {
            $this->activeConnections++;
            try {
                $connection = $this->createConnection();
                $this->lastConnection = $connection;

                /** @var PromiseInterface<PDO> $promise */
                $promise = Promise::resolved($connection);
                return $promise;
            } catch (Throwable $e) {
                $this->activeConnections--;
                /** @var PromiseInterface<PDO> $promise */
                $promise = Promise::rejected($e);
                return $promise;
            }
        }

        /** @var Promise<PDO> $promise */
        $promise = new Promise();
        $this->waiters->enqueue($promise);
        return $promise;
    }

    /**
     * Releases a PDO connection back to the pool for reuse.
     *
     * If other fibers are waiting for a connection, the connection is passed
     * directly to the next waiting fiber. Otherwise, it's returned to the
     * idle pool.
     *
     * @param PDO $connection The PDO connection to release.
     */
    public function release(PDO $connection): void
    {
        if (!$this->isConnectionAlive($connection)) {
            $this->activeConnections--;
            if (!$this->waiters->isEmpty() && $this->activeConnections < $this->maxSize) {
                $this->activeConnections++;
                /** @var Promise<PDO> $promise */
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

        if (!$this->waiters->isEmpty()) {
            /** @var Promise<PDO> $promise */
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
     * @return PDO|null The last connection object or null if none have been handled.
     */
    public function getLastConnection(): ?PDO
    {
        return $this->lastConnection;
    }

    /**
     * Retrieves statistics about the current state of the connection pool.
     *
     * @return array<string, int|bool> An associative array with pool metrics.
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
     * This method rejects any pending connection requests and clears the pool.
     * The pool is reset to an empty state and cannot be used until re-initialized.
     */
    public function close(): void
    {
        while (!$this->pool->isEmpty()) {
            $this->pool->dequeue();
        }
        while (!$this->waiters->isEmpty()) {
            /** @var Promise<PDO> $promise */
            $promise = $this->waiters->dequeue();
            $promise->reject(new RuntimeException('Pool is being closed'));
        }
        $this->pool = new SplQueue();
        $this->waiters = new SplQueue();
        $this->activeConnections = 0;
        $this->lastConnection = null;
    }

    /**
     * Validates the provided database configuration array.
     *
     * @param array<string, mixed> $dbConfig
     * @throws InvalidArgumentException
     */
    private function validateDbConfig(array $dbConfig): void
    {
        if (count($dbConfig) === 0) {
            throw new InvalidArgumentException('Database configuration cannot be empty');
        }
        if (!isset($dbConfig['driver']) || !is_string($dbConfig['driver']) || $dbConfig['driver'] === '') {
            throw new InvalidArgumentException("Database configuration field 'driver' must be a non-empty string");
        }
        $this->validateDriverSpecificConfig($dbConfig);
        if (isset($dbConfig['port']) && (!is_int($dbConfig['port']) || $dbConfig['port'] <= 0)) {
            throw new InvalidArgumentException('Database port must be a positive integer');
        }
        if (isset($dbConfig['options']) && !is_array($dbConfig['options'])) {
            throw new InvalidArgumentException('Database options must be an array');
        }
    }

    /**
     * Validates driver-specific configuration requirements.
     *
     * @param array<string, mixed> $dbConfig
     * @throws InvalidArgumentException
     */
    private function validateDriverSpecificConfig(array $dbConfig): void
    {
        /** @var string $driver */
        $driver = $dbConfig['driver'];
        switch (strtolower($driver)) {
            case 'mysql':
            case 'pgsql':
            case 'postgresql':
                $this->validateRequiredFields($dbConfig, ['host', 'database']);
                break;
            case 'sqlite':
            case 'firebird':
            case 'informix':
            case 'oci':
            case 'oracle':
                $this->validateRequiredFields($dbConfig, ['database']);
                break;
            case 'sqlsrv':
            case 'mssql':
                $this->validateRequiredFields($dbConfig, ['host']);
                break;
            case 'ibm':
            case 'db2':
            case 'odbc':
                if (!isset($dbConfig['database']) && !isset($dbConfig['dsn'])) {
                    throw new InvalidArgumentException("Driver '{$driver}' requires either 'database' or 'dsn' field");
                }
                break;
            default:
                // No action needed for drivers that may not require specific fields.
        }
    }

    /**
     * Validates that required fields are present and not empty in the configuration.
     *
     * @param array<string, mixed> $dbConfig The configuration to check.
     * @param list<string> $requiredFields A list of keys that must exist and be non-empty.
     * @throws InvalidArgumentException
     */
    private function validateRequiredFields(array $dbConfig, array $requiredFields): void
    {
        /** @var string $driver */
        $driver = $dbConfig['driver'];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $dbConfig) || $dbConfig[$field] === '' || $dbConfig[$field] === null) {
                throw new InvalidArgumentException("Database configuration field '{$field}' cannot be empty for driver '{$driver}'");
            }
        }
    }

    /**
     * Establishes a new PDO connection.
     *
     * @return PDO The newly created connection object.
     * @throws RuntimeException If the connection fails.
     */
    private function createConnection(): PDO
    {
        $dsn = $this->buildDSN($this->dbConfig);
        $username = isset($this->dbConfig['username']) && is_string($this->dbConfig['username']) ? $this->dbConfig['username'] : null;
        $password = isset($this->dbConfig['password']) && is_string($this->dbConfig['password']) ? $this->dbConfig['password'] : null;
        $options = isset($this->dbConfig['options']) && is_array($this->dbConfig['options']) ? $this->dbConfig['options'] : [];

        try {
            $pdo = new PDO($dsn, $username, $password, $options);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (PDOException $e) {
            throw new RuntimeException('PDO Connection failed: ' . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Checks if a PDO connection is still active and usable.
     *
     * @param PDO $connection The connection to check.
     * @return bool True if the connection is alive.
     */
    private function isConnectionAlive(PDO $connection): bool
    {
        try {
            return $connection->query('SELECT 1') !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Resets the state of a connection before returning it to the pool.
     *
     * @param PDO $connection The connection to reset.
     */
    private function resetConnectionState(PDO $connection): void
    {
        try {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        } catch (Throwable $e) {
            // isConnectionAlive will catch this on the next cycle.
        }
    }

    /**
     * Builds a Data Source Name (DSN) string for PDO from a configuration array.
     *
     * @param array<string, mixed> $config The database configuration.
     * @return string The formatted DSN string.
     * @throws PDOException If the driver is not supported.
     */
    private function buildDSN(array $config): string
    {
        /** @var string $driver */
        $driver = $config['driver'];

        $host = is_scalar($config['host'] ?? null) ? (string)$config['host'] : '127.0.0.1';
        $port = is_numeric($config['port'] ?? null) ? (int)$config['port'] : 0;
        $database = is_scalar($config['database'] ?? null) ? (string)$config['database'] : '';
        $charset = is_scalar($config['charset'] ?? null) ? (string)$config['charset'] : 'utf8mb4';
        $dsnVal = is_scalar($config['dsn'] ?? null) ? (string)$config['dsn'] : $database;

        return match (strtolower($driver)) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $host,
                $port > 0 ? $port : 3306,
                $database,
                $charset
            ),
            'pgsql', 'postgresql' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $host,
                $port > 0 ? $port : 5432,
                $database
            ),
            'sqlite' => 'sqlite:' . $database,
            'sqlsrv', 'mssql' => $this->buildSqlSrvDSN($config),
            'oci', 'oracle' => $this->buildOciDSN($config),
            'ibm', 'db2' => 'ibm:' . $dsnVal,
            'odbc' => 'odbc:' . $dsnVal,
            'firebird' => 'firebird:dbname=' . $database,
            'informix' => $this->buildInformixDSN($config),
            default => throw new PDOException("Unsupported database driver for pool: {$driver}")
        };
    }

    /**
     * Builds a SQL Server DSN string from a configuration array.
     * @param array<string, mixed> $config
     * @return string
     */
    private function buildSqlSrvDSN(array $config): string
    {
        $host = is_scalar($config['host'] ?? null) ? (string)$config['host'] : '';
        $database = is_scalar($config['database'] ?? null) ? (string)$config['database'] : '';
        $port = is_numeric($config['port'] ?? null) ? (int)$config['port'] : 1433;
        
        $dsn = 'sqlsrv:server=' . $host;
        if ($port !== 1433) {
            $dsn .= ',' . $port;
        }
        if ($database !== '') {
            $dsn .= ';Database=' . $database;
        }
        return $dsn;
    }

    /**
     * Builds an Oracle DSN string from a configuration array.
     * @param array<string, mixed> $config
     * @return string
     */
    private function buildOciDSN(array $config): string
    {
        $database = is_scalar($config['database'] ?? null) ? (string)$config['database'] : '';
        $charset = is_scalar($config['charset'] ?? null) ? (string)$config['charset'] : '';
        $host = is_scalar($config['host'] ?? null) ? (string)$config['host'] : '';
        $port = is_numeric($config['port'] ?? null) ? (int)$config['port'] : 0;

        $dsn = 'oci:dbname=';
        if ($host !== '') {
            $dsn .= '//' . $host;
            if ($port > 0) {
                $dsn .= ':' . $port;
            }
            $dsn .= '/';
        }
        $dsn .= $database;
        if ($charset !== '') {
            $dsn .= ';charset=' . $charset;
        }
        return $dsn;
    }

    /**
     * Builds an Informix DSN string from a configuration array.
     * @param array<string, mixed> $config
     * @return string
     */
    private function buildInformixDSN(array $config): string
    {
        $dsnParts = [];
        $keys = ['host', 'database', 'server', 'protocol', 'service'];
        foreach ($keys as $key) {
            $value = $config[$key] ?? null;
            if (is_scalar($value) && (string)$value !== '') {
                $dsnParts[] = $key . '=' . (string)$value;
            }
        }
        return 'informix:' . implode(';', $dsnParts);
    }
}
