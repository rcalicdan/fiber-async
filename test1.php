<?php

require_once __DIR__ . '/vendor/autoload.php';

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\PreparedStatement;

run(function () {
    $connectionParams = [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'user'     => 'root',
        'password' => '',
        'database' => 'yos',
        'debug'    => true,
    ];

    $client = new MySQLClient($connectionParams);
    $selectStmt = null;
    $insertStmt = null;

    try {
        echo "Connecting...\n";
        echo "host: " . $connectionParams['host'] . "\n";
        echo "port: " . $connectionParams['port'] . "\n";
        echo "user: " . $connectionParams['user'] . "\n";
        echo "password: " . $connectionParams['password'] . "\n";
        echo "database: " . $connectionParams['database'] . "\n";
        echo "debug: " . $connectionParams['debug'] . "\n";
        await($client->connect());
        echo "Connection successful.\n\n";

        $selectSql = 'SELECT id, name, email FROM users WHERE id = ? OR id = ?';
        echo "Preparing: {$selectSql}\n";
        $selectStmt = await($client->prepare($selectSql));

        $userIds = [1, 3];
        echo "Executing with params: " . json_encode($userIds) . "\n";
        $users = await($selectStmt->execute($userIds));
        
        echo "Query Result:\n";
        print_r($users);
        echo "\n";

        // --- FIX IS HERE ---
        // 1. Added 'password' to the column list
        $insertSql = 'INSERT INTO users (name, email, password) VALUES (?, ?, ?)';
        echo "Preparing: {$insertSql}\n";
        $insertStmt = await($client->prepare($insertSql));

        // 2. Added a value for the password parameter
        // In a real app, this should be a securely hashed password.
        $hashedPassword = password_hash('supersecret123', PASSWORD_DEFAULT);
        $newUser = ['Jane Doe', 'jane.doe@example.com', $hashedPassword];
        echo "Executing with params: " . json_encode(['Jane Doe', 'jane.doe@example.com', '...hashed_password...']) . "\n";
        
        $insertResult = await($insertStmt->execute($newUser));
        
        echo "Insert Result:\n";
        echo "  - Affected Rows: {$insertResult->affectedRows}\n";
        echo "  - Last Insert ID: {$insertResult->lastInsertId}\n\n";

    } catch (Throwable $e) {
        echo "[ERROR] " . $e->getMessage() . "\n";
    } finally {
        if ($selectStmt) {
            echo "Closing SELECT statement...\n";
            await($selectStmt->close());
        }
        if ($insertStmt) {
            echo "Closing INSERT statement...\n";
            await($insertStmt->close());
        }
        if ($client->getSocket() && !$client->getSocket()->isClosed()) {
            echo "Closing database connection...\n";
            await($client->close());
        }
        echo "Done.\n";
    }
});