<?php

namespace Rcalicdan\FiberAsync\Facades;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\MySQL\Pool;
use Rcalicdan\FiberAsync\Database\MySQL\ValueObjects\MysqlConfig;

final class AsyncMysql
{
    private static ?Pool $pool = null;

    private static function getPool(): Pool
    {
        if (self::$pool === null) {
            \Dotenv\Dotenv::createImmutable(getcwd())->load();
            $config = MysqlConfig::fromEnv();
            self::$pool = new Pool($config);
        }

        return self::$pool;
    }

    public static function query(string $sql): PromiseInterface
    {
        return self::getPool()->query($sql);
    }

    public static function prepare(string $sql): PromiseInterface
    {
        return self::getPool()->prepare($sql);
    }

    public static function beginTransaction(): PromiseInterface
    {
        return self::getPool()->beginTransaction();
    }

    public static function close(): void
    {
        if (self::$pool) {
            self::getPool()->close();
            self::$pool = null;
        }
    }

    public static function reset(): void
    {
        self::close();
    }
}