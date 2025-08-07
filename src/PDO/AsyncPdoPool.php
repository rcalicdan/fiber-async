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
    private bool $configValidated = false;
    private ?PDO $lastConnection = null;

    /**
     * Creates a new connection pool.
     *
     * @param  array  $dbConfig  The database configuration array, compatible with PDOManager.
     * @param  int  $maxSize  The maximum number of concurrent connections allowed.
     *
     * @throws \InvalidArgumentException When database configuration is invalid
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
        // Check if connection is still alive before reusing
        if (! $this->isConnectionAlive($connection)) {
            $this->activeConnections--;

            // If there are waiters, try to create a new connection for them
            if (! $this->waiters->isEmpty() && $this->activeConnections < $this->maxSize) {
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
        if (! $this->waiters->isEmpty()) {
            $promise = $this->waiters->dequeue();
            $this->lastConnection = $connection;
            $promise->resolve($connection);
        } else {
            // Otherwise, add the connection to the pool of available connections.
            $this->pool->enqueue($connection);
        }
    }

    /**
     * Gets the last used connection.
     */
    public function getLastConnection(): ?PDO
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
     * Useful for graceful application shutdown.
     */
    public function close(): void
    {
        // Close all pooled connections (PDO will handle cleanup automatically)
        while (! $this->pool->isEmpty()) {
            $connection = $this->pool->dequeue();
            // PDO connections are closed when the object is destroyed
            unset($connection);
        }

        // Reject all waiting promises
        while (! $this->waiters->isEmpty()) {
            $promise = $this->waiters->dequeue();
            $promise->reject(new \RuntimeException('Pool is being closed'));
        }

        // Clear references to prevent memory leaks
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

        // Check for required driver field
        if (! array_key_exists('driver', $dbConfig)) {
            throw new \InvalidArgumentException("Missing required database configuration field: 'driver'");
        }

        if (empty($dbConfig['driver'])) {
            throw new \InvalidArgumentException("Database configuration field 'driver' cannot be empty");
        }

        if (! is_string($dbConfig['driver'])) {
            throw new \InvalidArgumentException('Database driver must be a string');
        }

        // Validate driver-specific requirements
        $this->validateDriverSpecificConfig($dbConfig);

        if (isset($dbConfig['port']) && (! is_int($dbConfig['port']) || $dbConfig['port'] <= 0)) {
            throw new \InvalidArgumentException("Database port must be a positive integer: {$dbConfig['port']}");
        }

        if (isset($dbConfig['host']) && ! is_string($dbConfig['host'])) {
            throw new \InvalidArgumentException('Database host must be a string');
        }

        if (isset($dbConfig['username']) && ! is_string($dbConfig['username'])) {
            throw new \InvalidArgumentException('Database username must be a string');
        }

        if (isset($dbConfig['password']) && ! is_string($dbConfig['password'])) {
            throw new \InvalidArgumentException('Database password must be a string');
        }

        if (isset($dbConfig['charset']) && ! is_string($dbConfig['charset'])) {
            throw new \InvalidArgumentException('Database charset must be a string');
        }

        if (isset($dbConfig['options']) && ! is_array($dbConfig['options'])) {
            throw new \InvalidArgumentException('Database options must be an array');
        }
    }

    /**
     * Validates driver-specific configuration requirements.
     */
    private function validateDriverSpecificConfig(array $dbConfig): void
    {
        $driver = strtolower($dbConfig['driver']);

        switch ($driver) {
            case 'mysql':
            case 'pgsql':
            case 'postgresql':
                $this->validateRequiredFields($dbConfig, ['host', 'database']);

                break;

            case 'sqlite':
                $this->validateRequiredFields($dbConfig, ['database']);

                break;

            case 'sqlsrv':
            case 'mssql':
                $this->validateRequiredFields($dbConfig, ['host']);

                break;

            case 'oci':
            case 'oracle':
                $this->validateRequiredFields($dbConfig, ['database']);

                break;

            case 'ibm':
            case 'db2':
                if (! isset($dbConfig['database']) && ! isset($dbConfig['dsn'])) {
                    throw new \InvalidArgumentException("IBM DB2 driver requires either 'database' or 'dsn' field");
                }

                break;

            case 'odbc':
                if (! isset($dbConfig['database']) && ! isset($dbConfig['dsn'])) {
                    throw new \InvalidArgumentException("ODBC driver requires either 'database' or 'dsn' field");
                }

                break;

            case 'firebird':
                $this->validateRequiredFields($dbConfig, ['database']);

                break;

            case 'informix':
                $this->validateRequiredFields($dbConfig, ['database']);

                break;

            default:
                throw new \InvalidArgumentException("Unsupported database driver: '{$driver}'");
        }
    }

    /**
     * Validates that required fields are present and not empty.
     */
    private function validateRequiredFields(array $dbConfig, array $requiredFields): void
    {
        foreach ($requiredFields as $field) {
            if (! array_key_exists($field, $dbConfig)) {
                throw new \InvalidArgumentException("Missing required database configuration field: '{$field}' for driver '{$dbConfig['driver']}'");
            }

            if (empty($dbConfig[$field])) {
                throw new \InvalidArgumentException("Database configuration field '{$field}' cannot be empty for driver '{$dbConfig['driver']}'");
            }
        }
    }

    /**
     * Creates a new PDO connection based on the provided configuration.
     */
    private function createConnection(): PDO
    {
        $config = $this->dbConfig;
        $dsn = $this->buildDSN($config);

        try {
            $pdo = new PDO(
                $dsn,
                $config['username'] ?? null,
                $config['password'] ?? null,
                $config['options'] ?? []
            );

            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $pdo;
        } catch (PDOException $e) {
            throw new \RuntimeException('PDO Connection failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Checks if a PDO connection is still alive.
     */
    private function isConnectionAlive(PDO $connection): bool
    {
        try {
            $stmt = $connection->query('SELECT 1');

            return $stmt !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resets connection state to clean it for reuse.
     */
    private function resetConnectionState(PDO $connection): void
    {
        try {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\Throwable $e) {
            // If reset fails, connection will be considered dead
            // and will be caught by isConnectionAlive check
        }
    }

    /**
     * Builds a DSN string for PDO based on the provided configuration.
     *
     * @param  array  $config  The database configuration array
     * @return string The DSN string
     *
     * @throws PDOException If the driver is not set or not supported
     */
    private function buildDSN(array $config): string
    {
        return match (strtolower($config['driver'])) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'],
                $config['port'] ?? 3306,
                $config['database'],
                $config['charset'] ?? 'utf8mb4'
            ),

            'pgsql', 'postgresql' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $config['host'],
                $config['port'] ?? 5432,
                $config['database']
            ),

            'sqlite' => 'sqlite:'.$config['database'],

            'sqlsrv', 'mssql' => $this->buildSqlSrvDSN($config),

            'oci', 'oracle' => $this->buildOciDSN($config),

            'ibm', 'db2' => $this->buildIbmDSN($config),

            'odbc' => 'odbc:'.($config['dsn'] ?? $config['database']),

            'firebird' => 'firebird:dbname='.$config['database'],

            'informix' => $this->buildInformixDSN($config),

            default => throw new PDOException("Unsupported database driver for pool: {$config['driver']}")
        };
    }

    /**
     * Builds a SQL Server DSN string.
     */
    private function buildSqlSrvDSN(array $config): string
    {
        $dsn = 'sqlsrv:server='.$config['host'];

        if (isset($config['port']) && $config['port'] != 1433) {
            $dsn .= ','.$config['port'];
        }

        if (isset($config['database'])) {
            $dsn .= ';Database='.$config['database'];
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
            $dsn .= '//'.$config['host'];

            if (isset($config['port'])) {
                $dsn .= ':'.$config['port'];
            }

            $dsn .= '/';
        }

        $dsn .= $config['database'];

        if (isset($config['charset'])) {
            $dsn .= ';charset='.$config['charset'];
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
            $dsn .= 'host='.$config['host'].';';
        }

        $dsn .= 'database='.$config['database'].';';

        if (isset($config['server'])) {
            $dsn .= 'server='.$config['server'].';';
        }

        if (isset($config['protocol'])) {
            $dsn .= 'protocol='.$config['protocol'].';';
        }

        if (isset($config['service'])) {
            $dsn .= 'service='.$config['service'].';';
        }

        return $dsn;
    }
}
