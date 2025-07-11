<?php

require 'vendor/autoload.php';

require_once __DIR__ . '/src/Helpers/async_helper.php';
require_once __DIR__ . '/src/Helpers/loop_helper.php';

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\Transaction;

$client = new MySQLClient([
    'host' => '127.0.0.1',
    'port' => 3309,
    'user' => 'root',
    'password' => 'Reymart1234',
    'database' => 'yo',
]);

run(async(function () use ($client) {
    /** @var ?Transaction $transaction */
    $transaction = null;

    try {
        await($client->connect());
        echo "Successfully connected.\n";

        await($client->query("DROP TABLE IF EXISTS users, promotions"));
        await($client->query("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL)"));
        await($client->query("CREATE TABLE promotions (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, campaign_name VARCHAR(255))"));
        echo "Tables created.\n--------------------------\n";
        $transaction = await($client->beginTransaction());

        try {
            await($transaction->query("INSERT INTO users (email) VALUES ('new_user@example.com')"));
            echo "User 'new_user@example.com' inserted into the transaction.\n";
            await($transaction->savepoint('user_registered'));
            echo "SAVEPOINT 'user_registered' created.\n";

            echo "Attempting to add user to a 'Welcome' campaign...\n";
            throw new \Exception("Simulated failure: The promotional campaign is full.");
            
        } catch (\Throwable $e) {
            echo "An error occurred in an optional step: " . $e->getMessage() . "\n";
            echo "Rolling back to SAVEPOINT 'user_registered'...\n";
            await($transaction->rollbackToSavepoint('user_registered'));
        }

        echo "Committing the main transaction...\n";
        await($transaction->commit());

        echo "Transaction completed successfully.\n";

    } catch (\Throwable $e) {
        echo "A top-level error occurred: " . $e->getMessage() . "\n";
        if ($transaction && $transaction->isActive()) {
            await($transaction->rollback());
        }
    } finally {
        // --- Let's verify the final state of the database ---
        echo "--------------------------\nFinal state of database:\n";
        
        $users = await($client->query("SELECT * FROM users"));
        echo "Users table:\n";
        print_r($users);

        $promotions = await($client->query("SELECT * FROM promotions"));
        echo "\nPromotions table:\n";
        print_r($promotions);

        if ($client) {
            $client->close();
        }
    }
}));