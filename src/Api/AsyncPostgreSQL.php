<?php

namespace Rcalicdan\FiberAsync\Api;

use PgSql\Connection;
use PgSql\Result;
use Rcalicdan\FiberAsync\Async\Handlers\PromiseCollectionHandler;
use Rcalicdan\FiberAsync\PostgreSQL\AsyncPostgreSQLPool;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Throwable;

final class AsyncPostgreSQL
{
    private static ?AsyncPostgreSQLPool $pool = null;
    private static bool $isInitialized = false;

    public static function init(array $dbConfig, int $poolSize = 10): void
    {
        if (self::$isInitialized) {
            return;
        }

        self::$pool = new AsyncPostgreSQLPool($dbConfig, $poolSize);
        self::$isInitialized = true;
    }

    public static function reset(): void
    {
        if (self::$pool) {
            self::$pool->close();
        }
        self::$pool = null;
        self::$isInitialized = false;
    }

    public static function run(callable $callback): PromiseInterface
    {
        return Async::async(function () use ($callback) {
            $connection = null;

            try {
                $connection = await(self::getPool()->get());

                return $callback($connection);
            } finally {
                if ($connection) {
                    self::getPool()->release($connection);
                }
            }
        })();
    }

    public static function query(string $sql, array $params = []): PromiseInterface
    {
        return self::executeAsyncQuery($sql, $params, 'fetchAll');
    }

    public static function fetchOne(string $sql, array $params = []): PromiseInterface
    {
        return self::executeAsyncQuery($sql, $params, 'fetchOne');
    }

    public static function execute(string $sql, array $params = []): PromiseInterface
    {
        return self::executeAsyncQuery($sql, $params, 'execute');
    }

    public static function fetchValue(string $sql, array $params = []): PromiseInterface
    {
        return self::executeAsyncQuery($sql, $params, 'fetchValue');
    }

    public static function transaction(callable $callback): PromiseInterface
    {
        return self::run(function (Connection $connection) use ($callback) {
            pg_query($connection, 'BEGIN');

            try {
                $result = $callback($connection);
                pg_query($connection, 'COMMIT');

                return $result;
            } catch (Throwable $e) {
                pg_query($connection, 'ROLLBACK');

                throw $e;
            }
        });
    }

    public static function raceTransactions(array $transactions): PromiseInterface
    {
        return Async::async(function () use ($transactions) {
            $transactionPromises = [];
            $pgConnections = [];
            $cancellablePromises = [];

            foreach ($transactions as $index => $transactionCallback) {
                $cancellablePromise = self::startCancellableRacingTransaction($transactionCallback, $index, $pgConnections);
                $transactionPromises[$index] = $cancellablePromise;
                $cancellablePromises[$index] = $cancellablePromise;
            }

            $collectionHandler = new PromiseCollectionHandler;

            try {
                $winnerResult = await($collectionHandler->race($transactionPromises));

                self::cancelLosingTransactions($cancellablePromises, $winnerResult['winner_index']);

                await(self::finalizeRacingTransactions($pgConnections, $winnerResult['winner_index']));

                return $winnerResult['result'];
            } catch (Throwable $e) {
                self::cancelAllTransactions($cancellablePromises);
                await(self::rollbackAllTransactions($pgConnections));

                throw $e;
            }
        })();
    }

    private static function startCancellableRacingTransaction(callable $transactionCallback, int $index, array &$pgConnections): CancellablePromise
    {
        $cancellablePromise = new CancellablePromise(function ($resolve, $reject) use ($transactionCallback, $index, &$pgConnections) {
            $connection = await(self::getPool()->get());
            $pgConnections[$index] = $connection;

            pg_query($connection, 'BEGIN');

            try {
                $result = $transactionCallback($connection);

                $resolve([
                    'result' => $result,
                    'winner_index' => $index,
                    'success' => true,
                ]);
            } catch (Throwable $e) {
                throw $e;
            }
        });

        $cancellablePromise->setCancelHandler(function () use ($index, &$pgConnections) {
            if (isset($pgConnections[$index])) {
                $connection = $pgConnections[$index];

                try {
                    pg_query($connection, 'ROLLBACK');
                    self::getPool()->release($connection);
                } catch (Throwable $e) {
                    error_log("Failed to cancel transaction {$index}: ".$e->getMessage());
                    self::getPool()->release($connection);
                }
            }
        });

        return $cancellablePromise;
    }

