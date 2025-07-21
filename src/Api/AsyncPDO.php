<?php

namespace Rcalicdan\FiberAsync\Api;

use PDO;
use Rcalicdan\FiberAsync\Async\Handlers\PromiseCollectionHandler;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\PDO\AsyncPdoPool;

use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Throwable;

final class AsyncPDO
{
    private static ?AsyncPdoPool $pool = null;
    private static bool $isInitialized = false;

    /**
     * Initializes the entire async database system.
     * This is the single point of configuration.
     */
    public static function init(array $dbConfig, int $poolSize = 10): void
    {
        if (self::$isInitialized) {
            return;
        }

        self::$pool = new AsyncPdoPool($dbConfig, $poolSize);
        self::$isInitialized = true;

        $loop = EventLoop::getInstance();
        $loop->configureDatabase($dbConfig);
    }

    /**
     * Resets both this facade and the underlying event loop for clean testing.
     */
    public static function reset(): void
    {
        if (self::$pool) {
            self::$pool->close();
        }
        self::$pool = null;
        self::$isInitialized = false;

        EventLoop::reset();
    }

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

    public static function query(string $sql, array $params = []): PromiseInterface
    {
        return self::run(function (PDO $pdo) use ($sql, $params) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    public static function fetchOne(string $sql, array $params = []): PromiseInterface
    {
        return self::run(function (PDO $pdo) use ($sql, $params) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetch(PDO::FETCH_ASSOC);
        });
    }

    public static function execute(string $sql, array $params = []): PromiseInterface
    {
        return self::run(function (PDO $pdo) use ($sql, $params) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->rowCount();
        });
    }

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

    public static function fetchValue(string $sql, array $params = []): PromiseInterface
    {
        return self::run(function (PDO $pdo) use ($sql, $params) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetch(PDO::FETCH_COLUMN);
        });
    }

    /**
     * Race multiple transactions and commit only the winner, rolling back all others.
     *
     * @param  array  $transactions  Array of transaction callbacks
     * @return PromiseInterface Promise that resolves with the winner's result
     */
    public static function raceTransactions(array $transactions): PromiseInterface
    {
        return Async::async(function () use ($transactions) {
            $transactionPromises = [];
            $pdoConnections = [];
            $cancellablePromises = [];

            foreach ($transactions as $index => $transactionCallback) {
                $cancellablePromise = self::startCancellableRacingTransaction($transactionCallback, $index, $pdoConnections);
                $transactionPromises[$index] = $cancellablePromise;
                $cancellablePromises[$index] = $cancellablePromise;
            }

            $collectionHandler = new PromiseCollectionHandler;

            try {
                $winnerResult = await($collectionHandler->race($transactionPromises));

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
     * Start a cancellable racing transaction
     */
    private static function startCancellableRacingTransaction(callable $transactionCallback, int $index, array &$pdoConnections): CancellablePromise
    {
        $cancellablePromise = new CancellablePromise(function ($resolve, $reject) use ($transactionCallback, $index, &$pdoConnections) {
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

        return $cancellablePromise;
    }

    /**
     * Cancel all losing transactions immediately
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
     * Cancel all transactions (for error scenarios)
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
     * Finalize racing transactions: commit winner, rollback losers
     */
    private static function finalizeRacingTransactions(array $pdoConnections, int $winnerIndex): PromiseInterface
    {
        return Async::async(function () use ($pdoConnections, $winnerIndex) {
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
     * Rollback all transactions in case of error
     */
    private static function rollbackAllTransactions(array $pdoConnections): PromiseInterface
    {
        return Async::async(function () use ($pdoConnections) {
            $rollbackPromises = [];

            foreach ($pdoConnections as $index => $pdo) {
                $rollbackPromises[] = Async::async(function () use ($pdo, $index) {
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

            $collectionHandler = new PromiseCollectionHandler;
            await($collectionHandler->all($rollbackPromises));
        })();
    }

    private static function getPool(): AsyncPdoPool
    {
        if (! self::$isInitialized) {
            throw new \RuntimeException(
                'AsyncPDO has not been initialized. Please call AsyncPDO::init() at application startup.'
            );
        }

        return self::$pool;
    }
}
