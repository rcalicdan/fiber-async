<?php

return [
    'default' => $_ENV['DB_CONNECTION'] ?? 'mysql',

    'connections' => [
        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => match ($path = $_ENV['DB_SQLITE_PATH'] ?? null) {
                ':memory:' => 'file::memory:?cache=shared',
                null => __DIR__ . '/../database/database.sqlite',
                default => $path,
            },
            'options'  => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        ],

        'mysql' => [
            'driver'   => 'mysql',
            'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port'     => $_ENV['DB_PORT'] ?? 3306,
            'database' => $_ENV['DB_DATABASE'] ?? 'test',
            'username' => $_ENV['DB_USERNAME'] ?? 'root',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset'  => 'utf8mb4',
            'options'  => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        ],

        'pgsql' => [
            'driver'   => 'pgsql',
            'host'     => $_ENV['DB_HOST_PGSQL'] ?? '127.0.0.1',
            'port'     => $_ENV['DB_PORT_PGSQL'] ?? 5432,
            'database' => $_ENV['DB_DATABASE_PGSQL'] ?? 'test',
            'username' => $_ENV['DB_USERNAME_PGSQL'] ?? 'postgres',
            'password' => $_ENV['DB_PASSWORD_PGSQL'] ?? '',
            'charset'  => 'utf8',
            'options'  => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        ],
    ],

    'pool_size' => (int)($_ENV['DB_POOL_SIZE'] ?? 10),
];
