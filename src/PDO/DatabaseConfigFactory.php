<?php

namespace Rcalicdan\FiberAsync\PDO;

use InvalidArgumentException;
use PDO;

/**
 * Creates standardized database configuration arrays for various drivers.
 *
 * This factory provides convenient static methods to generate configuration
 * arrays for common database drivers with sensible defaults. It also supports
 * parsing database URLs for easy configuration from environment variables.
 */
class DatabaseConfigFactory
{
    /**
     * Creates a standardized MySQL database configuration array.
     *
     * @param  array<string, mixed>  $config  Optional configuration values to override the defaults.
     * @return array<string, mixed> The complete MySQL configuration array.
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
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            ],
        ], $config);
    }

    /**
     * Creates a standardized PostgreSQL database configuration array.
     *
     * @param  array<string, mixed>  $config  Optional configuration values to override the defaults.
     * @return array<string, mixed> The complete PostgreSQL configuration array.
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
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ], $config);
    }

    /**
     * Creates a standardized SQLite database configuration array.
     *
     * @param  string  $database  Path to the SQLite database file or ':memory:' for an in-memory database.
     * @return array<string, mixed> The complete SQLite configuration array.
     */
    public static function sqlite(string $database = ':memory:'): array
    {
        return [
            'driver' => 'sqlite',
            'database' => $database,
            'username' => '',
            'password' => '',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ];
    }

    /**
     * Creates a standardized Microsoft SQL Server database configuration array.
     *
     * @param  array<string, mixed>  $config  Optional configuration values to override the defaults.
     * @return array<string, mixed> The complete SQL Server configuration array.
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
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ], $config);
    }

    /**
     * Creates a standardized Oracle database configuration array.
     *
     * @param  array<string, mixed>  $config  Optional configuration values to override the defaults.
     * @return array<string, mixed> The complete Oracle configuration array.
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
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ], $config);
    }

    /**
     * Creates a standardized IBM DB2 database configuration array.
     *
     * @param  array<string, mixed>  $config  Optional configuration values to override the defaults.
     * @return array<string, mixed> The complete IBM DB2 configuration array.
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
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ], $config);
    }

    /**
     * Creates a standardized Firebird database configuration array.
     *
     * @param  array<string, mixed>  $config  Optional configuration values to override the defaults.
     * @return array<string, mixed> The complete Firebird configuration array.
     */
    public static function firebird(array $config = []): array
    {
        return array_merge([
            'driver' => 'firebird',
            'host' => 'localhost',
            'port' => 3050,
            'database' => '/path/to/your/database.fdb',
            'username' => 'SYSDBA',
            'password' => 'masterkey',
            'charset' => 'UTF8',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ], $config);
    }

    /**
     * Creates a standardized ODBC database configuration array.
     *
     * Note: This typically relies on a pre-configured DSN (Data Source Name) on the server.
     *
     * @param  array<string, mixed>  $config  Optional configuration values to override the defaults.
     * @return array<string, mixed> The complete ODBC configuration array.
     */
    public static function odbc(array $config = []): array
    {
        return array_merge([
            'driver' => 'odbc',
            'dsn' => 'MyDataSource', // This must be configured on the server
            'username' => '',
            'password' => '',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ], $config);
    }

    /**
     * Creates a standardized Informix database configuration array.
     *
     * @param  array<string, mixed>  $config  Optional configuration values to override the defaults.
     * @return array<string, mixed> The complete Informix configuration array.
     */
    public static function informix(array $config = []): array
    {
        return array_merge([
            'driver' => 'informix',
            'host' => 'localhost',
            'database' => 'sysmaster',
            'server' => 'ol_informix', // Example server name
            'protocol' => 'onsoctcp',
            'username' => '',
            'password' => '',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ], $config);
    }

    /**
     * Creates a database configuration from a database URL string.
     *
     * Supports URLs in the format: `driver://username:password@host:port/database?options`
     * PDO options can be specified with a 'pdo_' prefix in the query string.
     *
     * @example `mysql://user:pass@localhost:3306/mydb?pdo_attr_errmode=exception`
     *
     * @param  string  $url  The database connection URL.
     * @return array<string, mixed> The parsed database configuration array.
     *
     * @throws InvalidArgumentException If the URL is malformed.
     */
    public static function fromUrl(string $url): array
    {
        $parsed = parse_url($url);

        if ($parsed === false) {
            throw new InvalidArgumentException('Invalid database URL provided.');
        }

        $driver = isset($parsed['scheme']) && is_string($parsed['scheme']) ? $parsed['scheme'] : 'mysql';
        $host = isset($parsed['host']) && is_string($parsed['host']) ? $parsed['host'] : 'localhost';
        $port = isset($parsed['port']) && is_int($parsed['port']) ? $parsed['port'] : null;
        $database = isset($parsed['path']) && is_string($parsed['path']) ? ltrim($parsed['path'], '/') : '';
        $username = isset($parsed['user']) && is_string($parsed['user']) ? $parsed['user'] : '';
        $password = isset($parsed['pass']) && is_string($parsed['pass']) ? $parsed['pass'] : '';

        $options = [];
        if (isset($parsed['query']) && is_string($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
            foreach ($queryParams as $key => $value) {
                if (! is_string($key)) {
                    continue;
                }
                if (str_starts_with($key, 'pdo_')) {
                    $constantName = PDO::class.'::'.strtoupper(substr($key, 4));
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