    private static function cancelLosingTransactions(array $cancellablePromises, int $winnerIndex): void
    {
        foreach ($cancellablePromises as $index => $promise) {
            if ($index !== $winnerIndex && ! $promise->isCancelled()) {
                $promise->cancel();
            }
        }
    }

    private static function cancelAllTransactions(array $cancellablePromises): void
    {
        foreach ($cancellablePromises as $promise) {
            if (! $promise->isCancelled()) {
                $promise->cancel();
            }
        }
    }

    private static function finalizeRacingTransactions(array $pgConnections, int $winnerIndex): PromiseInterface
    {
        return Async::async(function () use ($pgConnections, $winnerIndex) {
            if (isset($pgConnections[$winnerIndex])) {
                $connection = $pgConnections[$winnerIndex];

                try {
                    pg_query($connection, 'COMMIT');
                    echo "Transaction $winnerIndex: Winner committed!\n";
                    self::getPool()->release($connection);
                } catch (Throwable $e) {
                    error_log("Failed to commit winner transaction {$winnerIndex}: ".$e->getMessage());
                    pg_query($connection, 'ROLLBACK');
                    self::getPool()->release($connection);

                    throw $e;
                }
            }
        })();
    }

    private static function rollbackAllTransactions(array $pgConnections): PromiseInterface
    {
        return Async::async(function () use ($pgConnections) {
            $rollbackPromises = [];

            foreach ($pgConnections as $index => $connection) {
                $rollbackPromises[] = Async::async(function () use ($connection, $index) {
                    try {
                        pg_query($connection, 'ROLLBACK');
                        self::getPool()->release($connection);
                    } catch (Throwable $e) {
                        error_log("Failed to rollback transaction {$index}: ".$e->getMessage());
                        self::getPool()->release($connection);
                    }
                })();
            }

            $collectionHandler = new PromiseCollectionHandler;
            await($collectionHandler->all($rollbackPromises));
        })();
    }

    private static function executeAsyncQuery(string $sql, array $params, string $resultType): PromiseInterface
    {
        return Async::async(function () use ($sql, $params, $resultType) {
            $connection = await(self::getPool()->get());

            try {
                if (! empty($params)) {
                    // Use async version for parameterized queries
                    if (! pg_send_query_params($connection, $sql, $params)) {
                        throw new \RuntimeException('Failed to send parameterized query: '.pg_last_error($connection));
                    }
                } else {
                    if (! pg_send_query($connection, $sql)) {
                        throw new \RuntimeException('Failed to send query: '.pg_last_error($connection));
                    }
                }

                $result = await(self::waitForAsyncCompletion($connection));

                return self::processResult($result, $resultType, $connection);
            } finally {
                self::getPool()->release($connection);
            }
        })();
    }

    private static function waitForAsyncCompletion(Connection $connection): PromiseInterface
    {
        return Async::async(function () use ($connection) {
            $pollInterval = 100; // microseconds
            $maxInterval = 1000;

            while (pg_connection_busy($connection)) {
                await(Timer::delay($pollInterval / 1000000)); // Convert to seconds
                $pollInterval = min($pollInterval * 1.2, $maxInterval);
            }

            return pg_get_result($connection);
        })();
    }

    private static function processResult(Result|false $result, string $resultType, Connection $connection): mixed
    {
        if ($result === false) {
            $error = pg_last_error($connection);

            throw new \RuntimeException('Query execution failed: '.($error ?: 'Unknown error'));
        }

        return match ($resultType) {
            'fetchAll' => self::handleFetchAll($result),
            'fetchOne' => self::handleFetchOne($result),
            'fetchValue' => self::handleFetchValue($result),
            'execute' => self::handleExecute($result),
            default => $result,
        };
    }

    private static function handleFetchAll(Result $result): array
    {
        return pg_fetch_all($result) ?: [];
    }

    private static function handleFetchOne(Result $result): ?array
    {
        return pg_fetch_assoc($result) ?: null;
    }

    private static function handleFetchValue(Result $result): mixed
    {
        $row = pg_fetch_row($result);

        return $row ? $row[0] : null;
    }

    private static function handleExecute(Result $result): int
    {
        return pg_affected_rows($result);
    }

    private static function getPool(): AsyncPostgreSQLPool
    {
        if (! self::$isInitialized) {
            throw new \RuntimeException(
                'AsyncPostgreSQL has not been initialized. Please call AsyncPostgreSQL::init() at application startup.'
            );
        }

        return self::$pool;
    }
}
