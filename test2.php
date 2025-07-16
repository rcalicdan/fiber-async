<?php

require_once "vendor/autoload.php";

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\Database\DatabaseConfigFactory;
use Rcalicdan\FiberAsync\Handlers\PDO\PDOHandler;

$mysqlConfig = DatabaseConfigFactory::mysql([
    'host' => 'localhost',
    'port' => 3309,
    'database' => 'yo',
    'username' => 'root',
    'password' => 'Reymart1234',
    'charset' => 'utf8mb4'
]);


$loop = AsyncEventLoop::getInstance($mysqlConfig);
$pdoHandler = new PDOHandler();

$loop->setPDOLatencyConfig([
    'query' => 0.001,
    'execute' => 0.002,
]);

$loop->nextTick(function () use ($pdoHandler) {
    $pdoHandler->execute('
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ')->then(function () {
        echo "Users table created successfully\n";
    });

    $pdoHandler->execute(
        'INSERT INTO users (username, email) VALUES (?, ?)',
        ['john_doe', 'john@example.com']
    )->then(function ($rowCount) {
        echo "Inserted $rowCount user(s)\n";
    });

    $pdoHandler->query('SELECT * FROM users ORDER BY created_at DESC LIMIT 10')
        ->then(function ($users) {
            echo "Found " . count($users) . " users:\n";
            foreach ($users as $user) {
                echo "- {$user['username']} ({$user['email']})\n";
            }
        });
});

$loop->run();
