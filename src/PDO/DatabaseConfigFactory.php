<?php

namespace Rcalicdan\FiberAsync\PDO;

class DatabaseConfigFactory
{
    public static function mysql(array $config = []): array
    {
        return array_merge([
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'test',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            ],
        ], $config);
    }

    public static function postgresql(array $config = []): array
    {
        return array_merge([
            'driver' => 'pgsql',
            'host' => 'localhost',
            'port' => 5432,
            'database' => 'test',
            'username' => 'postgres',
            'password' => '',
            'charset' => 'utf8',
            'options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ], $config);
    }

    public static function sqlite(string $database = ':memory:'): array
    {
        return [
            'driver' => 'sqlite',
            'database' => $database,
            'username' => '',
            'password' => '',
            'options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ],
        ];
    }

    public static function fromUrl(string $url): array
    {
        $parsed = parse_url($url);

        if (! $parsed) {
            throw new \InvalidArgumentException('Invalid database URL');
        }

        $driver = $parsed['scheme'] ?? 'mysql';
        $host = $parsed['host'] ?? 'localhost';
        $port = $parsed['port'] ?? null;
        $database = ltrim($parsed['path'] ?? '', '/');
        $username = $parsed['user'] ?? '';
        $password = $parsed['pass'] ?? '';

        // Parse query parameters
        $options = [];
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
            foreach ($queryParams as $key => $value) {
                if (strpos($key, 'pdo_') === 0) {
                    $options[constant('\PDO::'.strtoupper(substr($key, 4)))] = $value;
                }
            }
        }

        return [
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'options' => $options,
        ];
    }
}
