<?php

namespace Rcalicdan\FiberAsync\Api;

use PDO;
use Rcalicdan\FiberAsync\PDO\AsyncPdoPool;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Throwable;

/**
 * Asynchronous PDO API providing fiber-based database operations.
 *
 * This class serves as the main entry point for async database operations,
 * managing connection pooling and providing convenient methods for common
 * database tasks like queries, transactions, and batch operations.
 */
final class AsyncPDO
{
    /** @var AsyncPdoPool|null Connection pool instance */
    private static ?AsyncPdoPool $pool = null;

    /** @var bool Tracks initialization state */
    private static bool $isInitialized = false;

    /**
     * Initializes the entire async database system.
     *
     * This is the single point of configuration and must be called before
     * using any other AsyncPDO methods. Multiple calls are ignored.
     *
     * @param  array<string, mixed>  $dbConfig  Database configuration array containing:
     *                                          - driver: Database driver (e.g., 'mysql', 'pgsql')
     *                                          - host: Database host (e.g., 'localhost')
     *                                          - database: Database name
     *                                          - port: Database port
     *                                          - username: Database username
     *                                          - password: Database password
     *                                          - options: PDO options array (optional)
     * @param  int  $poolSize  Maximum number of connections in the pool
     */
    public static function init(array $dbConfig, int $poolSize = 10): void
    {
        if (self::$isInitialized) {
            return;
        }

        self::$pool = new AsyncPdoPool($dbConfig, $poolSize);
        self::$isInitialized = true;
    }

    /**
     * Resets both this facade and the underlying event loop for clean testing.
     *
     * Closes all database connections and clears the pool. Primarily used
     * in testing scenarios to ensure clean state between tests.
     */
    public static function reset(): void
    {
        if (self::$pool !== null) {
            self::$pool->close();
        }
        self::$pool = null;
        self::$isInitialized = false;
    }

    /**
     * Executes a callback with an async PDO connection from the pool.
     *
     * Automatically handles connection acquisition and release. The callback
     * receives a PDO instance and can perform any database operations.
     *
     * @template TResult
     *
     * @param  callable(PDO): TResult  $callback  Function that receives PDO instance
     * @return PromiseInterface<TResult> Promise resolving to callback's return value
     *
     * @throws \RuntimeException If AsyncPDO is not initialized
     */
    public static function run(callable $callback): PromiseInterface
    {
        // @phpstan-ignore-next-line
        return Async::async(function () use ($callback): mixed {
            $pdo = null;

            try {
                $pdo = await(self::getPool()->get());

                return $callback($pdo);
            } finally {
                if ($pdo !== null) {
                    self::getPool()->release($pdo);
                }
            }
        })();
    }

