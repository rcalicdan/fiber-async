<?php
// src/Database/DB.php

namespace Rcalicdan\FiberAsync\Database;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\Contracts\DatabaseClientInterface;

class DB
{
    private static ?DatabaseClientInterface $client = null;

    public static function setClient(DatabaseClientInterface $client): void
    {
        self::$client = $client;
    }

    public static function getClient(): DatabaseClientInterface
    {
        if (self::$client === null) {
            self::$client = DatabaseFactory::createFromEnv();
        }
        
        return self::$client;
    }

    public static function query(string $sql, array $params = []): PromiseInterface
    {
        return self::getClient()->query($sql, $params);
    }

    public static function table(string $table): QueryBuilder
    {
        return new QueryBuilder(self::getClient());
    }

    public static function beginTransaction(): PromiseInterface
    {
        return self::getClient()->beginTransaction();
    }

    public static function commit(): PromiseInterface
    {
        return self::getClient()->commit();
    }

    public static function rollback(): PromiseInterface
    {
        return self::getClient()->rollback();
    }

    public static function close(): PromiseInterface
    {
        return self::getClient()->close();
    }
}