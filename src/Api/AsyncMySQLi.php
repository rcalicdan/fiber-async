<?php

namespace Rcalicdan\FiberAsync\Api;

use mysqli;
use mysqli_result;
use mysqli_stmt;
use Rcalicdan\FiberAsync\Async\Handlers\PromiseCollectionHandler;
use Rcalicdan\FiberAsync\MySQLi\AsyncMySQLiPool;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Throwable;

final class AsyncMySQLi
{
    private static ?AsyncMySQLiPool $pool = null;
    private static bool $isInitialized = false;
    private const POOL_INTERVAL = 10;
    private const POOL_MAX_INTERVAL = 100;

    public static function init(array $dbConfig, int $poolSize = 10): void
    {
        if (self::$isInitialized) {
            return;
        }

        self::$pool = new AsyncMySQLiPool($dbConfig, $poolSize);
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
            $mysqli = null;

            try {
                $mysqli = await(self::getPool()->get());

                return $callback($mysqli);
            } finally {
                if ($mysqli) {
                    self::getPool()->release($mysqli);
                }
            }
        })();
    }

    public static function query(string $sql, array $params = [], ?string $types = null): PromiseInterface
    {
        return self::executeAsyncQuery($sql, $params, $types, 'fetchAll');
    }

    public static function fetchOne(string $sql, array $params = [], ?string $types = null): PromiseInterface
    {
        return self::executeAsyncQuery($sql, $params, $types, 'fetchOne');
    }

    public static function execute(string $sql, array $params = [], ?string $types = null): PromiseInterface
    {
        return self::executeAsyncQuery($sql, $params, $types, 'execute');
    }

    public static function fetchValue(string $sql, array $params = [], ?string $types = null): PromiseInterface
    {
        return self::executeAsyncQuery($sql, $params, $types, 'fetchValue');
    }

    public static function transaction(callable $callback): PromiseInterface
    {
        return self::run(function (mysqli $mysqli) use ($callback) {
            $mysqli->autocommit(false);
            $mysqli->begin_transaction();

            try {
                $result = $callback($mysqli);
                $mysqli->commit();
                $mysqli->autocommit(true);

                return $result;
            } catch (Throwable $e) {
                $mysqli->rollback();
                $mysqli->autocommit(true);

                throw $e;
            }
        });
    }

    public static function raceTransactions(array $transactions): PromiseInterface
    {
        return Async::async(function () use ($transactions) {
            $transactionPromises = [];
            $mysqliConnections = [];
            $cancellablePromises = [];

            foreach ($transactions as $index => $transactionCallback) {
                $cancellablePromise = self::startCancellableRacingTransaction($transactionCallback, $index, $mysqliConnections);
                $transactionPromises[$index] = $cancellablePromise;
                $cancellablePromises[$index] = $cancellablePromise;
            }

            $collectionHandler = new PromiseCollectionHandler;

            try {
                $winnerResult = await($collectionHandler->race($transactionPromises));

                self::cancelLosingTransactions($cancellablePromises, $winnerResult['winner_index']);

                await(self::finalizeRacingTransactions($mysqliConnections, $winnerResult['winner_index']));

                return $winnerResult['result'];
            } catch (Throwable $e) {
                self::cancelAllTransactions($cancellablePromises);
                await(self::rollbackAllTransactions($mysqliConnections));

                throw $e;
            }
        })();
    }

    private static function startCancellableRacingTransaction(callable $transactionCallback, int $index, array &$mysqliConnections): CancellablePromise
    {
        $cancellablePromise = new CancellablePromise(function ($resolve, $reject) use ($transactionCallback, $index, &$mysqliConnections) {
            $mysqli = await(self::getPool()->get());
            $mysqliConnections[$index] = $mysqli;

            $mysqli->autocommit(false);
            $mysqli->begin_transaction();

            try {
                $result = $transactionCallback($mysqli);

                $resolve([
                    'result' => $result,
                    'winner_index' => $index,
                    'success' => true,
                ]);
            } catch (Throwable $e) {
                throw $e;
            }
        });

        $cancellablePromise->setCancelHandler(function () use ($index, &$mysqliConnections) {
            if (isset($mysqliConnections[$index])) {
                $mysqli = $mysqliConnections[$index];

                try {
                    $mysqli->rollback();
                    $mysqli->autocommit(true);
                    self::getPool()->release($mysqli);
                } catch (Throwable $e) {
                    error_log("Failed to cancel transaction {$index}: ".$e->getMessage());
                    self::getPool()->release($mysqli);
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

    private static function finalizeRacingTransactions(array $mysqliConnections, int $winnerIndex): PromiseInterface
    {
        return Async::async(function () use ($mysqliConnections, $winnerIndex) {
            if (isset($mysqliConnections[$winnerIndex])) {
                $mysqli = $mysqliConnections[$winnerIndex];

                try {
                    $mysqli->commit();
                    $mysqli->autocommit(true);
                    echo "Transaction $winnerIndex: Winner committed!\n";
                    self::getPool()->release($mysqli);
                } catch (Throwable $e) {
                    error_log("Failed to commit winner transaction {$winnerIndex}: ".$e->getMessage());
                    $mysqli->rollback();
                    $mysqli->autocommit(true);
                    self::getPool()->release($mysqli);

                    throw $e;
                }
            }
        })();
    }

    private static function rollbackAllTransactions(array $mysqliConnections): PromiseInterface
    {
        return Async::async(function () use ($mysqliConnections) {
            $rollbackPromises = [];

            foreach ($mysqliConnections as $index => $mysqli) {
                $rollbackPromises[] = Async::async(function () use ($mysqli, $index) {
                    try {
                        $mysqli->rollback();
                        $mysqli->autocommit(true);
                        self::getPool()->release($mysqli);
                    } catch (Throwable $e) {
                        error_log("Failed to rollback transaction {$index}: ".$e->getMessage());
                        self::getPool()->release($mysqli);
                    }
                })();
            }

            $collectionHandler = new PromiseCollectionHandler;
            await($collectionHandler->all($rollbackPromises));
        })();
    }

    private static function detectParameterTypes(array $params): string
    {
        $types = '';

        foreach ($params as $param) {
            $types .= match (true) {
                $param === null => 's',
                is_bool($param) => 'i',
                is_int($param) => 'i',
                is_float($param) => 'd',
                is_resource($param) => 'b',
                is_string($param) && str_contains($param, "\0") => 'b',
                is_string($param) => 's',
                is_array($param) => 's',
                is_object($param) => 's',
                default => 's',
            };
        }

        return $types;
    }

    private static function preprocessParameters(array $params): array
    {
        $processedParams = [];

        foreach ($params as $param) {
            $processedParams[] = match (true) {
                $param === null => null,
                is_bool($param) => $param ? 1 : 0,
                is_int($param) || is_float($param) => $param,
                is_resource($param) => $param, // Keep resource as-is for blob
                is_string($param) => $param,
                is_array($param) => json_encode($param),
                is_object($param) && method_exists($param, '__toString') => (string) $param,
                is_object($param) => json_encode($param),
                default => (string) $param,
            };
        }

        return $processedParams;
    }

    private static function executeAsyncQuery(string $sql, array $params, ?string $types, string $resultType): PromiseInterface
    {
        return Async::async(function () use ($sql, $params, $types, $resultType) {
            $mysqli = await(self::getPool()->get());

            try {
                if (count($params) > 0) {
                    $stmt = $mysqli->prepare($sql);
                    if (! $stmt) {
                        throw new \RuntimeException('Prepare failed: '.$mysqli->error);
                    }

                    if ($types === null) {
                        $types = self::detectParameterTypes($params);
                    }

                    if ($types === '') {
                        $types = str_repeat('s', count($params));
                    }

                    $processedParams = self::preprocessParameters($params);

                    if (! $stmt->bind_param($types, ...$processedParams)) {
                        throw new \RuntimeException('Bind param failed: '.$stmt->error);
                    }

                    if (! $stmt->execute()) {
                        throw new \RuntimeException('Execute failed: '.$stmt->error);
                    }

                    if (
                        stripos(trim($sql), 'SELECT') === 0 ||
                        stripos(trim($sql), 'SHOW') === 0 ||
                        stripos(trim($sql), 'DESCRIBE') === 0
                    ) {
                        $result = $stmt->get_result();
                    } else {
                        $result = true;
                    }

                    return self::processResult($result, $resultType, $stmt, $mysqli);
                } else {
                    if (! $mysqli->query($sql, MYSQLI_ASYNC)) {
                        throw new \RuntimeException('Query failed: '.$mysqli->error);
                    }

                    $result = await(self::waitForAsyncCompletion($mysqli));

                    return self::processResult($result, $resultType, null, $mysqli);
                }
            } finally {
                self::getPool()->release($mysqli);
            }
        })();
    }

    private static function waitForAsyncCompletion(mysqli $mysqli, ?mysqli_stmt $stmt = null): PromiseInterface
    {
        return Async::async(function () use ($mysqli, $stmt) {
            $links = [$mysqli];
            $errors = [$mysqli];
            $reject = [$mysqli];

            $ready = mysqli_poll($links, $errors, $reject, 0, 0);

            if ($ready > 0) {
                return $stmt ? $stmt->get_result() : $mysqli->reap_async_query();
            }

            if ($ready === false) {
                throw new \RuntimeException('MySQLi poll failed immediately');
            }

            while (true) {
                $links = [$mysqli];
                $errors = [$mysqli];
                $reject = [$mysqli];

                $ready = mysqli_poll($links, $errors, $reject, 0, self::POOL_INTERVAL);

                if ($ready === false) {
                    throw new \RuntimeException('MySQLi poll failed during wait');
                }

                if ($ready > 0) {
                    return $stmt ? $stmt->get_result() : $mysqli->reap_async_query();
                }

                await(Timer::delay(0));
                $pollInterval = min((int) (self::POOL_INTERVAL * 1.2), self::POOL_MAX_INTERVAL);
            }
        })();
    }

    private static function processResult($result, string $resultType, ?mysqli_stmt $stmt = null, ?mysqli $mysqli = null)
    {
        if ($result === false) {
            $error = $stmt?->error ?? $mysqli?->error ?? 'Unknown error';

            throw new \RuntimeException('Query execution failed: '.$error);
        }

        return match ($resultType) {
            'fetchAll' => self::handleFetchAll($result),
            'fetchOne' => self::handleFetchOne($result),
            'fetchValue' => self::handleFetchValue($result),
            'execute' => self::handleExecute($stmt, $mysqli),
            default => $result,
        };
    }

    private static function handleFetchAll($result): array
    {
        if ($result instanceof mysqli_result) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }

        return [];
    }

    private static function handleFetchOne($result): ?array
    {
        if ($result instanceof mysqli_result) {
            return $result->fetch_assoc();
        }

        return null;
    }

    private static function handleFetchValue($result): mixed
    {
        if (! ($result instanceof mysqli_result)) {
            return null;
        }

        $row = $result->fetch_row();

        return $row ? $row[0] : null;
    }

    private static function handleExecute(?mysqli_stmt $stmt, ?mysqli $mysqli): int
    {
        if ($stmt) {
            return $stmt->affected_rows;
        }
        if ($mysqli) {
            return $mysqli->affected_rows;
        }

        return 0;
    }

    private static function getPool(): AsyncMySQLiPool
    {
        if (! self::$isInitialized) {
            throw new \RuntimeException(
                'AsyncMySQLi has not been initialized. Please call AsyncMySQLi::init() at application startup.'
            );
        }

        return self::$pool;
    }
}