    /**
     * Executes a SELECT query and returns all matching rows.
     *
     * @param  string  $sql  SQL query with optional parameter placeholders
     * @param  array<string|int, mixed>  $params  Parameter values for prepared statement
     * @return PromiseInterface<array<int, array<string, mixed>>> Promise resolving to array of associative arrays
     *
     * @throws \RuntimeException If AsyncPDO is not initialized
     * @throws \PDOException If query execution fails
     */
    public static function query(string $sql, array $params = []): PromiseInterface
    {
        return self::run(function (PDO $pdo) use ($sql, $params): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            /** @var array<int, array<string, mixed>> */
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $result;
        });
    }

    /**
     * Executes a SELECT query and returns the first matching row.
     *
     * @param  string  $sql  SQL query with optional parameter placeholders
     * @param  array<string|int, mixed>  $params  Parameter values for prepared statement
     * @return PromiseInterface<array<string, mixed>|false> Promise resolving to associative array or false if no rows
     *
     * @throws \RuntimeException If AsyncPDO is not initialized
     * @throws \PDOException If query execution fails
     */
    public static function fetchOne(string $sql, array $params = []): PromiseInterface
    {
        return self::run(function (PDO $pdo) use ($sql, $params): array|false {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            /** @var array<string, mixed>|false */
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result;
        });
    }

    /**
     * Executes an INSERT, UPDATE, or DELETE statement and returns affected row count.
     *
     * @param  string  $sql  SQL statement with optional parameter placeholders
     * @param  array<string|int, mixed>  $params  Parameter values for prepared statement
     * @return PromiseInterface<int> Promise resolving to number of affected rows
     *
     * @throws \RuntimeException If AsyncPDO is not initialized
     * @throws \PDOException If statement execution fails
     */
    public static function execute(string $sql, array $params = []): PromiseInterface
    {
        // @phpstan-ignore-next-line
        return self::run(function (PDO $pdo) use ($sql, $params): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->rowCount();
        });
    }

    /**
     * Executes a query and returns a single column value from the first row.
     *
     * Useful for queries that return a single scalar value like COUNT, MAX, etc.
     *
     * @param  string  $sql  SQL query with optional parameter placeholders
     * @param  array<string|int, mixed>  $params  Parameter values for prepared statement
     * @return PromiseInterface<mixed> Promise resolving to scalar value or false if no rows
     *
     * @throws \RuntimeException If AsyncPDO is not initialized
     * @throws \PDOException If query execution fails
     */
    public static function fetchValue(string $sql, array $params = []): PromiseInterface
    {
        return self::run(function (PDO $pdo) use ($sql, $params): mixed {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetch(PDO::FETCH_COLUMN);
        });
    }

    /**
     * Executes multiple operations within a database transaction.
     *
     * Automatically handles transaction begin/commit/rollback. If the callback
     * throws an exception, the transaction is rolled back automatically.
     *
     * @param  callable(PDO): mixed  $callback  Transaction callback receiving PDO instance
     * @return PromiseInterface<mixed> Promise resolving to callback's return value
     *
     * @throws \RuntimeException If AsyncPDO is not initialized
     * @throws \PDOException If transaction operations fail
     * @throws Throwable Any exception thrown by the callback (after rollback)
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
     * Race multiple transactions and commit only the winner, rolling back all others.
     *
     * Executes multiple transactions concurrently and commits the first one to complete
     * successfully while cancelling and rolling back all others. Useful for scenarios
     * like inventory reservation where only one transaction should succeed.
     *
     * @param  array<int, callable(PDO): mixed>  $transactions  Array of transaction callbacks
     * @return PromiseInterface<mixed> Promise that resolves with the winner's result
     *
     * @throws \RuntimeException If AsyncPDO is not initialized
     * @throws Throwable If all transactions fail or system error occurs
     */
    public static function raceTransactions(array $transactions): PromiseInterface
    {
        // @phpstan-ignore-next-line
        return Async::async(function () use ($transactions): mixed {
            /** @var array<int, CancellablePromise<array{result: mixed, winner_index: int, success: bool}>> $transactionPromises */
            $transactionPromises = [];
            /** @var array<int, PDO> $pdoConnections */
            $pdoConnections = [];
            /** @var array<int, CancellablePromise<array{result: mixed, winner_index: int, success: bool}>> $cancellablePromises */
            $cancellablePromises = [];

            foreach ($transactions as $index => $transactionCallback) {
                $cancellablePromise = self::startCancellableRacingTransaction($transactionCallback, $index, $pdoConnections);
                $transactionPromises[$index] = $cancellablePromise;
                $cancellablePromises[$index] = $cancellablePromise;
            }

            try {
                // @phpstan-ignore-next-line
                $promises = array_map(fn ($promise) => $promise, $transactionPromises);

                /** @var array{result: mixed, winner_index: int, success: bool} $winnerResult */
                // @phpstan-ignore-next-line
                $winnerResult = await(race($promises));

                self::cancelLosingTransactions($cancellablePromises, $winnerResult['winner_index']);

                await(self::finalizeRacingTransactions($pdoConnections, $winnerResult['winner_index']));

                return $winnerResult['result'];
            } catch (Throwable $e) {
                self::cancelAllTransactions($cancellablePromises);
                await(self::rollbackAllTransactions($pdoConnections));

                throw $e;
            }
        })();
    }

    /**
     * Starts a cancellable racing transaction.
     *
     * Creates a cancellable promise that executes a transaction callback and
     * stores the PDO connection for later cleanup operations.
     *
     * @param  callable(PDO): mixed  $transactionCallback  Transaction function to execute
     * @param  int  $index  Transaction index for identification
     * @param  array<int, PDO>  $pdoConnections  Reference to array storing PDO connections
     * @return CancellablePromise<array{result: mixed, winner_index: int, success: bool}> Promise that can be cancelled mid-execution
     *
     * @internal This method is for internal use by raceTransactions()
     */
    private static function startCancellableRacingTransaction(callable $transactionCallback, int $index, array &$pdoConnections): CancellablePromise
    {
        $cancellablePromise = new CancellablePromise(function (callable $resolve, callable $reject) use ($transactionCallback, $index, &$pdoConnections) {
            $pdo = await(self::getPool()->get());
            $pdoConnections[$index] = $pdo;

            $pdo->beginTransaction();

            try {
                $result = $transactionCallback($pdo);

                $resolve([
                    'result' => $result,
                    'winner_index' => $index,
                    'success' => true,
                ]);
            } catch (Throwable $e) {
                throw $e;
            }
        });

        $cancellablePromise->setCancelHandler(function () use ($index, &$pdoConnections) {
            if (isset($pdoConnections[$index])) {
                $pdo = $pdoConnections[$index];

                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    self::getPool()->release($pdo);
                } catch (Throwable $e) {
                    error_log("Failed to cancel transaction {$index}: ".$e->getMessage());
                    self::getPool()->release($pdo);
                }
            }
        });

        // @phpstan-ignore-next-line
        return $cancellablePromise;
    }

    /**
     * Cancels all losing transactions immediately.
     *
     * Iterates through all racing transactions and cancels those that didn't win,
     * triggering their rollback handlers.
     *
     * @param  array<int, CancellablePromise<array{result: mixed, winner_index: int, success: bool}>>  $cancellablePromises  Array of CancellablePromise instances
     * @param  int  $winnerIndex  Index of the winning transaction to preserve
     *
     * @internal This method is for internal use by raceTransactions()
     */
    private static function cancelLosingTransactions(array $cancellablePromises, int $winnerIndex): void
    {
        foreach ($cancellablePromises as $index => $promise) {
            if ($index !== $winnerIndex && ! $promise->isCancelled()) {
                $promise->cancel();
            }
        }
    }

    /**
     * Cancels all transactions (for error scenarios).
     *
     * Emergency cancellation of all racing transactions when a system error occurs.
     *
     * @param  array<int, CancellablePromise<array{result: mixed, winner_index: int, success: bool}>>  $cancellablePromises  Array of CancellablePromise instances
     *
     * @internal This method is for internal use by raceTransactions()
     */
    private static function cancelAllTransactions(array $cancellablePromises): void
    {
        foreach ($cancellablePromises as $promise) {
            if (! $promise->isCancelled()) {
                $promise->cancel();
            }
        }
    }

    /**
     * Finalizes racing transactions: commits winner, releases connection.
     *
     * Commits the winning transaction and releases its connection back to the pool.
     * Losing transactions should already be cancelled by this point.
     *
     * @param  array<int, PDO>  $pdoConnections  Array of PDO connections indexed by transaction
     * @param  int  $winnerIndex  Index of the winning transaction
     * @return PromiseInterface<void> Promise that resolves when finalization is complete
     *
     * @throws Throwable If commit fails
     *
     * @internal This method is for internal use by raceTransactions()
     */
    private static function finalizeRacingTransactions(array $pdoConnections, int $winnerIndex): PromiseInterface
    {
        // @phpstan-ignore-next-line
        return Async::async(function () use ($pdoConnections, $winnerIndex): void {
            if (isset($pdoConnections[$winnerIndex])) {
                $pdo = $pdoConnections[$winnerIndex];

                try {
                    if ($pdo->inTransaction()) {
                        $pdo->commit();
                        echo "Transaction $winnerIndex: Winner committed!\n";
                    }
                    self::getPool()->release($pdo);
                } catch (Throwable $e) {
                    error_log("Failed to commit winner transaction {$winnerIndex}: ".$e->getMessage());
                    $pdo->rollBack();
                    self::getPool()->release($pdo);

                    throw $e;
                }
            }
        })();
    }

    /**
     * Rolls back all transactions in case of error.
     *
     * Emergency cleanup that rolls back all racing transactions when a system
     * error occurs before a winner can be determined.
     *
     * @param  array<int, PDO>  $pdoConnections  Array of PDO connections to rollback
     * @return PromiseInterface<void> Promise that resolves when all rollbacks complete
     *
     * @internal This method is for internal use by raceTransactions()
     */
    private static function rollbackAllTransactions(array $pdoConnections): PromiseInterface
    {
        // @phpstan-ignore-next-line
        return Async::async(function () use ($pdoConnections): void {
            /** @var array<int, PromiseInterface<void>> $rollbackPromises */
            $rollbackPromises = [];

            foreach ($pdoConnections as $index => $pdo) {
                $rollbackPromises[] = Async::async(function () use ($pdo, $index): void {
                    try {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        self::getPool()->release($pdo);
                    } catch (Throwable $e) {
                        error_log("Failed to rollback transaction {$index}: ".$e->getMessage());
                        self::getPool()->release($pdo);
                    }
                })();
            }

            await(all($rollbackPromises));
        })();
    }

    /**
     * Gets the connection pool instance.
     *
     * @return AsyncPdoPool The initialized connection pool
     *
     * @throws \RuntimeException If AsyncPDO has not been initialized
     *
     * @internal This method is for internal use only
     */
    private static function getPool(): AsyncPdoPool
    {
        if (! self::$isInitialized || self::$pool === null) {
            throw new \RuntimeException(
                'AsyncPDO has not been initialized. Please call AsyncPDO::init() at application startup.'
            );
        }

        return self::$pool;
    }
}
