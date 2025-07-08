<?php

namespace Rcalicdan\FiberAsync\Database;

use Rcalicdan\FiberAsync\Database\Config\DatabaseConfig;
use Rcalicdan\FiberAsync\Contracts\Database\DatabaseClientInterface;
use Rcalicdan\FiberAsync\Database\MySQL\MySQLClient;

class DatabaseFactory
{
    public static function createMySQL(array $config = []): DatabaseClientInterface
    {
        $dbConfig = new DatabaseConfig(array_merge([
            'driver' => 'mysql',
        ], $config));
        
        return new MySQLClient($dbConfig);
    }

    public static function createFromEnv(): DatabaseClientInterface
    {
        $config = DatabaseConfig::fromEnv();
        
        return match ($config->getEnv('DB_CONNECTION', 'mysql')) {
            'mysql' => new MySQLClient($config),
            default => throw new \InvalidArgumentException('Unsupported database driver'),
        };
    }
}