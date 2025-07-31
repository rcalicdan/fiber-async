<?php

namespace Rcalicdan\FiberAsync\PDO;

/**
 * Database configuration factory for creating standardized database configurations.
 * 
 * This factory provides methods to create database configuration arrays for various
 * database drivers with sensible defaults. It also supports parsing database URLs
 * for easy configuration from environment variables or connection strings.
 */
class DatabaseConfigFactory
{
    /**
     * Create a MySQL database configuration.
     * 
     * @param array $config Optional configuration overrides
     * @return array Complete MySQL configuration array
     */
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

    /**
     * Create a PostgreSQL database configuration.
     * 
     * @param array $config Optional configuration overrides
     * @return array Complete PostgreSQL configuration array
     */
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

    /**
     * Create a SQLite database configuration.
     * 
     * @param string $database Path to SQLite database file or ':memory:' for in-memory database
     * @return array Complete SQLite configuration array
     */
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

    /**
     * Create a Microsoft SQL Server database configuration.
     * 
     * @param array $config Optional configuration overrides
     * @return array Complete SQL Server configuration array
     */
    public static function sqlserver(array $config = []): array
    {
        return array_merge([
            'driver' => 'sqlsrv',
            'host' => 'localhost',
            'port' => 1433,
            'database' => 'test',
            'username' => '',
            'password' => '',
            'options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ],
        ], $config);
    }

    /**
     * Create an Oracle database configuration.
     * 
     * @param array $config Optional configuration overrides
     * @return array Complete Oracle configuration array
     */
    public static function oracle(array $config = []): array
    {
        return array_merge([
            'driver' => 'oci',
            'host' => 'localhost',
            'port' => 1521,
            'database' => 'xe',
            'username' => '',
            'password' => '',
            'charset' => 'AL32UTF8',
            'options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ],
        ], $config);
    }

    /**
     * Create an IBM DB2 database configuration.
     * 
     * @param array $config Optional configuration overrides
     * @return array Complete IBM DB2 configuration array
     */
    public static function ibm(array $config = []): array
    {
        return array_merge([
            'driver' => 'ibm',
            'host' => 'localhost',
            'port' => 50000,
            'database' => 'test',
            'username' => '',
            'password' => '',
            'options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ],
        ], $config);
    }

    /**
     * Create a database configuration from a database URL.
     * 
     * Supports URLs in the format: driver://username:password@host:port/database?options
     * PDO options can be specified with 'pdo_' prefix in the query string.
     * 
     * Example: mysql://user:pass@localhost:3306/mydb?pdo_attr_errmode=exception
     * 
     * @param string $url Database connection URL
     * @return array Database configuration array
     * @throws \InvalidArgumentException If the URL is invalid
     */
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
                    $constantName = '\PDO::' . strtoupper(substr($key, 4));
                    if (defined($constantName)) {
                        $options[constant($constantName)] = $value;
                    }
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
