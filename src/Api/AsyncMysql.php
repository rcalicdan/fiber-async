<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\MySQL\MySQLClient;
use Rcalicdan\FiberAsync\MySQL\MySQLPool;
use Rcalicdan\FiberAsync\MySQL\PooledPreparedStatement;
use Rcalicdan\FiberAsync\MySQL\PooledTransaction;
use Rcalicdan\FiberAsync\MySQL\Transaction;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * Asynchronous MySQL API providing fiber-based and pure non-blocking database operations with connection pooling.
 */
final class AsyncMySQL
{
    private static ?MySQLPool $pool = null;
    private static bool $isInitialized = false;

    /**
     * Initializes the MySQL connection pool.
     */
    public static function init(array $connectionParams, int $poolSize = 10): void
    {
        if (self::$isInitialized) {
            return;
        }

        self::$pool = new MySQLPool($connectionParams, $poolSize);
        self::$isInitialized = true;
    }

    /**
     * Resets the pool and clears all connections.
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
     * Executes a callback with a MySQL client from the pool.
     */
    public static function run(callable $callback): PromiseInterface
    {
        return Async::async(function () use ($callback) {
            $client = null;

            try {
                $client = await(self::getPool()->get());

                return $callback($client);
            } finally {
                if ($client) {
                    self::getPool()->release($client);
                }
            }
        })();
    }

    /**
     * Executes a SQL query and returns all results.
     */
    public static function query(string $sql): PromiseInterface
    {
        return self::run(function (MySQLClient $client) use ($sql) {
            return await($client->query($sql));
        });
    }

    /**
     * Prepares a SQL statement.
     */
    public static function prepare(string $sql): PromiseInterface
    {
        return self::run(function (MySQLClient $client) use ($sql) {
            $stmt = await($client->prepare($sql));

            return new PooledPreparedStatement($stmt, $client, self::$pool);
        });
    }

    /**
     * Executes operations within a transaction.
     */
    public static function transaction(callable $callback, $isolationLevel = null): PromiseInterface
    {
        return self::run(function (MySQLClient $client) use ($callback, $isolationLevel) {
            await($client->beginTransaction($isolationLevel));

            try {
                $transaction = new Transaction($client);
                $result = $callback($transaction);

                if ($result instanceof PromiseInterface) {
                    $result = await($result);
                }

                await($transaction->commit());

                return $result;
            } catch (\Throwable $e) {
                try {
                    await($client->rollback());
                } catch (\Throwable $rollbackError) {
                    // Log rollback error if needed
                }

                throw $e;
            }
        });
    }

    /**
     * Begins a transaction and returns a Transaction object.
     */
    public static function beginTransaction($isolationLevel = null): PromiseInterface
    {
        return self::run(function (MySQLClient $client) use ($isolationLevel) {
            await($client->beginTransaction($isolationLevel));

            return new PooledTransaction($client, self::$pool);
        });
    }

    private static function getPool(): MySQLPool
    {
        if (! self::$isInitialized) {
            throw new \RuntimeException(
                'AsyncMySQL has not been initialized. Please call AsyncMySQL::init() first.'
            );
        }

        return self::$pool;
    }
}
