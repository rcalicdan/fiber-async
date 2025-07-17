<?php

namespace Rcalicdan\FiberAsync\Facades;

use PDO;
use Throwable;
use Rcalicdan\FiberAsync\Database\AsyncPdoPool;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

/**
 * A simplified, static facade for interacting with the AsyncPdoPool.
 *
 * This provides a high-level API for running database queries and transactions
 * without needing to manually manage connection acquisition and release.
 */
final class AsyncPDO
{
    private static ?AsyncPdoPool $pool = null;
    private static array $config = [];
    private static bool $isInitialized = false;

    /**
     * Initializes the asynchronous PDO service. Must be called once at application startup.
     *
     * @param array $dbConfig The database configuration.
     * @param int $poolSize The maximum number of concurrent connections.
     */
    public static function init(array $dbConfig, int $poolSize = 10): void
    {
        if (self::$isInitialized) {
            return; 
        }
        self::$config = $dbConfig;
        self::$pool = new AsyncPdoPool($dbConfig, $poolSize);
        self::$isInitialized = true;
    }
    
    /**
     * Resets the pool. Primarily for testing purposes.
     */
    public static function reset(): void
    {
        if (self::$pool) {
            self::$pool->close();
        }
        self::$pool = null;
        self::$isInitialized = false;
    }

    /**
     * Executes a callback with a PDO connection, ensuring it is released.
     * This is the core helper for all other facade methods.
     *
     * @param callable $callback The function to execute, which receives a PDO instance.
     * @return PromiseInterface
     */
    public static function run(callable $callback): PromiseInterface
    {
        return Async::async(function () use ($callback) {
            $pdo = null;
            try {
                $pdo = await(self::getPool()->get());
                return $callback($pdo);
            } finally {
                if ($pdo) {
                    self::getPool()->release($pdo);
                }
            }
        })();
    }

    /**
     * Prepares and executes a statement that returns multiple rows.
     *
     * @param string $sql The SQL query.
     * @param array $params The parameters to bind.
     * @return PromiseInterface<array> A promise that resolves with an array of all rows.
     */
    public static function query(string $sql, array $params = []): PromiseInterface
    {
        return self::run(function (PDO $pdo) use ($sql, $params) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    /**
     * Prepares and executes a statement that returns a single row.
     *
     * @param string $sql The SQL query.
     * @param array $params The parameters to bind.
     * @return PromiseInterface<array|false> A promise that resolves with a single row or false if not found.
     */
    public static function fetchOne(string $sql, array $params = []): PromiseInterface
    {
        return self::run(function (PDO $pdo) use ($sql, $params) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        });
    }

    /**
     * Prepares and executes a statement for INSERT, UPDATE, or DELETE.
     *
     * @param string $sql The SQL query.
     * @param array $params The parameters to bind.
     * @return PromiseInterface<int> A promise that resolves with the number of affected rows.
     */
    public static function execute(string $sql, array $params = []): PromiseInterface
    {
        return self::run(function (PDO $pdo) use ($sql, $params) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        });
    }

    /**
     * Executes a transaction, automatically handling commit and rollback.
     *
     * @param callable $callback The function to execute within the transaction. It receives the PDO instance.
     * @return PromiseInterface<mixed> A promise that resolves with the return value of the callback.
     */
    public static function transaction(callable $callback): PromiseInterface
    {
        return self::run(function (PDO $pdo) use ($callback) {
            $pdo->beginTransaction();
            try {
                $result = $callback($pdo);
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e; 
            }
        });
    }

    /**
     * Gets the singleton pool instance, ensuring it has been initialized.
     */
    private static function getPool(): AsyncPdoPool
    {
        if (!self::$isInitialized) {
            throw new \RuntimeException(
                'AsyncPDO has not been initialized. Please call AsyncPDO::init() at application startup.'
            );
        }
        return self::$pool;
    }
}