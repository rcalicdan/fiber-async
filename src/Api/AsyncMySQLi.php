<?php

namespace Rcalicdan\FiberAsync\Api;

use mysqli;
use mysqli_stmt;
use mysqli_result;
use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\MySQLi\AsyncMySQLiPool;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

final class AsyncMySQLi
{
    private static ?AsyncMySQLiPool $pool = null;
    private static bool $isInitialized = false;

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

    public static function query(string $sql, array $params = [], string $types = ''): PromiseInterface
    {
        return self::executeAsyncQuery($sql, $params, $types, 'fetchAll');
    }

    public static function fetchOne(string $sql, array $params = [], string $types = ''): PromiseInterface
    {
        return self::executeAsyncQuery($sql, $params, $types, 'fetchOne');
    }

    public static function execute(string $sql, array $params = [], string $types = ''): PromiseInterface
    {
        return self::executeAsyncQuery($sql, $params, $types, 'execute');
    }

    public static function fetchValue(string $sql, array $params = [], string $types = ''): PromiseInterface
    {
        return self::executeAsyncQuery($sql, $params, $types, 'fetchValue');
    }

    private static function executeAsyncQuery(string $sql, array $params, string $types, string $resultType): PromiseInterface
    {
        return Async::async(function () use ($sql, $params, $types, $resultType) {
            $mysqli = await(self::getPool()->get());

            try {
                if (!empty($params)) {
                    $stmt = $mysqli->prepare($sql);
                    if (!$stmt) {
                        throw new \RuntimeException("Prepare failed: " . $mysqli->error);
                    }

                    if (empty($types)) {
                        $types = str_repeat('s', count($params));
                    }

                    if (!$stmt->bind_param($types, ...$params)) {
                        throw new \RuntimeException("Bind param failed: " . $stmt->error);
                    }

                    if (!$stmt->execute()) {
                        throw new \RuntimeException("Execute failed: " . $stmt->error);
                    }

                    if (stripos(trim($sql), 'SELECT') === 0 || stripos(trim($sql), 'SHOW') === 0 || stripos(trim($sql), 'DESCRIBE') === 0) {
                        $result = $stmt->get_result();
                    } else {
                        $result = true; 
                    }

                    return self::processResult($result, $resultType, $stmt, $mysqli);
                } else {
                    if (!$mysqli->query($sql, MYSQLI_ASYNC)) {
                        throw new \RuntimeException("Query failed: " . $mysqli->error);
                    }

                    $result = await(self::waitForAsyncCompletion($mysqli));

                    return self::processResult($result, $resultType, null, $mysqli);
                }
            } finally {
                self::getPool()->release($mysqli);
            }
        })();
    }

    /**
     * Fixed async waiting that properly handles mysqli_poll array modifications
     */
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
                throw new \RuntimeException("MySQLi poll failed immediately");
            }

            $pollInterval = 100;
            $maxInterval = 1000;

            while (true) {
                $links = [$mysqli];
                $errors = [$mysqli];
                $reject = [$mysqli];

                $ready = mysqli_poll($links, $errors, $reject, 0, $pollInterval);

                if ($ready === false) {
                    throw new \RuntimeException("MySQLi poll failed during wait");
                }

                if ($ready > 0) {
                    return $stmt ? $stmt->get_result() : $mysqli->reap_async_query();
                }

                await(Timer::delay(0));
                $pollInterval = min($pollInterval * 1.2, $maxInterval);
            }
        })();
    }

    private static function processResult($result, string $resultType, ?mysqli_stmt $stmt = null, ?mysqli $mysqli = null)
    {
        if ($result === false) {
            $error = $stmt?->error ?? $mysqli?->error ?? 'Unknown error';
            throw new \RuntimeException("Query execution failed: " . $error);
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
        if (!($result instanceof mysqli_result)) {
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
        if (!self::$isInitialized) {
            throw new \RuntimeException(
                'AsyncMySQLi has not been initialized. Please call AsyncMySQLi::init() at application startup.'
            );
        }

        return self::$pool;
    }
}