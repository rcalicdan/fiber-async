<?php

/**
 * =================================================================
 * ASYNCHRONOUS DATABASE CONFIGURATION
 * =================================================================
 *
 * This file defines all the database connections for your application.
 * It is designed to be flexible and read its values from a .env file
 * for security and portability.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    */
    'default' => getenv('ASYNC_DB_CONNECTION') ?: 'mysql',


    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    */
    'connections' => [

        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => match ($path = getenv('ASYNC_DB_SQLITE_PATH')) {

                ':memory:' => 'file::memory:?cache=shared',
                false => __DIR__ . '/../database/database.sqlite',
                default => $path,
            },
            'options'  => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        ],

        'mysql' => [
            'driver'   => 'mysql',
            'host'     => getenv('ASYNC_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('ASYNC_DB_PORT') ?: 3306,
            'database' => getenv('ASYNC_DB_DATABASE') ?: 'test',
            'username' => getenv('ASYNC_DB_USERNAME') ?: 'root',
            'password' => getenv('ASYNC_DB_PASSWORD') ?: '',
            'charset'  => 'utf8mb4',
            'options'  => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        ],

        'pgsql' => [
            'driver'   => 'pgsql',
            'host'     => getenv('ASYNC_DB_HOST_PGSQL') ?: '127.0.0.1',
            'port'     => getenv('ASYNC_DB_PORT_PGSQL') ?: 5432,
            'database' => getenv('ASYNC_DB_DATABASE_PGSQL') ?: 'test',
            'username' => getenv('ASYNC_DB_USERNAME_PGSQL') ?: 'postgres',
            'password' => getenv('ASYNC_DB_PASSWORD_PGSQL') ?: '',
            'charset'  => 'utf8',
            'options'  => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection Pool Size
    |--------------------------------------------------------------------------
    */
    'pool_size' => (int)(getenv('ASYNC_DB_POOL_SIZE') ?: 10),


];
