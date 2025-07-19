<?php

namespace Rcalicdan\FiberAsync\Facades;

use PDO;
use Throwable;
use Rcalicdan\FiberAsync\Database\AsyncPdoPool;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\Handlers\AsyncOperations\PromiseCollectionHandler;

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

        $loop = AsyncEventLoop::getInstance();
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

        AsyncEventLoop::reset();
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
     * @param array $transactions Array of transaction callbacks
     * @return PromiseInterface Promise that resolves with the winner's result
     */
    public static function raceTransactions(array $transactions): PromiseInterface
    {
        return Async::async(function () use ($transactions) {
            $transactionPromises = [];
            $pdoConnections = [];

            foreach ($transactions as $index => $transactionCallback) {
                $transactionPromises[$index] = self::startRacingTransaction($transactionCallback, $index, $pdoConnections);
            }

            $collectionHandler = new PromiseCollectionHandler();

            try {
                $winnerResult = await($collectionHandler->race($transactionPromises));

                await(self::finalizeRacingTransactions($pdoConnections, $winnerResult['winner_index']));

                return $winnerResult['result'];
            } catch (Throwable $e) {
                await(self::rollbackAllTransactions($pdoConnections));
                throw $e;
            }
        })();
    }

    /**
     * Start a single racing transaction
     */
    private static function startRacingTransaction(callable $transactionCallback, int $index, array &$pdoConnections): PromiseInterface
    {
        return Async::async(function () use ($transactionCallback, $index, &$pdoConnections) {
            $pdo = await(self::getPool()->get());
            $pdoConnections[$index] = $pdo;

            $pdo->beginTransaction();

            try {
                $result = $transactionCallback($pdo);

                return [
                    'result' => $result,
                    'winner_index' => $index,
                    'success' => true
                ];
            } catch (Throwable $e) {
                throw $e;
            }
        })();
    }

    /**
     * Finalize racing transactions: commit winner, rollback losers
     */
    private static function finalizeRacingTransactions(array $pdoConnections, int $winnerIndex): PromiseInterface
    {
        return Async::async(function () use ($pdoConnections, $winnerIndex) {
            $commitPromises = [];

            foreach ($pdoConnections as $index => $pdo) {
                if ($index === $winnerIndex) {
                    $commitPromises[] = Async::async(function () use ($pdo, $index) {
                        try {
                            $pdo->commit();
                            self::getPool()->release($pdo);
                        } catch (Throwable $e) {
                            error_log("Failed to commit winner transaction {$index}: " . $e->getMessage());
                            $pdo->rollBack();
                            self::getPool()->release($pdo);
                            throw $e;
                        }
                    })();
                } else {
                    $commitPromises[] = Async::async(function () use ($pdo, $index) {
                        try {
                            $pdo->rollBack();
                            self::getPool()->release($pdo);
                        } catch (Throwable $e) {
                            error_log("Failed to rollback loser transaction {$index}: " . $e->getMessage());
                            self::getPool()->release($pdo);
                        }
                    })();
                }
            }

            // Wait for all commit/rollback operations to complete
            $collectionHandler = new PromiseCollectionHandler();
            await($collectionHandler->all($commitPromises));
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
                        error_log("Failed to rollback transaction {$index}: " . $e->getMessage());
                        self::getPool()->release($pdo);
                    }
                })();
            }

            $collectionHandler = new PromiseCollectionHandler();
            await($collectionHandler->all($rollbackPromises));
        })();
    }

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
