<?php
// src/Database/AsyncDB.php

namespace Rcalicdan\FiberAsync\Database;

use Rcalicdan\FiberAsync\Config\DatabaseConfig;
use Rcalicdan\FiberAsync\Contracts\DatabaseConnectionInterface;
use Rcalicdan\FiberAsync\Handlers\Database\ConnectionHandler;

final class AsyncDB
{
    private static ?DatabaseConnectionInterface $connection = null;
    private static ?ConnectionHandler $connectionHandler = null;

    public static function table(string $table): AsyncQueryBuilder
    {
        return new AsyncQueryBuilder(self::getConnection(), $table);
    }

    public static function getConnection(): DatabaseConnectionInterface
    {
        if (self::$connection === null) {
            $config = new DatabaseConfig();
            self::$connectionHandler = new ConnectionHandler($config);
            self::$connection = self::$connectionHandler->createConnection();
        }

        return self::$connection;
    }

    public static function disconnect(): void
    {
        if (self::$connection !== null) {
            self::$connection->disconnect();
            self::$connection = null;
            self::$connectionHandler = null;
        }
    }

    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }

    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    public static function rollback(): bool
    {
        return self::getConnection()->rollback();
    }
}