<?php

namespace Rcalicdan\FiberAsync\Facades;

use PDO;
use Throwable;
use Rcalicdan\FiberAsync\Database\AsyncPdoPool;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\AsyncEventLoop;

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
